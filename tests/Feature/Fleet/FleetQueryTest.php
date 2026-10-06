<?php

/*
|--------------------------------------------------------------------------
| SupportCase::fleetQuery() / fleetHostQuery() aggregation and scoping
|--------------------------------------------------------------------------
|
| The fleet list is a GROUP BY over support_cases keyed on machine_id with
| the filename host as fallback. Scoping must match the cases page exactly:
| own group OR public, minus rows the user hid via user_hidden_cases.
|
*/

use App\Models\SupportCase;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

const GID = 33;
const MID_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const MID_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

it('collapses reports into one row per machine_id with counts and first/last seen', function () {
    SupportCase::factory()->create(['machine_id' => MID_A, 'hostname' => 'web01', 'host' => 'file-a', 'date' => '2026-01-10', 'group' => GID]);
    SupportCase::factory()->create(['machine_id' => MID_A, 'hostname' => 'web01', 'host' => 'file-a', 'date' => '2026-03-05', 'group' => GID]);
    SupportCase::factory()->create(['machine_id' => MID_A, 'hostname' => 'web01', 'host' => 'file-a', 'date' => '2026-02-01', 'group' => GID]);
    SupportCase::factory()->create(['machine_id' => MID_B, 'hostname' => 'db01', 'host' => 'file-b', 'date' => '2026-02-20', 'group' => GID]);

    $rows = SupportCase::fleetQuery(GID, 1)->get()->keyBy('fleet_key');

    expect($rows)->toHaveCount(2)
        ->and($rows[MID_A]->report_count)->toBe(3)
        ->and($rows[MID_A]->first_seen)->toBe('2026-01-10')
        ->and($rows[MID_A]->last_seen)->toBe('2026-03-05')
        ->and($rows[MID_A]->display_hostname)->toBe('web01')
        ->and($rows[MID_B]->report_count)->toBe(1);
});

it('falls back to the filename host when machine_id is null or empty', function () {
    SupportCase::factory()->create(['machine_id' => null, 'host' => 'legacy1', 'date' => '2026-01-01', 'group' => GID]);
    SupportCase::factory()->create(['machine_id' => '', 'host' => 'legacy1', 'date' => '2026-01-02', 'group' => GID]);
    SupportCase::factory()->create(['machine_id' => MID_A, 'host' => 'legacy1', 'date' => '2026-01-03', 'group' => GID]);

    $rows = SupportCase::fleetQuery(GID, 1)->get()->keyBy('fleet_key');

    expect($rows)->toHaveCount(2)
        ->and($rows['legacy1']->report_count)->toBe(2)
        ->and($rows['legacy1']->display_hostname)->toBe('legacy1')
        ->and($rows[MID_A]->report_count)->toBe(1);
});

it('excludes other groups, includes public, excludes user-hidden cases', function () {
    $user = User::factory()->create();

    SupportCase::factory()->create(['machine_id' => MID_A, 'host' => 'mine', 'group' => GID]);
    SupportCase::factory()->create(['machine_id' => MID_B, 'host' => 'theirs', 'group' => 999, 'is_public' => false]);
    SupportCase::factory()->create(['machine_id' => 'cccccccccccccccccccccccccccccccc', 'host' => 'pub', 'group' => 999, 'is_public' => true]);
    $hidden = SupportCase::factory()->create(['machine_id' => 'dddddddddddddddddddddddddddddddd', 'host' => 'hidden', 'group' => GID]);
    DB::table('user_hidden_cases')->insert(['user_id' => $user->id, 'case_id' => $hidden->id]);

    $keys = SupportCase::fleetQuery(GID, $user->id)->get()->pluck('fleet_key');

    expect($keys)->toContain(MID_A)
        ->toContain('cccccccccccccccccccccccccccccccc')
        ->not->toContain(MID_B)
        ->not->toContain('dddddddddddddddddddddddddddddddd');
});

it('prefers the real hostname over the filename host for display', function () {
    SupportCase::factory()->create(['machine_id' => MID_A, 'hostname' => 'real.fqdn', 'host' => 'obfuscated0', 'group' => GID]);

    $row = SupportCase::fleetQuery(GID, 1)->get()->firstWhere('fleet_key', MID_A);

    expect($row->display_hostname)->toBe('real.fqdn');
});

it('fleetHostQuery returns one host\'s reports by machine_id or host fallback, scoped', function () {
    $a1 = SupportCase::factory()->create(['machine_id' => MID_A, 'host' => 'x', 'date' => '2026-01-01', 'group' => GID]);
    $a2 = SupportCase::factory()->create(['machine_id' => MID_A, 'host' => 'y', 'date' => '2026-02-01', 'group' => GID]);
    SupportCase::factory()->create(['machine_id' => MID_B, 'host' => 'x', 'group' => GID]);
    SupportCase::factory()->create(['machine_id' => MID_A, 'host' => 'x', 'group' => 999, 'is_public' => false]);
    $legacy = SupportCase::factory()->create(['machine_id' => null, 'host' => 'legacy1', 'group' => GID]);

    $ids = SupportCase::fleetHostQuery(MID_A, GID, 1)->orderBy('date')->pluck('id');
    expect($ids->all())->toBe([$a1->id, $a2->id]);

    $legacyIds = SupportCase::fleetHostQuery('legacy1', GID, 1)->pluck('id');
    expect($legacyIds->all())->toBe([$legacy->id]);
});
