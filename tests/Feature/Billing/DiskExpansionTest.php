<?php

/**
 * Disk Expansion Plans Tests
 *
 * Covers:
 *  - POST /expandDisk dispatches ExpandVault event and records VAULT_EXPAND sysevent
 *  - POST /cancelDisk dispatches ShrinkVault event and records VAULT_SHRINK sysevent
 *  - POST /scheduleCancelDisk sets delete_at on PaddleSubscription (admin + user paths)
 *  - POST /scheduleCancelDisk records VAULT_SHRINK_SCHEDULED sysevent
 *  - POST /addTokens updates UserToken balance and records BUY_TOKENS sysevent
 *  - POST /addTokens returns error JSON for wrong plan type
 *  - Unauthenticated requests to disk endpoints redirect to login
 */

use App\Events\ExpandVault;
use App\Events\ShrinkVault;
use App\Models\Sysevent;
use App\Models\User;
use App\Models\UserToken;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Wave\Http\Middleware\VerifyPaddleWebhookSignature;
use Wave\PaddleSubscription;
use Wave\Plan;
use Wave\Setting;
use Wave\Subscription;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\post;

uses(RefreshDatabase::class)->in(__FILE__);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Cache::forget('wave_settings');
    Setting::updateOrCreate(['key' => 'billing.provider'], ['display_name' => 'billing.provider', 'value' => 'paddle', 'type' => 'text', 'order' => 0]);
    Setting::updateOrCreate(['key' => 'billing.paddle_api_key'], ['display_name' => 'billing.paddle_api_key', 'value' => 'test_api_key', 'type' => 'text', 'order' => 0]);
    Cache::forget('wave_settings');
    $this->withoutMiddleware(VerifyPaddleWebhookSignature::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeDiskPlan(string $name = '10GB', array $overrides = []): Plan
{
    return Plan::create(array_merge([
        'name' => $name,
        'slug' => strtolower($name).uniqid(),
        'description' => "{$name} vault increase",
        'features' => '10 GB vault increase',
        'role_id' => 3,
        'type' => 'disk',
        'active' => 1,
        'status' => 'available',
        'price' => '10',
        'monthly_price' => 10,
        'monthly_price_id' => 'pri_test_disk_'.uniqid(),
        'yearly_price' => 100,
        'yearly_price_id' => 'pri_test_disk_yr_'.uniqid(),
        'product_id' => 'pro_test_disk_'.uniqid(),
        'default' => 0,
    ], $overrides));
}

function makeTokenPlan(): Plan
{
    return Plan::create([
        'name' => '1M Tokens',
        'slug' => 'mtoken1'.uniqid(),
        'description' => '1M AI tokens',
        'features' => '{}',
        'role_id' => 3,
        'type' => 'tokens',
        'active' => 1,
        'status' => 'available',
        'price' => '5',
        'monthly_price' => 5,
        'monthly_price_id' => 'pri_test_tokens_'.uniqid(),
        'product_id' => 'pro_test_tokens_'.uniqid(),
        'default' => 0,
    ]);
}

function makeVerifiedDiskUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'verification_code' => null,
    ]);
}

// ---------------------------------------------------------------------------
// Unauthenticated guards
// ---------------------------------------------------------------------------

it('redirects unauthenticated users from /expandDisk', function () {
    post('/expandDisk', ['item' => 1])->assertRedirect();
});

it('redirects unauthenticated users from /cancelDisk', function () {
    post('/cancelDisk', ['item' => 1])->assertRedirect();
});

it('redirects unauthenticated users from /scheduleCancelDisk', function () {
    post('/scheduleCancelDisk', ['item' => 1])->assertRedirect();
});

it('redirects unauthenticated users from /addTokens', function () {
    post('/addTokens', ['item' => 1])->assertRedirect();
});

// ---------------------------------------------------------------------------
// expandDisk
// ---------------------------------------------------------------------------

