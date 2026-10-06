<?php

use App\Models\Sysevent;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Wave\Setting;

uses(RefreshDatabase::class);

it('addEvent creates a Sysevent row for SCHEDULER type', function () {
    expect(Sysevent::count())->toBe(0);

    addEvent(
        (object) ['message' => 'unit-test scheduler event'],
        'SCHEDULER', 'SUCCESS', 'ACTIVITY', 0, 0, 0, 0
    );

    $event = Sysevent::first();
    expect($event)->not->toBeNull();
    expect($event->type)->toBe('SCHEDULER');
    expect($event->status)->toBe('SUCCESS');
    expect($event->class)->toBe('ACTIVITY');
});

it('addEvent falls back to vault_id 0 for a vault-less FAILED event instead of crashing on the NOT NULL constraint', function () {
    // Regression: an interrupted vault provision recorded a FAILED ADD_VAULT
    // with a null vault_id and no Vault row to resolve, hitting the
    // sysevents.vault_id NOT NULL constraint and 500-ing the login retry.
    expect(Sysevent::count())->toBe(0);

    $this->seed(RolesTableSeeder::class);
    $admin = User::factory()->create();

    addEvent(
        (object) ['message' => 'device file already exists'],
        'ADD_VAULT', 'FAILED', 'ACTIVITY', 0, null, $admin->id, 0
    );

    $event = Sysevent::first();
    expect($event)->not->toBeNull();
    expect((int) $event->vault_id)->toBe(0);
    expect($event->status)->toBe('FAILED');
});

it('users:send-trial-end-emails completes without errors and emits no Sysevent on no-op', function () {
    $this->seed(RolesTableSeeder::class);

    $this->artisan('users:send-trial-end-emails')->assertSuccessful();

    expect(Sysevent::where('type', 'SCHEDULER')->count())->toBe(0);
});

it('users:send-trial-end-emails short-circuits when site.trial_end_emails is disabled', function () {
    $this->seed(RolesTableSeeder::class);
    Setting::updateOrCreate(
        ['key' => 'site.trial_end_emails'],
        ['display_name' => 'Send Trial-End Reminder Emails', 'value' => '0', 'type' => 'text', 'order' => 5, 'group' => 'Site']
    );

    $this->artisan('users:send-trial-end-emails')->assertSuccessful();

    expect(Sysevent::where('type', 'SCHEDULER')->count())->toBe(0);
});

it('db:purge-expired completes without errors and emits no Sysevent on no-op', function () {
    $this->artisan('db:purge-expired')->assertSuccessful();

    expect(Sysevent::where('type', 'SCHEDULER')->count())->toBe(0);
});
