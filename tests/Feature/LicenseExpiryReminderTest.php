<?php

use App\Models\License;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function makeLicenseExpiring(User $user, int $daysUntilExpiry, string $cycle = 'yearly'): License
{
    return License::create([
        'customer_id' => $user->id,
        'machine_tokens' => ['sha256:test'],
        'seats' => 1,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'issued_at' => $cycle === 'yearly' ? now()->subMonths(11)->addDays($daysUntilExpiry) : now()->subDays(30 - $daysUntilExpiry),
        'expires_at' => now()->addDays($daysUntilExpiry)->startOfDay(),
    ]);
}

function countEmailsSent(): int
{
    $count = 0;
    Mail::shouldReceive('raw')->andReturnUsing(function () use (&$count) {
        $count++;

        return null;
    });

    return $count;
}

test('yearly license gets 30-day reminder', function () {
    $user = User::factory()->create();
    makeLicenseExpiring($user, 30, 'yearly');

    $sent = 0;
    Mail::shouldReceive('raw')->andReturnUsing(function () use (&$sent) {
        $sent++;
    });

    $this->artisan('license:send-expiry-reminders')->assertSuccessful();

    expect($sent)->toBe(1);
});

test('monthly license does NOT get 30-day reminder', function () {
    $user = User::factory()->create();
    makeLicenseExpiring($user, 30, 'monthly');

    $sent = 0;
    Mail::shouldReceive('raw')->andReturnUsing(function () use (&$sent) {
        $sent++;
    });

    $this->artisan('license:send-expiry-reminders')->assertSuccessful();

    expect($sent)->toBe(0);
});

test('15-day reminder fires for any license', function () {
    $user = User::factory()->create();
    makeLicenseExpiring($user, 15, 'monthly');

    $sent = 0;
    Mail::shouldReceive('raw')->andReturnUsing(function () use (&$sent) {
        $sent++;
    });

    $this->artisan('license:send-expiry-reminders')->assertSuccessful();

    expect($sent)->toBe(1);
});

test('daily reminders fire for each day in the last 7 days', function () {
    $user = User::factory()->create();
    makeLicenseExpiring($user, 5); // 5 days until expiry

    $sent = 0;
    Mail::shouldReceive('raw')->andReturnUsing(function () use (&$sent) {
        $sent++;
    });

    $this->artisan('license:send-expiry-reminders')->assertSuccessful();

    expect($sent)->toBe(1);
});

test('expired license does NOT get a reminder (only ACTIVE)', function () {
    $user = User::factory()->create();
    License::create([
        'customer_id' => $user->id,
        'machine_tokens' => ['sha256:x'],
        'seats' => 1,
        'features' => ['srms'],
        'status' => 'EXPIRED',
        'issued_at' => now()->subYear(),
        'expires_at' => now()->addDays(7),
    ]);

    $sent = 0;
    Mail::shouldReceive('raw')->andReturnUsing(function () use (&$sent) {
        $sent++;
    });

    $this->artisan('license:send-expiry-reminders')->assertSuccessful();

    expect($sent)->toBe(0);
});

test('dry-run option does not send emails', function () {
    $user = User::factory()->create();
    makeLicenseExpiring($user, 15);

    $sent = 0;
    Mail::shouldReceive('raw')->andReturnUsing(function () use (&$sent) {
        $sent++;
    });

    $this->artisan('license:send-expiry-reminders', ['--dry-run' => true])->assertSuccessful();

    expect($sent)->toBe(0);
});
