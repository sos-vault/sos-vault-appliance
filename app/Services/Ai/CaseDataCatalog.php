<?php

namespace App\Services\Ai;

/**
 * Read/query access to the declarative case-data catalog (config/ai_case_catalog.php).
 *
 * This is the single source of truth for "what data exists" behind Mil's case
 * analysis. Both hybrid retrieval paths build on it: the semantic selector
 * matches questions against each source's purpose/answers, and the agentic
 * fetch_case_data() tool uses it to know which sources (and files) it may pull.
 *
 * Keeping this behind a small service (rather than reading config() everywhere)
 * means the retrieval layers depend on a stable API, not the raw array shape.
 */
class CaseDataCatalog
{
    /**
     * Every source, keyed by its stable id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return config('ai_case_catalog.sources', []);
    }

    /** The domain this catalog describes (e.g. "sosreport"). */
    public function domain(): string
    {
        return (string) config('ai_case_catalog.domain', '');
    }

    /** All declared source ids. */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    /**
     * A single source by id, with its id folded in, or null when unknown.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $src = $this->all()[$id] ?? null;

        return $src === null ? null : ['id' => $id, ...$src];
    }

    /**
     * Resolve a source by its output filename (e.g. ".networkData.json").
     *
     * @return array<string, mixed>|null
     */
    public function forFile(string $file): ?array
    {
        foreach ($this->all() as $id => $src) {
            if (($src['file'] ?? null) === $file) {
                return ['id' => $id, ...$src];
            }
        }

        return null;
    }

    /**
     * The compact "map" injected so the model knows what data is available:
     * id, title, purpose and the sample questions each source answers — but not
     * the full field dictionaries (those are pulled on demand / when selected).
     *
     * @return array<int, array<string, mixed>>
     */
    public function index(): array
    {
        $index = [];
        foreach ($this->all() as $id => $src) {
            $index[] = [
                'id' => $id,
                'title' => $src['title'] ?? $id,
                'purpose' => $src['purpose'] ?? '',
                'answers' => $src['answers'] ?? [],
            ];
        }

        return $index;
    }

    /**
     * The join edges declared across all sources, as a flat list of
     * { from, to, on, note } — the correlation graph used by the reasoning
     * playbook and (later) surfaced to the model.
     *
     * @return array<int, array<string, string>>
     */
    public function joins(): array
    {
        $edges = [];
        foreach ($this->all() as $id => $src) {
            foreach ($src['joins'] ?? [] as $join) {
                $edges[] = [
                    'from' => $id,
                    'to' => (string) ($join['to'] ?? ''),
                    'on' => (string) ($join['on'] ?? ''),
                    'note' => (string) ($join['note'] ?? ''),
                ];
            }
        }

        return $edges;
    }
}
