<?php

/**
 * First admin login provisions the personal LUKS vault inline, which runs the
 * full dd → luksFormat → mkfs flow and can take several seconds — making the
 * first request feel slow with nothing in the log to explain it. createPersonalVault
 * must log a start and a completion breadcrumb so the operator can see it.
 *
 * Runs under APP_NOVAULTS=TRUE (phpunit.xml), so the LUKS flow is skipped and
 * only the DB row + the log lines are exercised.
 */

use App\Models\User;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);
});

it('logs the start and completion of personal vault provisioning', function () {
    $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $admin->syncRoles(['admin']);

    Log::spy();

    $vault = VaultTools::createPersonalVault($admin->fresh(), 50);

    expect($vault)->not->toBeNull();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $m) => str_contains($m, 'provisioning') && str_contains($m, "user {$admin->id}"))
        ->once();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $m) => str_contains($m, "vault {$vault->id} ready") && str_contains($m, "user {$admin->id}"))
        ->once();
});
