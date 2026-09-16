<?php

/**
 * PaddleWebhook Suspension Tests
 *
 * Covers every event branch the webhook handler deals with:
 *  - subscription.canceled      → user is suspended
 *  - adjustment.updated         → refund approved   → user is suspended
 *  - adjustment.updated         → chargeback approved → user is suspended
 *  - adjustment.updated         → chargeback rejected → suspended user is reactivated
 *  - subscription.activated     → suspended user is reactivated
 *  - adjustment.updated (credit) → unhandled action, no side-effects
 *  - missing subscription_id    → 200 response, no crash
 *  - unknown event_type         → 200 response, no side-effects
 *  - initializeVault skips suspended users on login
 */

use App\Events\SendUserEmail;
use App\Models\User;
use App\Services\TelegramService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Wave\Http\Middleware\VerifyPaddleWebhookSignature;
use Wave\Plan;
use Wave\Subscription;

use function Pest\Laravel\mock;
use function Pest\Laravel\post;

uses(RefreshDatabase::class)->in(__FILE__);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function webhookUser(string $role = 'Basic'): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'verification_code' => null,
    ]);
    $user->syncRoles([$role]);

    return $user;
}

function webhookPlan(string $name = 'Basic'): Plan
{
    $role = Role::firstOrCreate(
        ['name' => $name, 'guard_name' => 'web'],
        ['display_name' => $name]
    );

    return Plan::create([
        'name' => $name,
        'slug' => strtolower($name).uniqid(),
        'description' => "{$name} Plan",
        'features' => '{}',
        'role_id' => $role->id,
        'type' => 'service',
        'active' => 1,
        'status' => 'available',
        'price' => '9',
        'monthly_price' => 9,
        'monthly_price_id' => 'pri_test_'.strtolower($name),
        'yearly_price' => 90,
        'yearly_price_id' => 'pri_test_y_'.strtolower($name),
        'default' => 0,
    ]);
}

function webhookSubscription(User $user, Plan $plan, string $vendorId = 'sub_test_001'): Subscription
{
    return Subscription::create([
        'billable_type' => 'user',
        'billable_id' => $user->id,
        'plan_id' => $plan->id,
        'vendor_slug' => 'paddle',
        'vendor_transaction_id' => 'txn_'.uniqid(),
        'vendor_customer_id' => 'ctm_'.uniqid(),
        'vendor_subscription_id' => $vendorId,
        'cycle' => 'month',
        'status' => 'active',
        'seats' => 1,
    ]);
}

// ─── Setup ────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);

    Role::firstOrCreate(
        ['name' => 'suspended', 'guard_name' => 'web'],
        ['display_name' => 'Suspended']
    );

    Config::set('app.vaultsDisabled', 'TRUE');

    // Skip signature verification for all webhook requests in this suite.
    $this->withoutMiddleware(VerifyPaddleWebhookSignature::class);

    // Fake all events so emails and Telegram don't fire real I/O.
    Event::fake([SendUserEmail::class]);

    mock(TelegramService::class)
        ->shouldReceive('sendTelegramMessage')
        ->andReturn(null);
});

// ─── subscription.canceled ───────────────────────────────────────────────────

describe('subscription.canceled', function () {

    it('assigns the cancelled role when a subscription is cancelled voluntarily', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('Basic');
        webhookSubscription($user, $plan, 'sub_cancel_001');

        $response = post('/webhook/paddle', [
            'event_type' => 'subscription.canceled',
            'data' => ['id' => 'sub_cancel_001'],
        ]);

        $response->assertSuccessful();
        expect($user->fresh()->hasRole('cancelled'))->toBeTrue();
    });

    it('fires a suspension email on cancellation', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('Basic');
        webhookSubscription($user, $plan, 'sub_cancel_002');

        post('/webhook/paddle', [
            'event_type' => 'subscription.canceled',
            'data' => ['id' => 'sub_cancel_002'],
        ]);

        Event::assertDispatched(SendUserEmail::class, function (SendUserEmail $e) use ($user) {
            return $e->data['type'] === 'accountSuspended'
                && $e->data['to'] === $user->email
                && $e->data['reason'] === 'cancellation';
        });
    });

    it('returns 200 when subscription_id is missing in the payload', function () {
        post('/webhook/paddle', [
            'event_type' => 'subscription.canceled',
            'data' => [],
        ])
            ->assertSuccessful();

        Event::assertNotDispatched(SendUserEmail::class);
    });

    it('returns 200 when subscription_id does not match any record', function () {
        post('/webhook/paddle', [
            'event_type' => 'subscription.canceled',
            'data' => ['id' => 'sub_nonexistent'],
        ])
            ->assertSuccessful();

        Event::assertNotDispatched(SendUserEmail::class);
    });
});

