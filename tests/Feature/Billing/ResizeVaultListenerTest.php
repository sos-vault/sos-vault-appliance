<?php

/**
 * ResizeVault Listener Tests
 *
 * Covers:
 *  - ResizeVault::failed() sends an error notification to the user
 *  - ResizeVault::handle() when a non-stale lock is active → "vault is currently busy" notification
 *  - ResizeVault::handle() when a stale lock (>200s) is found → "Please try again" warning
 *  - ResizeVault::handle() with a missing/non-existent vault → error notification (vaultsDisabled path)
 *  - ExpandVault event carries the expected payload keys
 *  - ShrinkVault event carries the expected payload keys
 */

use App\Events\ExpandVault;
use App\Events\ShrinkVault;
use App\Listeners\ResizeVault;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Wave\Plan;
use Wave\Setting;

uses(RefreshDatabase::class)->in(__FILE__);

// ---------------------------------------------------------------------------
// Helpers (local copies — DiskExpansionTest helpers are file-scoped)
// ---------------------------------------------------------------------------

function makeDiskPlanForListener(string $name = '10GB', array $overrides = []): Plan
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
        'monthly_price_id' => 'pri_test_listener_'.uniqid(),
        'yearly_price' => 100,
        'yearly_price_id' => 'pri_test_listener_yr_'.uniqid(),
        'product_id' => 'pro_test_listener_'.uniqid(),
        'default' => 0,
    ], $overrides));
}

function makeListenerUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'verification_code' => null,
    ]);
}

// ---------------------------------------------------------------------------
// Test setup / teardown
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Cache::forget('wave_settings');
    Setting::updateOrCreate(['key' => 'billing.provider'], ['display_name' => 'billing.provider', 'value' => 'paddle', 'type' => 'text', 'order' => 0]);
    Setting::updateOrCreate(['key' => 'billing.paddle_api_key'], ['display_name' => 'billing.paddle_api_key', 'value' => 'test_api_key', 'type' => 'text', 'order' => 0]);
    Cache::forget('wave_settings');
});

afterEach(function () {
    // Remove any lock files that tests may have created.
    foreach (glob('/var/tmp/.resizeVault_*.lock') as $lock) {
        @unlink($lock);
    }
});

// ---------------------------------------------------------------------------
// ResizeVault::failed()
// ---------------------------------------------------------------------------

it('failed() sends an error notification to the user', function () {
    $user = makeListenerUser();
    $plan = makeDiskPlanForListener();

    $event = new ExpandVault(['user' => $user, 'size' => 1024 * 1024 * 1024, 'plan' => $plan]);
    $exception = new RuntimeException('Simulated queue failure');

    (new ResizeVault)->failed($event, $exception);

    expect($user->notifications()->count())->toBe(1);
});

it('failed() with ShrinkVault event also notifies the user', function () {
    $user = makeListenerUser();
    $plan = makeDiskPlanForListener();

    $event = new ShrinkVault(['user' => $user, 'size' => 1024 * 1024 * 1024, 'plan' => $plan]);
    $exception = new RuntimeException('Simulated queue failure');

    (new ResizeVault)->failed($event, $exception);

    expect($user->notifications()->count())->toBe(1);
});

it('failed() does not throw when user is missing from the event data', function () {
    $plan = makeDiskPlanForListener();

    $event = new ExpandVault(['user' => null, 'size' => 0, 'plan' => $plan]);
    $exception = new RuntimeException('Simulated failure without user');

    // Should not throw — graceful degradation when data is incomplete.
    expect(fn () => (new ResizeVault)->failed($event, $exception))->not->toThrow(Throwable::class);
});

// ---------------------------------------------------------------------------
// ResizeVault::handle() — lock file scenarios
// (With APP_NOVAULTS=TRUE in phpunit.xml the listener skips real OS vault
//  operations, so these tests exercise the lock-guard paths only.)
// ---------------------------------------------------------------------------

it('handle() sends a "currently busy" error when an active lock file exists', function () {
    $user = makeListenerUser();
    $plan = makeDiskPlanForListener();
    $lock = "/var/tmp/.resizeVault_{$user->id}.lock";

    // Pre-create a fresh lock (simulates another job running for this user).
    file_put_contents($lock, "\n");

    $event = new ExpandVault(['user' => $user, 'size' => 1024 * 1024 * 1024, 'plan' => $plan]);
    (new ResizeVault)->handle($event);

    $notification = $user->notifications()->latest()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['status'])->toBe('error')
        ->and($notification->data['body'])->toContain('currently busy');
});

