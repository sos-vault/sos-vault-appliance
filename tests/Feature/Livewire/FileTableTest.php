<?php

use App\Livewire\FileTable;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();
});

// ---------------------------------------------------------------------------
// generateColumns — driven entirely by session state
// ---------------------------------------------------------------------------

it('generateColumns returns empty array when has_header is false', function () {
    $this->actingAs($this->user);

    session(['has_header' => false]);

    $instance = Livewire::test(FileTable::class, [
        'vid' => 1, 'did' => 2, 'fid' => 3, 'cid' => 4,
    ])->instance();

    expect($instance->generateColumns())->toBeEmpty();
});

it('generateColumns returns empty array when has_header is not set', function () {
    $this->actingAs($this->user);

    session()->forget('has_header');

    $instance = Livewire::test(FileTable::class, [
        'vid' => 1, 'did' => 2, 'fid' => 3, 'cid' => 4,
    ])->instance();

    expect($instance->generateColumns())->toBeEmpty();
});

it('generateColumns returns one column per header token', function () {
    $this->actingAs($this->user);

    session([
        'has_header'  => true,
        'headers'     => 'name|age|city',
        'column_keys' => 'name|age|city',
        'columns'     => 3,
        'isLogFile'   => false,
    ]);

    $instance = Livewire::test(FileTable::class, [
        'vid' => 1, 'did' => 2, 'fid' => 3, 'cid' => 4,
    ])->instance();

    expect($instance->generateColumns())->toHaveCount(3);
});

it('generateColumns uses sanitized column_keys for column names', function () {
    $this->actingAs($this->user);

    session([
        'has_header'  => true,
        'headers'     => '%usr|%system|%cpu',
        'column_keys' => '_usr|_system|_cpu',
        'columns'     => 3,
        'isLogFile'   => false,
    ]);

    $instance = Livewire::test(FileTable::class, [
        'vid' => 1, 'did' => 2, 'fid' => 3, 'cid' => 4,
    ])->instance();

    $columns = $instance->generateColumns();

    expect($columns)->toHaveCount(3)
        ->and($columns[0]->getName())->toBe('_usr')
        ->and($columns[1]->getName())->toBe('_system')
        ->and($columns[2]->getName())->toBe('_cpu');
});

it('generateColumns falls back to header value when column_keys session is missing', function () {
    $this->actingAs($this->user);

    session([
        'has_header' => true,
        'headers'    => 'host|cpu|mem',
        'columns'    => 3,
        'isLogFile'  => false,
    ]);
    session()->forget('column_keys');

    $instance = Livewire::test(FileTable::class, [
        'vid' => 1, 'did' => 2, 'fid' => 3, 'cid' => 4,
    ])->instance();

    $columns = $instance->generateColumns();

    expect($columns)->toHaveCount(3)
        ->and($columns[0]->getName())->toBe('host');
});

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

it('renders without errors', function () {
    $this->actingAs($this->user);

    Livewire::test(FileTable::class, [
        'vid' => 1, 'did' => 2, 'fid' => 3, 'cid' => 4,
    ])->assertStatus(200);
});
