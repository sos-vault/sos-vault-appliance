<?php

namespace App\Services\Ai;

/**
 * Per-provider budget + capability profile. The small local CPU model gets tight
 * token budgets and has current-sosreport analysis disabled; cloud models get
 * generous budgets and full analysis. Values are tunable via config('ai.profiles').
 */
class ProviderProfile
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $caseAnalysisEnabled,
        public readonly int $maxKnowledgeChars,
        public readonly int $perFileCap,
        public readonly int $historyTurns,
    ) {}

    public static function for(string $provider): self
    {
        $profiles = config('ai.profiles', []);
        $cfg = array_merge(
            $profiles['default'] ?? [],
            $profiles[$provider] ?? [],
        );

        return new self(
            provider: $provider,
            caseAnalysisEnabled: (bool) ($cfg['case_analysis_enabled'] ?? true),
            maxKnowledgeChars: (int) ($cfg['max_knowledge_chars'] ?? 8000),
            perFileCap: (int) ($cfg['per_file_cap'] ?? 2500),
            historyTurns: (int) ($cfg['history_turns'] ?? 4),
        );
    }
}