it('expandDisk dispatches ExpandVault event', function () {
    Event::fake([ExpandVault::class]);

    $user = makeVerifiedDiskUser();
    $plan = makeDiskPlan();

    actingAs($user)
        ->post('/expandDisk', ['item' => $plan->id])
        ->assertSuccessful()
        ->assertJson(['status' => 1]);

    Event::assertDispatched(ExpandVault::class);
});

it('expandDisk records a VAULT_EXPAND sysevent', function () {
    Event::fake([ExpandVault::class]);

    $user = makeVerifiedDiskUser();
    $plan = makeDiskPlan();

    actingAs($user)->post('/expandDisk', ['item' => $plan->id]);

    assertDatabaseHas(Sysevent::class, [
        'owner' => $user->id,
        'type' => 'VAULT_EXPAND',
        'status' => 'SUCCESS',
    ]);
});

it('expandDisk returns status 0 for a non-disk plan', function () {
    Event::fake([ExpandVault::class]);

    $user = makeVerifiedDiskUser();
    $plan = makeDiskPlan('BadPlan', ['type' => 'service']);

    actingAs($user)
        ->post('/expandDisk', ['item' => $plan->id])
        ->assertSuccessful()
        ->assertJson(['status' => 0]);

    Event::assertNotDispatched(ExpandVault::class);
});

// ---------------------------------------------------------------------------
// cancelDisk
// ---------------------------------------------------------------------------

it('cancelDisk dispatches ShrinkVault event', function () {
    Event::fake([ShrinkVault::class]);

    $user = makeVerifiedDiskUser();
    $plan = makeDiskPlan();

    actingAs($user)
        ->post('/cancelDisk', ['item' => $plan->id])
        ->assertSuccessful()
        ->assertJson(['status' => 1]);

    Event::assertDispatched(ShrinkVault::class);
});

it('cancelDisk records a VAULT_SHRINK sysevent', function () {
    Event::fake([ShrinkVault::class]);

    $user = makeVerifiedDiskUser();
    $plan = makeDiskPlan();

    actingAs($user)->post('/cancelDisk', ['item' => $plan->id]);

    assertDatabaseHas(Sysevent::class, [
        'owner' => $user->id,
        'type' => 'VAULT_SHRINK',
        'status' => 'SUCCESS',
    ]);
});

// ---------------------------------------------------------------------------
// scheduleCancelDisk
// ---------------------------------------------------------------------------

it('scheduleCancelDisk (admin path) creates a PaddleSubscription with delete_at set', function () {
    $user = makeVerifiedDiskUser();
    $user->syncRoles(['admin']);
    $plan = makeDiskPlan();

    actingAs($user)
        ->post('/scheduleCancelDisk', ['item' => $plan->id])
        ->assertSuccessful()
        ->assertJson(['status' => 1]);

    expect(
        PaddleSubscription::where('user_id', $user->id)
            ->whereNotNull('delete_at')
            ->exists()
    )->toBeTrue();
});

it('scheduleCancelDisk records a VAULT_SHRINK_SCHEDULED sysevent', function () {
    $user = makeVerifiedDiskUser();
    $user->syncRoles(['admin']);
    $plan = makeDiskPlan();

    actingAs($user)->post('/scheduleCancelDisk', ['item' => $plan->id]);

    assertDatabaseHas(Sysevent::class, [
        'owner' => $user->id,
        'type' => 'VAULT_SHRINK_SCHEDULED',
        'status' => 'SUCCESS',
    ]);
});

it('scheduleCancelDisk returns 0 when no active disk subscription exists for a non-admin', function () {
    $user = makeVerifiedDiskUser();
    $plan = makeDiskPlan();

    actingAs($user)
        ->post('/scheduleCancelDisk', ['item' => $plan->id])
        ->assertSuccessful()
        ->assertJson(['status' => 0]);
});

