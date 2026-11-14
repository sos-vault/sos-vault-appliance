<?php

/**
 * Subscription Journey Tests
 *
 * Covers:
 *  - plans.blade.php — filtering (service, not Free, active), button variants per auth/subscription state
 *  - billing.checkout Livewire component — headless mode, switchPlanById event
 *  - verifyPaddleTransaction — mocked Paddle API creates subscription
 *  - savePaddleSubscription — redirects to /subscription/welcome
 *  - /settings/subscription — auth guard, content for subscriber vs non-subscriber
 */

use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Wave\Http\Livewire\Billing\Checkout;
use Wave\Plan;
use Wave\Setting;
use Wave\Subscription;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;

uses(RefreshDatabase::class)->in(__FILE__);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Cache::forget('wave_settings');
    seedBillingSetting('billing.provider', 'paddle');
    seedBillingSetting('billing.paddle_client_side_token', 'test_token_abc');
    seedBillingSetting('billing.paddle_api_key', 'test_api_key');
    seedBillingSetting('billing.paddle_env', 'sandbox');
});

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function seedBillingSetting(string $key, string $value): void
{
    Setting::updateOrCreate(
        ['key' => $key],
        ['display_name' => $key, 'value' => $value, 'type' => 'text', 'order' => 0]
    );
    Cache::forget('wave_settings');
}

/** Create a minimal active service plan (not Free) linked to a role. */
function makeServicePlan(string $name, array $overrides = []): Plan
{
    $role = Role::firstOrCreate(
        ['name' => $name, 'guard_name' => 'web'],
        ['display_name' => $name]
    );

    return Plan::create(array_merge([
        'name' => $name,
        'slug' => strtolower($name),
        'description' => "{$name} Plan",
        'features' => '{}',
        'role_id' => $role->id,
        'type' => 'service',
        'active' => 1,
        'status' => 'available',
        'price' => '9',
        'monthly_price' => 9,
        'monthly_price_id' => 'pri_test_monthly_'.strtolower($name),
        'yearly_price' => 90,
        'yearly_price_id' => 'pri_test_yearly_'.strtolower($name),
        'default' => 0,
    ], $overrides));
}

/** Create a verified user with no active subscription. */
function makeUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'verification_code' => null,
        'trial_ends_at' => now()->addDays(14),
    ]);
}

/** Give a user an active subscription to a plan. */
function subscribeToPlan(User $user, Plan $plan): Subscription
{
    $user->syncRoles([$plan->role->name]);

    return Subscription::create([
        'billable_type' => 'user',
        'billable_id' => $user->id,
        'plan_id' => $plan->id,
        'vendor_slug' => 'paddle',
        'vendor_transaction_id' => 'txn_test_001',
        'vendor_customer_id' => 'ctm_test_001',
        'vendor_subscription_id' => 'sub_test_001',
        'cycle' => 'month',
        'status' => 'active',
        'seats' => 1,
    ]);
}

// ---------------------------------------------------------------------------
// Plans partial — plan filtering
// ---------------------------------------------------------------------------

