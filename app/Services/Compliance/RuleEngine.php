<?php

namespace App\Services\Compliance;

class RuleEngine
{
    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<string, mixed>  $facts
     * @return array<int, array<string, mixed>>
     */
    public function evaluate(array $rules, array $facts): array
    {
        $results = [];

        foreach ($rules as $rule) {
            $results[] = $this->evaluateRule($rule, $facts);
        }

        return $results;
    }

    private function evaluateRule(array $rule, array $facts): array
    {
        $status = 'not_applicable';
        $evidence = [];
        $matchedPath = null;

        $applicable = $rule['applicable'] ?? null;

        if ($applicable && ! $applicable($facts)) {
            $status = 'not_applicable';
        } else {
            try {
                $outcome = ($rule['check'])($facts);

                if (is_array($outcome)) {
                    $status = $outcome['status'] ?? 'error';
                    $evidence = $outcome['evidence'] ?? [];
                    $matchedPath = $outcome['matched_path'] ?? null;
                } else {
                    $status = (string) $outcome;
                }
            } catch (\Throwable $e) {
                // A single misbehaving rule must never abort the batch — record
                // it as an 'error' finding and keep evaluating the rest.
                $status = 'error';
                $evidence = ['exception' => $e->getMessage()];
            }
        }

        return [
            'rule_id' => $rule['id'],
            'category' => $rule['category'] ?? null,
            'title' => $rule['title'] ?? null,
            'description' => $rule['description'] ?? null,
            'severity' => $rule['severity'] ?? null,
            'status' => $status,
            'evidence' => $evidence,
            'matched_path' => $matchedPath,
            'remediation' => $rule['remediation'] ?? null,
            'reference_url' => $rule['reference_url'] ?? null,
        ];
    }
}
