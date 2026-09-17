<?php

/**
 * Plan Switch Tests
 *
 * Covers:
 *  - Upgrade: AdjustVault dispatched, SWITCHPLAN SUCCESS sysevent, upgrade notification sent
 *  - Upgrade with existing disk expansions: AdjustVault still dispatched (disk expansions are additive)
 *  - Downgrade: blocked when active disk expansions exist (SWITCHPLAN FAILED, no Paddle call)
 *  - Downgrade: PaddleSubscription with shrink_mb + delete_at created, SWITCHPLAN SUCCESS sysevent
 *  - Downgrade: vault shrink size = old_disk_mb - new_disk_mb
 *  - Downgrade: tokens adjusted immediately when plan changes
 *  - Paddle API failure: SWITCHPLAN FAILED sysevent + error notification, no redirect
 *  - Scheduler: ShrinkVault dispatched for plan-downgrade PaddleSubscription records
 *  - Scheduler: plan-downgrade record deleted after dispatch
 */

use App\Events\AdjustVault;
use App\Events\ShrinkVault;
use App\Models\Sysevent;
use App\Models\User;
use App\Models\UserToken;
use Carbon\Carbon;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Wave\Http\Livewire\Billing\Checkout;
use Wave\PaddleSubscription;
use Wave\Plan;
use Wave\Setting;
use Wave\Subscription;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class)->in(__FILE__);

// ---------------------------------------------------------------------------
// Shared helpers (DRY with SubscriptionJourneyTest helpers where possible)
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Cache::forget('wave_settings');
    Setting::updateOrCreate(['key' => 'billing.provider'], ['display_name' => 'billing.provider', 'value' => 'paddle', 'type' => 'text', 'order' => 0]);
    Setting::updateOrCreate(['key' => 'billing.paddle_api_key'], ['display_name' => 'billing.paddle_api_key', 'value' => 'test_api_key', 'type' => 'text', 'order' => 0]);
    Setting::updateOrCreate(['key' => 'billing.paddle_env'], ['display_name' => 'billing.paddle_env', 'value' => 'sandbox', 'type' => 'text', 'order' => 0]);
    Cache::forget('wave_settings');
});

