<?php

namespace App\Services;

use App\Contracts\AiChatServiceContract;
use App\Enums\AiIntent;
use App\Exceptions\AiProviderException;
use App\Exceptions\AiRateLimitException;
use App\Models\UserToken;
use App\Services\Ai\CaseContextBuilder;
use App\Services\Ai\CaseDataTool;
use App\Services\Ai\IntentRouter;
use App\Services\Ai\KnowledgeLoader;
use App\Services\Ai\ProviderProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class AiChatService implements AiChatServiceContract
{
    private readonly ProviderProfile $profile;

    private readonly IntentRouter $router;

    private readonly KnowledgeLoader $knowledge;

    private readonly CaseContextBuilder $caseContext;

    private readonly CaseDataTool $caseDataTool;

    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly float $temperature,
        private readonly bool $injectCaseContext,
        ?IntentRouter $router = null,
        ?KnowledgeLoader $knowledge = null,
        ?CaseContextBuilder $caseContext = null,
        ?ProviderProfile $profile = null,
        ?CaseDataTool $caseDataTool = null,
    ) {
        $this->router = $router ?? new IntentRouter;
        $this->knowledge = $knowledge ?? new KnowledgeLoader;
        $this->caseContext = $caseContext ?? new CaseContextBuilder;
        $this->profile = $profile ?? ProviderProfile::for($provider);
        $this->caseDataTool = $caseDataTool ?? new CaseDataTool;
    }

    public function chat(
        string $userMessage,
        array $history,
        ?int $caseDirectoryId,
        ?int $caseId,
        int $userId,
    ): string {
        $this->enforceRateLimit($userId);

        $userMessage = $this->sanitize($userMessage);

        if ($this->isGibberish($userMessage)) {
            return "I didn't quite understand that. Could you rephrase your question?";
        }

        // Route once, on the labelled message ("Case:" etc. steers the router).
        $caseOpen = $caseDirectoryId !== null && $this->injectCaseContext;
        $intent = $this->router->classify($userMessage, $caseOpen);
        // The model itself should only see the question, not the routing label.
        $clean = $this->router->stripLabel($userMessage);

        if ($this->shouldUseTools($intent, $caseDirectoryId)) {
            $response = $this->chatWithTools($caseDirectoryId, $userId, $userMessage, $clean, $history);
        } else {
            $systemPrompt = $this->buildSystemPrompt($caseDirectoryId, $userId, $userMessage);
            $messages = $this->buildMessages($systemPrompt, $history, $clean);
            $response = $this->callProvider($messages);
        }

        $this->trackUsage($userId, $response);

        return $response->text ?? '';
    }

    /**
     * Whether to use the agentic fetch_case_data tool: a case-analysis question,
     * with a case open and enabled, on a tool-capable provider. The local CPU
     * model has case analysis disabled and no reliable tool-calling, so it always
     * takes the single-shot path.
     */
    private function shouldUseTools(AiIntent $intent, ?int $caseDirectoryId): bool
    {
        return $intent === AiIntent::CaseAnalysis
            && $caseDirectoryId !== null
            && $this->injectCaseContext
            && $this->profile->caseAnalysisEnabled
            && $this->providerSupportsTools();
    }

    /**
     * Cloud providers tool-call reliably, so they always get the agentic path.
     * On-prem Ollama runs a customer-chosen model whose tool-call ability we
     * cannot know, so it is opt-in via the "Ollama supports tool-calling" setting
     * — off by default, when it falls back to the robust single-shot path.
     */
    private function providerSupportsTools(): bool
    {
        if (in_array($this->provider, ['openai', 'anthropic'], true)) {
            return true;
        }

        return $this->provider === 'ollama' && (bool) config('ai.ollama_tools', false);
    }

    /**
     * Primary path: let the model pull case data on demand via fetch_case_data,
     * correlating across sources. On any tool-calling failure, degrade to the
     * single-shot selective context so the user still gets an answer.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function chatWithTools(int $caseDirectoryId, int $userId, string $userMessage, string $clean, array $history): object
    {
        $systemPrompt = $this->buildToolSystemPrompt($caseDirectoryId, $userId, $clean);
        $messages = $this->buildMessages($systemPrompt, $history, $clean);

        try {
            return $this->callProviderWithTools($messages, $caseDirectoryId, $userId);
        } catch (AiProviderException $e) {
            Log::info('AI tool-calling failed; falling back to single-shot context: '.$e->getMessage());

            // Re-route on the labelled message so the fallback still injects the
            // selective case context (the "Case:" label forced CaseAnalysis).
            $fallbackPrompt = $this->buildSystemPrompt($caseDirectoryId, $userId, $userMessage);
            $messages = $this->buildMessages($fallbackPrompt, $history, $clean);

            return $this->callProvider($messages);
        }
    }

    public function providerName(): string
    {
        return $this->provider.'/'.$this->model;
    }

    /**
     * Routes the question to one of four knowledge areas, loads only that area's
     * knowledge within the provider's budget, and (for current-sosreport
     * analysis on a capable provider) appends the selective live case context.
     */
    private function buildSystemPrompt(?int $caseDirectoryId, int $userId, string $userMessage): string
    {
        $caseOpen = $caseDirectoryId !== null && $this->injectCaseContext;
        $intent = $this->router->classify($userMessage, $caseOpen);

        // Strip any routing label so plugin/topic matching sees only the question.
        $clean = $this->router->stripLabel($userMessage);

        $parts = [$this->knowledge->loadFor($intent, $this->profile, $clean)];

        if ($intent === AiIntent::CaseAnalysis
            && $caseDirectoryId
            && $this->injectCaseContext
            && $this->profile->caseAnalysisEnabled
        ) {
            $context = $this->caseContext->build($caseDirectoryId, $userId, $clean, $this->profile);
            if ($context !== '') {
                $parts[] = $context;
            }
        }

        return implode("\n\n---\n\n", array_filter($parts));
    }

    /**
     * Tool-mode system prompt: the analysis knowledge + a compact health digest
     * (if present) + the catalog guide telling the model what data exists, how it
     * correlates, and to pull it via fetch_case_data. The specific case files are
     * NOT injected here — the model fetches them on demand.
     */
    private function buildToolSystemPrompt(int $caseDirectoryId, int $userId, string $clean): string
    {
        $parts = [$this->knowledge->loadFor(AiIntent::CaseAnalysis, $this->profile, $clean)];

        $digest = $this->caseContext->digestFor($caseDirectoryId, $userId);
        if ($digest !== '') {
            $parts[] = "## System health digest\n\nHigh-signal summary of the analysed system:\n```json\n{$digest}\n```";
        }

        $parts[] = $this->caseDataTool->catalogGuide();

        return implode("\n\n---\n\n", array_filter($parts));
    }

    /** @param array<int, array{role: string, content: string}> $history */
    private function buildMessages(string $systemPrompt, array $history, string $userMessage): array
    {
        $messages = [];

        if ($systemPrompt !== '') {
            $messages[] = new SystemMessage($this->utf8Clean($systemPrompt));
        }

        // Keep only the last N messages (per provider profile) to control prefill
        // cost. The small CPU model has a tight context budget; growing history
        // causes inference timeouts. Cloud models allow more.
        $history = array_slice($history, -$this->profile->historyTurns);

        foreach ($history as $turn) {
            $content = $this->utf8Clean($turn['content']);
            $messages[] = match ($turn['role']) {
                'user' => new UserMessage($content),
                'assistant' => new AssistantMessage($content),
                default => null,
            };
        }

        $messages = array_values(array_filter($messages));
        $messages[] = new UserMessage($this->utf8Clean($userMessage));

        return $messages;
    }

    /** Strip invalid UTF-8 bytes so Guzzle's json_encode never fails on message content. */
    private function utf8Clean(string $text): string
    {
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    private function callProvider(array $messages): object
    {
        $driver = $this->resolveDriver($this->provider);

        try {
            return Prism::text()
                ->using($driver, $this->model)
                ->withMessages($messages)
                ->withMaxTokens($this->maxTokens)
                ->usingTemperature($this->temperature)
                ->generate();
        } catch (\Throwable $e) {
            Log::error('AI primary provider failed: '.$e->getMessage());

            throw new AiProviderException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Agentic call: the model may invoke fetch_case_data across several steps to
     * pull and correlate case data before answering. Throws AiProviderException
     * on failure so the caller can degrade to the single-shot path.
     *
     * @param  array<int, mixed>  $messages
     */
    private function callProviderWithTools(array $messages, int $caseDirectoryId, int $userId): object
    {
        $driver = $this->resolveDriver($this->provider);
        $tool = $this->caseDataTool->prismTool($caseDirectoryId, $userId, $this->profile);

        try {
            return Prism::text()
                ->using($driver, $this->model)
                ->withMessages($messages)
                ->withMaxTokens($this->maxTokens)
                ->usingTemperature($this->temperature)
                ->withTools([$tool])
                ->withMaxSteps(6)
                ->generate();
        } catch (\Throwable $e) {
            Log::error('AI tool-calling provider failed: '.$e->getMessage());

            throw new AiProviderException($e->getMessage(), 0, $e);
        }
    }

    private function resolveDriver(string $provider): Provider|string
    {
        return match ($provider) {
            'openai' => Provider::OpenAI,
            'anthropic' => Provider::Anthropic,
            // OpenRouter driver uses /v1/chat/completions — compatible with llama.cpp server.
            // The OpenAI driver uses /v1/responses (new Responses API) which llama.cpp does not implement.
            // On-prem Ollama exposes the same /v1/chat/completions API, so it shares this driver.
            'local', 'ollama' => Provider::OpenRouter,
            default => throw new \InvalidArgumentException("Unknown AI provider: {$provider}"),
        };
    }

    private function enforceRateLimit(int $userId): void
    {
        $key = "ai_chat_{$userId}";
        $limit = config('ai.rate_limit_per_minute', 5);

        $allowed = RateLimiter::attempt($key, $limit, fn () => true, 60);

        if (! $allowed) {
            throw new AiRateLimitException('Too many messages. Please wait a moment before sending another.');
        }
    }

    private function sanitize(string $input): string
    {
        $input = strip_tags($input);
        $input = preg_replace('/\s+/', ' ', trim($input));

        return mb_substr($input, 0, 2000);
    }

    private function isGibberish(string $input): bool
    {
        if (mb_strlen(trim($input)) < 3) {
            return true;
        }

        $letters = preg_replace('/[^a-zA-Z]/', '', $input);
        if (strlen($letters) > 6) {
            $vowels = preg_match_all('/[aeiouAEIOU]/', $letters);
            if ($vowels === 0 || ($vowels / strlen($letters)) < 0.05) {
                return true;
            }
        }

        $wordChars = preg_match_all('/\w/', $input);
        $nonWordChars = preg_match_all('/[^\w\s]/', $input);
        if ($wordChars > 0 && ($nonWordChars / $wordChars) > 0.6) {
            return true;
        }

        return false;
    }

    private function trackUsage(int $userId, object $response): void
    {
        try {
            $inputTokens = $response->usage?->promptTokens ?? 0;
            $outputTokens = $response->usage?->completionTokens ?? 0;

            UserToken::addTokens($userId, $inputTokens, $outputTokens);

            Log::info("AI usage user={$userId} provider={$this->provider} in={$inputTokens} out={$outputTokens}");
        } catch (\Throwable $e) {
            Log::warning('AI token tracking failed: '.$e->getMessage());
        }
    }
}
