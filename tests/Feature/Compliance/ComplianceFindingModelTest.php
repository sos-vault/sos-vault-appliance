<?php

use App\Models\AnalysisRun;
use App\Models\ComplianceFinding;

it('belongs to an analysis run', function () {
    $run = AnalysisRun::factory()->create();
    $finding = ComplianceFinding::factory()->create(['analysis_run_id' => $run->id]);

    expect($finding->run)->not->toBeNull()
        ->and($finding->run->id)->toBe($run->id);
});

it('casts evidence to an array', function () {
    $finding = ComplianceFinding::factory()->create([
        'evidence' => ['line' => 'PermitRootLogin yes', 'path' => 'etc/ssh/sshd_config'],
    ]);

    expect($finding->evidence)->toBe(['line' => 'PermitRootLogin yes', 'path' => 'etc/ssh/sshd_config']);
});

it('scopes to failing findings only', function () {
    ComplianceFinding::factory()->create(['status' => 'fail']);
    ComplianceFinding::factory()->create(['status' => 'pass']);
    ComplianceFinding::factory()->create(['status' => 'fail']);

    expect(ComplianceFinding::failing()->count())->toBe(2);
});

it('scopes to findings for a given case', function () {
    ComplianceFinding::factory()->create(['case_id' => 42]);
    ComplianceFinding::factory()->create(['case_id' => 42]);
    ComplianceFinding::factory()->create(['case_id' => 99]);

    expect(ComplianceFinding::forCase(42)->count())->toBe(2);
});

it('scopes to findings for a given ruleset', function () {
    ComplianceFinding::factory()->create(['ruleset' => 'cis']);
    ComplianceFinding::factory()->create(['ruleset' => 'stig']);
    ComplianceFinding::factory()->create(['ruleset' => 'cis']);

    expect(ComplianceFinding::ruleset('cis')->count())->toBe(2);
});

it('exposes rulesets, statuses and severities as constants', function () {
    expect(ComplianceFinding::RULESETS)->toBe(['cis', 'stig', 'docker_bench', 'exposure']);
    expect(ComplianceFinding::STATUSES)->toBe(['pass', 'fail', 'not_applicable', 'error']);
    expect(ComplianceFinding::SEVERITIES)->toBe(['INFO', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL']);
});
