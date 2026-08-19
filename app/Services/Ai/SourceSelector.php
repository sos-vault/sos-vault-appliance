<?php

namespace App\Services\Ai;

/**
 * Picks which case-data sources a question needs, by scoring it against the
 * Data Catalog itself — each source's title, sample answers, field names,
 * purpose and key. This replaces the hand-maintained keyword→file map: the
 * retrieval signal now lives in the same catalog that documents the data, so
 * adding a source (or a whole domain) makes it selectable automatically, with
 * no keyword upkeep.
 *
 * This is the single-shot (fallback) path of the hybrid design. The primary
 * path (agentic tool-calling) lets the model itself pick sources from the same
 * catalog; a lexical scorer is deliberately enough here — it is only reached
 * when tool-calling is unavailable, and a future embedding-based scorer can
 * drop in behind this same API.
 */
class SourceSelector
{
    /** Field-group => weight. Curated question phrasings (answers/title) score highest. */
    private const WEIGHTS = [
        'answers' => 3,
        'title' => 3,
        'id' => 2,
        'keyed_by' => 2,
        'field_names' => 2,
        'purpose' => 1,
        'field_desc' => 1,
    ];

    /** Generic words that carry no source signal. */
    private const STOPWORDS = [
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'to', 'of', 'in',
        'on', 'for', 'and', 'or', 'what', 'which', 'how', 'does', 'do', 'did', 'this',
        'that', 'these', 'those', 'system', 'show', 'list', 'give', 'me', 'my', 'i',
        'it', 'its', 'with', 'from', 'has', 'have', 'had', 'get', 'there', 'any',
        'many', 'much', 'you', 'can', 'tell', 'about', 'please', 'up',
    ];

    public function __construct(private readonly CaseDataCatalog $catalog = new CaseDataCatalog) {}

    /**
     * Ranked source ids the question is about, best first, capped at $limit.
     * Empty when nothing scores — the caller then injects only the digest.
     *
     * @return array<int, string>
     */
    public function select(string $question, int $limit = 4): array
    {
        $qTokens = $this->tokens($question);
        if ($qTokens === []) {
            return [];
        }

        $scores = [];
        foreach ($this->catalog->all() as $id => $src) {
            $score = $this->score($qTokens, (string) $id, $src);
            if ($score > 0) {
                $scores[$id] = $score;
            }
        }

        arsort($scores);

        return array_slice(array_keys($scores), 0, $limit);
    }

    /**
     * The output files for the selected sources, in rank order.
     *
     * @return array<int, string>
     */
    public function selectFiles(string $question, int $limit = 4): array
    {
        $files = [];
        foreach ($this->select($question, $limit) as $id) {
            $src = $this->catalog->get($id);
            if ($src !== null && ! empty($src['file'])) {
                $files[] = $src['file'];
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Weighted term index for a source: candidate token => highest weight it carries.
     *
     * @param  array<string, mixed>  $src
     * @return array<int, float>
     */
    private function score(array $qTokens, string $id, array $src): float
    {
        $index = $this->indexSource($id, $src);

        $score = 0.0;
        foreach ($qTokens as $token) {
            $best = 0;
            foreach ($this->variants($token) as $cand) {
                if (isset($index[$cand]) && $index[$cand] > $best) {
                    $best = $index[$cand];
                }
            }
            $score += $best;
        }

        return $score;
    }

    /**
     * Build the candidate-token => max-weight lookup for a source from its
     * catalog text, so scoring is a set membership test.
     *
     * @param  array<string, mixed>  $src
     * @return array<string, int>
     */
    private function indexSource(string $id, array $src): array
    {
        $groups = [
            'id' => [$id],
            'keyed_by' => [(string) ($src['keyed_by'] ?? '')],
            'title' => [(string) ($src['title'] ?? '')],
            'purpose' => [(string) ($src['purpose'] ?? '')],
            'answers' => array_map('strval', $src['answers'] ?? []),
            'field_names' => array_keys($src['fields'] ?? []),
            'field_desc' => array_values(array_map('strval', $src['fields'] ?? [])),
        ];

        $index = [];
        foreach ($groups as $group => $texts) {
            $weight = self::WEIGHTS[$group] ?? 1;
            foreach ($texts as $text) {
                foreach ($this->tokens($text) as $token) {
                    foreach ($this->variants($token) as $cand) {
                        if (($index[$cand] ?? 0) < $weight) {
                            $index[$cand] = $weight;
                        }
                    }
                }
            }
        }

        return $index;
    }

    /**
     * Normalise text to meaningful lowercase tokens (>=2 chars, no stopwords).
     *
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [];

        $tokens = [];
        foreach ($words as $w) {
            if (strlen($w) >= 2 && ! in_array($w, self::STOPWORDS, true) && ! ctype_digit($w)) {
                $tokens[$w] = true; // distinct
            }
        }

        return array_keys($tokens);
    }

    /**
     * Singular/plural candidates for a token so "ports" matches "port" and
     * "processes" matches "process". Junk candidates are harmless — they simply
     * never collide with a real catalog term.
     *
     * @return array<int, string>
     */
    private function variants(string $token): array
    {
        $v = [$token];
        if (strlen($token) > 4 && str_ends_with($token, 'es')) {
            $v[] = substr($token, 0, -2);
        }
        if (strlen($token) > 3 && str_ends_with($token, 's')) {
            $v[] = substr($token, 0, -1);
        }

        return $v;
    }
}
