<?php

use App\Models\License;
use App\Models\LicensePurchaseIntent;
use App\Models\LicenseVerification;
use App\Models\User;
use App\Services\GpgService;
use App\Services\LicenseGeneratorService;
use App\Services\LicenseRevocationService;
use Database\Seeders\PlansTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Wave\Http\Middleware\VerifyPaddleWebhookSignature;
use Wave\Plan;
use Wave\Subscription;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->seed(PlansTableSeeder::class);
    Mail::fake();
});

function subscriptionFor(User $user): Subscription
{
    // Use the first available plan (seeded) rather than hardcoding a plan id.
    $plan = Plan::first();

    return Subscription::create([
        'billable_type' => config('wave.user_model'),
        'billable_id' => $user->id,
        'plan_id' => $plan?->id,
        'vendor_slug' => 'paddle',
        'status' => 'active',
        'cycle' => 'year',
    ]);
}

function licenseForUser(User $user, int $subscriptionId, string $status = 'ACTIVE'): License
{
    return License::create([
        'customer_id' => $user->id,
        'subscription_id' => $subscriptionId,
        'machine_tokens' => ['sha256:test'],
        'seats' => 3,
        'features' => ['srms', 'ai_analysis'],
        'status' => $status,
        'signed_license' => "-----BEGIN PGP SIGNED MESSAGE-----\n...",
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
    ]);
}

test('revokeForSubscription marks active licenses as REVOKED and clears signed content', function () {
    $user = User::factory()->create();
    $sub = subscriptionFor($user);
    $license = licenseForUser($user, $sub->id);

    $service = app(LicenseRevocationService::class);
    $result = $service->revokeForSubscription($sub->id, 'refund');

    expect($result)->toHaveCount(1);

    $fresh = $license->fresh();
    expect($fresh->status)->toBe('REVOKED');
    expect($fresh->revocation_reason)->toBe('refund');
    expect($fresh->signed_license)->toBeNull();
});

test('revokeForSubscription skips licenses that are already revoked or expired', function () {
    $user = User::factory()->create();
    $sub = subscriptionFor($user);
    licenseForUser($user, $sub->id, 'REVOKED');
    licenseForUser($user, $sub->id, 'EXPIRED');

    $result = app(LicenseRevocationService::class)->revokeForSubscription($sub->id, 'chargeback');

    expect($result)->toHaveCount(0);
});

test('expireForSubscription marks active licenses as EXPIRED, preserves signed content', function () {
    $user = User::factory()->create();
    $sub = subscriptionFor($user);
    $license = licenseForUser($user, $sub->id);

    $result = app(LicenseRevocationService::class)->expireForSubscription($sub->id);

    expect($result)->toHaveCount(1);

    $fresh = $license->fresh();
    expect($fresh->status)->toBe('EXPIRED');
    expect($fresh->signed_license)->not->toBeNull();
    expect($fresh->revocation_reason)->toBeNull();
});

test('revoke notifies the customer by email', function () {
    $user = User::factory()->create(['email' => 'customer@example.com']);
    $sub = subscriptionFor($user);
    $license = licenseForUser($user, $sub->id);

    // Swap the mailer to capture messages sent via Mail::raw().
    Mail::swap($mailer = new MailManager(app()));
    $sent = [];
    Mail::shouldReceive('raw')->andReturnUsing(function ($body, $callback) use (&$sent) {
        $message = new class
        {
            public array $to = [];

            public string $subject = '';

            public function to($address)
            {
                $this->to[] = $address;

                return $this;
            }

            public function subject($s)
            {
                $this->subject = $s;

                return $this;
            }
        };
        $callback($message);
        $sent[] = $message;
    });

    app(LicenseRevocationService::class)->revoke($license, 'refund');

    expect($sent)->toHaveCount(1);
    expect($sent[0]->to)->toContain($user->email);
});

test('revoke skips notification when notify flag is false', function () {
    $user = User::factory()->create();
    $sub = subscriptionFor($user);
    $license = licenseForUser($user, $sub->id);

    $rawCalled = false;
    Mail::shouldReceive('raw')->andReturnUsing(function () use (&$rawCalled) {
        $rawCalled = true;
    });

    app(LicenseRevocationService::class)->revoke($license, 'refund', notify: false);

    expect($rawCalled)->toBeFalse();
});