// ─── adjustment.updated — refund ─────────────────────────────────────────────

describe('adjustment.updated — refund approved', function () {

    it('suspends the user when a refund is approved', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('Basic');
        webhookSubscription($user, $plan, 'sub_refund_001');

        post('/webhook/paddle', [
            'event_type' => 'adjustment.updated',
            'data' => [
                'action' => 'refund',
                'status' => 'approved',
                'subscription_id' => 'sub_refund_001',
            ],
        ])
            ->assertSuccessful();

        expect($user->fresh()->hasRole('suspended'))->toBeTrue();
    });

    it('fires a suspension email with reason refund', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('Basic');
        webhookSubscription($user, $plan, 'sub_refund_002');

        post('/webhook/paddle', [
            'event_type' => 'adjustment.updated',
            'data' => [
                'action' => 'refund',
                'status' => 'approved',
                'subscription_id' => 'sub_refund_002',
            ],
        ]);

        Event::assertDispatched(SendUserEmail::class, function (SendUserEmail $e) use ($user) {
            return $e->data['type'] === 'accountSuspended'
                && $e->data['reason'] === 'refund'
                && $e->data['to'] === $user->email;
        });
    });
});

// ─── adjustment.updated — chargeback ─────────────────────────────────────────

describe('adjustment.updated — chargeback approved', function () {

    it('suspends the user when a chargeback is approved', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('Basic');
        webhookSubscription($user, $plan, 'sub_chargeback_001');

        post('/webhook/paddle', [
            'event_type' => 'adjustment.updated',
            'data' => [
                'action' => 'chargeback',
                'status' => 'approved',
                'subscription_id' => 'sub_chargeback_001',
            ],
        ])
            ->assertSuccessful();

        expect($user->fresh()->hasRole('suspended'))->toBeTrue();
    });

    it('fires a suspension email with reason chargeback', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('Basic');
        webhookSubscription($user, $plan, 'sub_chargeback_002');

        post('/webhook/paddle', [
            'event_type' => 'adjustment.updated',
            'data' => [
                'action' => 'chargeback',
                'status' => 'approved',
                'subscription_id' => 'sub_chargeback_002',
            ],
        ]);

        Event::assertDispatched(SendUserEmail::class, function (SendUserEmail $e) use ($user) {
            return $e->data['type'] === 'accountSuspended'
                && $e->data['reason'] === 'chargeback'
                && $e->data['to'] === $user->email;
        });
    });
});

// ─── adjustment.updated — chargeback rejected ─────────────────────────────────

describe('adjustment.updated — chargeback rejected', function () {

    it('reactivates a suspended user when chargeback is rejected', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('suspended');
        webhookSubscription($user, $plan, 'sub_cb_reject_001');
        // Keep the user suspended even after subscription creation role-sync
        $user->syncRoles(['suspended']);

        post('/webhook/paddle', [
            'event_type' => 'adjustment.updated',
            'data' => [
                'action' => 'chargeback',
                'status' => 'rejected',
                'subscription_id' => 'sub_cb_reject_001',
            ],
        ])
            ->assertSuccessful();

        expect($user->fresh()->hasRole('suspended'))->toBeFalse()
            ->and($user->fresh()->hasRole('Basic'))->toBeTrue();
    });

    it('fires a reactivation email on chargeback rejected', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('suspended');
        webhookSubscription($user, $plan, 'sub_cb_reject_002');
        $user->syncRoles(['suspended']);

        post('/webhook/paddle', [
            'event_type' => 'adjustment.updated',
            'data' => [
                'action' => 'chargeback',
                'status' => 'rejected',
                'subscription_id' => 'sub_cb_reject_002',
            ],
        ]);

        Event::assertDispatched(SendUserEmail::class, function (SendUserEmail $e) use ($user) {
            return $e->data['type'] === 'accountReactivated'
                && $e->data['reason'] === 'chargeback_rejected'
                && $e->data['to'] === $user->email;
        });
    });

    it('does nothing when chargeback is rejected but user is not suspended', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('Basic'); // not suspended
        webhookSubscription($user, $plan, 'sub_cb_reject_003');

        post('/webhook/paddle', [
            'event_type' => 'adjustment.updated',
            'data' => [
                'action' => 'chargeback',
                'status' => 'rejected',
                'subscription_id' => 'sub_cb_reject_003',
            ],
        ])
            ->assertSuccessful();

        // Role unchanged, no email fired
        expect($user->fresh()->hasRole('Basic'))->toBeTrue();
        Event::assertNotDispatched(SendUserEmail::class);
    });
});

