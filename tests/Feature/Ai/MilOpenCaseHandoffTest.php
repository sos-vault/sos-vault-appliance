<?php

use App\Livewire\ChatWidget;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Livewire\Livewire;

// The tool pages (Summary, Top, Compare, file viewer, …) each mount their own
// ChatWidget from the app layout. Only the Browse SOS Report page used to hand the
// open case to Mil, so a tool page whose widget mounted without that session write
// (the Summary tab loses that race) answered case questions blind. rememberMilOpenCase()
// lets every tool page assert its own open case; the widget then adopts it on mount.

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();
});

it('stashes the open case in the session for Mil to adopt', function () {
    rememberMilOpenCase(42, 7);

    expect(session('mil_open_case'))->toBe(['did' => 42, 'cid' => 7, 'tool' => null, 'fid' => null]);
});

it('stashes the tool and file id when a tool page hands off its case', function () {
    rememberMilOpenCase(42, 7, 'File Viewer', 4821);

    expect(session('mil_open_case'))->toBe(['did' => 42, 'cid' => 7, 'tool' => 'File Viewer', 'fid' => 4821]);
});

it('does not stash a partial/empty case', function () {
    session()->forget('mil_open_case');

    rememberMilOpenCase(0, 7);
    rememberMilOpenCase(42, null);
    rememberMilOpenCase(null, null);

    expect(session('mil_open_case'))->toBeNull();
});

it('lets the ChatWidget adopt the case a tool page recorded, on mount', function () {
    $this->actingAs($this->user);

    // Simulate a tool page (e.g. Summary) having handed off its case in mount().
    rememberMilOpenCase(99, 3);

    Livewire::test(ChatWidget::class)
        ->assertSet('did', 99)
        ->assertSet('cid', 3);
});