describe('plans partial — plan filtering', function () {

    it('only queries service plans that are active and not named Free', function () {
        makeServicePlan('Basic');
        makeServicePlan('Team');
        Plan::create([
            'name' => 'Free',
            'slug' => 'free',
            'features' => '{}',
            'type' => 'service',
            'active' => 1,
            'status' => 'available',
            'price' => '0',
            'role_id' => Role::where('name', 'Free')->first()->id,
        ]);
        Plan::create([
            'name' => 'Disk Expansion',
            'slug' => 'disk-expansion',
            'features' => '{}',
            'type' => 'disk',
            'active' => 1,
            'status' => 'available',
            'price' => '5',
            'role_id' => Role::where('name', 'Basic')->first()->id,
        ]);

        $displayed = Plan::where('type', 'service')
            ->whereEnglishNameNot('Free')
            ->where('active', true)
            ->orderBy('monthly_price')
            ->get();

        expect($displayed->pluck('name')->all())
            ->not->toContain('Free')
            ->not->toContain('Disk Expansion')
            ->toContain('Basic')
            ->toContain('Team');
    });

    it('excludes inactive service plans', function () {
        makeServicePlan('Basic');
        makeServicePlan('InactivePlan', ['active' => 0]);

        $displayed = Plan::where('type', 'service')
            ->whereEnglishNameNot('Free')
            ->where('active', true)
            ->get();

        expect($displayed->pluck('name')->all())
            ->toContain('Basic')
            ->not->toContain('InactivePlan');
    });

    it('orders plans by monthly_price ascending', function () {
        makeServicePlan('Expensive', ['monthly_price' => 49]);
        makeServicePlan('Cheap', ['monthly_price' => 9]);
        makeServicePlan('Mid', ['monthly_price' => 19]);

        // monthly_price is stored as a string column; cast to numeric for ordering
        $prices = Plan::where('type', 'service')
            ->whereEnglishNameNot('Free')
            ->where('active', true)
            ->orderByRaw('CAST(monthly_price AS REAL)')
            ->pluck('monthly_price')
            ->map(fn ($p) => (int) $p)
            ->all();

        expect($prices)->toBe([9, 19, 49]);
    });
});

// ---------------------------------------------------------------------------
// Plans partial — rendered HTML (pricing page is the host page for guests)
// ---------------------------------------------------------------------------

describe('plans partial — rendered output', function () {

    it('shows Get Started links for guests', function () {
        makeServicePlan('Basic');

        get('/pricing')
            ->assertOk()
            ->assertSee('Get Started')
            ->assertSee('/register');
    });

    it('shows Subscribe to this Plan button for authenticated non-subscribers', function () {
        makeServicePlan('Basic');
        $user = makeUser();

        actingAs($user);

        get('/pricing')
            ->assertOk()
            ->assertSee('Subscribe to this Plan');
    });

    it('shows Subscribed badge for a user on their current plan', function () {
        $plan = makeServicePlan('Basic');
        $user = makeUser();
        subscribeToPlan($user, $plan);

        actingAs($user);

        get('/pricing')
            ->assertOk()
            ->assertSee('Subscribed');
    });

    it('shows Switch Plans button when a subscriber views a different plan', function () {
        $currentPlan = makeServicePlan('Basic');
        makeServicePlan('Team');
        $user = makeUser();
        subscribeToPlan($user, $currentPlan);

        actingAs($user);

        get('/pricing')
            ->assertOk()
            ->assertSee('Switch Plans');
    });

    it('shows Coming Soon badge for pending plans', function () {
        makeServicePlan('Future', ['status' => 'pending']);

        get('/pricing')
            ->assertOk()
            ->assertSee('Coming Soon');
    });

    it('renders the Monthly and Yearly billing cycle toggle tabs', function () {
        makeServicePlan('Basic');

        get('/pricing')
            ->assertOk()
            ->assertSee('Monthly')
            ->assertSee('Yearly');
    });

    it('renders both monthly and yearly price IDs in the checkout button for non-subscriber guests', function () {
        $plan = makeServicePlan('Basic');

        get('/pricing')
            ->assertOk()
            ->assertSee($plan->monthly_price_id)
            ->assertSee($plan->yearly_price_id);
    });

    it('renders both monthly and yearly price IDs for an authenticated non-subscriber', function () {
        $plan = makeServicePlan('Basic');
        $user = makeUser();
        actingAs($user);

        get('/pricing')
            ->assertOk()
            ->assertSee($plan->monthly_price_id)
            ->assertSee($plan->yearly_price_id);
    });

    it('renders the annual total alongside the per-month equivalent for yearly pricing', function () {
        makeServicePlan('Basic', ['monthly_price' => 15, 'yearly_price' => 150]);

        get('/pricing')
            ->assertOk()
            ->assertSee('150') // annual total
            ->assertSee('13');  // floor(150/12) = 12 → number_format rounds to 13
    });
});

