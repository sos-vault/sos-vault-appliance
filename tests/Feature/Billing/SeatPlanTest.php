<?php

/**
 * Extra Seat Add-On Plan Tests
 *
 * Covers:
 *  - POST /addSeats increments group->max_members and records sysevent
 *  - POST /addSeats returns error for wrong plan type
 *  - POST /addSeats returns error for non-Team/Enterprise user
 *  - Webhook subscription.canceled for seat plan decrements max_members
 *  - Webhook subscription.canceled for seat plan suspends newest overflow members
 *  - Webhook subscription.canceled for seat plan never suspends the group owner
 *  - Webhook adjustment.updated refund for seat plan decrements max_members
 *  - Webhook transaction.completed for seat plan skips token top-up (no duplicate seat add)
 *  - Webhook subscription.canceled for a service plan still suspends the user (not treated as seat)
 */

use App\Models\Group;
use App\Models\Sysevent;
use App\Models\User;
use App\Services\AccountSuspensionService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Wave\Http\Middleware\VerifyPaddleWebhookSignature;
use Wave\PaddleSubscription;
use Wave\Plan;
use Wave\Setting;
use Wave\Subscription;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\post;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function seatRole(string $name): Role
{
    return Role::firstOrCreate(
        ['name' => $name, 'guard_name' => 'web'],
        ['display_name' => $name]
    );
}

function seatUser(string $role = 'Team'): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'verification_code' => null,
    ]);
    $user->syncRoles([$role]);

    return $user;
}

function seatPlan(string $productId = 'pro_seat_001'): Plan
{
    $role = seatRole('Team');

    return Plan::create([
        'name' => 'Extra seat',
        'slug' => 'extra-seat-'.uniqid(),
        'description' => 'One extra team seat',
        'features' => '{}',
        'role_id' => $role->id,
        'type' => 'seat',
        'active' => 1,
        'status' => 'available',
        'price' => '10',
        'monthly_price' => 10,
        'monthly_price_id' => 'pri_seat_m',
        'yearly_price' => 100,
        'yearly_price_id' => 'pri_seat_y',
        'product_id' => $productId,
        'default' => 0,
    ]);
}

function seatGroup(User $owner, int $maxMembers = 8): Group
{
    $group = Group::create([
        'name' => $owner->name."'s Group",
        'owner_id' => $owner->id,
        'max_members' => $maxMembers,
    ]);
    $owner->update(['group_id' => $group->id]);

    return $group;
}

function seatPaddleSubscription(User $user, Plan $plan, string $subId = 'sub_seat_001', int $quantity = 1): PaddleSubscription
{
    return PaddleSubscription::create([
        'subscription_id' => $subId,
        'plan_id' => $plan->product_id,
        'user_id' => $user->id,
        'status' => 'active',
        'quantity' => $quantity,
    ]);
}

function seatWebhookPayload(string $event, string $subId, array $extra = []): array
{
    return array_merge([
        'event_type' => $event,
        'occurred_at' => now()->toIso8601String(),
        'data' => array_merge([
            'id' => $subId,
            'subscription_id' => $subId,
        ], $extra),
    ], []);
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Cache::forget('wave_settings');
    Setting::updateOrCreate(['key' => 'billing.provider'], ['display_name' => 'billing.provider', 'value' => 'paddle', 'type' => 'text', 'order' => 0]);
    Setting::updateOrCreate(['key' => 'billing.paddle_api_key'], ['display_name' => 'billing.paddle_api_key', 'value' => 'test_key', 'type' => 'text', 'order' => 0]);
    Cache::forget('wave_settings');
    // Bypass Paddle webhook signature verification in tests
    $this->withoutMiddleware(VerifyPaddleWebhookSignature::class);
});

// ─── POST /addSeats ────────────────────────────────────────────────────────────

