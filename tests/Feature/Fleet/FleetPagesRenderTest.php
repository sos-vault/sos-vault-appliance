<?php

/*
|--------------------------------------------------------------------------
| /fleet and /fleet/{fleetKey} page rendering
|--------------------------------------------------------------------------
|
| The fleet pages are Folio+Volt+Filament tables mirroring the cases page:
| auth-gated, group-scoped, one row per host on the list, and a chronological
| report timeline on the host detail page.
|
*/

use App\Models\SupportCase;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();
    $this->gid = $this->user->group_id ?? $this->user->id;
});

it('redirects guests to login', function (string $uri) {
    $this->get($uri)->assertRedirect('/login');
})->with(['/fleet', '/fleet/some-host']);

it('renders the fleet list with the seeded hostname', function () {
    SupportCase::factory()->create([
        'machine_id' => 'ec2f3a9b8c7d6e5f4a3b2c1d0e9f8a7b',
        'hostname' => 'web01.example.com',
        'group' => $this->gid,
    ]);

    $this->actingAs($this->user)
        ->get('/fleet')
        ->assertStatus(200)
        ->assertSee('web01.example.com');
});

it('renders the host timeline listing that host\'s cases', function () {
    $mid = 'ec2f3a9b8c7d6e5f4a3b2c1d0e9f8a7b';
    SupportCase::factory()->create([
        'machine_id' => $mid, 'hostname' => 'web01', 'case' => 'CASE-1111',
        'date' => '2026-01-05', 'group' => $this->gid,
    ]);
    SupportCase::factory()->create([
        'machine_id' => $mid, 'hostname' => 'web01', 'case' => 'CASE-2222',
        'date' => '2026-02-05', 'group' => $this->gid,
    ]);
    SupportCase::factory()->create([
        'machine_id' => 'ffffffffffffffffffffffffffffffff', 'hostname' => 'other',
        'case' => 'CASE-9999', 'group' => $this->gid,
    ]);

    $this->actingAs($this->user)
        ->get('/fleet/'.$mid)
        ->assertStatus(200)
        ->assertSee('web01')
        ->assertSee('CASE-1111')
        ->assertSee('CASE-2222')
        ->assertDontSee('CASE-9999');
});

it('shows the no-machine-id hint for filename-grouped hosts', function () {
    SupportCase::factory()->create([
        'machine_id' => null, 'hostname' => null, 'host' => 'legacyhost',
        'group' => $this->gid,
    ]);

    $this->actingAs($this->user)
        ->get('/fleet/legacyhost')
        ->assertStatus(200)
        ->assertSee(__('fleet.host_description_no_machine_id'));
});

it('shows the fleet link in the sidebar', function () {
    $this->actingAs($this->user)
        ->get('/dashboard')
        ->assertStatus(200)
        ->assertSee(__('nav.nav_fleet'));
});
