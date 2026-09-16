<?php

use App\Models\ContentsRequest;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/**
 * Public share-link endpoints (sosShared / sosSharedDir): read-time expiry
 * enforcement (S-3) and probing throttle (S-4). vaultsDisabled=TRUE lets the
 * 'vault' middleware + VaultTools run without a real LUKS mount.
 */
beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Config::set('app.vaultsDisabled', 'TRUE');
});

function svaultActingUserWithVault(): User
{
    $user = User::factory()->create();
    Vault::factory()->create(['owner' => $user->id, 'status' => 'OPEN']);
    test()->actingAs($user);

    return $user;
}

it('rejects a share whose expire timestamp has passed (before the daily purge)', function () {
    svaultActingUserWithVault();
    $owner = User::factory()->create();

    $token = str_repeat('a', 40);
    ContentsRequest::factory()->create([
        'url' => url("sosShared/{$token}"),
        'vault_id' => 60, 'dir_id' => 16, 'file_id' => 4961, 'case_id' => 582,
        'owner' => $owner->id,
        'status' => 'VALID', // cron has NOT flipped it to EXPIRED yet
        'expire' => now()->subDay()->format('Y-m-d H:i:s'),
    ]);

    $this->get("/sosShared/{$token}")->assertRedirect('/dashboard');
});

it('accepts a valid, unexpired share and redirects into the file browser', function () {
    svaultActingUserWithVault();
    $owner = User::factory()->create();
    Vault::factory()->create(['owner' => $owner->id, 'status' => 'OPEN']);

    $token = str_repeat('b', 40);
    ContentsRequest::factory()->create([
        'url' => url("sosShared/{$token}"),
        'vault_id' => 60, 'dir_id' => 16, 'file_id' => 4961, 'case_id' => 582,
        'owner' => $owner->id,
        'status' => 'SHARED',
        'expire' => now()->addDays(5)->format('Y-m-d H:i:s'),
    ]);

    $this->get("/sosShared/{$token}")
        ->assertRedirect('/filebrowser/582/4961?sme=2&vid=60&did=16');
});

it('throttles repeated share-link probing', function () {
    svaultActingUserWithVault();

    // A token with no matching ContentsRequest just redirects to /dashboard;
    // the point is the rate limit, which must trip after 30 hits/min.
    $token = str_repeat('c', 40);

    for ($i = 0; $i < 30; $i++) {
        $this->get("/sosShared/{$token}")->assertStatus(302);
    }

    $this->get("/sosShared/{$token}")->assertStatus(429);
});
