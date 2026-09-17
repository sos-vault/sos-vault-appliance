<?php

use App\Contracts\AiChatServiceContract;
use App\Exceptions\AiRateLimitException;
use App\Services\AiChatService;
use Illuminate\Support\Facades\RateLimiter;
use Prism\Prism\Facades\Prism;

beforeEach(function () {
    config([
        'ai.provider' => 'local',
        'ai.local.model' => 'qwen2.5-3b-instruct',
        'ai.max_tokens' => 512,
        'ai.temperature' => 0.3,
        'ai.inject_case_context' => false,
        'ai.rate_limit_per_minute' => 5,
        'ai.system_prompt_path' => base_path('agent'),
        'ai.context_files' => [],
    ]);

    RateLimiter::clear('ai_chat_1');
    RateLimiter::clear('ai_chat_2');
    RateLimiter::clear('ai_chat_3');
    RateLimiter::clear('ai_chat_4');
    RateLimiter::clear('ai_chat_999');
});

it('resolves AiChatServiceContract from the container', function () {
    expect(app(AiChatServiceContract::class))->toBeInstanceOf(AiChatService::class);
});

it('returns provider name', function () {
    expect(app(AiChatServiceContract::class)->providerName())->toBe('local/qwen2.5-3b-instruct');
});

it('returns a string response from the LLM', function () {
    Prism::fake(); // returns empty string by default

    $response = app(AiChatServiceContract::class)->chat(
        userMessage: 'What is the system uptime?',
        history: [],
        caseDirectoryId: null,
        caseId: null,
        userId: 1,
    );

    expect($response)->toBeString();
});

it('returns gibberish reply without calling LLM for nonsense input', function () {
    $fake = Prism::fake();

    $response = app(AiChatServiceContract::class)->chat(
        userMessage: 'kzxqvbnmwrtp',
        history: [],
        caseDirectoryId: null,
        caseId: null,
        userId: 2,
    );

    expect($response)->toContain("didn't quite understand");
    $fake->assertCallCount(0);
});

it('throws AiRateLimitException after exceeding the configured rate limit', function () {
    config(['ai.rate_limit_per_minute' => 2]);
    Prism::fake();

    $service = app(AiChatServiceContract::class);
    $service->chat('What is load average?', [], null, null, 999);
    $service->chat('What is disk usage?', [], null, null, 999);

    expect(fn () => $service->chat('What is memory?', [], null, null, 999))
        ->toThrow(AiRateLimitException::class);
});

it('strips HTML tags from user input before sending to LLM', function () {
    $fake = Prism::fake();

    app(AiChatServiceContract::class)->chat(
        userMessage: '<b>Hello</b> <script>alert("xss")</script>world',
        history: [],
        caseDirectoryId: null,
        caseId: null,
        userId: 3,
    );

    $fake->assertRequest(function (array $recorded) {
        $messages = $recorded[0]->messages();
        $last = end($messages);
        expect($last->text())->not->toContain('<script>');

        return true;
    });
});

it('strips the routing label from the message sent to the LLM', function () {
    $fake = Prism::fake();

    app(AiChatServiceContract::class)->chat(
        userMessage: 'SosCommand: how do I limit log size to 10MB?',
        history: [],
        caseDirectoryId: null,
        caseId: null,
        userId: 7,
    );

    $fake->assertRequest(function (array $recorded) {
        $messages = $recorded[0]->messages();
        $last = end($messages);
        expect($last->text())
            ->toBe('how do I limit log size to 10MB?')
            ->not->toContain('SosCommand:');

        return true;
    });
});

it('includes history messages in the request to the LLM', function () {
    $fake = Prism::fake();

    app(AiChatServiceContract::class)->chat(
        userMessage: 'And the disk?',
        history: [
            ['role' => 'user',      'content' => 'What is the CPU usage?'],
            ['role' => 'assistant', 'content' => 'CPU is at 45%.'],
        ],
        caseDirectoryId: null,
        caseId: null,
        userId: 4,
    );

    // system prompt + 2 history turns + 1 new user message = at least 3
    $fake->assertRequest(function (array $recorded) {
        expect(count($recorded[0]->messages()))->toBeGreaterThanOrEqual(3);

        return true;
    });
});
