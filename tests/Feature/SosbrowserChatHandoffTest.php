<?php

/**
 * sosbrowser/[caseid] Volt page — hands the open case to the Mil chat widget.
 *
 * When a case finishes loading (JS emits `case-selection-done` → toggleLoading),
 * the page must dispatch `chat-set-case` with the directory id + case id so the
 * globally-rendered ChatWidget can inject live sosreport data for "this system"
 * questions. Without this the widget never learns a case is open and every
 * case-analysis answer is blind (regression: #1/#2/#6 from the fresh-VM report).
 */

use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('dispatches chat-set-case with the open case when a case finishes loading', function () {
    Volt::test('sosbrowser', ['caseid' => null])
        ->set('did', 7)
        ->set('caseid', 42)
        ->call('toggleLoading')
        ->assertDispatched('chat-set-case', did: 7, cid: 42);
});

it('persists the open case in the session so tool-window widgets adopt it', function () {
    Volt::test('sosbrowser', ['caseid' => null])
        ->set('did', 7)
        ->set('caseid', 42)
        ->call('toggleLoading');

    expect(session('mil_open_case'))->toBe(['did' => 7, 'cid' => 42]);
});

it('does not hand a case to the chat widget when none is open', function () {
    Volt::test('sosbrowser', ['caseid' => null])
        ->set('did', null)
        ->set('caseid', 0)
        ->call('toggleLoading')
        ->assertNotDispatched('chat-set-case');

    expect(session('mil_open_case'))->toBeNull();
});
