<?php

namespace App\Livewire;

use App\Contracts\AiChatServiceContract;
use App\Enums\AiIntent;
use App\Events\SendUserEmail;
use App\Exceptions\AiProviderException;
use App\Exceptions\AiRateLimitException;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\SupportCase;
use App\Models\Sysevent;
use App\Services\Ai\IntentRouter;
use App\Services\Ai\ProviderProfile;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class ChatWidget extends Component
{
    public bool $isOpen = false;

    /** True when rendered as a standalone pop-out window (route /mil), not the floating widget. */
    public bool $detached = false;

    public string $input = '';

    /** @var array<int, array{role: string, content: string, time: string}> */
    public array $messages = [];

    public bool $thinking = false;

    public ?int $did = null;

    public ?int $cid = null;

    /** Human case label (SupportCase->case, e.g. "alma9-SSUP2026") for the header. */
    public ?string $caseLabel = null;

    /** Tool page the open case was handed off from (Summary, Top, …) + open file. */
    public ?string $tool = null;

    public ?int $fid = null;

    /** Tracks an in-progress /suggest|/complain|/inquiry flow waiting for body text. */
    public ?string $pendingCommand = null;

    /**
     * Non-persisted banner shown when the assistant can't answer because the
     * local model isn't downloaded yet (appliance builds). Empty when the
     * assistant is usable.
     */
    public string $assistantNotice = '';

    private ?ChatSession $session = null;

    /** Session key holding the case currently open in Browse SOS Report. */
    private const OPEN_CASE_SESSION_KEY = 'mil_open_case';

    public function mount(bool $detached = false): void
    {
        $this->detached = $detached;

        // The pop-out window is the chat, so it opens expanded and stays open.
        if ($detached) {
            $this->isOpen = true;
        }

        $this->hydrateOpenCaseFromSession();
        $this->loadHistory();
        $this->refreshAssistantAvailability();
    }

    /**
     * The chat widget lives in the app layout, so a fresh instance mounts on
     * every page — and every tool window (Summary, file viewer, …) that the
     * sosbrowser opens. A same-window `chat-set-case` dispatch only reaches the
     * one window that fired it, so we also stash the open case in the session
     * when a case is loaded and read it back here. That is what lets Mil answer
     * case questions from any tool page, not just the Browse SOS Report tab.
     */
    private function hydrateOpenCaseFromSession(): void
    {
        $open = session(self::OPEN_CASE_SESSION_KEY);

        if (is_array($open) && ! empty($open['did']) && ! empty($open['cid'])) {
            $this->did = (int) $open['did'];
            $this->cid = (int) $open['cid'];
            $this->tool = $open['tool'] ?? null;
            $this->fid = ! empty($open['fid']) ? (int) $open['fid'] : null;
            $this->resolveCaseLabel();
        }
    }

    /** Look up the open case's human label (SupportCase->case) for the header. */
    private function resolveCaseLabel(): void
    {
        $this->caseLabel = $this->cid ? SupportCase::whereKey($this->cid)->value('case') : null;
    }

    public function toggle(): void
    {
        $this->isOpen = ! $this->isOpen;
    }

    #[On('chat-set-case')]
    public function setCase(int $did, int $cid): void
    {
        if ($this->did !== $did || $this->cid !== $cid) {
            $this->did = $did;
            $this->cid = $cid;
            $this->session = null;
            $this->pendingCommand = null;
            $this->resolveCaseLabel();
            $this->loadHistory();
            $this->refreshAssistantAvailability();

            // Persist so other windows/pages (tool popups, later navigations)
            // that mount their own widget adopt the same open case.
            session([self::OPEN_CASE_SESSION_KEY => ['did' => $did, 'cid' => $cid]]);
        }
    }

    public function send(): void
    {
        $message = trim($this->input);

        if ($message === '' || $this->thinking) {
            return;
        }

        $this->input = '';

        $router = app(IntentRouter::class);
        $forced = $router->forcedIntent($message);

        // Route slash commands without involving the LLM — EXCEPT the topic
        // commands (/case, /sosvault, /sos, /linux), which pin the knowledge area
        // for a normal AI question and fall through to the chat flow below.
        if (str_starts_with($message, '/') && $forced === null) {
            $this->handleCommand($message);

            return;
        }

        // If we're waiting for the body of a contact command, handle it.
        if ($this->pendingCommand !== null) {
            $this->handlePendingCommand($message);

            return;
        }

        // A /case question with no open case can't be answered — steer the user to
        // open a case instead of letting the model invent a generic reply.
        if ($forced === AiIntent::CaseAnalysis && $this->did === null) {
            $this->appendUserMessage($message);
            $this->appendBotMessage($this->noCaseText());

            return;
        }

        // A /case question with a case open, but the configured model is the small
        // local one (case analysis disabled). Rather than let it return a generic,
        // data-free reply that reads like a /sosvault answer, steer to a capable
        // cloud LLM — an immediate, zero-token gate.
        if ($forced === AiIntent::CaseAnalysis
            && $this->did !== null
            && ! ProviderProfile::for((string) config('ai.provider'))->caseAnalysisEnabled) {
            $this->appendUserMessage($message);
            $this->appendBotMessage($this->caseNeedsCloudText());

            return;
        }

        // A bare topic command ("/case" with nothing after it) has no question —
        // nudge the user to add one instead of sending an empty prompt.
        if ($forced !== null && trim($router->stripLabel($message)) === '') {
            $this->appendUserMessage($message);
            $this->appendBotMessage($this->topicCommandHintText());

            return;
        }

        // Vague openers ("what should I do?", "help", "hi") get a concrete,
        // zero-token starter instead of a hollow LLM reply.
        if ($this->isVagueOpener($message)) {
            $this->appendUserMessage($message);
            $this->appendBotMessage($this->starterText());
            $this->recordBotEvent('BOT_GENERIC', 'SUCCESS', 0, $message, null);

            return;
        }

        // Normal AI chat flow.
        $this->thinking = true;

        $user = auth()->user();
        $session = $this->getOrCreateSession();

        // Deterministic, zero-token routing — also picks the BOT_* telemetry type.
        $intent = $router->classify($message, $this->did !== null);

        ChatMessage::create([
            'session_id' => $session->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $message,
        ]);

        $this->messages[] = [
            'role' => 'user',
            'content' => $message,
            'time' => now()->format('H:i'),
        ];

        $history = array_map(
            fn ($m) => ['role' => $m['role'], 'content' => $m['content']],
            array_slice($this->messages, -11, 10)
        );

        /** @var AiChatServiceContract $ai */
        $ai = app(AiChatServiceContract::class);

        $assistant = null;
        $assistantIndex = null;
        $status = 'SUCCESS';
        $startedAt = microtime(true);

        try {
            $response = $ai->chat(
                userMessage: $message,
                history: $history,
                caseDirectoryId: $this->did,
                caseId: $this->cid,
                userId: $user->id,
            );

            $assistant = ChatMessage::create([
                'session_id' => $session->id,
                'user_id' => null,
                'role' => 'assistant',
                'content' => $response,
                'provider' => $ai->providerName(),
            ]);

            $this->messages[] = [
                'role' => 'assistant',
                'content' => $response,
                'time' => now()->format('H:i'),
                'sysevent_id' => null,
                'quality' => null,
            ];
            $assistantIndex = array_key_last($this->messages);
        } catch (AiRateLimitException $e) {
            $status = 'FAILED';
            $this->appendBotMessage($e->getMessage());
        } catch (AiProviderException $e) {
            $status = $this->isTimeout($e) ? 'TIMEDOUT' : 'FAILED';
            $this->appendBotMessage('The AI assistant is currently unavailable. Please try again in a moment.');
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $event = $this->recordBotEvent($intent->botEventType(), $status, $durationMs, $message, $ai->providerName());

        // Link the reply to its telemetry event so a later 👍/👎 can rate it.
        if ($assistant !== null && $event !== null) {
            $assistant->update(['sysevent_id' => $event->id]);

            if ($assistantIndex !== null) {
                $this->messages[$assistantIndex]['sysevent_id'] = $event->id;
            }
        }

        $session->touchActivity();
        $this->thinking = false;
    }

    /**
     * Record a 👍/👎 on an assistant reply by writing GOOD/BAD into its linked
     * BOT_* event's payload. Clicking the active rating again clears it. Only
     * replies with a telemetry event (real AI answers) are rateable.
     */
    public function rateMessage(int $index, string $quality): void
    {
        if (! in_array($quality, ['GOOD', 'BAD'], true)) {
            return;
        }

        $message = $this->messages[$index] ?? null;

        if (! is_array($message) || ($message['role'] ?? '') !== 'assistant' || empty($message['sysevent_id'])) {
            return;
        }

        $event = Sysevent::find($message['sysevent_id']);

        if (! $event) {
            return;
        }

        $payload = json_decode($event->payload ?? '', true) ?: [];
        $newQuality = ($payload['quality'] ?? null) === $quality ? null : $quality;

        $payload['quality'] = $newQuality;
        $event->update(['payload' => json_encode($payload)]);

        $this->messages[$index]['quality'] = $newQuality;
    }

    /**
     * Record a Mil usage event (BOT_* family) so the admin can measure usage and
     * response times. Response time, provider, tool/file and the (rateable)
     * quality live in the JSON payload; vault/case are resolved by addEvent().
     */
    private function recordBotEvent(string $type, string $status, int $durationMs, string $question, ?string $provider): ?Sysevent
    {
        $user = auth()->user();

        $payload = [
            'duration_ms' => $durationMs,
            'quality' => null,
            'provider' => $provider,
            'tool' => $this->tool,
            'fid' => $this->fid,
            'question' => Str::limit($question, 200),
        ];

        return addEvent($payload, $type, $status, 'ACTIVITY', $this->cid ?? 0, 0, $user->id, $user->id);
    }

    /**
     * Whether a provider failure was a timeout (vs a hard error) so the event is
     * recorded as TIMEDOUT rather than FAILED. The original cause is wrapped as the
     * exception's previous.
     */
    private function isTimeout(\Throwable $e): bool
    {
        $haystack = strtolower($e->getMessage().' '.($e->getPrevious()?->getMessage() ?? ''));

        foreach (['timed out', 'timeout', 'curl error 28', 'maximum execution time'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function clearHistory(): void
    {
        $session = $this->getOrCreateSession();
        $session->messages()->delete();
        $this->messages = [];
        $this->session = null;
        $this->pendingCommand = null;
    }

    private function handleCommand(string $raw): void
    {
        $cmd = strtolower(strtok(trim($raw), ' '));

        switch ($cmd) {
            case '/help':
                $this->appendUserMessage($raw);
                $this->appendBotMessage($this->helpText());
                break;

            case '/clear':
                $this->clearHistory();
                $this->appendBotMessage('Conversation cleared.');
                break;

            case '/suggest':
            case '/complain':
            case '/inquiry':
                // Contact-our-team commands only make sense on the hosted service.
                // Self-hosted installs have no "our team" to route to, so gate them.
                if (! isSaas()) {
                    $this->appendUserMessage($raw);
                    $this->appendBotMessage(
                        "`{$cmd}` isn't available in the self-hosted edition. For support, "
                        .'please contact your administrator. Type `/help` to see available commands.'
                    );
                    break;
                }

                $this->pendingCommand = ltrim($cmd, '/');
                $labels = ['suggest' => 'suggestion', 'complain' => 'complaint', 'inquiry' => 'inquiry'];
                $this->appendUserMessage($raw);
                $this->appendBotMessage(
                    'Please type your '.$labels[$this->pendingCommand].' and press Enter. I will forward it to our team.'
                );
                break;

            default:
                $this->appendUserMessage($raw);
                $this->appendBotMessage("Unknown command `{$cmd}`. Type `/help` to see available commands.");
        }
    }

    private function handlePendingCommand(string $body): void
    {
        $command = $this->pendingCommand;
        $this->pendingCommand = null;

        $this->appendUserMessage($body);

        $user = auth()->user();

        $subject = match ($command) {
            'suggest' => "[sos-vault] Suggestion from {$user->name}",
            'complain' => "[sos-vault] Complaint from {$user->name}",
            'inquiry' => "[sos-vault] Inquiry from {$user->name}",
        };

        $to = config('mail.from.address', 'admin@sos-vault.com');

        try {
            event(new SendUserEmail([
                'type' => 'internal',
                'to' => $to,
                'from' => $user->email,
                'subject' => $subject,
                'title' => $subject,
                'name' => $user->name,
                'username' => $user->username,
                'uid' => $user->id,
                'email' => $user->email,
                'plans' => $user->role->display_name ?? '—',
                'daysleft' => $user->daysLeftOnTrial(),
                'since' => Carbon::parse($user->created_at)->toDateString(),
                'body' => $body,
            ]));

            $this->appendBotMessage("Your {$command} has been sent to our team. Thank you!");
        } catch (\Throwable $e) {
            Log::error("ChatWidget {$command} email failed: {$e->getMessage()}");
            $this->appendBotMessage(
                "Sorry, I could not send your {$command} right now. Please contact us directly at {$to}."
            );
        }
    }

    private function appendUserMessage(string $content): void
    {
        $session = $this->getOrCreateSession();

        ChatMessage::create([
            'session_id' => $session->id,
            'user_id' => auth()->id(),
            'role' => 'user',
            'content' => $content,
        ]);

        $this->messages[] = [
            'role' => 'user',
            'content' => $content,
            'time' => now()->format('H:i'),
        ];
    }

    private function appendBotMessage(string $content): void
    {
        $session = $this->getOrCreateSession();

        ChatMessage::create([
            'session_id' => $session->id,
            'user_id' => null,
            'role' => 'assistant',
            'content' => $content,
        ]);

        $this->messages[] = [
            'role' => 'assistant',
            'content' => $content,
            'time' => now()->format('H:i'),
        ];
    }

    /**
     * True for short, generic openers that carry no real question, so we can
     * answer with a helpful starter rather than paying for an empty LLM turn.
     * Kept to exact matches (after trimming punctuation) to avoid hijacking real
     * questions that merely start with "how do i…".
     */
    private function isVagueOpener(string $message): bool
    {
        $m = rtrim(strtolower(trim($message)), " \t?.!");

        return in_array($m, [
            'hi', 'hello', 'hey', 'help', 'help me', 'start', 'get started', 'begin',
            'what now', 'what next', 'what do i do', 'what should i do', 'what shall i do',
            'what should i do now', 'what shall i do now', 'how do i start', 'where do i start',
            'what can you do', 'what do you do', 'what can i ask', 'what can i do',
        ], true);
    }

    private function starterText(): string
    {
        return <<<'MD'
        **I'm Mil — I can help with four things:**

        1. **sos-vault** — using the app: upload, browse, tools, settings → `/sosvault`
        2. **The `sos` command** — generating/uploading reports, options, plugins → `/sos`
        3. **Linux** — commands, logs, diagnostics → `/linux`
        4. **Your open sosreport** — analysis of the case you have open → `/case`

        **Tip:** start with a command so I focus on the right area:
        - `/sosvault how do I upload a sosreport?`
        - `/sos how do I limit log size to 10MB?`
        - `/linux how do I check disk usage?`
        - `/case how many processes are running?`

        Or just ask naturally. Type `/help` for commands.
        MD;
    }

    /**
     * Steer shown when the user asks a /case question with no sosreport open.
     * Answering would only invite a hallucinated, data-free reply.
     */
    private function noCaseText(): string
    {
        return <<<'MD'
        I can only answer **`/case`** questions when a sosreport is open — there's no
        case data to analyse right now. Open one first from **Browse SOS Report**
        (pick a vault, then a case), then ask me again, e.g.
        `/case what is the overall system state?`
        MD;
    }

    /**
     * Steer shown when the user asks a /case question but the configured model is
     * the small local one, which can't analyse sosreports. Points them to have an
     * administrator connect a capable cloud provider instead of returning a generic,
     * data-free answer.
     */
    private function caseNeedsCloudText(): string
    {
        return <<<'MD'
        I can only answer **`/case`** questions when a more capable external LLM is
        configured. Ask your administrator to connect a cloud AI provider (OpenAI or
        Anthropic) under **Admin → Manage Settings → AI Assistant**, then try again, e.g.
        `/case what is the overall system state?`
        MD;
    }

    /** Nudge shown when a topic command is sent with no question after it. */
    private function topicCommandHintText(): string
    {
        return <<<'MD'
        Add your question after the command, for example:
        - `/case what is the overall system state?`
        - `/linux what does load average mean?`
        - `/sos how do I limit log size to 10MB?`
        - `/sosvault how do I share a file?`
        MD;
    }

    private function helpText(): string
    {
        $topics = <<<'MD'
        **Ask about a specific area** — begin your question with one of these to get a
        focused, accurate answer (or just ask naturally and I'll route it for you):
        `/case` — the sosreport you have open: load, memory, CPU, disk, processes, logs…
        `/linux` — general Linux: commands, logs, diagnostics
        `/sos` — the `sos` command: generating, uploading and cleaning reports, plugins
        `/sosvault` — using sos-vault: uploading, browsing, tools, sharing, settings

        **Utility**
        `/clear` — Clear the chat history
        `/help` — Show this help message
        MD;

        $contact = <<<'MD'


        **Contact us**
        `/suggest` — Send a suggestion
        `/complain` — File a complaint
        `/inquiry` — Ask our team about sos-vault
        MD;

        $examples = <<<'MD'


        Examples:
        - `/sosvault how do I share a file?`
        - `/sos how do I limit log size to 10MB?`
        - `/linux what does the load average mean?`
        - `/case how many processes are running?`
        MD;

        return $topics.(isSaas() ? $contact : '').$examples;
    }

    /**
     * Populate $assistantNotice when the local AI model isn't available so the
     * user understands why the bot can't answer and who can fix it. Only the
     * appliance build ships a local model (ModelProvisionService), so the check
     * is a no-op on SaaS/master and whenever a cloud provider is configured.
     */
    private function refreshAssistantAvailability(): void
    {
        $this->assistantNotice = '';

        if (config('ai.provider') !== 'local') {
            return; // a cloud provider is configured — the assistant works.
        }

        $service = 'App\\Services\\ModelProvisionService';
        if (! class_exists($service) || app($service)->isInstalled()) {
            return;
        }

        $notice = "⚠️ The AI assistant isn't ready yet — its local language model hasn't been downloaded. "
            .'An administrator can enable it from **Admin → Software Updates → Download AI model**.';

        if (function_exists('applianceLicensed') && applianceLicensed()) {
            $notice .= ' Alternatively, an administrator can connect a cloud AI provider under '
                .'**Admin → Manage Settings → AI Assistant**.';
        }

        $this->assistantNotice = $notice;
    }

    private function loadHistory(): void
    {
        $session = $this->getOrCreateSession();

        $this->messages = $session->messages()
            ->with('sysevent')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ChatMessage $m) => [
                'role' => $m->role,
                'content' => $m->content,
                'time' => $m->created_at->format('H:i'),
                'sysevent_id' => $m->sysevent_id,
                'quality' => $m->sysevent
                    ? (json_decode($m->sysevent->payload ?? '', true)['quality'] ?? null)
                    : null,
            ])
            ->toArray();
    }

    private function getOrCreateSession(): ChatSession
    {
        if ($this->session instanceof ChatSession) {
            return $this->session;
        }

        $user = auth()->user();

        $this->session = ChatSession::firstOrCreate(
            [
                'user_id' => $user->id,
                'case_directory_id' => $this->did,
                'case_id' => $this->cid,
                'is_group' => false,
            ],
            [
                'title' => $this->did ? "Case #{$this->cid}" : 'General Help',
                'last_activity_at' => now(),
            ]
        );

        return $this->session;
    }

    public function render(): View
    {
        return view('livewire.chat-widget');
    }
}
