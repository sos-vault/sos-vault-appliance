<?php

namespace App\Services\Ai;

use App\Models\SupportCase;
use App\Providers\VaultTools;
use Illuminate\Support\Facades\Cache;

/**
 * Assembles the "Live Case System Data" block for current-sosreport analysis.
 * Always injects the compact health digest, plus only the source file(s) the
 * question needs — chosen by the catalog-driven SourceSelector, not a hand
 * keyword map — instead of dumping all 16 files blind-truncated. Large files
 * (processes, network, packages) are trimmed to the relevant rows, not byte-cut.
 */
class CaseContextBuilder
{
    public function __construct(
        private readonly SourceSelector $selector = new SourceSelector,
        private readonly CaseDigestRegenerator $regenerator = new CaseDigestRegenerator,
    ) {}

    public function build(int $caseDirectoryId, int $userId, string $userMessage, ProviderProfile $profile): string
    {
        if (! $profile->caseAnalysisEnabled) {
            return '';
        }

        $files = $this->selectTopicFiles($userMessage);
        $cacheKey = "ai_case_context_{$caseDirectoryId}_".md5(implode(',', $files));

        return Cache::remember($cacheKey, 300, function () use ($caseDirectoryId, $userId, $userMessage, $profile) {
            $path = $this->resolveCasePath($caseDirectoryId, $userId);
            if ($path === null) {
                return '';
            }

            return $this->buildFromPath($path, $userMessage, $profile);
        });
    }

    /**
     * Assemble the context block from a resolved case directory path. Kept
     * separate from path/vault resolution so it can be exercised directly.
     */
    public function buildFromPath(string $path, string $userMessage, ProviderProfile $profile): string
    {
        if (! $profile->caseAnalysisEnabled) {
            return '';
        }

        $files = $this->selectTopicFiles($userMessage);

        $digest = $this->readDigest($path);
        $blocks = '';
        foreach ($files as $file) {
            $blocks .= $this->readTopicFile($path, $file, $profile->perFileCap, $userMessage);
        }

        if ($digest === '' && $blocks === '') {
            return '';
        }

        $context = "## Live Case System Data\n\n"
            .'Data extracted from the analysed system (point-in-time snapshot). '
            ."Use it to answer the user's question; do not mention the data field names.\n\n";

        if ($digest !== '') {
            $context .= "### digest\n```json\n{$digest}\n```\n\n";
        }

        return $context.$blocks;
    }

    /**
     * The case-data files the question needs, resolved from the Data Catalog by
     * the SourceSelector (scores the question against each source's own catalog
     * text). Replaces the former hand-maintained config('ai.topic_files') map.
     *
     * @return array<int, string>
     */
    private function selectTopicFiles(string $userMessage): array
    {
        return $this->selector->selectFiles($userMessage);
    }

    private function resolveCasePath(int $caseDirectoryId, int $userId): ?string
    {
        // A case's data lives in its OWNER's vault. resolveVaultUser() picks the
        // owner (not the viewer) for a shared vault, so a case shared to another
        // user resolves to the owner's mounted vault — exactly like the file
        // browser (App\Models\FileContent). Reading it in the viewer's own vault
        // (the previous behaviour) left shared cases with no data. A directory id
        // is only unique within a vault, so try each candidate vault and use the
        // first that is open and actually holds the directory.
        $candidates = SupportCase::where('file_id', $caseDirectoryId)
            ->get(['id', 'vault_id'])
            ->unique('vault_id');

        foreach ($candidates as $candidate) {
            $vid = $candidate->vault_id;
            if (! $vid) {
                continue;
            }

            $vtools = new VaultTools(resolveVaultUser($vid, $candidate->id), (string) $vid);
            if (! $vtools->isOpen()) {
                continue;
            }

            $dir = $vtools->getDirById($caseDirectoryId);
            if (! $dir) {
                continue;
            }

            $path = rtrim($vtools->getMountPoint(), '/').'/'.$dir->name;

            // Lazy backfill: cases unpacked before the digest feature have no
            // .aiDigest.json (written only at unpack). Generate it — in the
            // owner's vault — on first analysis so pre-existing cases work too.
            $this->regenerator->ensure($vtools, $vtools->getVaultId(), $caseDirectoryId, $path);

            return $path;
        }

        return null;
    }

