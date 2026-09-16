<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertTrigger;
use App\Models\SupportCase;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use App\Rules\ValidPcreRegex;

/**
 * Evaluates Alert rules (grep / levels / diff) against a freshly-unpacked
 * case and records a match as an AlertTrigger, then hands off to
 * AlertDeliveryService. Called from the queued EvaluateAlerts listener, never
 * on the upload request path.
 */
class AlertEvaluationService
{
    public function __construct(private readonly AlertDeliveryService $delivery) {}

    /** Fan-out entry point: every enabled alert owned by this case's vault. */
    public function evaluateVaultForCase(SupportCase $case, DataTools $dtools, VaultTools $vtools): void
    {
        Alert::where('vault_id', $case->vault_id)->where('enabled', true)->get()
            ->each(fn (Alert $alert) => $this->evaluateAlertForCase($alert, $case, $dtools, $vtools));
    }

    public function evaluateAlertForCase(Alert $alert, SupportCase $case, DataTools $dtools, VaultTools $vtools): ?AlertTrigger
    {
        $detail = match ($alert->type) {
            'grep' => $this->evaluateGrep($dtools, $alert),
            'levels' => $this->evaluateLevels($dtools, $alert),
            'diff' => $this->evaluateDiff($dtools, $alert, $case, $vtools),
            default => null,
        };

        if ($detail === null) {
            return null;
        }

        $trigger = AlertTrigger::create([
            'alert_id' => $alert->id,
            'case_id' => $case->id,
            'vault_id' => $case->vault_id,
            'dir_id' => $case->file_id,
            // Normalised (no leading slash) so it matches file-controls'
            // ltrim($this->filepath, '/') lookup for the status badge.
            'matched_path' => isset($detail['matched_path']) ? ltrim($detail['matched_path'], '/') : null,
            'detail' => $detail,
        ]);

        $delivered = $this->delivery->deliver($alert, $trigger, $case);
        $trigger->update(['delivered_channels' => $delivered]);

        return $trigger;
    }

    /** Missing file is treated as "no signal" — never fires, either match mode. */
    public function evaluateGrep(DataTools $dtools, Alert $alert): ?array
    {
        $lines = $dtools->readFileContents($alert->grep_path);
        if ($lines === null) {
            return null;
        }

        $pattern = ValidPcreRegex::delimit((string) $alert->grep_regex);
        $matchedLine = null;
        $matchedLineNo = null;
        foreach ($lines as $i => $line) {
            if (@preg_match($pattern, $line) === 1) {
                $matchedLine = $line;
                $matchedLineNo = $i + 1;
                break;
            }
        }

        $found = $matchedLine !== null;
        $fires = $alert->grep_match_mode === 'not_found' ? ! $found : $found;

        if (! $fires) {
            return null;
        }

        return array_filter([
            'matched_path' => $alert->grep_path,
            'matched_line' => $matchedLine,
            'line_no' => $matchedLineNo,
            'reason' => $found ? 'pattern found' : 'pattern absent',
        ], fn ($v) => $v !== null);
    }

    public function evaluateLevels(DataTools $dtools, Alert $alert): ?array
    {
        $measured = match ($alert->levels_metric) {
            'cpu' => $this->measureCpu($dtools),
            'memory' => $this->measurePercent($dtools->getMemoryData(), 'memory', 'pused'),
            'swap' => $this->measurePercent($dtools->getMemoryData(), 'swap', 'pused'),
            'disk' => $this->measureDisk($dtools, (string) $alert->levels_mount_path, 'pused'),
            'inodes' => $this->measureDisk($dtools, (string) $alert->levels_mount_path, 'ipused'),
            'processes' => $this->measureTaskCount($dtools),
            'open_files' => $this->measureOpenFiles($dtools),
            'connections' => $this->measureConnections($dtools),
            default => null,
        };

        if ($measured === null || $measured < (float) $alert->levels_threshold) {
            return null;
        }

        return [
            'metric' => $alert->levels_metric,
            'measured' => $measured,
            'threshold' => (float) $alert->levels_threshold,
            'mount' => $alert->levels_mount_path,
        ];
    }

    /**
     * "diff -q" semantics: fires when the checksum of $alert->diff_path in the
     * current case differs from the same path in the fixed reference case (or
     * the file exists on only one side). Reference case must live in the same
     * vault — the Select offering it is pre-scoped that way.
     */
    public function evaluateDiff(DataTools $dtools, Alert $alert, SupportCase $case, VaultTools $vtools): ?array
    {
        $referenceCase = $alert->referenceCase;
        if (! $referenceCase || (int) $referenceCase->vault_id !== (int) $case->vault_id) {
            return null;
        }

        $currentLines = $dtools->readFileContents($alert->diff_path);

        $refDtools = new DataTools($vtools, (int) $case->vault_id, (int) $referenceCase->file_id);
        $referenceLines = $refDtools->readFileContents($alert->diff_path);

        if ($currentLines === null && $referenceLines === null) {
            return null;
        }

        $currentChecksum = $currentLines === null ? null : hash('sha256', implode("\n", $currentLines));
        $referenceChecksum = $referenceLines === null ? null : hash('sha256', implode("\n", $referenceLines));

        if ($currentChecksum === $referenceChecksum) {
            return null;
        }

        return [
            'matched_path' => $alert->diff_path,
            'old_checksum' => $referenceChecksum,
            'new_checksum' => $currentChecksum,
            'reference_case_id' => $referenceCase->id,
        ];
    }

    private function measureCpu(DataTools $dtools): ?float
    {
        $cpu = $this->toArray($dtools->getCpuData());
        $idle = $cpu['cpu']['idle'] ?? null;

        return $idle === null ? null : round(100 - (float) $idle, 2);
    }

    private function measurePercent($data, string $section, string $field): ?float
    {
        $arr = $this->toArray($data);
        $value = $arr[$section][$field]['value'] ?? null;

        return $value === null ? null : round((float) $value, 2);
    }

    private function measureDisk(DataTools $dtools, string $mountPath, string $field): ?float
    {
        if ($mountPath === '') {
            return null;
        }

        foreach ($this->toArray($dtools->getDiskData()) as $disk) {
            if (($disk['point'] ?? null) === $mountPath) {
                return round((float) ($disk[$field] ?? 0), 2);
            }
        }

        return null;
    }

    private function measureTaskCount(DataTools $dtools): ?float
    {
        $procs = $this->toArray($dtools->getProcessesData());

        return isset($procs['tasks']['tasks']) ? (float) $procs['tasks']['tasks'] : null;
    }

    private function measureOpenFiles(DataTools $dtools): ?float
    {
        $files = $this->toArray($dtools->getOpenFilesData());
        if (empty($files)) {
            return null;
        }

        $counts = array_column($files, 'FILES');

        return $counts !== [] ? (float) array_sum($counts) : (float) count($files);
    }

    private function measureConnections(DataTools $dtools): ?float
    {
        $connections = $this->toArray($dtools->getNetworkData());

        return $connections === [] ? null : (float) count($connections);
    }

    /** Normalise a getter's mixed object/array/null result to a plain array. */
    private function toArray($data): array
    {
        if (empty($data)) {
            return [];
        }

        return json_decode(json_encode($data), true) ?: [];
    }
}
