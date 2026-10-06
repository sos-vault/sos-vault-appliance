<?php

namespace App\Console\Commands;

use App\Contracts\AiChatServiceContract;
use App\Enums\AiIntent;
use App\Services\Ai\KnowledgeLoader;
use App\Services\Ai\ProviderProfile;
use Illuminate\Console\Command;

/**
 * Non-interactive health check for the Mil AI assistant. Reports the resolved
 * provider/profile, verifies every configured knowledge-base file is present on
 * disk, and shows the assembled prompt size per intent.
 *
 * This exists because a KB-less image (agent/ excluded from the build context)
 * made /sosvault answer generically while /sos and /linux — public knowledge the
 * model already has — still looked fine, which was slow to spot. Prefer this over
 * `php artisan tinker` for field troubleshooting: it needs no REPL / writable HOME
 * and is scriptable. Run inside the app container:
 *   docker exec ... php artisan ai:doctor [--ping]
 */
class AiDoctor extends Command
{
    protected $signature = 'ai:doctor {--ping : Also send a tiny live request to the configured provider}';

    protected $description = 'Diagnose the Mil AI assistant: provider config, knowledge-base files, and prompt assembly';

    public function handle(KnowledgeLoader $knowledge): int
    {
        $provider = (string) config('ai.provider', 'local');
        $profile = ProviderProfile::for($provider);

        $this->line('<info>Mil AI assistant — diagnostics</info>');
        $this->newLine();

        // --- Provider / profile ------------------------------------------------
        $this->line('Provider config');
        $this->table(['setting', 'value'], [
            ['provider', $provider],
            ['model', (string) config("ai.{$provider}.model", '—')],
            ['max_tokens', (string) config('ai.max_tokens', '—')],
            ['temperature', (string) config('ai.temperature', '—')],
            ['rate_limit_per_minute', (string) config('ai.rate_limit_per_minute', '—')],
            ['inject_case_context', config('ai.inject_case_context') ? 'true' : 'false'],
            ['ollama_tools', config('ai.ollama_tools') ? 'true' : 'false'],
            ['case_analysis_enabled', $profile->caseAnalysisEnabled ? 'true' : 'false'],
            ['max_knowledge_chars', (string) $profile->maxKnowledgeChars],
            ['per_file_cap', (string) $profile->perFileCap],
            ['history_turns', (string) $profile->historyTurns],
        ]);

        // --- Knowledge-base files ---------------------------------------------
        $dir = rtrim((string) config('ai.system_prompt_path', base_path('agent')), '/');
        $kb = (array) config('ai.knowledge', []);
        $this->newLine();
        $this->line("Knowledge base ({$dir})");

        $rows = [];
        $missing = 0;
        foreach ($kb as $area => $paths) {
            foreach ((array) $paths as $path) {
                if ($path === '') {
                    continue;
                }
                $full = $dir.'/'.$path;
                $ok = is_file($full);
                $missing += $ok ? 0 : 1;
                $rows[] = [$area, $path, $ok ? number_format(filesize($full)).' B' : '<error>MISSING</error>'];
            }
        }
        $this->table(['area', 'file', 'status'], $rows);

        // --- Prompt assembly per intent ---------------------------------------
        $this->newLine();
        $this->line('Assembled prompt size per intent');
        $promptRows = [];
        foreach ([
            [AiIntent::SosVault, 'how do I upload a sosreport?'],
            [AiIntent::SosCommand, 'how do I obfuscate a report?'],
            [AiIntent::Linux, 'how do I check disk usage?'],
            [AiIntent::CaseAnalysis, 'what is the overall system state?'],
        ] as [$intent, $question]) {
            $len = strlen($knowledge->loadFor($intent, $profile, $question));

            // SosVault & SosCommand carry app/tool knowledge that lives ONLY in the
            // KB — an empty prompt there means the knowledge base isn't shipping.
            $note = ($len === 0 && in_array($intent, [AiIntent::SosVault, AiIntent::SosCommand], true))
                ? '<error>EMPTY — KB not loaded</error>'
                : 'ok';
            $promptRows[] = [$intent->value, number_format($len).' chars', $note];
        }
        $this->table(['intent', 'prompt', 'note'], $promptRows);

        // --- Verdict -----------------------------------------------------------
        $this->newLine();
        if ($missing > 0) {
            $this->error("✗ {$missing} knowledge-base file(s) MISSING from {$dir}. Mil will answer app-specific "
                .'questions generically. Confirm agent/ ships in the image (not excluded by .dockerignore).');
        } else {
            $this->info('✓ All configured knowledge-base files are present.');
        }

        // --- Optional live ping ------------------------------------------------
        if ($this->option('ping')) {
            $this->newLine();
            $this->line("Pinging provider '{$provider}' (one small live request)…");
            try {
                $reply = app(AiChatServiceContract::class)->chat('ping', [], null, null, 0);
                $this->info('✓ Provider responded ('.strlen($reply).' chars).');
            } catch (\Throwable $e) {
                $this->error('✗ Provider ping failed: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
