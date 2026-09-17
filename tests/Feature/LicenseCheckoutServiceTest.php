<?php

use App\Models\License;
use App\Models\LicensePurchaseIntent;
use App\Models\LicenseVerification;
use App\Models\User;
use App\Services\GpgService;
use App\Services\LicenseCheckoutService;
use App\Services\LicenseGeneratorService;
use Carbon\CarbonInterface;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Wave\Plan;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);

    Config::set('wave.paddle.api_key', 'test-api-key');
    Config::set('wave.paddle.env', 'sandbox');
    Config::set('license.paddle_return_url', '/portal/licenses?checkout=complete');
    Config::set('license.intent_ttl_minutes', 60);

    $fakeGpgHome = sys_get_temp_dir().'/fake-gpg-home-checkout-test';
    @mkdir($fakeGpgHome, 0700, true);
    Config::set('license.gpg_home_sign', $fakeGpgHome);
});

/**
 * Seed the Self-hosted plan row used by licensePriceId() lookups. Pass null
 * for either price to simulate a plan that has only one cycle configured.
 *
 * Uses a raw insert to bypass the Plan::creating hook that rewrites slug
 * from name; we need slug='standalone' for the licensePlanSlug() mapping.
 */
function seedStandalonePlan(?string $monthlyPriceId = 'pri_standalone_month', ?string $yearlyPriceId = 'pri_standalone_year'): Plan
{
    Plan::query()->where('slug', 'standalone')->delete();

    $roleId = DB::table('roles')->value('id') ?? 1;

    DB::table('plans')->insert([
        'name' => json_encode(['en' => 'Self-hosted']),
        'slug' => 'standalone',
        'type' => 'service',
        'role_id' => $roleId,
        'features' => '',
        'monthly_price' => 99,
        'monthly_price_id' => $monthlyPriceId,
        'yearly_price' => 990,
        'yearly_price_id' => $yearlyPriceId,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The per-seat add-on used for any purchase with additional seats.
    Plan::query()->where('slug', 'extra-seat')->delete();
    DB::table('plans')->insert([
        'name' => json_encode(['en' => 'Extra seat']),
        'slug' => 'extra-seat',
        'type' => 'seat',
        'role_id' => $roleId,
        'features' => '',
        'monthly_price' => 9,
        'monthly_price_id' => 'pri_seat_month',
        'yearly_price' => 90,
        'yearly_price_id' => 'pri_seat_year',
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Plan::where('slug', 'standalone')->first();
}

function verifiedCustomer(): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
    ]);
    $user->syncRoles(['Self-hosted']);

    LicenseVerification::create([
        'user_id' => $user->id,
        'file_path' => 'private/test.tar.gz',
        'status' => 'passed',
        'machine_tokens' => ['sha256:test-machine'],
    ]);

    return $user;
}

function paddleTransactionCreatedResponse(string $txnId = 'txn_abc123', string $checkoutUrl = 'https://sandbox-pay.paddle.com/checkout/abc123'): array
{
    return ['data' => ['id' => $txnId, 'checkout' => ['url' => $checkoutUrl]]];
}

// ---------------------------------------------------------------------------
// bundleKey() helper
// ---------------------------------------------------------------------------

it('builds canonical bundle keys regardless of input order', function () {
    expect(bundleKey(['srms']))->toBe('srms');
    expect(bundleKey(['srms', 'ai_analysis']))->toBe('ai_analysis+srms');
    expect(bundleKey(['ai_analysis', 'srms']))->toBe('ai_analysis+srms');
    expect(bundleKey(['telegram', 'jira', 'srms', 'ai_analysis']))
        ->toBe('ai_analysis+jira+srms+telegram');
});

it('forces srms into the bundle when omitted', function () {
    expect(bundleKey(['ai_analysis']))->toBe('ai_analysis+srms');
});

it('deduplicates repeated features', function () {
    expect(bundleKey(['srms', 'srms', 'jira']))->toBe('jira+srms');
});

// ---------------------------------------------------------------------------
// licensePriceId() helper — reads from the plans table
// ---------------------------------------------------------------------------

it('resolves licensePriceId from the Self-hosted plan for the full bundle (yearly)', function () {
    seedStandalonePlan('pri_month', 'pri_year');
    expect(licensePriceId(['srms', 'ai_analysis', 'jira', 'telegram'], 'year'))->toBe('pri_year');
});

it('resolves licensePriceId from the Self-hosted plan for the full bundle (monthly)', function () {
    seedStandalonePlan('pri_month', 'pri_year');
    expect(licensePriceId(['srms', 'ai_analysis', 'jira', 'telegram'], 'month'))->toBe('pri_month');
});

it('returns empty string when the Self-hosted plan is not seeded', function () {
    Plan::query()->where('slug', 'standalone')->delete();
    expect(licensePriceId(['srms', 'ai_analysis', 'jira', 'telegram']))->toBe('');
});

it('returns empty string for bundles that have no plan mapping yet', function () {
    seedStandalonePlan();
    // Only the full bundle resolves today; partial bundles have no plan.
    expect(licensePriceId(['srms']))->toBe('');
    expect(licensePriceId(['srms', 'ai_analysis']))->toBe('');
});

it('returns empty string when the plan has no price_id for the requested cycle', function () {
    seedStandalonePlan(monthlyPriceId: null, yearlyPriceId: 'pri_year');
    expect(licensePriceId(['srms', 'ai_analysis', 'jira', 'telegram'], 'month'))->toBe('');
    expect(licensePriceId(['srms', 'ai_analysis', 'jira', 'telegram'], 'year'))->toBe('pri_year');
});

// ---------------------------------------------------------------------------
// createIntent()
// ---------------------------------------------------------------------------

it('creates a pending intent and returns the Paddle checkout URL', function () {
    seedStandalonePlan('pri_month', 'pri_year');

    Http::fake([
        '*paddle.com/transactions' => Http::response(paddleTransactionCreatedResponse('txn_999', 'https://pay/checkout/999')),
    ]);

    $user = verifiedCustomer();
    $url = app(LicenseCheckoutService::class)->createIntent(
        $user,
        ['srms', 'ai_analysis', 'jira', 'telegram'],
        3,
    );

    expect($url)->toBe('https://pay/checkout/999');

    $intent = LicensePurchaseIntent::where('customer_id', $user->id)->first();
    expect($intent)->not->toBeNull();
    expect($intent->status)->toBe('pending');
    expect($intent->seats)->toBe(3);
    expect($intent->bundle_key)->toBe('ai_analysis+jira+srms+telegram');
    expect($intent->cycle)->toBe('year');
    expect($intent->paddle_price_id)->toBe('pri_year');
    expect($intent->paddle_transaction_id)->toBe('txn_999');
});

it('persists cycle=month and resolves the monthly price when requested', function () {
    seedStandalonePlan('pri_month', 'pri_year');

    Http::fake([
        '*paddle.com/transactions' => Http::response(paddleTransactionCreatedResponse()),
    ]);

    $user = verifiedCustomer();
    app(LicenseCheckoutService::class)->createIntent(
        $user,
        ['srms', 'ai_analysis', 'jira', 'telegram'],
        1,
        'month',
    );

    $intent = LicensePurchaseIntent::where('customer_id', $user->id)->first();
    expect($intent->cycle)->toBe('month');
    expect($intent->paddle_price_id)->toBe('pri_month');
});

it('sends a base item (qty 1) + extra-seat item (qty seats-1) and custom_data.intent_id to Paddle', function () {
    seedStandalonePlan('pri_month', 'pri_year');

    Http::fake([
        '*paddle.com/transactions' => Http::response(paddleTransactionCreatedResponse()),
    ]);

    $user = verifiedCustomer();
    app(LicenseCheckoutService::class)->createIntent(
        $user,
        ['srms', 'ai_analysis', 'jira', 'telegram'],
        5,
    );

    $intent = LicensePurchaseIntent::where('customer_id', $user->id)->first();

    Http::assertSent(function ($request) use ($intent) {
        $body = $request->data();

        return count($body['items']) === 2
            && $body['items'][0] === ['price_id' => 'pri_year', 'quantity' => 1]
            && $body['items'][1] === ['price_id' => 'pri_seat_year', 'quantity' => 4]
            && $body['custom_data']['intent_id'] === (string) $intent->id;
    });
});

it('omits the extra-seat item for a basic license (seats = 1)', function () {
    seedStandalonePlan('pri_month', 'pri_year');

    Http::fake([
        '*paddle.com/transactions' => Http::response(paddleTransactionCreatedResponse()),
    ]);

    app(LicenseCheckoutService::class)->createIntent(
        verifiedCustomer(),
        ['srms', 'ai_analysis', 'jira', 'telegram'],
        1,
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        return count($body['items']) === 1
            && $body['items'][0] === ['price_id' => 'pri_year', 'quantity' => 1];
    });
});

