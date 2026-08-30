<?php

/*
|--------------------------------------------------------------------------
| Fleet host-identity columns on support_cases
|--------------------------------------------------------------------------
|
| The View Fleet feature groups cases by the real host identity extracted
| from the sosreport (/etc/machine-id + uname hostname) instead of the
| filename-derived `host` column. These columns must exist and be fillable.
|
*/

use App\Models\SupportCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has machine_id and hostname columns on support_cases', function () {
    expect(Schema::hasColumn('support_cases', 'machine_id'))->toBeTrue()
        ->and(Schema::hasColumn('support_cases', 'hostname'))->toBeTrue();
});

it('mass-assigns machine_id and hostname', function () {
    $case = SupportCase::factory()->create([
        'machine_id' => 'ec2f3a9b8c7d6e5f4a3b2c1d0e9f8a7b',
        'hostname' => 'web01.example.com',
    ]);

    expect($case->fresh())
        ->machine_id->toBe('ec2f3a9b8c7d6e5f4a3b2c1d0e9f8a7b')
        ->hostname->toBe('web01.example.com');
});

it('defaults machine_id and hostname to null for legacy rows', function () {
    $case = SupportCase::factory()->create();

    expect($case->fresh())
        ->machine_id->toBeNull()
        ->hostname->toBeNull();
});