it('handle() sends a "Please try again" warning when only a stale lock file exists', function () {
    $user = makeListenerUser();
    $plan = makeDiskPlanForListener();
    $lock = "/var/tmp/.resizeVault_{$user->id}.lock";

    // Create a lock file and back-date its mtime by 210 seconds.
    file_put_contents($lock, "\n");
    touch($lock, time() - 210);
    clearstatcache();

    $event = new ExpandVault(['user' => $user, 'size' => 1024 * 1024 * 1024, 'plan' => $plan]);
    (new ResizeVault)->handle($event);

    $notification = $user->notifications()->latest()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['status'])->toBe('warning')
        ->and($notification->data['body'])->toContain('Please try again');
});

it('handle() removes the stale lock file after sending the warning', function () {
    $user = makeListenerUser();
    $plan = makeDiskPlanForListener();
    $lock = "/var/tmp/.resizeVault_{$user->id}.lock";

    file_put_contents($lock, "\n");
    touch($lock, time() - 210);
    clearstatcache();

    $event = new ExpandVault(['user' => $user, 'size' => 1024 * 1024 * 1024, 'plan' => $plan]);
    (new ResizeVault)->handle($event);

    expect(file_exists($lock))->toBeFalse();
});

// ---------------------------------------------------------------------------
// ResizeVault::handle() — early-return guard (zero size)
// ---------------------------------------------------------------------------

it('handle() returns early and sends no notification when size is zero', function () {
    // The listener guards against a missing user or zero size at the top of
    // handle(): `if (! $user || ! $size) { return; }`.
    // With size=0 no notification should reach the user.
    $user = makeListenerUser();
    $plan = makeDiskPlanForListener();

    $event = new ExpandVault(['user' => $user, 'size' => 0, 'plan' => $plan]);
    (new ResizeVault)->handle($event);

    expect($user->notifications()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// ExpandVault / ShrinkVault event payload structure
// ---------------------------------------------------------------------------

it('expandDisk dispatches ExpandVault with user, size, and plan in the payload', function () {
    Event::fake([ExpandVault::class]);

    $user = makeListenerUser();
    $plan = makeDiskPlanForListener();

    $this->actingAs($user)->post('/expandDisk', ['item' => $plan->id]);

    Event::assertDispatched(ExpandVault::class, function (ExpandVault $event) use ($user, $plan) {
        return isset($event->data['user'])
            && $event->data['user']->id === $user->id
            && isset($event->data['size'])
            && $event->data['size'] > 0
            && isset($event->data['plan'])
            && $event->data['plan']->id === $plan->id;
    });
});

it('cancelDisk dispatches ShrinkVault with user, size, and plan in the payload', function () {
    Event::fake([ShrinkVault::class]);

    $user = makeListenerUser();
    $plan = makeDiskPlanForListener();

    $this->actingAs($user)->post('/cancelDisk', ['item' => $plan->id]);

    Event::assertDispatched(ShrinkVault::class, function (ShrinkVault $event) use ($user, $plan) {
        return isset($event->data['user'])
            && $event->data['user']->id === $user->id
            && isset($event->data['size'])
            && $event->data['size'] > 0
            && isset($event->data['plan'])
            && $event->data['plan']->id === $plan->id;
    });
});

it('ExpandVault event size matches the GB value declared in the plan features', function () {
    Event::fake([ExpandVault::class]);

    $user = makeListenerUser();
    // Plan with '10 GB vault increase' → size should be 10 * 1024^3 bytes.
    $plan = makeDiskPlanForListener('10GB', ['features' => '10 GB vault increase']);

    $this->actingAs($user)->post('/expandDisk', ['item' => $plan->id]);

    Event::assertDispatched(ExpandVault::class, function (ExpandVault $event) {
        $expected = 10 * pow(1024, 3);

        // Use loose equality: the route stores size as float, expected may be int.
        return $event->data['size'] == $expected && $event->data['size'] > 0;
    });
});
