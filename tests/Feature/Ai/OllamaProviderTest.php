<?php

use App\Enums\AiIntent;
use App\Services\Ai\ProviderProfile;
use App\Services\AiChatService;
use Prism\Prism\Enums\Provider;

/** Invoke the private tool-gating decision. */
function ollamaShouldUseTools(AiChatService $svc): bool
{
    $ref = new ReflectionMethod($svc, 'shouldUseTools');
    $ref->setAccessible(true);

    return $ref->invoke($svc, AiIntent::CaseAnalysis, 123);
}

// Feature D: on-prem Ollama is a first-class provider for customers hosting their
// own capable models (DeepSeek, Llama, …). It reuses the OpenAI-compatible driver
// (Ollama's /v1 endpoint) and gets the generous "default" profile with analysis on.

it('gives on-prem ollama the capable default profile with case analysis enabled', function () {
    $profile = ProviderProfile::for('ollama');

    expect($profile->caseAnalysisEnabled)->toBeTrue();
    expect($profile->maxKnowledgeChars)->toBe(24000);
    expect($profile->perFileCap)->toBe(4000);
    expect($profile->historyTurns)->toBe(6);
});

it('routes the ollama provider through the OpenAI-compatible OpenRouter driver', function () {
    $svc = new AiChatService('ollama', 'llama3.1', 512, 0.3, true);

    $ref = new ReflectionMethod($svc, 'resolveDriver');
    $ref->setAccessible(true);

    expect($ref->invoke($svc, 'ollama'))->toBe(Provider::OpenRouter);
});

it('exposes an ollama config block with an OpenAI-compatible /v1 base URL', function () {
    expect(config('ai.ollama.base_url'))->toEndWith('/v1');
    expect(config('ai.ollama.model'))->not->toBeEmpty();
});

it('keeps ollama on the single-shot path by default (tool-calling off)', function () {
    config()->set('ai.ollama_tools', false);
    $svc = new AiChatService('ollama', 'llama3.1', 512, 0.3, true);

    expect(ollamaShouldUseTools($svc))->toBeFalse();
});

it('lets ollama use the agentic tool path when tool-calling is enabled', function () {
    config()->set('ai.ollama_tools', true);
    $svc = new AiChatService('ollama', 'deepseek-r1', 512, 0.3, true);

    expect(ollamaShouldUseTools($svc))->toBeTrue();
});

it('never uses tools when case analysis is off, even with the ollama toggle on', function () {
    config()->set('ai.ollama_tools', true);
    // injectCaseContext = false disables case analysis entirely.
    $svc = new AiChatService('ollama', 'deepseek-r1', 512, 0.3, false);

    expect(ollamaShouldUseTools($svc))->toBeFalse();
});
