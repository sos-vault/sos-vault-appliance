<?php

namespace App\Services\Ai;

use App\Enums\AiIntent;

/**
 * Deterministic, zero-token classifier. Decides which of the four knowledge
 * areas a user message belongs to so the prompt builder loads only the relevant
 * knowledge. Runs before any LLM call.
 *
 * Tie-break priority: CaseAnalysis (if a case is open) > SosVault > SosCommand > Linux.
 *
 * An explicit leading topic command ("/case", "/sosvault", "/sos", "/linux") — or
 * the legacy "Label:" prefix ("Case:", "SosVault:", …) — wins outright over keyword
 * scoring, letting a user pin the area deterministically when the small local model
 * would otherwise misroute or hallucinate.
 */
class IntentRouter
{
    /**
     * Leading "Label:" prefixes that force an intent. Matched case-insensitively
     * on the whole label token before the first colon; aliases are exact.
     *
     * @var array<string, array<int, string>>
     */
    private const INTENT_LABELS = [
        'sos_vault' => ['sosvault', 'sos-vault', 'sos vault', 'vault'],
        'sos_command' => ['soscommand', 'sos-command', 'sos command', 'sosreport', 'sos report', 'sos'],
        'linux' => ['linux'],
        'case_analysis' => ['case', 'analysis', 'analyse', 'analyze', 'diagnose', 'diagnosis'],
    ];

    /**
     * Analytical phrasing that signals the user wants the OPEN sosreport
     * diagnosed (not generic help). Weighted higher than plain topic words.
     */
    private const ANALYTICAL_PHRASES = [
        'why is', 'why are', 'why does', "what's wrong", 'what is wrong', 'whats wrong',
        'root cause', 'diagnose', 'troubleshoot this', 'out of memory', 'oom',
        'running out', 'any issues', 'any problems', 'is there a problem',
        'what is using', "what's using", 'whats using', 'which process',
        'too high', 'too many', 'high load', 'memory leak', 'is there a leak',
        'health check', "what's happening", 'analyze this', 'analyse this',
        'what is causing', 'whats causing', 'is this system healthy',
    ];

    /** Topic phrases that point at the analyzed system's metrics. */
    private const CASE_TOPIC_PHRASES = [
        'memory usage', 'cpu usage', 'disk usage', 'load average', 'swap usage',
        'top process', 'top processes', 'open files', 'dmesg', 'oom killer',
        'failed unit', 'failed units', 'disk full', 'inode', 'iowait',
    ];

    /** Deictic references to the analyzed system. Add weight but never gate alone. */
    private const SYSTEM_REFERENCES = [
        'this system', 'this host', 'this server', 'this machine', 'this box',
        'this node', 'the system', 'the host', 'the server', 'this case',
        'this report', 'the report', 'the sosreport', 'this sosreport',
    ];

    private const SOS_COMMAND_KEYWORDS = [
        'sos report', 'sosreport', 'sos collect', 'sos clean', '--clean', '--all-logs',
        '--batch', 'obfuscat', 'plugin', 'plugins', 'sos_extras', 'sos command',
        'generate a report', 'create a report', 'run sos', 'collect a report',
        'upload-url', 'case-id', 'sos.conf',
    ];

    private const SOS_VAULT_KEYWORDS = [
        'sos-vault', 'sosvault', 'vault', 'sidebar', 'menu', 'tab', 'dashboard',
        'compare', 'bookmark', 'share', 'shared', 'note', 'notes', 'api key',
        'jira', 'jsm', 'itsm', 'browse', 'summary tool', 'top tool', 'settings',
        'where do i find', 'where can i', 'how do i find', 'open the vault',
        'upload a report', 'upload my report', 'log in', 'login', 'two-factor', '2fa',
    ];