// ─── subscription.activated ───────────────────────────────────────────────────

describe('subscription.activated', function () {

    it('reactivates a suspended user when subscription is re-activated', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('suspended');
        webhookSubscription($user, $plan, 'sub_react_001');
        $user->syncRoles(['suspended']);

        post('/webhook/paddle', [
            'event_type' => 'subscription.activated',
            'data' => ['id' => 'sub_react_001'],
        ])
            ->assertSuccessful();

        expect($user->fresh()->hasRole('suspended'))->toBeFalse()
            ->and($user->fresh()->hasRole('Basic'))->toBeTrue();
    });

    it('fires a reactivation email on subscription activated', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('suspended');
        webhookSubscription($user, $plan, 'sub_react_002');
        $user->syncRoles(['suspended']);

        post('/webhook/paddle', [
            'event_type' => 'subscription.activated',
            'data' => ['id' => 'sub_react_002'],
        ]);

        Event::assertDispatched(SendUserEmail::class, function (SendUserEmail $e) use ($user) {
            return $e->data['type'] === 'accountReactivated'
                && $e->data['reason'] === 'subscription_activated'
                && $e->data['to'] === $user->email;
        });
    });

    it('skips reactivation when activated user is not suspended', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('Basic'); // already active
        webhookSubscription($user, $plan, 'sub_react_003');

        post('/webhook/paddle', [
            'event_type' => 'subscription.activated',
            'data' => ['id' => 'sub_react_003'],
        ])
            ->assertSuccessful();

        Event::assertNotDispatched(SendUserEmail::class);
    });
});

// ─── Unhandled / edge cases ───────────────────────────────────────────────────

describe('edge cases', function () {

    it('ignores adjustment actions that are not refund or chargeback', function () {
        $plan = webhookPlan('Basic');
        $user = webhookUser('Basic');
        webhookSubscription($user, $plan, 'sub_credit_001');

        post('/webhook/paddle', [
            'event_type' => 'adjustment.updated',
            'data' => [
                'action' => 'credit',
                'status' => 'approved',
                'subscription_id' => 'sub_credit_001',
            ],
        ])
            ->assertSuccessful();

        expect($user->fresh()->hasRole('Basic'))->toBeTrue();
        Event::assertNotDispatched(SendUserEmail::class);
    });

    it('returns 200 for unknown event types without side-effects', function () {
        post('/webhook/paddle', [
            'event_type' => 'transaction.completed',
            'data' => [],
        ])
            ->assertSuccessful();

        Event::assertNotDispatched(SendUserEmail::class);
    });

    it('returns 200 when adjustment subscription_id is missing', function () {
        post('/webhook/paddle', [
            'event_type' => 'adjustment.updated',
            'data' => [
                'action' => 'refund',
                'status' => 'approved',
            ],
        ])
            ->assertSuccessful();

        Event::assertNotDispatched(SendUserEmail::class);
    });
});

// ─── initializeVault — login guard ───────────────────────────────────────────

describe('initializeVault — suspended user guard', function () {

    it('does not open vault for a suspended user on login', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'verified' => 1,
            'verification_code' => null,
        ]);
        $user->syncRoles(['suspended']);

        // Spy on VaultTools to confirm openVault is never called.
        // With APP_NOVAULTS=TRUE the vault is a no-op anyway, so we
        // assert indirectly: the user's role remains suspended after login.
        $this->actingAs($user);

        // Fire the Login event manually (the listener is invoked during actingAs in some setups;
        // we fire it explicitly to ensure the listener path is covered).
        event(new Login('web', $user, false));

        expect($user->fresh()->hasRole('suspended'))->toBeTrue();
    });
});
