<?php

/**
 * Paddle transaction.completed Webhook Tests
 *
 * Covers the transaction.completed (recurring payment) handler in PaddleWebhook:
 *  - Updates last_payment_at and next_payment_at on PaddleSubscription
 *  - Tops up the user's AI token balance (UserToken)
 *  - Logs a PAYMENT / SUCCESS sysevent via addEvent()
 *  - Fires a SendUserEmail event with type paymentReceived
 *  - Returns 200 gracefully when subscription_id is missing
 *  - Returns 200 gracefully when PaddleSubscription record is not found
 *  - Returns 200 gracefully when the linked user is not found
 *  - All three event aliases resolve to the same handler:
 *      transaction.completed, transaction_completed, transaction_payment_failed
 *  - Primary route /paddle/webhook works identically to the legacy aliases
 */

use App\Events\SendUserEmail;
use App\Models\Sysevent;
use App\Models\User;
use App\Models\UserToken;
use App\Services\TelegramService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Wave\Http\Middleware\VerifyPaddleWebhookSignature;
use Wave\Plan;
use Wave\PaddleSubscription;

use function Pest\Laravel\mock;
use function Pest\Laravel\post;

uses(RefreshDatabase::class)->in(__FILE__);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Seed a minimal Plan for the 'Free' role so getPlanTokens() returns '5 M'
 * in the test environment (users get the Free role by default on creation).
 */
function txSeedPlan(): void
{
    Cache::flush();
    $role = Role::findByName('Free', 'web');
    Plan::create([
        'name' => ['en' => 'Free'],
        'slug' => 'free',
        'status' => 'available',
        'type' => 'service',
        'role_id' => $role->id,
        'features' => json_encode(['Included Tokens' => ['amount' => '5', 'units' => 'M']]),
    ]);
}

function txUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'verification_code' => null,
    ]);
}

function txPaddleSub(User $user, string $subscriptionId = 'sub_tx_001'): PaddleSubscription
{
    return PaddleSubscription::create([
        'subscription_id' => $subscriptionId,
        'plan_id' => 'pri_basic_monthly',
        'user_id' => $user->id,
        'status' => 'active',
    ]);
}

function txPayload(string $subscriptionId = 'sub_tx_001', ?string $endsAt = null): array
{
    return [
        'event_type' => 'transaction.completed',
        'occurred_at' => now()->toIso8601String(),
        'data' => [
            'id' => 'txn_'.uniqid(),
            'subscription_id' => $subscriptionId,
            'billing_period' => [
                'ends_at' => $endsAt ?? now()->addMonth()->toIso8601String(),
            ],
        ],
    ];
}

// ─── Setup ────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);

    // Seed a plan so getPlanTokens() returns '5 M' for Free-role users.
    txSeedPlan();

    Config::set('app.vaultsDisabled', 'TRUE');

    // Skip signature verification for all webhook requests in this suite.
    $this->withoutMiddleware(VerifyPaddleWebhookSignature::class);

    // Fake emails; silence Telegram (not involved in payment events).
    Event::fake([SendUserEmail::class]);

    mock(TelegramService::class)
        ->shouldReceive('sendTelegramMessage')
        ->andReturn(null);
});

// ─── Core behaviour ───────────────────────────────────────────────────────────

describe('transaction.completed — core behaviour', function () {

    it('returns 200', function () {
        $user = txUser();
        txPaddleSub($user, 'sub_core_001');

        post('/paddle/webhook', txPayload('sub_core_001'))
            ->assertSuccessful();
    });

    it('updates last_payment_at on the PaddleSubscription record', function () {
        $user = txUser();
        $sub = txPaddleSub($user, 'sub_ts_001');
        $occurredAt = now()->subMinutes(5)->toIso8601String();

        post('/paddle/webhook', array_merge(txPayload('sub_ts_001'), ['occurred_at' => $occurredAt]));

        $sub->refresh();
        expect($sub->last_payment_at->toDateString())->toBe(Carbon::parse($occurredAt)->toDateString());
    });

    it('updates next_payment_at from billing_period.ends_at', function () {
        $user = txUser();
        $sub = txPaddleSub($user, 'sub_np_001');
        $endsAt = now()->addMonth()->toIso8601String();

        post('/paddle/webhook', txPayload('sub_np_001', $endsAt));

        $sub->refresh();
        expect($sub->next_payment_at->toDateString())->toBe(Carbon::parse($endsAt)->toDateString());
    });

    it('creates a UserToken record and tops up the token balance', function () {
        $user = txUser();
        txPaddleSub($user, 'sub_tok_001');

        post('/paddle/webhook', txPayload('sub_tok_001'));

        $tokens = UserToken::where('user_id', $user->id)->first();
        expect($tokens)->not->toBeNull()
            ->and($tokens->input_tokens_available)->toBeGreaterThan(0);
    });

    it('adds tokens on top of an existing balance', function () {
        $user = txUser();
        txPaddleSub($user, 'sub_tok_002');

        // Pre-seed an existing balance.
        UserToken::create([
            'user_id' => $user->id,
            'input_tokens_available' => 500_000,
            'output_tokens_available' => 500,
            'total_tokens_available' => 500_500,
        ]);

        post('/paddle/webhook', txPayload('sub_tok_002'));

        $tokens = UserToken::where('user_id', $user->id)->first();
        expect($tokens->input_tokens_available)->toBeGreaterThan(500_000);
    });
});

