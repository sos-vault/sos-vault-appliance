<?php

use App\Enums\AiIntent;
use App\Services\AiChatService;

/** Invoke the private routed prompt builder. */
function buildPrompt(AiChatService $svc, ?int $did, int $userId, string $message): string
{
    $ref = new ReflectionMethod($svc, 'buildSystemPrompt');
    $ref->setAccessible(true);

    return $ref->invoke($svc, $did, $userId, $message);
}

function shouldUseTools(AiChatService $svc, AiIntent $intent, ?int $did): bool
{
    $ref = new ReflectionMethod($svc, 'shouldUseTools');
    $ref->setAccessible(true);

    return $ref->invoke($svc, $intent, $did);
}

it('gives the local model a manual-steer (no live data) for a case-analysis question', function () {
    $svc = new AiChatService('local', 'qwen2.5-1.5b-instruct', 512, 0.1, true);

    $prompt = buildPrompt($svc, 123, 1, 'why is this system out of memory?');

    expect($prompt)->toContain('Current-sosreport analysis is unavailable here')
        ->not->toContain('## Live Case System Data');
});

it('uses the analysis guide for a case-analysis question on a cloud provider', function () {
    $svc = new AiChatService('openai', 'gpt-4o', 512, 0.1, true);

    $prompt = buildPrompt($svc, 123, 1, 'why is this system running out of memory?');

    // The analysis guide is loaded; the live-data block is absent here only
    // because the test user has no open vault (path resolves to null).
    expect($prompt)->toContain('Analysis Guide')
        ->not->toContain('Current-sosreport analysis is unavailable here');
});

it('routes a sos-vault question to vault knowledge regardless of provider', function () {
    $svc = new AiChatService('local', 'qwen2.5-1.5b-instruct', 512, 0.1, true);

    $prompt = buildPrompt($svc, 123, 1, 'how do I upload a report and share a file?');

    expect($prompt)->toContain('sos-vault — Usage & Navigation Reference')
        ->not->toContain('## Live Case System Data');
});

it('uses tool-calling only for a case question, with a case open, on a cloud provider', function () {
    $cloud = new AiChatService('openai', 'gpt-4o', 512, 0.1, true);
    $local = new AiChatService('local', 'qwen2.5-1.5b-instruct', 512, 0.1, true);

    // Cloud + case-analysis + open case → agentic path.
    expect(shouldUseTools($cloud, AiIntent::CaseAnalysis, 123))->toBeTrue();

    // No case open → single-shot.
    expect(shouldUseTools($cloud, AiIntent::CaseAnalysis, null))->toBeFalse();
    // Non-case intent → single-shot.
    expect(shouldUseTools($cloud, AiIntent::SosVault, 123))->toBeFalse();
    // Local model (case analysis disabled, weak tool use) → single-shot.
    expect(shouldUseTools($local, AiIntent::CaseAnalysis, 123))->toBeFalse();
});

it('builds a tool-mode prompt that offers the fetch tool instead of inlining files', function () {
    $svc = new AiChatService('openai', 'gpt-4o', 512, 0.1, true);

    $ref = new ReflectionMethod($svc, 'buildToolSystemPrompt');
    $ref->setAccessible(true);
    // userId with no open vault: digest resolves empty, but the guide is always present.
    $prompt = $ref->invoke($svc, 123, 1, 'why is this system out of memory?');

    expect($prompt)->toContain('Analysis Guide')          // knowledge still loaded
        ->toContain('fetch_case_data')                     // the tool is offered
        ->not->toContain('## Live Case System Data');      // files are NOT pre-injected
});