test('revokeForSubscription only affects licenses for that subscription', function () {
    $user = User::factory()->create();
    $sub1 = subscriptionFor($user);
    $sub2 = subscriptionFor($user);
    $license1 = licenseForUser($user, $sub1->id);
    $license2 = licenseForUser($user, $sub2->id);

    app(LicenseRevocationService::class)->revokeForSubscription($sub1->id, 'refund');

    expect($license1->fresh()->status)->toBe('REVOKED');
    expect($license2->fresh()->status)->toBe('ACTIVE');
});

// ---------------------------------------------------------------------------
// transaction.completed → license intent minting (one-time purchase flow)
// ---------------------------------------------------------------------------

function bindFakeGenerator(): void
{
    $gpg = Mockery::mock(GpgService::class);
    $gpg->shouldReceive('clearsign')->andReturnUsing(function ($in, $out, $home, $pass) {
        file_put_contents($out, "-----BEGIN PGP SIGNED MESSAGE-----\n{}\n-----BEGIN PGP SIGNATURE-----\nfake\n-----END PGP SIGNATURE-----");
    });
    app()->instance(LicenseGeneratorService::class, new LicenseGeneratorService($gpg));
}

function makePendingIntent(): LicensePurchaseIntent
{
    $user = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $user->syncRoles(['Self-hosted']);

    $verification = LicenseVerification::create([
        'user_id' => $user->id,
        'file_path' => 'private/v.tar.gz',
        'status' => 'passed',
        'machine_tokens' => ['sha256:hookmachine'],
    ]);

    return LicensePurchaseIntent::create([
        'customer_id' => $user->id,
        'verification_id' => $verification->id,
        'seats' => 4,
        'features' => ['srms', 'ai_analysis'],
        'bundle_key' => 'ai_analysis+srms',
        'paddle_price_id' => 'pri_test',
        'status' => 'pending',
        'expires_at' => now()->addHour(),
    ]);
}

function postWebhook(array $data, string $event = 'transaction.completed')
{
    return test()->withoutMiddleware(VerifyPaddleWebhookSignature::class)
        ->postJson('/paddle/webhook', [
            'event_type' => $event,
            'data' => $data,
            'occurred_at' => now()->toIso8601String(),
        ]);
}

test('transaction.completed mints License when custom_data.intent_id is present', function () {
    bindFakeGenerator();
    $intent = makePendingIntent();

    $response = postWebhook([
        'id' => 'txn_one_time_001',
        'subscription_id' => null,
        'custom_data' => ['intent_id' => (string) $intent->id],
    ]);

    $response->assertOk();

    $intent->refresh();
    expect($intent->status)->toBe('completed');
    expect($intent->license_id)->not->toBeNull();

    $license = License::find($intent->license_id);
    expect($license)->not->toBeNull();
    expect($license->customer_id)->toBe($intent->customer_id);
    expect($license->seats)->toBe(4);
    expect($license->features)->toBe(['srms', 'ai_analysis']);
    expect($license->signed_license)->toContain('BEGIN PGP SIGNED MESSAGE');
});

test('transaction.completed without intent_id still flows to existing token-topup logic', function () {
    bindFakeGenerator();

    // No intent in payload → existing path runs (PaddleSubscription not found
    // for this random id, so it returns early without creating a License).
    $response = postWebhook([
        'id' => 'txn_no_intent',
        'subscription_id' => 'sub_unknown',
    ]);

    $response->assertOk();
    expect(License::count())->toBe(0);
    expect(LicensePurchaseIntent::count())->toBe(0);
});

test('transaction.completed with intent_id but a non-null subscription_id falls through to recurring logic', function () {
    bindFakeGenerator();
    $intent = makePendingIntent();

    // Both intent_id AND subscription_id present means this is a recurring
    // payment — the intent route must NOT consume it (defensive guard).
    $response = postWebhook([
        'id' => 'txn_with_sub',
        'subscription_id' => 'sub_recurring',
        'custom_data' => ['intent_id' => (string) $intent->id],
    ]);

    $response->assertOk();
    expect($intent->fresh()->status)->toBe('pending');
    expect(License::count())->toBe(0);
});