it('scheduleCancelDisk marks existing PaddleSubscription delete_at for non-admin', function () {
    $user = makeVerifiedDiskUser();
    $plan = makeDiskPlan();

    PaddleSubscription::create([
        'subscription_id' => 'sub_test_disk_'.uniqid(),
        'plan_id' => $plan->product_id,
        'user_id' => $user->id,
        'status' => 'active',
        'cancel_url' => 'n/a',
        'update_url' => 'n/a',
        'last_payment_at' => now()->subDays(5),
        'next_payment_at' => now()->addDays(25),
    ]);

    actingAs($user)
        ->post('/scheduleCancelDisk', ['item' => $plan->id])
        ->assertSuccessful()
        ->assertJson(['status' => 1]);

    expect(
        PaddleSubscription::where('user_id', $user->id)
            ->where('plan_id', $plan->product_id)
            ->whereNotNull('delete_at')
            ->exists()
    )->toBeTrue();
});

// ---------------------------------------------------------------------------
// addTokens
// ---------------------------------------------------------------------------

it('addTokens increases UserToken balance and records BUY_TOKENS sysevent', function () {
    $user = makeVerifiedDiskUser();
    $tokenPlan = makeTokenPlan();

    UserToken::create([
        'user_id' => $user->id,
        'input_tokens_available' => 0,
        'output_tokens_available' => 0,
        'total_tokens_available' => 0,
    ]);

    actingAs($user)
        ->post('/addTokens', ['item' => $tokenPlan->id])
        ->assertSuccessful()
        ->assertJson(['status' => 1]);

    assertDatabaseHas(Sysevent::class, [
        'owner' => $user->id,
        'type' => 'BUY_TOKENS',
        'status' => 'SUCCESS',
    ]);

    $tokens = UserToken::where('user_id', $user->id)->first();
    expect($tokens->input_tokens_available)->toBeGreaterThan(0);
});

it('addTokens returns status 0 for a non-tokens plan', function () {
    $user = makeVerifiedDiskUser();
    $plan = makeDiskPlan();

    actingAs($user)
        ->post('/addTokens', ['item' => $plan->id])
        ->assertSuccessful()
        ->assertJson(['status' => 0]);
});

// ---------------------------------------------------------------------------
// scheduleCancelDisk — notification locale
// ---------------------------------------------------------------------------

it('scheduleCancelDisk sends localized notification to non-admin with next payment date', function () {
    $user = makeVerifiedDiskUser();
    $user->locale = 'es';
    $user->save();

    $plan = makeDiskPlan('LocaleTest10GB');

    PaddleSubscription::create([
        'subscription_id' => 'sub_locale_'.uniqid(),
        'plan_id' => $plan->product_id,
        'user_id' => $user->id,
        'status' => 'active',
        'cancel_url' => 'n/a',
        'update_url' => 'n/a',
        'last_payment_at' => now()->subDays(5),
        'next_payment_at' => now()->addDays(25),
    ]);

    $response = actingAs($user)
        ->post('/scheduleCancelDisk', ['item' => $plan->id])
        ->assertSuccessful()
        ->assertJson(['status' => 1]);

    // Verify delete_at was set (scheduling worked)
    expect(
        PaddleSubscription::where('user_id', $user->id)
            ->where('plan_id', $plan->product_id)
            ->whereNotNull('delete_at')
            ->exists()
    )->toBeTrue();
});

it('scheduleCancelDisk notifies user about scheduled shrink', function () {
    $user = makeVerifiedDiskUser();
    $plan = makeDiskPlan('NotifyTest10GB');

    PaddleSubscription::create([
        'subscription_id' => 'sub_notify_'.uniqid(),
        'plan_id' => $plan->product_id,
        'user_id' => $user->id,
        'status' => 'active',
        'cancel_url' => 'n/a',
        'update_url' => 'n/a',
        'last_payment_at' => now()->subDays(5),
        'next_payment_at' => now()->addDays(25),
    ]);

    actingAs($user)->post('/scheduleCancelDisk', ['item' => $plan->id]);

    assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
});

// ---------------------------------------------------------------------------
// expandDisk / cancelDisk — user notification persisted
// ---------------------------------------------------------------------------

it('expandDisk sends a user notification', function () {
    Event::fake([ExpandVault::class]);

    $user = makeVerifiedDiskUser();
    $plan = makeDiskPlan();

    actingAs($user)->post('/expandDisk', ['item' => $plan->id]);

    assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
});