// ---------------------------------------------------------------------------
// billing.checkout Livewire component — headless mode
// ---------------------------------------------------------------------------

describe('billing.checkout — headless mode', function () {

    it('renders without visible plan content when headless=true', function () {
        $user = makeUser();
        actingAs($user);

        Livewire::test(Checkout::class, ['headless' => true])
            ->assertSet('headless', true)
            ->assertDontSee('Subscribe to this Plan');
    });

    it('renders plan cards and the Subscribe button when headless=false', function () {
        makeServicePlan('Basic');
        $user = makeUser();
        actingAs($user);

        Livewire::test(Checkout::class, ['headless' => false])
            ->assertSet('headless', false)
            ->assertSee('Subscribe to this Plan');
    });
});

// ---------------------------------------------------------------------------
// billing.checkout — switchPlanById event
// ---------------------------------------------------------------------------

describe('billing.checkout — switchPlanById', function () {

    it('defaults to monthly price ID and sets cycle to month when no cycle is given', function () {
        $oldPlan = makeServicePlan('Basic');
        $newPlan = makeServicePlan('Team');
        $user = makeUser();
        $subscription = subscribeToPlan($user, $oldPlan);
        actingAs($user);

        Http::fake(['*paddle*' => Http::response(['data' => ['status' => 'active']], 200)]);

        Livewire::test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $newPlan->id)
            ->assertSet('billing_cycle_selected', 'month');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'subscriptions')
            && $request->data()['items'][0]['price_id'] === $newPlan->monthly_price_id
        );

        $subscription->refresh();
        expect($subscription->plan_id)->toBe($newPlan->id)
            ->and($subscription->cycle)->toBe('month');
    });

    it('uses monthly price ID when cycle is explicitly month', function () {
        $oldPlan = makeServicePlan('Basic');
        $newPlan = makeServicePlan('Team');
        $user = makeUser();
        $subscription = subscribeToPlan($user, $oldPlan);
        actingAs($user);

        Http::fake(['*paddle*' => Http::response(['data' => ['status' => 'active']], 200)]);

        Livewire::test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $newPlan->id, cycle: 'month')
            ->assertSet('billing_cycle_selected', 'month');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'subscriptions')
            && $request->data()['items'][0]['price_id'] === $newPlan->monthly_price_id
        );

        $subscription->refresh();
        expect($subscription->cycle)->toBe('month');
    });

    it('uses yearly price ID and sets cycle to year when cycle is year', function () {
        $oldPlan = makeServicePlan('Basic');
        $newPlan = makeServicePlan('Team');
        $user = makeUser();
        $subscription = subscribeToPlan($user, $oldPlan);
        actingAs($user);

        Http::fake(['*paddle*' => Http::response(['data' => ['status' => 'active']], 200)]);

        Livewire::test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $newPlan->id, cycle: 'year')
            ->assertSet('billing_cycle_selected', 'year');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'subscriptions')
            && $request->data()['items'][0]['price_id'] === $newPlan->yearly_price_id
        );

        $subscription->refresh();
        expect($subscription->plan_id)->toBe($newPlan->id)
            ->and($subscription->cycle)->toBe('year');
    });

    it('guards against invalid cycle values and falls back to month', function () {
        $oldPlan = makeServicePlan('Basic');
        $newPlan = makeServicePlan('Team');
        $user = makeUser();
        subscribeToPlan($user, $oldPlan);
        actingAs($user);

        Http::fake(['*paddle*' => Http::response(['data' => ['status' => 'active']], 200)]);

        Livewire::test(Checkout::class, ['headless' => true])
            ->dispatch('switchPlanById', planId: $newPlan->id, cycle: 'quarterly')
            ->assertSet('billing_cycle_selected', 'month');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'subscriptions')
            && $request->data()['items'][0]['price_id'] === $newPlan->monthly_price_id
        );
    });
});