// ─── Sysevent ─────────────────────────────────────────────────────────────────

describe('transaction.completed — sysevent', function () {

    it('logs a PAYMENT SUCCESS sysevent', function () {
        $user = txUser();
        txPaddleSub($user, 'sub_ev_001');

        post('/paddle/webhook', txPayload('sub_ev_001'));

        $event = Sysevent::where('owner', $user->id)
            ->where('type', 'PAYMENT')
            ->where('status', 'SUCCESS')
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->class)->toBe('ACTIVITY');
    });

    it('logs a PAYMENT FAILED sysevent when PaddleSubscription is not found', function () {
        post('/paddle/webhook', txPayload('sub_nonexistent'));

        $event = Sysevent::where('type', 'PAYMENT')
            ->where('status', 'FAILED')
            ->first();

        expect($event)->not->toBeNull();
    });
});

// ─── Email ────────────────────────────────────────────────────────────────────

describe('transaction.completed — email', function () {

    it('fires a SendUserEmail event with type paymentReceived', function () {
        $user = txUser();
        txPaddleSub($user, 'sub_mail_001');

        post('/paddle/webhook', txPayload('sub_mail_001'));

        Event::assertDispatched(SendUserEmail::class, function (SendUserEmail $e) use ($user) {
            return $e->data['type'] === 'paymentReceived'
                && $e->data['to'] === $user->email;
        });
    });

    it('includes next_payment_at and tokens in the email payload', function () {
        $user = txUser();
        txPaddleSub($user, 'sub_mail_002');
        $endsAt = now()->addMonth()->toIso8601String();

        post('/paddle/webhook', txPayload('sub_mail_002', $endsAt));

        Event::assertDispatched(SendUserEmail::class, function (SendUserEmail $e) use ($endsAt) {
            return $e->data['type'] === 'paymentReceived'
                && ! empty($e->data['next_payment_at'])
                && ! empty($e->data['tokens']);
        });
    });
});

// ─── Edge cases ───────────────────────────────────────────────────────────────

describe('transaction.completed — edge cases', function () {

    it('returns 200 gracefully when subscription_id is missing from payload', function () {
        post('/paddle/webhook', [
            'event_type' => 'transaction.completed',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['id' => 'txn_orphan'],
        ])->assertSuccessful();

        Event::assertNotDispatched(SendUserEmail::class);
    });

    it('returns 200 gracefully when PaddleSubscription record does not exist', function () {
        post('/paddle/webhook', txPayload('sub_missing_999'))
            ->assertSuccessful();

        Event::assertNotDispatched(SendUserEmail::class);
    });

    it('returns 200 gracefully when the linked user_id does not exist', function () {
        // Create a paddle sub pointing at a non-existent user.
        PaddleSubscription::create([
            'subscription_id' => 'sub_orphan_001',
            'plan_id' => 'pri_basic',
            'user_id' => 99999,
            'status' => 'active',
        ]);

        post('/paddle/webhook', txPayload('sub_orphan_001'))
            ->assertSuccessful();

        Event::assertNotDispatched(SendUserEmail::class);
    });
});

// ─── Event type aliases ───────────────────────────────────────────────────────

describe('event type aliases', function () {

    it('handles transaction_completed (Paddle Classic alias)', function () {
        $user = txUser();
        txPaddleSub($user, 'sub_alias_001');

        post('/paddle/webhook', array_merge(txPayload('sub_alias_001'), ['event_type' => 'transaction_completed']))
            ->assertSuccessful();

        Event::assertDispatched(SendUserEmail::class, fn (SendUserEmail $e) => $e->data['type'] === 'paymentReceived');
    });

    it('handles transaction_payment_failed as a transaction event', function () {
        $user = txUser();
        txPaddleSub($user, 'sub_alias_002');

        post('/paddle/webhook', array_merge(txPayload('sub_alias_002'), ['event_type' => 'transaction_payment_failed']))
            ->assertSuccessful();
    });
});

// ─── Route consolidation ──────────────────────────────────────────────────────

describe('route consolidation', function () {

    it('primary /paddle/webhook route handles transaction.completed', function () {
        $user = txUser();
        txPaddleSub($user, 'sub_route_001');

        post('/paddle/webhook', txPayload('sub_route_001'))->assertSuccessful();

        Event::assertDispatched(SendUserEmail::class, fn (SendUserEmail $e) => $e->data['type'] === 'paymentReceived'
            && $e->data['to'] === $user->email);
    });

    it('legacy /webhook/paddle route still works', function () {
        $user = txUser();
        txPaddleSub($user, 'sub_legacy_001');

        post('/webhook/paddle', txPayload('sub_legacy_001'))->assertSuccessful();

        Event::assertDispatched(SendUserEmail::class, fn (SendUserEmail $e) => $e->data['type'] === 'paymentReceived');
    });

    it('legacy /webhook/paddle-v2 route still works', function () {
        $user = txUser();
        txPaddleSub($user, 'sub_legacy_002');

        post('/webhook/paddle-v2', txPayload('sub_legacy_002'))->assertSuccessful();

        Event::assertDispatched(SendUserEmail::class, fn (SendUserEmail $e) => $e->data['type'] === 'paymentReceived');
    });
});
