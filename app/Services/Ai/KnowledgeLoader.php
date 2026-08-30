<?php

namespace App\Services\Ai;

use App\Enums\AiIntent;
use Illuminate\Support\Facades\Log;

/**
 * Loads only the knowledge relevant to the routed intent, within the provider's
 * character budget. Instructions are always included; the Linux area carries no
 * extra doc (models know Linux — saves tokens); case-analysis on a provider that
 * has it disabled gets a short manual-steer instead of the analysis guide.
 */
class KnowledgeLoader
{
    private const MAX_PLUGIN_MATCHES = 3;

    public function loadFor(AiIntent $intent, ProviderProfile $profile, string $userMessage): string
    {
        $dir = rtrim(config('ai.system_prompt_path', base_path('agent')), '/');
        $kb = config('ai.knowledge', []);
        $budget = $profile->maxKnowledgeChars;

        $parts = [];

        $instructions = $this->read($dir.'/'.($kb['instructions'] ?? 'instructions.md'));
        if ($instructions !== '') {
            $parts[] = $instructions;
            $budget -= strlen($instructions);
        }

        foreach ($this->areaParts($intent, $profile, $userMessage, $dir, $kb) as $part) {
            if ($part === '' || $budget <= 0) {
                continue;
            }
            if (strlen($part) > $budget) {
                $part = substr($part, 0, $budget)."\n... [truncated]";
                $budget = 0;
            } else {
                $budget -= strlen($part);
            }
            $parts[] = $part;
        }

        return implode("\n\n---\n\n", array_filter($parts));
    }

    /** @return array<int, string> */
    private function areaParts(AiIntent $intent, ProviderProfile $profile, string $userMessage, string $dir, array $kb): array
    {
        return match ($intent) {
            AiIntent::SosVault => $this->readArea($dir, $kb['sos_vault'] ?? []),
            AiIntent::SosCommand => [
                ...$this->readArea($dir, $kb['sos_command'] ?? []),
                $this->pluginLookup($dir.'/'.($kb['plugins_lookup'] ?? ''), $userMessage),
            ],
            AiIntent::Linux => [],
            AiIntent::CaseAnalysis => $profile->caseAnalysisEnabled
                ? $this->readArea($dir, $kb['case_analysis'] ?? [])
                : [$this->caseAnalysisDisabledSteer()],
        };
    }

    /**
     * Read one area's knowledge, which may be a single file path (string) or an
     * ordered list of files (array). Files are returned in order so a branch can
     * front-load the highest-priority doc within the provider's char budget
     * (e.g. the appliance loads its operator FAQ before the shared app guide).
     *
     * @param  string|array<int, string>  $paths
     * @return array<int, string>
     */
    private function readArea(string $dir, string|array $paths): array
    {
        $parts = [];
        foreach ((array) $paths as $path) {
            if ($path === '') {
                continue;
            }
            $full = $dir.'/'.$path;
            if (! is_file($full)) {
                // A configured KB file is missing from the deployment, so this area
                // falls back to bare instructions and the model answers generically.
                // Log it so a stale/incomplete build (e.g. agent/kb not shipped, or a
                // config:cache pointing at an old path) is diagnosable from the logs.
                Log::warning("Mil KnowledgeLoader: configured KB file not found: {$full}");

                continue;
            }
            $parts[] = $this->read($full);
        }

        return $parts;
    }

    private function read(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        return trim(mb_convert_encoding((string) file_get_contents($path), 'UTF-8', 'UTF-8'));
    }

    /**
     * When the user asks about a named sos plugin, inject that plugin's catalog
     * entry. Gated on the word "plugin" so generic words (kernel, memory, ...)
     * that happen to be plugin names don't pull in noise.
     */
    private function pluginLookup(string $path, string $userMessage): string
    {
        $text = mb_strtolower($userMessage);
        if (! str_contains($text, 'plugin') || ! is_file($path)) {
            return '';
        }

        $data = json_decode((string) file_get_contents($path), true);
        $plugins = $data['sos_plugins'] ?? null;
        if (! is_array($plugins)) {
            return '';
        }

        $matches = [];
        foreach ($plugins as $plugin) {
            $name = $plugin['name'] ?? '';
            if ($name === '' || strlen($name) < 3) {
                continue;
            }
            if (preg_match('/\b'.preg_quote(mb_strtolower($name), '/').'\b/', $text)) {
                $matches[] = $plugin;
            }
        }

        if ($matches === []) {
            return '';
        }

        // Prefer the most specific (longest) names; cap the count.
        usort($matches, fn ($a, $b) => strlen($b['name']) <=> strlen($a['name']));
        $matches = array_slice($matches, 0, self::MAX_PLUGIN_MATCHES);

        $lines = ['## Referenced sos plugins'];
        foreach ($matches as $plugin) {
            $desc = trim(($plugin['description'] ?? '').' — '.($plugin['details'] ?? ''), ' —');
            $lines[] = "- **{$plugin['name']}**: {$desc}";
        }

        return implode("\n", $lines);
    }

    private function caseAnalysisDisabledSteer(): string
    {
        return "## Current-sosreport analysis is unavailable here\n"
            .'Automatic analysis of the uploaded sosreport is not available on the local AI '
            .'assistant. Tell the user this kind of analysis requires the cloud AI assistant '
            .'(an administrator can enable it in Settings), and guide them to inspect the report '
            .'manually using the Tools menu — **Summary** (colour-coded health badges) and '
            .'**Top** — plus the relevant files. Continue to answer general sos-vault, sos '
            .'command, and Linux questions normally.';
    }
}