it('throws when the user has no passed verification', function () {
    seedStandalonePlan();

    $user = User::factory()->create();
    $user->syncRoles(['Self-hosted']);

    expect(fn () => app(LicenseCheckoutService::class)->createIntent(
        $user,
        ['srms', 'ai_analysis', 'jira', 'telegram'],
        1,
    ))->toThrow(RuntimeException::class, 'No verified SOS report');
});

it('throws when no Paddle price is configured for the bundle', function () {
    Plan::query()->where('slug', 'standalone')->delete();

    $user = verifiedCustomer();

    expect(fn () => app(LicenseCheckoutService::class)->createIntent(
        $user,
        ['srms', 'ai_analysis', 'jira', 'telegram'],
        1,
    ))->toThrow(RuntimeException::class, 'No Paddle price configured');

    expect(LicensePurchaseIntent::where('customer_id', $user->id)->count())->toBe(0);
});

it('rejects unsupported billing cycles', function () {
    seedStandalonePlan();

    $user = verifiedCustomer();

    expect(fn () => app(LicenseCheckoutService::class)->createIntent(
        $user,
        ['srms', 'ai_analysis', 'jira', 'telegram'],
        1,
        'quarterly',
    ))->toThrow(RuntimeException::class, 'Unsupported billing cycle');
});