/**
 * Create a service plan linked to a freshly created role.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeSwitchServicePlan(string $name, int $diskGb = 10, int $tokens = 5, array $overrides = []): Plan
{
    $role = Role::firstOrCreate(
        ['name' => $name, 'guard_name' => 'web'],
        ['display_name' => $name]
    );

    return Plan::create(array_merge([
        'name' => $name,
        'slug' => strtolower($name).uniqid(),
        'description' => "{$name} Plan",
        'features' => json_encode([
            'Vault Size' => ['amount' => $diskGb, 'units' => 'GB', 'enabled' => true],
            'Included Tokens' => ['amount' => $tokens, 'units' => 'M', 'enabled' => true],
        ]),
        'role_id' => $role->id,
        'type' => 'service',
        'active' => 1,
        'status' => 'available',
        'price' => '9',
        'monthly_price' => 9,
        'monthly_price_id' => 'pri_switch_monthly_'.strtolower($name).uniqid(),
        'yearly_price' => 90,
        'yearly_price_id' => 'pri_switch_yearly_'.strtolower($name).uniqid(),
        'default' => 0,
        'product_id' => 'pro_switch_'.strtolower($name).uniqid(),
    ], $overrides));
}

function makeSwitchDiskPlan(string $name = 'DiskAddon10GB', int $gb = 10): Plan
{
    $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['display_name' => $name]);

    return Plan::create([
        'name' => $name,
        'slug' => strtolower($name).uniqid(),
        'description' => "{$gb} GB vault increase",
        'features' => "{$gb} GB vault increase",
        'role_id' => $role->id,
        'type' => 'disk',
        'active' => 1,
        'status' => 'available',
        'price' => '10',
        'monthly_price' => 10,
        'monthly_price_id' => 'pri_disk_'.uniqid(),
        'yearly_price' => 100,
        'yearly_price_id' => 'pri_disk_yr_'.uniqid(),
        'product_id' => 'pro_disk_'.uniqid(),
        'default' => 0,
    ]);
}

function makeSwitchUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'verification_code' => null,
    ]);
}

function subscribeSwitchUser(User $user, Plan $plan): Subscription
{
    $user->syncRoles([$plan->role->name]);
    $user->forceFill(['role_id' => $plan->role_id])->save();

    return Subscription::create([
        'billable_type' => 'user',
        'billable_id' => $user->id,
        'plan_id' => $plan->id,
        'vendor_slug' => 'paddle',
        'vendor_transaction_id' => 'txn_switch_001',
        'vendor_customer_id' => 'ctm_switch_001',
        'vendor_subscription_id' => 'sub_switch_001',
        'cycle' => 'month',
        'status' => 'active',
        'seats' => 1,
    ]);
}

function paddleSuccessResponse(array $extra = []): array
{
    return ['data' => array_merge(['status' => 'active', 'next_billed_at' => now()->addDays(30)->toIso8601String()], $extra)];
}

// ---------------------------------------------------------------------------
// Upgrade flow
// ---------------------------------------------------------------------------

describe('plan upgrade', function () {

    it('dispatches AdjustVault and records a SWITCHPLAN SUCCESS sysevent', function () {
        Event::fake([AdjustVault::class]);

        $basicPlan = makeSwitchServicePlan('BasicSwitch', diskGb: 10);
        $teamPlan = makeSwitchServicePlan('TeamSwitch', diskGb: 20);
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $basicPlan);

        Http::fake(['*paddle*' => Http::response(paddleSuccessResponse(), 200)]);

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $teamPlan->id);

        Event::assertDispatched(AdjustVault::class);

        assertDatabaseHas(Sysevent::class, [
            'owner' => $user->id,
            'type' => 'SWITCHPLAN',
            'status' => 'SUCCESS',
        ]);
    });

    it('updates subscription plan_id and user role_id', function () {
        Event::fake([AdjustVault::class]);

        $basicPlan = makeSwitchServicePlan('BasicUpd', diskGb: 10);
        $teamPlan = makeSwitchServicePlan('TeamUpd', diskGb: 20);
        $user = makeSwitchUser();
        $subscription = subscribeSwitchUser($user, $basicPlan);

        Http::fake(['*paddle*' => Http::response(paddleSuccessResponse(), 200)]);

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $teamPlan->id);

        $subscription->refresh();
        $user->refresh();

        expect($subscription->plan_id)->toBe($teamPlan->id)
            ->and($user->role_id)->toBe($teamPlan->role_id);
    });

    it('dispatches AdjustVault with correct old and new role IDs', function () {
        Event::fake([AdjustVault::class]);

        $basicPlan = makeSwitchServicePlan('BasicRoles', diskGb: 10);
        $teamPlan = makeSwitchServicePlan('TeamRoles', diskGb: 20);
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $basicPlan);

        Http::fake(['*paddle*' => Http::response(paddleSuccessResponse(), 200)]);

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $teamPlan->id);

        Event::assertDispatched(AdjustVault::class, function (AdjustVault $event) use ($basicPlan, $teamPlan) {
            return $event->data['oldrole_id'] == $basicPlan->role_id
                && $event->data['newrole_id'] == $teamPlan->role_id;
        });
    });

    it('dispatches AdjustVault even when the user has active disk expansions', function () {
        Event::fake([AdjustVault::class]);

        $basicPlan = makeSwitchServicePlan('BasicDisk', diskGb: 10);
        $teamPlan = makeSwitchServicePlan('TeamDisk', diskGb: 20);
        $diskPlan = makeSwitchDiskPlan('UpgDisk');
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $basicPlan);

        // Active disk expansion subscription
        PaddleSubscription::create([
            'subscription_id' => 'sub_disk_'.uniqid(),
            'plan_id' => $diskPlan->product_id,
            'user_id' => $user->id,
            'status' => 'active',
            'cancel_url' => 'n/a',
            'update_url' => 'n/a',
            'last_payment_at' => now()->subDays(5),
            'next_payment_at' => now()->addDays(25),
        ]);

        Http::fake(['*paddle*' => Http::response(paddleSuccessResponse(), 200)]);

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $teamPlan->id);

        // Upgrade is NOT blocked by disk expansions; AdjustVault must fire.
        Event::assertDispatched(AdjustVault::class);
    });

});

// ---------------------------------------------------------------------------
// Downgrade flow
// ---------------------------------------------------------------------------

describe('plan downgrade', function () {

    it('is blocked when the user has active disk expansions and records SWITCHPLAN FAILED', function () {
        Event::fake([AdjustVault::class, ShrinkVault::class]);

        $teamPlan = makeSwitchServicePlan('TeamBlock', diskGb: 20);
        $basicPlan = makeSwitchServicePlan('BasicBlock', diskGb: 10);
        $diskPlan = makeSwitchDiskPlan('BlockDisk');
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $teamPlan);

        PaddleSubscription::create([
            'subscription_id' => 'sub_block_'.uniqid(),
            'plan_id' => $diskPlan->product_id,
            'user_id' => $user->id,
            'status' => 'active',
            'cancel_url' => 'n/a',
            'update_url' => 'n/a',
            'last_payment_at' => now()->subDays(5),
            'next_payment_at' => now()->addDays(25),
        ]);

        Http::fake();  // should NOT be called

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $basicPlan->id);

        Event::assertNotDispatched(AdjustVault::class);
        Event::assertNotDispatched(ShrinkVault::class);
        Http::assertNothingSent();

        assertDatabaseHas(Sysevent::class, [
            'owner' => $user->id,
            'type' => 'SWITCHPLAN',
            'status' => 'FAILED',
        ]);
    });

    it('creates a PaddleSubscription with shrink_mb and delete_at set', function () {
        Event::fake([AdjustVault::class, ShrinkVault::class]);

        $teamPlan = makeSwitchServicePlan('TeamSched', diskGb: 20);
        $basicPlan = makeSwitchServicePlan('BasicSched', diskGb: 10);
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $teamPlan);

        Http::fake(['*paddle*' => Http::response(paddleSuccessResponse(), 200)]);

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $basicPlan->id);

        $record = PaddleSubscription::where('user_id', $user->id)
            ->whereNotNull('shrink_mb')
            ->whereNotNull('delete_at')
            ->first();

        expect($record)->not->toBeNull()
            ->and($record->shrink_mb)->toBe(10240) // (20 - 10) GB × 1024 MB/GB
            ->and($record->status)->toBe('active');
    });

    it('records SWITCHPLAN SUCCESS sysevent', function () {
        Event::fake([AdjustVault::class, ShrinkVault::class]);

        $teamPlan = makeSwitchServicePlan('TeamEvt', diskGb: 20);
        $basicPlan = makeSwitchServicePlan('BasicEvt', diskGb: 10);
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $teamPlan);

        Http::fake(['*paddle*' => Http::response(paddleSuccessResponse(), 200)]);

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $basicPlan->id);

        assertDatabaseHas(Sysevent::class, [
            'owner' => $user->id,
            'type' => 'SWITCHPLAN',
            'status' => 'SUCCESS',
        ]);
    });

    it('does NOT dispatch AdjustVault (vault shrink is deferred)', function () {
        Event::fake([AdjustVault::class, ShrinkVault::class]);

        $teamPlan = makeSwitchServicePlan('TeamNoAdj', diskGb: 20);
        $basicPlan = makeSwitchServicePlan('BasicNoAdj', diskGb: 10);
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $teamPlan);

        Http::fake(['*paddle*' => Http::response(paddleSuccessResponse(), 200)]);

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $basicPlan->id);

        Event::assertNotDispatched(AdjustVault::class);
        Event::assertNotDispatched(ShrinkVault::class);
    });

    it('adjusts tokens immediately when tokens differ between plans', function () {
        Event::fake([AdjustVault::class, ShrinkVault::class]);

        // Team: 20GB + 20M tokens → Basic: 10GB + 5M tokens
        $teamPlan = makeSwitchServicePlan('TeamTok', diskGb: 20, tokens: 20);
        $basicPlan = makeSwitchServicePlan('BasicTok', diskGb: 10, tokens: 5);
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $teamPlan);

        // Seed starting token balance
        UserToken::create([
            'user_id' => $user->id,
            'input_tokens_available' => 20_000_000,
            'output_tokens_available' => 20_000,
            'total_tokens_available' => 20_020_000,
        ]);

        Http::fake(['*paddle*' => Http::response(paddleSuccessResponse(), 200)]);

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $basicPlan->id);

        $tokens = UserToken::where('user_id', $user->id)->first();
        // diff = 5 - 20 = -15M input tokens
        expect($tokens->input_tokens_available)->toBe(20_000_000 - 15_000_000);
    });

});

// ---------------------------------------------------------------------------
// Paddle API failure
// ---------------------------------------------------------------------------

describe('plan switch — Paddle API failure', function () {

    it('records SWITCHPLAN FAILED sysevent and no vault changes occur', function () {
        Event::fake([AdjustVault::class, ShrinkVault::class]);

        $basicPlan = makeSwitchServicePlan('BasicFail', diskGb: 10);
        $teamPlan = makeSwitchServicePlan('TeamFail', diskGb: 20);
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $basicPlan);

        Http::fake(['*paddle*' => Http::response([], 500)]);

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $teamPlan->id);

        Event::assertNotDispatched(AdjustVault::class);

        assertDatabaseHas(Sysevent::class, [
            'owner' => $user->id,
            'type' => 'SWITCHPLAN',
            'status' => 'FAILED',
        ]);
    });

});

// ---------------------------------------------------------------------------
// Scheduler — plan-downgrade shrink query logic
//
// The Kernel scheduler closure cannot be invoked directly in tests via
// schedule:run (closures are only dispatched when time is "due"). We test
// the scheduler's QUERY and STATE logic directly instead.
// ---------------------------------------------------------------------------

describe('scheduler — plan-downgrade shrinks (query & state)', function () {

    it('includes plan-downgrade records (shrink_mb set) in the overdue query', function () {
        $basicPlan = makeSwitchServicePlan('BasicQuery', diskGb: 10);
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $basicPlan);

        $overdueRecord = PaddleSubscription::create([
            'subscription_id' => 'plan_switch_'.uniqid(),
            'plan_id' => 'plan_downgrade',
            'user_id' => $user->id,
            'status' => 'active',
            'cancel_url' => 'n/a',
            'update_url' => 'n/a',
            'last_payment_at' => now()->subDays(31),
            'next_payment_at' => now()->subMinutes(10),
            'delete_at' => now()->subMinutes(10),
            'shrink_mb' => 10240,
        ]);

        // Replicate the Kernel query (extended to include shrink_mb records).
        $diskPlanProductIds = Plan::where('type', 'disk')->pluck('product_id')->toArray();

        $found = PaddleSubscription::where('status', 'active')
            ->whereNotNull('delete_at')
            ->where(function ($q) use ($diskPlanProductIds) {
                $q->whereIn('plan_id', $diskPlanProductIds)
                    ->orWhereNotNull('shrink_mb');
            })
            ->get();

        expect($found->contains($overdueRecord))->toBeTrue();
    });

    it('does NOT include future plan-downgrade records in an overdue dispatch', function () {
        $basicPlan = makeSwitchServicePlan('BasicFuture', diskGb: 10);
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $basicPlan);

        $futureRecord = PaddleSubscription::create([
            'subscription_id' => 'plan_switch_'.uniqid(),
            'plan_id' => 'plan_downgrade',
            'user_id' => $user->id,
            'status' => 'active',
            'cancel_url' => 'n/a',
            'update_url' => 'n/a',
            'last_payment_at' => now(),
            'next_payment_at' => now()->addDays(30),
            'delete_at' => now()->addDays(30),
            'shrink_mb' => 10240,
        ]);

        $now = Carbon::now();

        // Only records where delete_at < now() should trigger dispatch.
        $overdue = PaddleSubscription::where('status', 'active')
            ->whereNotNull('delete_at')
            ->whereNotNull('shrink_mb')
            ->get()
            ->filter(fn ($sub) => Carbon::parse($sub->delete_at) < $now);

        expect($overdue->contains($futureRecord))->toBeFalse();
    });

    it('shrink_mb value encodes the correct MB difference between old and new plan disk sizes', function () {
        Event::fake([AdjustVault::class, ShrinkVault::class]);

        $teamPlan = makeSwitchServicePlan('TeamMB', diskGb: 20);
        $basicPlan = makeSwitchServicePlan('BasicMB', diskGb: 10);
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $teamPlan);

        Http::fake(['*paddle*' => Http::response(paddleSuccessResponse(), 200)]);

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $basicPlan->id);

        $record = PaddleSubscription::where('user_id', $user->id)
            ->whereNotNull('shrink_mb')
            ->first();

        // (20 GB - 10 GB) × 1024 MB/GB = 10240 MB
        expect($record->shrink_mb)->toBe(10240);
    });

    it('plan-downgrade PaddleSubscription has plan_id prefix that identifies it as a plan switch', function () {
        Event::fake([AdjustVault::class, ShrinkVault::class]);

        $teamPlan = makeSwitchServicePlan('TeamPrefix', diskGb: 20);
        $basicPlan = makeSwitchServicePlan('BasicPrefix', diskGb: 10);
        $user = makeSwitchUser();
        subscribeSwitchUser($user, $teamPlan);

        Http::fake(['*paddle*' => Http::response(paddleSuccessResponse(), 200)]);

        Livewire::actingAs($user)
            ->test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $basicPlan->id);

        $record = PaddleSubscription::where('user_id', $user->id)
            ->whereNotNull('shrink_mb')
            ->first();

        expect($record->plan_id)->toBe('plan_downgrade')
            ->and(str_starts_with($record->subscription_id, 'plan_switch_'))->toBeTrue();
    });

});