// ---------------------------------------------------------------------------
// billing.checkout — verifyPaddleTransaction
// ---------------------------------------------------------------------------

describe('billing.checkout — verifyPaddleTransaction', function () {

    it('creates a Subscription record when Paddle returns a paid transaction', function () {
        $plan = makeServicePlan('Basic');
        $user = makeUser();
        actingAs($user);

        $transactionId = 'txn_paddle_test_123';

        Http::fake([
            '*paddle*' => Http::response([
                'data' => [
                    'status' => 'paid',
                    'customer_id' => 'ctm_paddle_001',
                    'subscription_id' => 'sub_paddle_001',
                    'items' => [[
                        'price' => ['id' => $plan->monthly_price_id],
                    ]],
                ],
            ], 200),
        ]);

        Livewire::test(Checkout::class, ['headless' => true])
            ->dispatch('verifyPaddleTransaction', transactionId: $transactionId);

        assertDatabaseHas('subscriptions', [
            'billable_id' => $user->id,
            'plan_id' => $plan->id,
            'vendor_transaction_id' => $transactionId,
            'status' => 'active',
        ]);
    });

    it('does not create a subscription when Paddle returns a non-paid status', function () {
        makeServicePlan('Basic');
        $user = makeUser();
        actingAs($user);

        Http::fake([
            '*paddle*' => Http::response([
                'data' => ['status' => 'draft'],
            ], 200),
        ]);

        Livewire::test(Checkout::class, ['headless' => true])
            ->dispatch('verifyPaddleTransaction', transactionId: 'txn_bad_001');

        expect(Subscription::where('billable_id', $user->id)->count())->toBe(0);
    });

    it('shows a danger notification when Paddle API call fails', function () {
        makeServicePlan('Basic');
        $user = makeUser();
        actingAs($user);

        Http::fake([
            '*paddle*' => Http::response([], 500),
        ]);

        Livewire::test(Checkout::class, ['headless' => true])
            ->dispatch('verifyPaddleTransaction', transactionId: 'txn_fail_001');

        Notification::assertNotified('Error processing the transaction. Please try again.');
    });
});

// ---------------------------------------------------------------------------
// /settings/subscription — access control & content
// ---------------------------------------------------------------------------

describe('/settings/subscription', function () {

    it('redirects unauthenticated users to login', function () {
        get('/settings/subscription')->assertRedirect();
    });

    it('shows the no-subscription alert for authenticated non-subscribers', function () {
        $user = makeUser();
        actingAs($user);

        get('/settings/subscription')
            ->assertOk()
            ->assertSee('No active subscriptions found');
    });

    it('shows the active subscription confirmation for subscribers', function () {
        $plan = makeServicePlan('Basic');
        $user = makeUser();
        subscribeToPlan($user, $plan);

        actingAs($user);

        get('/settings/subscription')
            ->assertOk()
            ->assertSee('You are currently subscribed');
    });

    it('shows plan cards on the subscription page for non-subscribers', function () {
        makeServicePlan('Basic');
        $user = makeUser();
        actingAs($user);

        get('/settings/subscription')
            ->assertOk()
            ->assertSee('Subscribe to this Plan');
    });

    it('shows Switch Plan heading and plan cards for subscribers', function () {
        $plan = makeServicePlan('Basic');
        makeServicePlan('Team');
        $user = makeUser();
        subscribeToPlan($user, $plan);

        actingAs($user);

        get('/settings/subscription')
            ->assertOk()
            ->assertSee('Switch Plan')
            ->assertSee('Switch Plans');
    });

    it('shows the admin warning and no checkout for admin users', function () {
        $admin = makeUser();
        $admin->assignRole('admin');

        actingAs($admin);

        get('/settings/subscription')
            ->assertOk()
            ->assertSee('logged in as an admin');
    });
});
