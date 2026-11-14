<?php

/**
 * PlanBadge widget — trial active vs. trial ended states.
 *
 * Covers:
 *  - Active trial: shows "Trial ends" + "Free trial active".
 *  - Expired trial: shows "Trial ended" + "Free trial ended" (not the active strings).
 */

use App\Livewire\PlanBadge;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

it('shows the active-trial strings while the trial is in the future', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'trial_ends_at' => now()->addDays(10),
    ]);

    actingAs($user);

    Livewire::test(PlanBadge::class)
        ->assertSee(__('plan.badge_trial_ends', ['date' => $user->trial_ends_at->format('Y-m-d')]))
        ->assertSee(__('plan.badge_trial_active'))
        ->assertDontSee(__('plan.badge_trial_inactive'));
});

it('shows the ended-trial strings once trial_ends_at is in the past', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'trial_ends_at' => now()->subDays(2),
    ]);

    actingAs($user);

    Livewire::test(PlanBadge::class)
        ->assertSee(__('plan.badge_trial_ended', ['date' => $user->trial_ends_at->format('Y-m-d')]))
        ->assertSee(__('plan.badge_trial_inactive'))
        ->assertDontSee(__('plan.badge_trial_active'));
});

it('uses danger color when the trial has expired', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'trial_ends_at' => now()->subDay(),
    ]);

    actingAs($user);

    Livewire::test(PlanBadge::class)
        ->assertSee(__('plan.badge_days_left'))
        ->assertSee('fi-color-danger');
});

it('treats a trial with under 24 hours left as still active', function () {
    // Regression: Carbon 3 diffInDays() returns a float, so intval() of a partial
    // day truncated to 0 and initializeVault treated the trial as expired even
    // though trial_ends_at was still in the future.
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
        'trial_ends_at' => now()->addHours(17),
    ]);

    expect($user->onTrial())->toBeTrue()
        ->and($user->daysLeftOnTrial())->toBeGreaterThanOrEqual(1);
});