    /**
     * Public path resolution + digest access for the agentic fetch tool
     * (App\Services\Ai\CaseDataTool), which resolves the case directory once and
     * pulls individual sources on demand.
     */
    public function resolvePath(int $caseDirectoryId, int $userId): ?string
    {
        return $this->resolveCasePath($caseDirectoryId, $userId);
    }

    public function digestFor(int $caseDirectoryId, int $userId): string
    {
        $path = $this->resolveCasePath($caseDirectoryId, $userId);

        return $path === null ? '' : $this->readDigest($path);
    }

    private function readDigest(string $path): string
    {
        $file = config('ai.case_digest_file', '.aiDigest.json');
        $filepath = $path.'/'.$file;
        if (! is_file($filepath)) {
            return '';
        }

        $json = json_decode($this->cleanRead($filepath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return '';
        }

        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * Read one source file as trimmed, capped JSON text (no markdown wrapper).
     * Shared by the single-shot path (readTopicFile) and the agentic fetch tool.
     * $filter focuses the large sources (a PID, "listen", a package name, …).
     */
    public function readSourceData(string $path, string $file, int $cap, string $filter = ''): string
    {
        $filepath = $path.'/'.$file;
        if (! is_file($filepath)) {
            return '';
        }

        $json = json_decode($this->cleanRead($filepath), true);
        if (json_last_error() !== JSON_ERROR_NONE || $json === null) {
            return '';
        }

        // The large per-record files (processes, network connections, installed
        // packages) overflow the per-file cap and would be blind byte-truncated,
        // dropping exactly the rows the question is about. Trim them to the
        // relevant/high-signal rows instead, keyed off the question/filter.
        $json = match ($file) {
            '.processesData.json' => $this->trimProcesses($json, $filter),
            '.networkData.json' => $this->trimNetwork($json),
            '.packagesData.json' => $this->trimPackages($json, $filter),
            default => $json,
        };

        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return '';
        }
        if (strlen($encoded) > $cap) {
            $encoded = substr($encoded, 0, $cap)."\n... [truncated]";
        }

        return $encoded;
    }

    private function readTopicFile(string $path, string $file, int $cap, string $userMessage = ''): string
    {
        $encoded = $this->readSourceData($path, $file, $cap, $userMessage);
        if ($encoded === '') {
            return '';
        }

        $name = ltrim($file, '.');
        $name = preg_replace('/\.json$/', '', $name);

        return "### {$name}\n```json\n{$encoded}\n```\n\n";
    }

    /**
     * Reduce the (potentially huge) per-PID process map to the heaviest rows by
     * CPU and by memory, plus the task-state summary — so the model gets the real
     * heavy hitters rather than the first arbitrary PIDs a byte-truncation keeps.
     *
     * Any PID named explicitly in the question is also included, as its FULL row
     * and placed first, so targeted questions ("how many open files does PID
     * 4148341 have?") are answerable even when that process is not a top-N hog.
     */
    private function trimProcesses(array $procs, string $userMessage = '', int $limit = 15): array
    {
        $tasks = $procs['tasks'] ?? null;
        unset($procs['tasks']);

        // PIDs referenced literally in the question (only those that actually
        // exist in the map, so stray numbers like "10MB" can't inject noise).
        $targeted = [];
        if (preg_match_all('/\d+/', $userMessage, $m)) {
            foreach (array_unique($m[0]) as $num) {
                if (isset($procs[$num]) && is_array($procs[$num])) {
                    $targeted[(string) $num] = $procs[$num];
                }
            }
        }

        $rows = [];
        foreach ($procs as $pid => $p) {
            if (! is_array($p)) {
                continue;
            }
            $p['_pid'] = $p['PID'] ?? $pid;
            $rows[] = $p;
        }

        $byCpu = $rows;
        usort($byCpu, fn ($a, $b) => (float) ($b['%CPU'] ?? 0) <=> (float) ($a['%CPU'] ?? 0));
        $byMem = $rows;
        usort($byMem, fn ($a, $b) => (int) ($b['RSS'] ?? 0) <=> (int) ($a['RSS'] ?? 0));

        $keep = [];
        foreach (array_merge(array_slice($byCpu, 0, $limit), array_slice($byMem, 0, $limit)) as $p) {
            $pidKey = (string) $p['_pid'];
            if (isset($targeted[$pidKey])) {
                continue; // already kept in full below
            }
            $keep[$pidKey] = $this->slimProcess($p);
        }

        // Targeted full rows first so they survive any per-file cap truncation.
        $out = $targeted + $keep;
        if ($tasks !== null) {
            $out['tasks'] = $tasks;
        }

        return $out;
    }

    private function slimProcess(array $p): array
    {
        return array_filter([
            'PID' => $p['_pid'] ?? null,
            'PPID' => $p['PPID'] ?? null,
            'USER' => $p['USER'] ?? null,
            'Command' => $p['Command'] ?? null,
            'STAT' => $p['STAT'] ?? null,
            '%CPU' => $p['%CPU'] ?? null,
            '%MEM' => $p['%MEM'] ?? null,
            'RSS' => $p['RSS'] ?? null,
            'threads' => $p['threads'] ?? null,
            'fd-nr' => $p['fd-nr'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Netstat/ss dumps can carry hundreds of connections; a blind byte-truncation
     * would keep an arbitrary slice and often drop the LISTEN sockets — the rows
     * that matter for "what is listening on which port". Keep every LISTEN socket
     * (few, high signal), then fill the remainder with other connections.
     */
    private function trimNetwork(array $conns, int $limit = 80): array
    {
        $listen = [];
        $other = [];
        foreach ($conns as $c) {
            if (! is_array($c)) {
                continue; // skip "#INCOMPLETE:..." markers and the like
            }
            if (isset($c['State']) && strtoupper((string) $c['State']) === 'LISTEN') {
                $listen[] = $c;
            } else {
                $other[] = $c;
            }
        }

        return array_slice(array_merge($listen, $other), 0, $limit);
    }

    /**
     * The installed-package list is thousands of entries; dumping it byte-truncated
     * keeps only the alphabetical head. Instead return the package(s) the question
     * actually names (matched against the entry Name), falling back to a bounded
     * sample when the question names none.
     */
    private function trimPackages(array $packages, string $userMessage, int $limit = 60): array
    {
        $tokens = array_filter(
            preg_split('/[^a-z0-9+.-]+/', mb_strtolower($userMessage)) ?: [],
            fn ($t) => strlen($t) >= 3 && ! in_array($t, self::PACKAGE_STOPWORDS, true)
        );

        if ($tokens !== []) {
            $matched = array_filter($packages, function ($p) use ($tokens) {
                $name = mb_strtolower((string) ($p['Name'] ?? ''));
                foreach ($tokens as $t) {
                    if ($name !== '' && str_contains($name, $t)) {
                        return true;
                    }
                }

                return false;
            });

            if ($matched !== []) {
                return array_slice(array_values($matched), 0, $limit);
            }
        }

        return array_slice(array_values($packages), 0, $limit);
    }

    /** Common words in package questions that must not be treated as package names. */
    private const PACKAGE_STOPWORDS = [
        'the', 'what', 'which', 'version', 'versions', 'installed', 'install',
        'package', 'packages', 'library', 'libraries', 'system', 'have', 'has',
        'show', 'list', 'give', 'does', 'this', 'that', 'and', 'for', 'are', 'is',
    ];

    private function cleanRead(string $filepath): string
    {
        return mb_convert_encoding((string) file_get_contents($filepath), 'UTF-8', 'UTF-8');
    }
}
