<?php

namespace App\Services\Compliance;

use App\Models\AnalysisRun;
use App\Models\ComplianceFinding;
use App\Models\SupportCase;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use App\Services\Compliance\Rulesets\CisRuleset;
use App\Services\Compliance\Rulesets\DockerBenchRuleset;
use App\Services\Compliance\Rulesets\ExposureRuleset;
use App\Services\Compliance\Rulesets\StigRuleset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a single compliance analysis run for a case: extract facts,
 * evaluate all four rulesets, persist findings, summarize, deliver
 * notifications. This feature is unlicensed by design — no checkAccess()/
 * applianceLicensed() gate anywhere in this class or anything it calls.
 */
class ComplianceAnalysisService
{
    private const RULESETS = [
        'cis' => CisRuleset::class,
        'stig' => StigRuleset::class,
        'docker_bench' => DockerBenchRuleset::class,
        'exposure' => ExposureRuleset::class,
    ];

    public function analyzeCase(SupportCase $case, DataTools $dtools, VaultTools $vtools, string $triggeredBy = 'unpack'): AnalysisRun
    {
        $run = AnalysisRun::create([
            'case_id' => $case->id,
            'vault_id' => $case->vault_id,
            'dir_id' => $case->file_id,
            'status' => 'running',
            'triggered_by' => $triggeredBy,
            'started_at' => now(),
        ]);

        addEvent(
            (object) ['run_id' => $run->id, 'case' => $case->case, 'triggered_by' => $triggeredBy],
            'COMPLIANCE_RUN',
            'SUCCESS',
            'NORMAL',
            $case->id,
            $case->vault_id,
            $case->owner,
            $case->owner,
        );

        try {
            $factExtractor = app(ComplianceFactExtractor::class);
            $facts = $factExtractor->extract($dtools);

            $context = [
                'os_family' => $facts['os']['family'] ?? 'unknown',
                'os_id' => $facts['os']['id'] ?? null,
                'os_version_id' => $facts['os']['version_id'] ?? null,
                'stig_slug' => null,
            ];

            $engine = app(RuleEngine::class);
            $summary = [];
            $rows = [];

            foreach (self::RULESETS as $ruleset => $rulesetClass) {
                $results = $engine->evaluate($rulesetClass::rules($context), $facts);

                $counts = ['pass' => 0, 'fail' => 0, 'not_applicable' => 0, 'error' => 0];
                foreach ($results as $result) {
                    $status = $result['status'];
                    if (! array_key_exists($status, $counts)) {
                        $status = 'error';
                    }
                    $counts[$status]++;

                    $rows[] = [
                        'analysis_run_id' => $run->id,
                        'case_id' => $case->id,
                        'vault_id' => $case->vault_id,
                        'ruleset' => $ruleset,
                        'rule_id' => $result['rule_id'],
                        'title' => $result['title'],
                        'description' => $result['description'],
                        'severity' => $result['severity'],
                        'status' => $status,
                        'category' => $result['category'],
                        'matched_path' => $result['matched_path'],
                        'evidence' => json_encode($result['evidence']),
                        'remediation' => $result['remediation'],
                        'reference_url' => $result['reference_url'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $evaluated = $counts['pass'] + $counts['fail'];
                $summary[$ruleset] = $counts + [
                    'score' => $evaluated > 0 ? round(($counts['pass'] / $evaluated) * 100, 1) : null,
                ];
            }

            DB::transaction(function () use ($rows) {
                foreach (array_chunk($rows, 200) as $chunk) {
                    ComplianceFinding::insert($chunk);
                }
            });

            $run->markCompleted($summary);

            addEvent(
                (object) ['run_id' => $run->id, 'case' => $case->case, 'summary' => $summary],
                'COMPLIANCE_RUN',
                'SUCCESS',
                'NORMAL',
                $case->id,
                $case->vault_id,
                $case->owner,
                $case->owner,
            );

            app(ComplianceFindingsDeliveryService::class)->notifyIfNeeded($run, $case);
        } catch (\Throwable $e) {
            $run->markFailed($e->getMessage());

            addEvent(
                (object) ['run_id' => $run->id, 'case' => $case->case, 'error' => $e->getMessage()],
                'COMPLIANCE_RUN',
                'FAILED',
                'NORMAL',
                $case->id,
                $case->vault_id,
                $case->owner,
                $case->owner,
            );

            Log::warning('ComplianceAnalysisService: analysis run failed: '.$e->getMessage());
        }

        return $run;
    }
}
