<?php

use App\Services\Ai\ProviderProfile;

it('disables current-sosreport analysis on the local model', function () {
    expect(ProviderProfile::for('local')->caseAnalysisEnabled)->toBeFalse();
});

it('enables current-sosreport analysis on cloud providers', function () {
    expect(ProviderProfile::for('openai')->caseAnalysisEnabled)->toBeTrue()
        ->and(ProviderProfile::for('anthropic')->caseAnalysisEnabled)->toBeTrue();
});

it('gives the local model a tighter budget than cloud providers', function () {
    $local = ProviderProfile::for('local');
    $cloud = ProviderProfile::for('openai');

    expect($local->maxKnowledgeChars)->toBeLessThan($cloud->maxKnowledgeChars)
        ->and($local->perFileCap)->toBeLessThan($cloud->perFileCap)
        ->and($local->historyTurns)->toBeLessThanOrEqual($cloud->historyTurns);
});

it('falls back to the default profile for an unknown provider', function () {
    $unknown = ProviderProfile::for('mystery');

    expect($unknown->caseAnalysisEnabled)->toBeTrue()
        ->and($unknown->maxKnowledgeChars)->toBe(config('ai.profiles.default.max_knowledge_chars'));
});
