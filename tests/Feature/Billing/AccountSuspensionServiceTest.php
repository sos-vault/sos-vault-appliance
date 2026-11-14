<?php

/**
 * AccountSuspensionService Tests
 *
 * Covers:
 *  - suspend(): assigns suspended role, closes vault, logs sysevent, fires email event, notifies Telegram
 *  - reactivate(): restores role from active subscription, falls back to Free, logs sysevent, fires email event
 *  - suspend() reason strings are forwarded correctly to sysevent payload and email
 *  - reactivate() with no active subscription falls back to the wave default_user_role
 */

use App\Events\SendUserEmail;
use App\Models\Sysevent;
use App\Models\User;
use App\Services\AccountSuspensionService;
use App\Services\TelegramService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Wave\Plan;
use Wave\Subscription;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class)->in(__FILE__);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function suspensionUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'verification_code' => null,
    ]);
}

function suspensionPlan(string $roleName = 'Basic'): Plan
{
    $role = Role::firstOrCreate(
        ['name' => $roleName, 'guard_name' => 'web'],
        ['display_name' => $roleName]
    );

    return Plan::create([
        'name' => $roleName,
        'slug' => strtolower($roleName).uniqid(),
        'description' => "{$roleName} Plan",
        'features' => '{}',
        'role_id' => $role->id,
        'type' => 'service',
        'active' => 1,
        'status' => 'available',
        'price' => '9',
        'monthly_price' => 9,
        'monthly_price_id' => 'pri_test_monthly',
        'yearly_price' => 90,
        'yearly_price_id' => 'pri_test_yearly',
        'default' => 0,
    ]);
}

function activeSubscription(User $user, Plan $plan): Subscription
{
    $user->syncRoles([$plan->role->name]);

    return Subscription::create([
        'billable_type' => 'user',
        'billable_id' => $user->id,
        'plan_id' => $plan->id,
        'vendor_slug' => 'paddle',
        'vendor_transaction_id' => 'txn_'.uniqid(),
        'vendor_customer_id' => 'ctm_'.uniqid(),
        'vendor_subscription_id' => 'sub_'.uniqid(),
        'cycle' => 'month',
        'status' => 'active',
        'seats' => 1,
    ]);
}

function suspensionService(): AccountSuspensionService
{
    return app(AccountSuspensionService::class);
}

// ─── Setup ────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);

    Role::firstOrCreate(
        ['name' => 'suspended', 'guard_name' => 'web'],
        ['display_name' => 'Suspended']
    );

    // Disable real LUKS/OS vault operations throughout these tests.
    Config::set('app.vaultsDisabled', 'TRUE');

    // Silence Telegram — we assert on the mock, not real HTTP.
    mock(TelegramService::class)
        ->shouldReceive('sendTelegramMessage')
        ->andReturn(null);
});

// ─── suspend() ───────────────────────────────────────────────────────────────

describe('suspend()', function () {

    it('assigns the suspended role to the user for forced suspensions', function () {
        $user = suspensionUser();
        $user->assignRole('Basic');

        suspensionService()->suspend($user, 'refund');

        expect($user->fresh()->hasRole('suspended'))->toBeTrue()
            ->and($user->fresh()->hasRole('Basic'))->toBeFalse();
    });

    it('assigns the cancelled role to the user for voluntary cancellations', function () {
        $user = suspensionUser();
        $user->assignRole('Basic');

        suspensionService()->suspend($user, 'cancellation');

        expect($user->fresh()->hasRole('cancelled'))->toBeTrue()
            ->and($user->fresh()->hasRole('Basic'))->toBeFalse();
    });

    it('logs a BILLING_SUSPENSION sysevent', function () {
        $user = suspensionUser();

        suspensionService()->suspend($user, 'refund', ['transaction_id' => 'txn_123']);

        $event = Sysevent::where('owner', $user->id)
            ->where('type', 'BILLING_SUSPENSION')
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->class)->toBe('BILLING')
            ->and($event->status)->toBe('SUCCESS');

        $payload = json_decode($event->payload, true);
        expect($payload['reason'])->toBe('refund')
            ->and($payload['event']['transaction_id'])->toBe('txn_123');
    });

    it('fires a SendUserEmail event with type accountSuspended', function () {
        Event::fake([SendUserEmail::class]);

        $user = suspensionUser();

        suspensionService()->suspend($user, 'chargeback');

        Event::assertDispatched(SendUserEmail::class, function (SendUserEmail $e) use ($user) {
            return $e->data['type'] === 'accountSuspended'
                && $e->data['to'] === $user->email
                && $e->data['reason'] === 'chargeback';
        });
    });

    it('sends a Telegram notification with SUSPENDED for forced suspensions', function () {
        $telegram = mock(TelegramService::class);
        $telegram->shouldReceive('sendTelegramMessage')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, 'SUSPENDED')));

        $user = suspensionUser();

        app(AccountSuspensionService::class)->suspend($user, 'chargeback');
    });

    it('sends a Telegram notification with CANCELLED for voluntary cancellations', function () {
        $telegram = mock(TelegramService::class);
        $telegram->shouldReceive('sendTelegramMessage')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, 'CANCELLED')));

        $user = suspensionUser();

        app(AccountSuspensionService::class)->suspend($user, 'cancellation');
    });

    it('strips all previous roles before assigning suspended', function () {
        $user = suspensionUser();
        $user->syncRoles(['Basic', 'admin']);

        suspensionService()->suspend($user, 'refund');

        $roles = $user->fresh()->getRoleNames()->toArray();
        expect($roles)->toBe(['suspended']);
    });

    it('handles each billing reason correctly', function (string $reason) {
        Event::fake([SendUserEmail::class]);
        $user = suspensionUser();

        suspensionService()->suspend($user, $reason);

        Event::assertDispatched(SendUserEmail::class, fn (SendUserEmail $e) => $e->data['reason'] === $reason);
    })->with(['cancellation', 'refund', 'chargeback', 'admin_action']);
});