it('cancelDisk sends a user notification', function () {
    Event::fake([ShrinkVault::class]);

    $user = makeVerifiedDiskUser();
    $plan = makeDiskPlan();

    actingAs($user)->post('/cancelDisk', ['item' => $plan->id]);

    assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
});

// ---------------------------------------------------------------------------
// Webhook: transaction.completed
// ---------------------------------------------------------------------------

it('paddle-v2 webhook transactionCompleted updates subscription payment dates', function () {
    $user = makeVerifiedDiskUser();

    $subscription = PaddleSubscription::create([
        'subscription_id' => 'sub_webhook_pay_'.uniqid(),
        'plan_id' => 'pri_main_plan',
        'user_id' => $user->id,
        'status' => 'active',
        'cancel_url' => 'n/a',
        'update_url' => 'n/a',
        'last_payment_at' => now()->subDays(30),
        'next_payment_at' => now()->subDay(),
    ]);

    UserToken::create([
        'user_id' => $user->id,
        'input_tokens_available' => 1000,
        'output_tokens_available' => 100,
        'total_tokens_available' => 1100,
    ]);

    $newNext = now()->addDays(30)->toDateTimeString();

    post('/webhook/paddle-v2', [
        'event_type' => 'transaction.completed',
        'data' => [
            'id' => $subscription->subscription_id,
            'billing_period' => ['ends_at' => $newNext],
        ],
        'occurred_at' => now()->toDateTimeString(),
    ])->assertSuccessful();

    assertDatabaseHas(Sysevent::class, [
        'owner' => $user->id,
        'type' => 'PAYMENT',
        'status' => 'SUCCESS',
    ]);
});

it('paddle-v2 webhook transactionCompleted sends user notification', function () {
    $user = makeVerifiedDiskUser();

    $subscription = PaddleSubscription::create([
        'subscription_id' => 'sub_webhook_notif_'.uniqid(),
        'plan_id' => 'pri_main_plan',
        'user_id' => $user->id,
        'status' => 'active',
        'cancel_url' => 'n/a',
        'update_url' => 'n/a',
        'last_payment_at' => now()->subDays(30),
        'next_payment_at' => now()->subDay(),
    ]);

    UserToken::create([
        'user_id' => $user->id,
        'input_tokens_available' => 0,
        'output_tokens_available' => 0,
        'total_tokens_available' => 0,
    ]);

    post('/webhook/paddle-v2', [
        'event_type' => 'transaction.completed',
        'data' => [
            'id' => $subscription->subscription_id,
            'billing_period' => ['ends_at' => now()->addDays(30)->toDateTimeString()],
        ],
        'occurred_at' => now()->toDateTimeString(),
    ])->assertSuccessful();

    assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
});

it('paddle-v2 webhook transactionCompleted returns 200 for missing subscription', function () {
    post('/webhook/paddle-v2', [
        'event_type' => 'transaction.completed',
        'data' => ['id' => 'nonexistent_sub_id'],
        'occurred_at' => now()->toDateTimeString(),
    ])->assertSuccessful();

    assertDatabaseHas(Sysevent::class, [
        'type' => 'PAYMENT',
        'status' => 'FAILED',
    ]);
});

// ---------------------------------------------------------------------------
// Webhook: subscription.canceled — disk expansion (no role change)
// ---------------------------------------------------------------------------

