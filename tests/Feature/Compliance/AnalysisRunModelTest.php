<?php

use App\Models\AnalysisRun;
use App\Models\ComplianceFinding;
use App\Models\SupportCase;
use Illuminate\Support\Carbon;

it('belongs to a support case', function () {
    $case = SupportCase::factory()->create();
    $run = AnalysisRun::factory()->create(['case_id' => $case->id]);

    expect($run->case)->not->toBeNull()
        ->and($run->case->id)->toBe($case->id);
});

it('has many compliance findings', function () {
    $run = AnalysisRun::factory()->create();
    ComplianceFinding::factory()->count(3)->create(['analysis_run_id' => $run->id]);

    expect($run->findings)->toHaveCount(3);
});

it('casts started_at, completed_at and summary', function () {
    $run = AnalysisRun::factory()->create([
        'started_at' => now(),
        'completed_at' => now(),
        'summary' => ['total' => 5, 'failed' => 2],
    ]);

    expect($run->started_at)->toBeInstanceOf(Carbon::class)
        ->and($run->completed_at)->toBeInstanceOf(Carbon::class)
        ->and($run->summary)->toBe(['total' => 5, 'failed' => 2]);
});

it('scopes to the latest run for a case', function () {
    $case = SupportCase::factory()->create();

    $older = AnalysisRun::factory()->create(['case_id' => $case->id, 'created_at' => now()->subDay()]);
    $newer = AnalysisRun::factory()->create(['case_id' => $case->id, 'created_at' => now()]);
    AnalysisRun::factory()->create(); // unrelated case

    $latest = AnalysisRun::latestForCase($case->id)->first();

    expect($latest->id)->toBe($newer->id);
});

it('marks a run as running', function () {
    $run = AnalysisRun::factory()->create(['status' => 'queued', 'started_at' => null]);

    $run->markRunning();
    $run->refresh();

    expect($run->status)->toBe('running')
        ->and($run->started_at)->not->toBeNull();
});

it('marks a run as completed with a summary', function () {
    $run = AnalysisRun::factory()->create(['status' => 'running']);

    $run->markCompleted(['total' => 10, 'failed' => 1]);
    $run->refresh();

    expect($run->status)->toBe('completed')
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->summary)->toBe(['total' => 10, 'failed' => 1]);
});

it('marks a run as failed with an error message', function () {
    $run = AnalysisRun::factory()->create(['status' => 'running']);

    $run->markFailed('boom');
    $run->refresh();

    expect($run->status)->toBe('failed')
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->error_message)->toBe('boom');
});