// ─── reactivate() ────────────────────────────────────────────────────────────

describe('reactivate()', function () {

    it('restores the role from the active subscription plan', function () {
        $user = suspensionUser();
        $plan = suspensionPlan('Basic');
        activeSubscription($user, $plan);
        $user->syncRoles(['suspended']);

        suspensionService()->reactivate($user, 'chargeback_rejected');

        expect($user->fresh()->hasRole('Basic'))->toBeTrue()
            ->and($user->fresh()->hasRole('suspended'))->toBeFalse();
    });

    it('falls back to Free when the user has no active subscription', function () {
        $user = suspensionUser();
        $user->syncRoles(['suspended']);

        suspensionService()->reactivate($user, 'admin_action');

        expect($user->fresh()->hasRole('Free'))->toBeTrue()
            ->and($user->fresh()->hasRole('suspended'))->toBeFalse();
    });

    it('logs a BILLING_REACTIVATION sysevent', function () {
        $user = suspensionUser();
        $user->syncRoles(['suspended']);

        suspensionService()->reactivate($user, 'subscription_activated', ['sub_id' => 'sub_abc']);

        $event = Sysevent::where('owner', $user->id)
            ->where('type', 'BILLING_REACTIVATION')
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->class)->toBe('BILLING')
            ->and($event->status)->toBe('SUCCESS');

        $payload = json_decode($event->payload, true);
        expect($payload['reason'])->toBe('subscription_activated')
            ->and($payload['event']['sub_id'])->toBe('sub_abc');
    });

    it('fires a SendUserEmail event with type accountReactivated', function () {
        Event::fake([SendUserEmail::class]);

        $user = suspensionUser();
        $user->syncRoles(['suspended']);

        suspensionService()->reactivate($user, 'admin_action');

        Event::assertDispatched(SendUserEmail::class, function (SendUserEmail $e) use ($user) {
            return $e->data['type'] === 'accountReactivated'
                && $e->data['to'] === $user->email
                && $e->data['reason'] === 'admin_action';
        });
    });

    it('sends a Telegram notification', function () {
        $telegram = mock(TelegramService::class);
        $telegram->shouldReceive('sendTelegramMessage')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, 'REACTIVATED')));

        $user = suspensionUser();
        $user->syncRoles(['suspended']);

        app(AccountSuspensionService::class)->reactivate($user, 'admin_action');
    });

    it('uses the most recently created subscription to resolve the role', function () {
        $user = suspensionUser();
        $planBasic = suspensionPlan('Basic');
        $planTeam = suspensionPlan('Team');

        // Older trialing subscription — insert first so it gets a lower primary key.
        $older = Subscription::create([
            'billable_type' => 'user',
            'billable_id' => $user->id,
            'plan_id' => $planBasic->id,
            'vendor_slug' => 'paddle',
            'vendor_subscription_id' => 'sub_old',
            'vendor_transaction_id' => 'txn_old',
            'vendor_customer_id' => 'ctm_old',
            'cycle' => 'month',
            'status' => 'trialing',
            'seats' => 1,
        ]);
        // Back-date it so .latest() (orderByDesc created_at) returns the Team sub first.
        DB::table('subscriptions')
            ->where('id', $older->id)
            ->update(['created_at' => now()->subDays(10)]);

        // Newer active subscription.
        Subscription::create([
            'billable_type' => 'user',
            'billable_id' => $user->id,
            'plan_id' => $planTeam->id,
            'vendor_slug' => 'paddle',
            'vendor_subscription_id' => 'sub_new',
            'vendor_transaction_id' => 'txn_new',
            'vendor_customer_id' => 'ctm_new',
            'cycle' => 'month',
            'status' => 'active',
            'seats' => 1,
        ]);

        $user->syncRoles(['suspended']);

        suspensionService()->reactivate($user, 'admin_action');

        expect($user->fresh()->hasRole('Team'))->toBeTrue();
    });
});