it('marks the intent as failed and rethrows when Paddle API returns 4xx/5xx', function () {
    seedStandalonePlan();

    Http::fake([
        '*paddle.com/transactions' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $user = verifiedCustomer();

    expect(fn () => app(LicenseCheckoutService::class)->createIntent(
        $user,
        ['srms', 'ai_analysis', 'jira', 'telegram'],
        1,
    ))->toThrow(RuntimeException::class, 'Paddle API rejected transaction');

    expect(LicensePurchaseIntent::where('customer_id', $user->id)->first()->status)->toBe('failed');
});

it('rejects seat counts below 1', function () {
    $user = verifiedCustomer();

    expect(fn () => app(LicenseCheckoutService::class)->createIntent(
        $user,
        ['srms', 'ai_analysis', 'jira', 'telegram'],
        0,
    ))->toThrow(RuntimeException::class, 'Seat count must be at least 1');
});

// ---------------------------------------------------------------------------
// mintFromTransaction()
// ---------------------------------------------------------------------------

function pendingIntent(User $user, array $features = ['srms', 'ai_analysis'], int $seats = 2, string $cycle = 'year'): LicensePurchaseIntent
{
    $verification = LicenseVerification::where('user_id', $user->id)->first();

    return LicensePurchaseIntent::create([
        'customer_id' => $user->id,
        'verification_id' => $verification?->id,
        'seats' => $seats,
        'features' => $features,
        'bundle_key' => bundleKey($features),
        'cycle' => $cycle,
        'paddle_price_id' => 'pri_x',
        'status' => 'pending',
        'expires_at' => now()->addHour(),
    ]);
}

function fakeGpgService(): void
{
    $gpg = Mockery::mock(GpgService::class);
    $gpg->shouldReceive('clearsign')->andReturnUsing(function ($in, $out, $home, $pass) {
        file_put_contents($out, "-----BEGIN PGP SIGNED MESSAGE-----\n{}\n-----BEGIN PGP SIGNATURE-----\nfake\n-----END PGP SIGNATURE-----");
    });
    app()->instance(LicenseGeneratorService::class, new LicenseGeneratorService($gpg));
}

it('mints a signed License from a pending intent and sets expires_at 1 year out for cycle=year', function () {
    fakeGpgService();
    $user = verifiedCustomer();
    $intent = pendingIntent($user, ['srms', 'ai_analysis'], 3, 'year');

    $license = app(LicenseCheckoutService::class)->mintFromTransaction(
        'txn_done_1',
        ['intent_id' => $intent->id]
    );

    expect($license)->toBeInstanceOf(License::class);
    expect($license->customer_id)->toBe($user->id);
    expect($license->seats)->toBe(3);
    expect($license->features)->toBe(['srms', 'ai_analysis']);
    expect($license->machine_tokens)->toBe(['sha256:test-machine']);
    expect($license->signed_license)->toContain('BEGIN PGP SIGNED MESSAGE');
    expect($license->issued_at->diffInDays($license->expires_at, true))->toBeGreaterThanOrEqual(364);

    $intent->refresh();
    expect($intent->status)->toBe('completed');
    expect($intent->license_id)->toBe($license->id);
    expect($intent->paddle_transaction_id)->toBe('txn_done_1');
});

it('sets expires_at 1 month out when intent cycle is month', function () {
    fakeGpgService();
    $user = verifiedCustomer();
    $intent = pendingIntent($user, ['srms', 'ai_analysis'], 1, 'month');

    $license = app(LicenseCheckoutService::class)->mintFromTransaction(
        'txn_done_month',
        ['intent_id' => $intent->id]
    );

    expect($license)->toBeInstanceOf(License::class);
    $diff = $license->issued_at->diffInDays($license->expires_at, true);
    expect($diff)->toBeGreaterThanOrEqual(27);
    expect($diff)->toBeLessThanOrEqual(32);
});

it('is idempotent on duplicate webhook delivery', function () {
    fakeGpgService();
    $user = verifiedCustomer();
    $intent = pendingIntent($user);

    $service = app(LicenseCheckoutService::class);
    $first = $service->mintFromTransaction('txn_dup', ['intent_id' => $intent->id]);
    $second = $service->mintFromTransaction('txn_dup', ['intent_id' => $intent->id]);

    expect($first->id)->toBe($second->id);
    expect(License::count())->toBe(1);
});

it('returns null when the intent does not exist', function () {
    fakeGpgService();

    $license = app(LicenseCheckoutService::class)->mintFromTransaction(
        'txn_nope',
        ['intent_id' => 999_999]
    );

    expect($license)->toBeNull();
    expect(License::count())->toBe(0);
});

it('cancels expired pending intents on webhook arrival', function () {
    fakeGpgService();
    $user = verifiedCustomer();
    $intent = pendingIntent($user);
    $intent->update(['expires_at' => now()->subMinutes(5)]);

    $license = app(LicenseCheckoutService::class)->mintFromTransaction(
        'txn_late',
        ['intent_id' => $intent->id]
    );

    expect($license)->toBeNull();
    expect($intent->fresh()->status)->toBe('cancelled');
    expect(License::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Renewal flow
// ---------------------------------------------------------------------------

function activeLicenseExpiringAt(User $user, CarbonInterface $expiresAt, array $features = ['srms', 'ai_analysis', 'jira', 'telegram'], int $seats = 2): License
{
    return License::create([
        'customer_id' => $user->id,
        'machine_tokens' => ['sha256:renewal-test'],
        'seats' => $seats,
        'features' => $features,
        'status' => 'ACTIVE',
        'issued_at' => (clone $expiresAt)->subYear(),
        'expires_at' => $expiresAt,
    ]);
}

it('createIntent with previousLicense persists the renewal link', function () {
    seedStandalonePlan('pri_month', 'pri_year');

    Http::fake([
        '*paddle.com/transactions' => Http::response(paddleTransactionCreatedResponse()),
    ]);

    $user = verifiedCustomer();
    $previous = activeLicenseExpiringAt($user, now()->addDays(30));

    app(LicenseCheckoutService::class)->createIntent(
        $user,
        ['srms', 'ai_analysis', 'jira', 'telegram'],
        $previous->seats,
        'year',
        $previous,
    );

    $intent = LicensePurchaseIntent::where('customer_id', $user->id)->latest()->first();
    expect($intent->previous_license_id)->toBe($previous->id);
    expect($intent->isRenewal())->toBeTrue();
});

it('rejects a previousLicense owned by a different customer', function () {
    seedStandalonePlan();

    $user = verifiedCustomer();
    $otherOwner = User::factory()->create();
    $otherOwner->syncRoles(['Self-hosted']);
    $foreign = activeLicenseExpiringAt($otherOwner, now()->addDays(30));

    expect(fn () => app(LicenseCheckoutService::class)->createIntent(
        $user,
        ['srms', 'ai_analysis', 'jira', 'telegram'],
        1,
        'year',
        $foreign,
    ))->toThrow(RuntimeException::class, 'different customer');
});

it('mints a renewal License whose expires_at chains from the previous expiry (yearly)', function () {
    fakeGpgService();
    $user = verifiedCustomer();
    $oldExpiry = now()->addDays(30);
    $previous = activeLicenseExpiringAt($user, $oldExpiry);

    $verification = LicenseVerification::where('user_id', $user->id)->first();
    $intent = LicensePurchaseIntent::create([
        'customer_id' => $user->id,
        'verification_id' => $verification->id,
        'seats' => $previous->seats,
        'features' => $previous->features,
        'bundle_key' => bundleKey($previous->features),
        'cycle' => 'year',
        'paddle_price_id' => 'pri_y',
        'status' => 'pending',
        'previous_license_id' => $previous->id,
        'expires_at' => now()->addHour(),
    ]);

    $license = app(LicenseCheckoutService::class)->mintFromTransaction(
        'txn_renew',
        ['intent_id' => $intent->id]
    );

    expect($license)->toBeInstanceOf(License::class);
    $expected = (clone $oldExpiry)->addYear();
    expect($license->expires_at->format('Y-m-d'))->toBe($expected->format('Y-m-d'));
});

it('mints a renewal License whose expires_at chains from the previous expiry (monthly)', function () {
    fakeGpgService();
    $user = verifiedCustomer();
    $oldExpiry = now()->addDays(5);
    $previous = activeLicenseExpiringAt($user, $oldExpiry);

    $verification = LicenseVerification::where('user_id', $user->id)->first();
    $intent = LicensePurchaseIntent::create([
        'customer_id' => $user->id,
        'verification_id' => $verification->id,
        'seats' => $previous->seats,
        'features' => $previous->features,
        'bundle_key' => bundleKey($previous->features),
        'cycle' => 'month',
        'paddle_price_id' => 'pri_m',
        'status' => 'pending',
        'previous_license_id' => $previous->id,
        'expires_at' => now()->addHour(),
    ]);

    $license = app(LicenseCheckoutService::class)->mintFromTransaction(
        'txn_renew_m',
        ['intent_id' => $intent->id]
    );

    expect($license)->toBeInstanceOf(License::class);
    $expected = (clone $oldExpiry)->addMonth();
    expect($license->expires_at->format('Y-m-d'))->toBe($expected->format('Y-m-d'));
});

// ---------------------------------------------------------------------------
// syncTransaction — mint on return from checkout (webhook-independent)
// ---------------------------------------------------------------------------

it('syncTransaction mints when Paddle reports the transaction paid', function () {
    fakeGpgService();
    $user = verifiedCustomer();
    $intent = pendingIntent($user, ['srms', 'ai_analysis'], 3, 'year');
    $intent->update(['paddle_transaction_id' => 'txn_sync_1']);

    Http::fake([
        '*sandbox-api.paddle.com/transactions/txn_sync_1' => Http::response([
            'data' => [
                'id' => 'txn_sync_1',
                'status' => 'completed',
                'custom_data' => ['intent_id' => (string) $intent->id],
            ],
        ]),
    ]);

    $license = app(LicenseCheckoutService::class)->syncTransaction('txn_sync_1');

    expect($license)->toBeInstanceOf(License::class);
    $intent->refresh();
    expect($intent->status)->toBe('completed');
    expect($intent->license_id)->toBe($license->id);
});

it('syncTransaction does not mint while the transaction is unpaid', function () {
    fakeGpgService();
    $user = verifiedCustomer();
    $intent = pendingIntent($user);
    $intent->update(['paddle_transaction_id' => 'txn_sync_2']);

    Http::fake([
        '*sandbox-api.paddle.com/transactions/txn_sync_2' => Http::response([
            'data' => [
                'id' => 'txn_sync_2',
                'status' => 'ready',
                'custom_data' => ['intent_id' => (string) $intent->id],
            ],
        ]),
    ]);

    $license = app(LicenseCheckoutService::class)->syncTransaction('txn_sync_2');

    expect($license)->toBeNull();
    expect($intent->fresh()->status)->toBe('pending');
});