it('increments group max_members when adding seats', function () {
    $plan = seatPlan();
    $user = seatUser('Team');
    $group = seatGroup($user, 8);

    actingAs($user)
        ->post('/addSeats', ['item' => $plan->id, 'quantity' => 3])
        ->assertOk()
        ->assertJsonPath('status', 1);

    expect($group->fresh()->max_members)->toBe(11);
});

it('records a SEAT_PURCHASE sysevent when adding seats', function () {
    $plan = seatPlan();
    $user = seatUser('Team');
    seatGroup($user, 8);

    actingAs($user)->post('/addSeats', ['item' => $plan->id, 'quantity' => 2]);

    assertDatabaseHas('sysevents', [
        'type' => 'SEAT_PURCHASE',
        'status' => 'SUCCESS',
        'owner' => $user->id,
    ]);
});

it('returns error when plan type is not seat', function () {
    $role = seatRole('Team');
    $servicePlan = Plan::create([
        'name' => 'Basic',
        'slug' => 'basic-'.uniqid(),
        'description' => 'Basic plan',
        'features' => '{}',
        'role_id' => $role->id,
        'type' => 'service',
        'active' => 1,
        'status' => 'available',
        'price' => '9',
        'monthly_price' => 9,
        'monthly_price_id' => 'pri_basic_m',
        'default' => 0,
    ]);
    $user = seatUser('Team');
    seatGroup($user, 8);

    actingAs($user)
        ->post('/addSeats', ['item' => $servicePlan->id, 'quantity' => 1])
        ->assertOk()
        ->assertJsonPath('status', 0);
});

it('returns error when user does not have Team or Enterprise role', function () {
    $plan = seatPlan();
    $user = seatUser('Minimal');

    actingAs($user)
        ->post('/addSeats', ['item' => $plan->id, 'quantity' => 1])
        ->assertOk()
        ->assertJsonPath('status', 0);
});

it('redirects unauthenticated users from /addSeats', function () {
    post('/addSeats', ['item' => 1])->assertRedirect();
});

// ─── Webhook: subscription.canceled (seat plan) ────────────────────────────────

it('decrements group max_members when seat subscription is cancelled', function () {
    $plan = seatPlan();
    $user = seatUser('Team');
    $group = seatGroup($user, 11); // base 8 + 3 purchased
    seatPaddleSubscription($user, $plan, 'sub_seat_c1', 3);

    post('/webhook/paddle', seatWebhookPayload('subscription.canceled', 'sub_seat_c1'));

    expect($group->fresh()->max_members)->toBe(8);
});

it('suspends newest overflow members on seat cancellation', function () {
    $plan = seatPlan();
    $owner = seatUser('Team');
    $group = seatGroup($owner, 11);

    // Create 5 members (oldest first)
    $members = collect();
    for ($i = 0; $i < 5; $i++) {
        $member = User::factory()->create([
            'group_id' => $group->id,
            'email_verified_at' => now(),
            'verified' => 1,
            'verification_code' => null,
        ]);
        $member->syncRoles(['Team']);
        $members->push($member);
        // Small sleep to ensure distinct timestamps
        usleep(1000);
    }

    // 11 total (owner + 5 members = 6 used); cancelling 3 seats → new max = 8 → available = 7 → no overflow
    // Let's instead set max to 8, so cancelling 3 leaves max=5, with 5 members + owner = 6 used → 1 suspended
    $group->update(['max_members' => 8]);
    seatPaddleSubscription($owner, $plan, 'sub_seat_c2', 3);

    post('/webhook/paddle', seatWebhookPayload('subscription.canceled', 'sub_seat_c2'));

    // new max = 5; owner + 4 members fit (4 slots for members); newest (last created) suspended
    $newestMember = $members->last();
    expect($newestMember->fresh()->hasRole('suspended'))->toBeTrue();
});

