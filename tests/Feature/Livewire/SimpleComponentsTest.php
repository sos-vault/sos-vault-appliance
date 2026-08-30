<?php

use App\Livewire\DirectoryTree;
use App\Livewire\FileSearch;
use App\Livewire\InFileSearch;
use App\Livewire\ListCases;
use App\Livewire\ProgressBar;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();
});

// ---------------------------------------------------------------------------
// FileSearch — thin render wrapper
// ---------------------------------------------------------------------------

it('FileSearch renders without errors', function () {
    $this->actingAs($this->user);

    Livewire::test(FileSearch::class)
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// InFileSearch — thin render wrapper
// ---------------------------------------------------------------------------

it('InFileSearch renders without errors', function () {
    $this->actingAs($this->user);

    Livewire::test(InFileSearch::class)
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// ListCases
// ---------------------------------------------------------------------------

it('ListCases renders without errors when type is null', function () {
    $this->actingAs($this->user);

    Livewire::test(ListCases::class)
        ->assertSet('type', null)
        ->assertStatus(200);
});

it('ListCases renders tool-control dropdown with cases', function () {
    $this->actingAs($this->user);

    SupportCase::factory()->count(2)->create(['owner' => $this->user->id]);

    Livewire::test(ListCases::class, ['type' => 'tool-control'])
        ->assertSet('type', 'tool-control')
        ->assertStatus(200);
});

it('ListCases renders sidebar dropdown with cases', function () {
    $this->actingAs($this->user);

    SupportCase::factory()->count(2)->create(['owner' => $this->user->id]);

    Livewire::test(ListCases::class, ['type' => 'sidebar'])
        ->assertSet('type', 'sidebar')
        ->assertStatus(200);
});

it('ListCases renders empty when no cases exist', function () {
    $this->actingAs($this->user);

    Livewire::test(ListCases::class, ['type' => 'sidebar'])
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// DirectoryTree
// ---------------------------------------------------------------------------

it('DirectoryTree mounts and renders without errors', function () {
    $this->actingAs($this->user);

    Livewire::test(DirectoryTree::class)
        ->assertStatus(200);
});

it('DirectoryTree dispatches showReportHierarchy on openSosReport event', function () {
    $this->actingAs($this->user);

    Livewire::test(DirectoryTree::class)
        ->dispatch('openSosReport',
            cid: 1, vid: 2, did: 3,
            root: 'pre1', mode: 'view',
            tree: [], cid2: 4,
        )
        ->assertDispatched('showReportHierarchy');
});

it('DirectoryTree does not dispatch showReportHierarchy when params are missing', function () {
    $this->actingAs($this->user);

    // Only some params passed — openSosReport guard requires all to be set
    Livewire::test(DirectoryTree::class)
        ->dispatch('openSosReport',
            cid: 1, vid: 2, did: 3,
            root: null, mode: 'view',
            tree: [], cid2: 4,
        )
        ->assertNotDispatched('showReportHierarchy');
});

// ---------------------------------------------------------------------------
// ProgressBar
// ---------------------------------------------------------------------------

it('ProgressBar mounts with initial state', function () {
    $this->actingAs($this->user);

    $vault = Vault::factory()->create(['status' => 'OPEN', 'owner' => $this->user->id]);

    Livewire::test(ProgressBar::class, ['vid' => $vault->id])
        ->assertSet('vid', $vault->id)
        ->assertSet('currentVal', 0)
        ->assertSet('isProgress', false);
});

it('ProgressBar toggleProgress sets isProgress to false and dispatches close-modal', function () {
    $this->actingAs($this->user);

    $vault = Vault::factory()->create(['status' => 'OPEN', 'owner' => $this->user->id]);

    Livewire::test(ProgressBar::class, ['vid' => $vault->id])
        ->dispatch('stop-progress')
        ->assertSet('isProgress', false)
        ->assertDispatched('close-modal');
});

it('ProgressBar startProgress sets isProgress to true and dispatches open-modal', function () {
    $this->actingAs($this->user);

    $vault = Vault::factory()->create(['status' => 'OPEN', 'owner' => $this->user->id]);

    Livewire::test(ProgressBar::class, ['vid' => $vault->id])
        ->dispatch('start-progress', fid: 'file123', key: 'abc')
        ->assertSet('isProgress', true)
        ->assertDispatched('open-modal');
});

it('ProgressBar startProgress dispatches unpackFile', function () {
    $this->actingAs($this->user);

    $vault = Vault::factory()->create(['status' => 'OPEN', 'owner' => $this->user->id]);

    Livewire::test(ProgressBar::class, ['vid' => $vault->id])
        ->dispatch('start-progress', fid: 'file123', key: 'abc')
        ->assertDispatched('unpackFile');
});
