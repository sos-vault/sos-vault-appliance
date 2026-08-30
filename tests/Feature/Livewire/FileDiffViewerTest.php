<?php

use App\Livewire\FileDiffViewer;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// mount
// ---------------------------------------------------------------------------

it('mounts with empty content by default', function () {
    Livewire::test(FileDiffViewer::class)
        ->assertSet('leftContent', '')
        ->assertSet('rightContent', '');
});

it('mounts with provided left and right content', function () {
    Livewire::test(FileDiffViewer::class, [
        'leftContent' => 'line one',
        'rightContent' => 'line two',
    ])
        ->assertSet('leftContent', 'line one')
        ->assertSet('rightContent', 'line two');
});

it('truncates left content that exceeds 2 000 000 characters', function () {
    $huge = str_repeat('a', 2_000_001);

    Livewire::test(FileDiffViewer::class, ['leftContent' => $huge])
        ->assertSet('leftContent', str_repeat('a', 2_000_000));
});

it('truncates right content that exceeds 2 000 000 characters', function () {
    $huge = str_repeat('b', 2_000_001);

    Livewire::test(FileDiffViewer::class, ['rightContent' => $huge])
        ->assertSet('rightContent', str_repeat('b', 2_000_000));
});

it('accepts content exactly at the 2 000 000 character limit without truncation', function () {
    $exact = str_repeat('x', 2_000_000);

    Livewire::test(FileDiffViewer::class, [
        'leftContent' => $exact,
        'rightContent' => $exact,
    ])
        ->assertSet('leftContent', $exact)
        ->assertSet('rightContent', $exact);
});

// ---------------------------------------------------------------------------
// load-chunk-diff event
// ---------------------------------------------------------------------------

it('updates content and dispatches getFileDiff on load-chunk-diff', function () {
    Livewire::test(FileDiffViewer::class)
        ->dispatch('load-chunk-diff', left: 'old', right: 'new')
        ->assertSet('leftContent', 'old')
        ->assertSet('rightContent', 'new')
        ->assertDispatched('getFileDiff');
});

it('dispatches getFileDiff when left is empty (file added/emptied between reports)', function () {
    Livewire::test(FileDiffViewer::class)
        ->dispatch('load-chunk-diff', left: '', right: 'new')
        ->assertSet('leftContent', '')
        ->assertSet('rightContent', 'new')
        ->assertDispatched('getFileDiff');
});

it('dispatches getFileDiff when right is empty (file removed/emptied between reports)', function () {
    Livewire::test(FileDiffViewer::class)
        ->dispatch('load-chunk-diff', left: 'old', right: '')
        ->assertSet('leftContent', 'old')
        ->assertSet('rightContent', '')
        ->assertDispatched('getFileDiff');
});

it('still dispatches when both sides are empty so upstream callers do not silently no-op', function () {
    Livewire::test(FileDiffViewer::class)
        ->dispatch('load-chunk-diff', left: '', right: '')
        ->assertDispatched('getFileDiff');
});

// ---------------------------------------------------------------------------
// render
// ---------------------------------------------------------------------------

it('renders without errors', function () {
    Livewire::test(FileDiffViewer::class)
        ->assertStatus(200);
});