    public function classify(string $message, bool $caseOpen): AiIntent
    {
        // An explicit "Label:" prefix wins over keyword scoring. A CaseAnalysis
        // label only sticks when a case is actually open; otherwise fall through
        // to keyword classification of the remaining body.
        [$forced, $body] = $this->extractLabel($message);
        if ($forced !== null && ($forced !== AiIntent::CaseAnalysis || $caseOpen)) {
            return $forced;
        }
        if ($forced !== null) {
            $message = $body;
        }

        $text = mb_strtolower($message);

        $analytical = $this->countHits($text, self::ANALYTICAL_PHRASES);
        $caseTopics = $this->countHits($text, self::CASE_TOPIC_PHRASES);
        $sysRefs = $this->countHits($text, self::SYSTEM_REFERENCES);

        // Case analysis is only eligible with a real analytical/topic signal —
        // a bare "the server" must not steal app or how-to questions.
        $caseEligible = $caseOpen && ($analytical > 0 || $caseTopics > 0);
        $caseScore = $caseEligible ? ($analytical * 2 + $caseTopics + $sysRefs) : 0;

        $vaultScore = $this->countHits($text, self::SOS_VAULT_KEYWORDS);
        $sosScore = $this->countHits($text, self::SOS_COMMAND_KEYWORDS);

        // argmax with the documented tie-break order.
        $best = AiIntent::Linux;
        $bestScore = 0;

        foreach ([
            [AiIntent::CaseAnalysis, $caseScore],
            [AiIntent::SosVault, $vaultScore],
            [AiIntent::SosCommand, $sosScore],
        ] as [$intent, $score]) {
            if ($score > $bestScore) {
                $best = $intent;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Remove a recognised leading topic command ("/case …") or "Label:" prefix so
     * the routing token isn't sent to the model or counted in plugin/topic matching.
     * Unrecognised prefixes (and colons inside normal prose or URLs) are left untouched.
     */
    public function stripLabel(string $message): string
    {
        return $this->extractLabel($message)[1];
    }

    /**
     * The intent a user explicitly pinned via a leading "/command" or "Label:"
     * prefix, or null if none. Unlike classify(), this ignores keyword scoring and
     * the case-open guard — it reports the raw user intent so the caller can, e.g.,
     * refuse a /case question when no case is open.
     */
    public function forcedIntent(string $message): ?AiIntent
    {
        return $this->extractLabel($message)[0];
    }

    /**
     * Slash topic commands, mirroring the /help /clear command convention. Maps the
     * command word (sans slash) to its AiIntent key. "sosvault" must be tried before
     * "sos" so "/sosvault" is not mis-read as "/sos".
     *
     * @var array<string, string>
     */
    private const SLASH_LABELS = [
        'case' => 'case_analysis',
        'sosvault' => 'sos_vault',
        'sos' => 'sos_command',
        'linux' => 'linux',
    ];

    /**
     * Split a leading intent label off a message. Recognises both the slash topic
     * command form ("/case …") and the legacy "Label:" prefix form ("Case: …").
     *
     * @return array{0: ?AiIntent, 1: string} [forced intent or null, body]
     */
    private function extractLabel(string $message): array
    {
        // Slash topic command: "/case …", "/sosvault …", "/sos …", "/linux …".
        // Longest alias first so "/sosvault" wins over "/sos"; \b stops "/sosreport"
        // from matching "/sos".
        if (preg_match('#^\s*/(sosvault|linux|case|sos)\b\s*(.*)$#is', $message, $m)) {
            return [AiIntent::from(self::SLASH_LABELS[mb_strtolower($m[1])]), trim($m[2])];
        }

        // A short token (letters/spaces/hyphens) followed by ':' and a non-empty
        // body. The lazy token + anchored colon avoids eating colons mid-sentence.
        if (! preg_match('/^\s*([a-z][a-z \-]{1,15}?)\s*:\s*(\S.*)$/is', $message, $m)) {
            return [null, trim($message)];
        }

        $label = preg_replace('/\s+/', ' ', mb_strtolower(trim($m[1])));

        foreach (self::INTENT_LABELS as $intentKey => $aliases) {
            if (in_array($label, $aliases, true)) {
                return [AiIntent::from($intentKey), trim($m[2])];
            }
        }

        return [null, trim($message)];
    }

    /** @param array<int, string> $keywords */
    private function countHits(string $text, array $keywords): int
    {
        $hits = 0;

        foreach ($keywords as $kw) {
            // Single plain words need word boundaries ("note" must not match
            // "another"); phrases and tokens with punctuation use substring.
            if (preg_match('/^[a-z0-9]+$/', $kw)) {
                $hits += preg_match_all('/\b'.preg_quote($kw, '/').'\b/', $text);
            } elseif (str_contains($text, $kw)) {
                $hits++;
            }
        }

        return $hits;
    }
}