it('never suspends the group owner during seat cancellation', function () {
    $plan = seatPlan();
    $owner = seatUser('Team');
    $group = seatGroup($owner, 3); // very tight

    // No members other than owner; cancelling 2 seats → max=1
    seatPaddleSubscription($owner, $plan, 'sub_seat_c3', 2);

    post('/webhook/paddle', seatWebhookPayload('subscription.canceled', 'sub_seat_c3'));

    // Owner must never be suspended
    expect($owner->fresh()->hasRole('suspended'))->toBeFalse();
    expect($group->fresh()->max_members)->toBe(1);
});

// ─── Webhook: adjustment.updated refund (seat plan) ───────────────────────────

it('decrements group max_members on seat refund via adjustment webhook', function () {
    $plan = seatPlan();
    $user = seatUser('Team');
    $group = seatGroup($user, 10);
    seatPaddleSubscription($user, $plan, 'sub_seat_r1', 2);

    // Create a Wave Subscription so findSubscription resolves the user
    Subscription::create([
        'billable_type' => 'user',
        'billable_id' => $user->id,
        'plan_id' => $plan->id,
        'vendor_subscription_id' => 'sub_seat_r1',
        'vendor_slug' => 'paddle',
        'status' => 'active',
        'cycle' => 'month',
        'seats' => 1,
    ]);

    post('/webhook/paddle', [
        'event_type' => 'adjustment.updated',
        'occurred_at' => now()->toIso8601String(),
        'data' => [
            'subscription_id' => 'sub_seat_r1',
            'status' => 'approved',
            'action' => 'refund',
        ],
    ]);

    expect($group->fresh()->max_members)->toBe(8);
});

// ─── Webhook: transaction.completed (seat plan) ───────────────────────────────

it('does not increment seats again on recurring seat subscription payment', function () {
    $plan = seatPlan();
    $user = seatUser('Team');
    $group = seatGroup($user, 11); // already has 3 extra seats
    $paddleSub = seatPaddleSubscription($user, $plan, 'sub_seat_t1', 3);

    post('/webhook/paddle', [
        'event_type' => 'transaction.completed',
        'occurred_at' => now()->toIso8601String(),
        'data' => [
            'id' => 'txn_001',
            'subscription_id' => 'sub_seat_t1',
            'billing_period' => ['ends_at' => now()->addMonth()->toIso8601String()],
            'items' => [['quantity' => 3]],
        ],
    ]);

    // max_members must remain 11 — NOT 11+3=14
    expect($group->fresh()->max_members)->toBe(11);
});

// ─── Webhook: service plan cancellation still suspends the account owner ───────

it('still suspends the owner when a service plan is cancelled', function () {
    $role = seatRole('Basic');
    $servicePlan = Plan::create([
        'name' => 'Basic',
        'slug' => 'basic-'.uniqid(),
        'description' => 'Basic plan',
        'features' => '{}',
        'role_id' => $role->id,
        'type' => 'service',
        'active' => 1,
        'status' => 'available',
        'price' => '9',
        'monthly_price' => 9,
        'monthly_price_id' => 'pri_basic_m2',
        'product_id' => 'pro_basic_001',
        'default' => 0,
    ]);
    $user = seatUser('Basic');

    Subscription::create([
        'billable_type' => 'user',
        'billable_id' => $user->id,
        'plan_id' => $servicePlan->id,
        'vendor_subscription_id' => 'sub_svc_001',
        'vendor_slug' => 'paddle',
        'status' => 'active',
        'cycle' => 'month',
        'seats' => 1,
    ]);
    PaddleSubscription::create([
        'subscription_id' => 'sub_svc_001',
        'plan_id' => $servicePlan->product_id,
        'user_id' => $user->id,
        'status' => 'active',
        'quantity' => 1,
    ]);

    // Mock TelegramService to prevent real HTTP calls
    $this->mock(AccountSuspensionService::class, function ($mock) use ($user) {
        $mock->shouldReceive('suspend')->once()->with(
            Mockery::on(fn ($u) => $u->id === $user->id),
            'cancellation',
            Mockery::any()
        );
    });

    post('/webhook/paddle', seatWebhookPayload('subscription.canceled', 'sub_svc_001'));
});
