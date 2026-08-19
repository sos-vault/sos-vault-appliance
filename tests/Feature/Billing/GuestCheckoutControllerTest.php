<?php

use App\Events\SendUserEmail;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Wave\Subscription;

/*
 * Security regression coverage for the guest Paddle checkout completion
 * endpoint (App\Http\Controllers\Billing\GuestCheckoutController).
 *
 * The endpoint is public and previously logged the caller in as whatever
 * account was tied to a submitted transaction id, with no proof of possession
 * and no one-time-use enforcement — a transaction id acted as a replayable
 * login credential. These tests lock in the hardened behaviour:
 *   - a fresh purchase still provisions + authenticates a NEW account,
 *   - an EXISTING account is provisioned but never auto-authenticated,
 *   - a replayed transaction id is an inert no-op,
 *   - the route is throttled.
 */

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);

    Config::set('wave.paddle.env', 'sandbox');
    Config::set('wave.paddle.api_key', 'test-api-key');

    $roleId = DB::table('roles')->where('name', 'Basic')->value('id');

    DB::table('plans')->insert([
        'name' => json_encode(['en' => 'Basic']),
        'slug' => 'basic',
        'type' => 'service',
        'role_id' => $roleId,
        'features' => '',
        'monthly_price' => 9,
        'monthly_price_id' => 'pri_basic_month',
        'yearly_price' => 90,
        'yearly_price_id' => 'pri_basic_year',
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

/**
 * Stub Paddle's transactions + customers endpoints. Any request that does not
 * match falls through to an empty 200 (covers the Telegram/activity fan-out).
 */
function fakePaddle(string $status = 'completed', string $email = 'buyer@example.com'): void
{
    Http::fake([
        '*/transactions/*' => Http::response([
            'data' => [
                'status' => $status,
                'customer_id' => 'ctm_123',
                'subscription_id' => 'sub_123',
                'payments' => [
                    ['method_details' => ['card' => ['cardholder_name' => 'Buyer One']]],
                ],
                'items' => [
                    ['price' => ['id' => 'pri_basic_month', 'billing_cycle' => ['interval' => 'month']]],
                ],
            ],
        ], 200),
        '*/customers/*' => Http::response(['data' => ['email' => $email]], 200),
        '*' => Http::response([], 200),
    ]);
}

it('provisions and authenticates a brand-new account for a paid transaction', function () {
    Event::fake([SendUserEmail::class]);
    fakePaddle(email: 'newbuyer@example.com');

    $response = $this->postJson(route('checkout.complete'), ['transaction_id' => 'txn_new_1']);

    $response->assertOk()->assertJson([
        'status' => 1,
        'redirect' => '/subscription/welcome',
    ]);

    $user = User::where('email', 'newbuyer@example.com')->first();
    expect($user)->not->toBeNull();
    $this->assertAuthenticatedAs($user);

    $this->assertDatabaseHas('subscriptions', [
        'billable_id' => $user->id,
        'vendor_transaction_id' => 'txn_new_1',
    ]);
    $this->assertDatabaseHas('password_resets', ['email' => 'newbuyer@example.com']);
    Event::assertDispatched(SendUserEmail::class);
});

it('never opens a session for an account that already exists (no takeover)', function () {
    $existing = User::create([
        'name' => 'Existing Owner',
        'email' => 'owner@example.com',
        'username' => 'owner',
        'password' => bcrypt('the-real-password'),
        'verified' => 1,
        'email_verified_at' => now(),
    ]);

    fakePaddle(email: 'owner@example.com');

    $response = $this->postJson(route('checkout.complete'), ['transaction_id' => 'txn_existing_1']);

    $response->assertOk()->assertJson([
        'status' => 1,
        'redirect' => '/login',
    ]);

    // The core of the finding: replaying a transaction for an existing account
    // must NOT authenticate the caller.
    $this->assertGuest();

    // The purchase is still honoured — the subscription is provisioned — but no
    // password-reset token is minted for the pre-existing account.
    $this->assertDatabaseHas('subscriptions', [
        'billable_id' => $existing->id,
        'vendor_transaction_id' => 'txn_existing_1',
    ]);
    $this->assertDatabaseMissing('password_resets', ['email' => 'owner@example.com']);
});

it('treats a replayed transaction id as an inert no-op', function () {
    // Simulate a transaction that already provisioned an account.
    $owner = User::create([
        'name' => 'Owner',
        'email' => 'prior@example.com',
        'username' => 'prior',
        'password' => bcrypt('pw'),
        'verified' => 1,
        'email_verified_at' => now(),
    ]);
    Subscription::create([
        'billable_id' => $owner->id,
        'billable_type' => 'user',
        'plan_id' => DB::table('plans')->value('id'),
        'vendor_slug' => 'paddle',
        'vendor_customer_id' => 'ctm_123',
        'vendor_transaction_id' => 'txn_replay',
        'cycle' => 'month',
        'status' => 'active',
        'seats' => 1,
    ]);

    $userCountBefore = User::count();
    fakePaddle(email: 'attacker-wont-matter@example.com');

    $response = $this->postJson(route('checkout.complete'), ['transaction_id' => 'txn_replay']);

    $response->assertOk()->assertJson([
        'status' => 1,
        'redirect' => '/login',
    ]);

    $this->assertGuest();
    // No new account minted, and the Paddle customer lookup is never reached.
    expect(User::count())->toBe($userCountBefore);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/customers/'));
});

it('throttles the public endpoint', function () {
    Config::set('cache.default', 'array');
    fakePaddle(status: 'pending'); // returns early; still counts against the limiter

    for ($i = 0; $i < 6; $i++) {
        $this->postJson(route('checkout.complete'), ['transaction_id' => "txn_rate_{$i}"])
            ->assertOk();
    }

    $this->postJson(route('checkout.complete'), ['transaction_id' => 'txn_rate_over'])
        ->assertStatus(429);
});
