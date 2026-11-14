<?php

/**
 * Public Report Feature Tests
 *
 * Covers:
 *  - Own reports are always visible in the table query
 *  - Public reports from other users appear in the table query
 *  - Private reports from other users are NOT visible
 *  - Hidden public reports are excluded from the table query
 *  - Hiding an already-hidden report does not duplicate the row
 *  - hiddenByUsers relationship works correctly
 *  - is_public column is fillable and stored
 *  - Reports page loads for authenticated users
 *  - Reports page redirects guests
 */

use App\Models\Report;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeReport(User $user, array $overrides = []): Report
{
    return Report::factory()->create(array_merge([
        'user_id'  => $user->id,
        'document' => '{}',
    ], $overrides));
}

function tableQueryFor(User $user): \Illuminate\Database\Eloquent\Builder
{
    $uid = $user->id;

    return Report::query()
        ->where(function ($q) use ($uid) {
            $q->where('user_id', $uid)
              ->orWhere('is_public', true);
        })
        ->whereNotIn('id', function ($q) use ($uid) {
            $q->select('report_id')
              ->from('user_hidden_reports')
              ->where('user_id', $uid);
        });
}

// ---------------------------------------------------------------------------
// Table query — visibility rules
// ---------------------------------------------------------------------------

it('shows own private reports in the table query', function () {
    $report = makeReport($this->owner, ['is_public' => false]);

    $ids = tableQueryFor($this->owner)->pluck('id');

    expect($ids)->toContain($report->id);
});

it('shows own public reports in the table query', function () {
    $report = makeReport($this->owner, ['is_public' => true]);

    $ids = tableQueryFor($this->owner)->pluck('id');

    expect($ids)->toContain($report->id);
});

it('shows public reports from other users in the table query', function () {
    $report = makeReport($this->other, ['is_public' => true]);

    $ids = tableQueryFor($this->owner)->pluck('id');

    expect($ids)->toContain($report->id);
});

it('does not show private reports from other users in the table query', function () {
    $report = makeReport($this->other, ['is_public' => false]);

    $ids = tableQueryFor($this->owner)->pluck('id');

    expect($ids)->not->toContain($report->id);
});

it('excludes public reports that the user has hidden', function () {
    $report = makeReport($this->other, ['is_public' => true]);

    DB::table('user_hidden_reports')->insert([
        'user_id'   => $this->owner->id,
        'report_id' => $report->id,
    ]);

    $ids = tableQueryFor($this->owner)->pluck('id');

    expect($ids)->not->toContain($report->id);
});

it('does not exclude public reports hidden by a different user', function () {
    $report = makeReport($this->other, ['is_public' => true]);

    DB::table('user_hidden_reports')->insert([
        'user_id'   => $this->other->id,
        'report_id' => $report->id,
    ]);

    $ids = tableQueryFor($this->owner)->pluck('id');

    expect($ids)->toContain($report->id);
});

// ---------------------------------------------------------------------------
// Model — is_public fillable and stored
// ---------------------------------------------------------------------------

it('is_public defaults to false', function () {
    $report = makeReport($this->owner);

    expect($report->fresh()->is_public)->toBeFalsy();
});

it('is_public can be set to true via update', function () {
    $report = makeReport($this->owner, ['is_public' => false]);

    $report->update(['is_public' => true]);

    expect($report->fresh()->is_public)->toBeTruthy();
});

it('is_public can be toggled back to false', function () {
    $report = makeReport($this->owner, ['is_public' => true]);

    $report->update(['is_public' => false]);

    expect($report->fresh()->is_public)->toBeFalsy();
});

// ---------------------------------------------------------------------------
// Model — hiddenByUsers relationship
// ---------------------------------------------------------------------------

it('hiddenByUsers relationship returns users who hid the report', function () {
    $report = makeReport($this->other, ['is_public' => true]);

    DB::table('user_hidden_reports')->insert([
        'user_id'   => $this->owner->id,
        'report_id' => $report->id,
    ]);

    expect($report->hiddenByUsers()->pluck('users.id'))->toContain($this->owner->id);
});

it('hiddenByUsers relationship is empty when nobody hid the report', function () {
    $report = makeReport($this->owner, ['is_public' => true]);

    expect($report->hiddenByUsers()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// user_hidden_reports table — constraints
// ---------------------------------------------------------------------------

it('duplicate hide entries are prevented by primary key constraint', function () {
    $report = makeReport($this->other, ['is_public' => true]);

    DB::table('user_hidden_reports')->insert([
        'user_id'   => $this->owner->id,
        'report_id' => $report->id,
    ]);

    expect(fn () => DB::table('user_hidden_reports')->insert([
        'user_id'   => $this->owner->id,
        'report_id' => $report->id,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('insertOrIgnore does not throw on duplicate hide', function () {
    $report = makeReport($this->other, ['is_public' => true]);

    DB::table('user_hidden_reports')->insertOrIgnore([
        'user_id'   => $this->owner->id,
        'report_id' => $report->id,
    ]);

    DB::table('user_hidden_reports')->insertOrIgnore([
        'user_id'   => $this->owner->id,
        'report_id' => $report->id,
    ]);

    $count = DB::table('user_hidden_reports')
        ->where('user_id', $this->owner->id)
        ->where('report_id', $report->id)
        ->count();

    expect($count)->toBe(1);
});

// ---------------------------------------------------------------------------
// HTTP — page access
// ---------------------------------------------------------------------------

it('reports page redirects unauthenticated users', function () {
    $case = SupportCase::factory()->create();

    $this->get('/reports/'.$case->id)->assertRedirect('/login');
});

it('reports page loads for authenticated users', function () {
    $case = SupportCase::factory()->create(['owner' => $this->owner->id]);

    $this->actingAs($this->owner)
        ->get('/reports/'.$case->id)
        ->assertOk();
});