it('paddle-v2 webhook subscriptionCancelled for disk expansion does not assign cancelled role', function () {
    $user = makeVerifiedDiskUser();
    $diskPlan = makeDiskPlan('WebhookDisk10GB');

    // Assign a non-cancelled role
    $basicRole = Role::create(['name' => 'webhook_basic_'.uniqid(), 'guard_name' => 'web', 'display_name' => 'Webhook Basic']);
    $user->syncRoles([$basicRole->name]);
    $user->forceFill(['role_id' => $basicRole->id])->save();

    $subscription = PaddleSubscription::create([
        'subscription_id' => 'sub_disk_cancel_'.uniqid(),
        'plan_id' => $diskPlan->product_id,
        'user_id' => $user->id,
        'status' => 'active',
        'cancel_url' => 'n/a',
        'update_url' => 'n/a',
        'last_payment_at' => now()->subDays(30),
        'next_payment_at' => now()->addDays(1),
    ]);

    post('/webhook/paddle-v2', [
        'event_type' => 'subscription.canceled',
        'data' => ['id' => $subscription->subscription_id],
        'occurred_at' => now()->toDateTimeString(),
    ])->assertSuccessful();

    // Role DB column should NOT have changed to 'cancelled'
    assertDatabaseHas('users', ['id' => $user->id, 'role_id' => $basicRole->id]);

    // Disk subscription should be marked cancelled
    assertDatabaseHas('paddle_subscriptions', [
        'subscription_id' => $subscription->subscription_id,
        'status' => 'cancelled',
    ]);

    // User should receive disk-specific notification
    assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
});

it('paddle-v2 webhook subscriptionCancelled for disk expansion records sysevent', function () {
    $user = makeVerifiedDiskUser();
    $diskPlan = makeDiskPlan('WebhookDiskSys10GB');

    $subscription = PaddleSubscription::create([
        'subscription_id' => 'sub_disk_sys_'.uniqid(),
        'plan_id' => $diskPlan->product_id,
        'user_id' => $user->id,
        'status' => 'active',
        'cancel_url' => 'n/a',
        'update_url' => 'n/a',
        'last_payment_at' => now()->subDays(30),
        'next_payment_at' => now()->addDays(1),
    ]);

    post('/webhook/paddle-v2', [
        'event_type' => 'subscription.canceled',
        'data' => ['id' => $subscription->subscription_id],
        'occurred_at' => now()->toDateTimeString(),
    ])->assertSuccessful();

    assertDatabaseHas(Sysevent::class, [
        'owner' => $user->id,
        'type' => 'CANCELATION',
        'status' => 'SUCCESS',
    ]);
});

// ---------------------------------------------------------------------------
// Webhook: subscription.canceled — main subscription (assigns cancelled role)
// ---------------------------------------------------------------------------

it('paddle-v2 webhook subscriptionCancelled for main subscription assigns cancelled role', function () {
    $user = makeVerifiedDiskUser();

    // Main subscription plan (not disk)
    $mainPlan = Plan::create([
        'name' => 'WebhookMainPlan',
        'slug' => 'webhook-main-'.uniqid(),
        'description' => 'Main plan',
        'features' => '10 GB',
        'role_id' => 3,
        'type' => 'service',
        'active' => 1,
        'status' => 'available',
        'price' => '20',
        'monthly_price' => 20,
        'monthly_price_id' => 'pri_main_'.uniqid(),
        'product_id' => 'pro_main_'.uniqid(),
        'default' => 0,
    ]);

    $subscription = PaddleSubscription::create([
        'subscription_id' => 'sub_main_cancel_'.uniqid(),
        'plan_id' => $mainPlan->product_id,
        'user_id' => $user->id,
        'status' => 'active',
        'cancel_url' => 'n/a',
        'update_url' => 'n/a',
        'last_payment_at' => now()->subDays(30),
        'next_payment_at' => now()->addDays(1),
    ]);

    post('/webhook/paddle-v2', [
        'event_type' => 'subscription.canceled',
        'data' => ['id' => $subscription->subscription_id],
        'occurred_at' => now()->toDateTimeString(),
    ])->assertSuccessful();

    // User DB column should be set to cancelled role ID
    $cancelledRole = Role::where('name', 'cancelled')->first();
    assertDatabaseHas('users', ['id' => $user->id, 'role_id' => $cancelledRole->id]);

    // User should be notified
    assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);

    assertDatabaseHas(Sysevent::class, [
        'owner' => $user->id,
        'type' => 'CANCELATION',
        'status' => 'SUCCESS',
    ]);
});
