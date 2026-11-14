<?php

namespace App\Services\Ai;

use Prism\Prism\Facades\Tool;
use Prism\Prism\Tool as PrismTool;

/**
 * The agentic (primary) retrieval path: exposes the analysed system's parsed
 * data to a tool-capable model as a `fetch_case_data(source, filter)` tool, and
 * builds the catalog "guide" injected as system context so the model knows what
 * exists and how the sources correlate.
 *
 * The model reads the guide, calls the tool for whichever sources it decides it
 * needs (iteratively, across files), and correlates the results — instead of us
 * pre-selecting files. Both this and the single-shot SourceSelector read the
 * same Data Catalog, so a new source/domain becomes usable on both paths at once.
 */
class CaseDataTool
{
    public function __construct(
        private readonly CaseDataCatalog $catalog = new CaseDataCatalog,
        private readonly CaseContextBuilder $reader = new CaseContextBuilder,
    ) {}

    /**
     * The Prism tool, bound to one open case. The closure captures the case
     * coordinates so the model only supplies the source (and an optional filter).
     */
    public function prismTool(int $caseDirectoryId, int $userId, ProviderProfile $profile): PrismTool
    {
        $ids = implode(', ', $this->catalog->ids());

        return Tool::as('fetch_case_data')
            ->for(
                "Fetch parsed data from the analysed system's sosreport for ONE data source. "
                .'Call it repeatedly — once per source you need — and combine the results to '
                .'answer. Use "filter" to focus large sources on a specific PID, port, package '
                .'or process name, or keyword. Do not guess; fetch the data.'
            )
            ->withStringParameter('source', "The data source to fetch. Must be one of: {$ids}.")
            ->withStringParameter(
                'filter',
                'Optional focus hint to trim large sources to what matters (e.g. a PID like '
                .'"4148341", a port, or a package name like "openssl").',
                required: false
            )
            ->using(function (string $source, ?string $filter = null) use ($caseDirectoryId, $userId, $profile): string {
                return $this->fetch($source, (string) $filter, $caseDirectoryId, $userId, $profile);
            });
    }

    /** Resolve the case path, then fetch one source. */
    public function fetch(string $source, string $filter, int $caseDirectoryId, int $userId, ProviderProfile $profile): string
    {
        $path = $this->reader->resolvePath($caseDirectoryId, $userId);
        if ($path === null) {
            return 'The case data is currently unavailable.';
        }

        return $this->fetchFromPath($source, $filter, $path, $profile);
    }

    /** Fetch one source from an already-resolved case path (kept separate for testing). */
    public function fetchFromPath(string $source, string $filter, string $path, ProviderProfile $profile): string
    {
        $src = $this->catalog->get($source);
        if ($src === null) {
            return 'Unknown source "'.$source.'". Valid sources: '.implode(', ', $this->catalog->ids()).'.';
        }

        $data = $this->reader->readSourceData($path, (string) $src['file'], $profile->perFileCap, $filter);

        return $data !== '' ? $data : "No '{$source}' data was captured in this report.";
    }

    /**
     * The system-prompt block: what data is available, how the sources connect,
     * and the method for root-cause questions. Kept compact — the field
     * dictionaries are pulled on demand via the tool, not dumped here.
     */
    public function catalogGuide(): string
    {
        $lines = [
            "## Analysing this system's data",
            '',
            'A compact health digest may be provided above. For anything specific, call the '
                .'`fetch_case_data(source, filter?)` tool for each source you need and correlate '
                .'the results — never guess at values you can fetch. Available sources:',
            '',
        ];

        foreach ($this->catalog->index() as $s) {
            $answers = ! empty($s['answers']) ? ' Answers: '.implode('; ', array_slice($s['answers'], 0, 2)).'.' : '';
            $lines[] = "- `{$s['id']}` — {$s['title']}.{$answers}";
        }

        $joins = $this->catalog->joins();
        if ($joins !== []) {
            $lines[] = '';
            $lines[] = 'How the sources connect (use these to trace cause and effect):';
            foreach ($joins as $j) {
                $lines[] = "- {$j['from']} ↔ {$j['to']} on {$j['on']}: {$j['note']}.";
            }
        }

        $lines[] = '';
        $lines[] = 'Method for root-cause questions: establish WHEN from the logs/digest → find the '
            .'resource under pressure (memory, cpu, disks) → identify the owning process (by PID, '
            .'%CPU, RSS) → walk up via PPID or the owning systemd unit. Cite concrete PIDs, ports, '
            .'units and figures from the fetched data.';

        return implode("\n", $lines);
    }
}
