<?php

namespace Jayanta\Jeeves\Engine;

use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * Dataset Seeder
 *
 * NQ-001-REDUCE cut this down to `detect()` alone. `seeds()` and its three
 * per-signal helpers (routing, schema aliases, column aliases -  EVERY
 * matching signal, not just the first) existed only to feed
 * `SchemaShortlister`, the scope-narrowing half of NQ-001-v2 the
 * G1-round-3 ruling struck out entirely. They are gone with it.
 *
 * `detect()` survives on its own merit: it is
 * `QueryOrchestrator::detectDatasetFromKeywords()` moved here VERBATIM -  a
 * single best guess, unchanged -  and it is load-bearing for NQ-003.
 * `QueryOrchestrator::resolveAskingDataset()` calls it to learn which
 * dataset a question resolves to at zero API cost, which is what stops a
 * cache hit from replaying one dataset's cached answer for another.
 */
class DatasetSeeder
{
    protected SchemaRegistry $registry;

    public function __construct(SchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Detect dataset from query keywords -  single best guess.
     *
     * Priority:
     * 1. User-defined query_routing rules (most specific, highest priority)
     * 2. Schema aliases (from schema config files)
     * 3. Column aliases (last resort)
     */
    public function detect(string $query): ?string
    {
        $queryLower = strtolower($query);

        // Priority 1: User-defined routing rules (longest match first)
        $routing = config('jeeves.query_routing', []);
        if (!empty($routing)) {
            // Sort by key length DESC -  longer phrases matched first
            uksort($routing, fn ($a, $b) => strlen($b) - strlen($a));

            foreach ($routing as $keyword => $datasetKey) {
                if (str_contains($queryLower, strtolower($keyword))) {
                    if ($this->registry->has($datasetKey)) {
                        Log::debug('[Jeeves] Route matched', ['keyword' => $keyword, 'dataset' => $datasetKey]);

                        return $datasetKey;
                    }
                }
            }
        }

        // Priority 2: Schema aliases (longest first for best match)
        foreach ($this->registry->all() as $key => $schema) {
            if (str_contains($queryLower, strtolower($key))) {
                return $key;
            }

            $aliases = $schema['aliases'] ?? [];
            usort($aliases, fn ($a, $b) => strlen($b) - strlen($a));

            foreach ($aliases as $alias) {
                if (str_contains($queryLower, strtolower($alias))) {
                    return $key;
                }
            }
        }

        // Priority 3: Column aliases
        foreach ($this->registry->all() as $key => $schema) {
            $columns = $schema['tables']['primary']['columns'] ?? [];
            foreach ($columns as $colName => $colDef) {
                foreach ($colDef['aliases'] ?? [] as $alias) {
                    if (str_contains($queryLower, strtolower($alias))) {
                        return $key;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The dataset a question MISSPELLS the name or an alias of.
     *
     * Deliberately NOT part of detect(). detect() also decides, through
     * QueryOrchestrator::resolveAskingDataset(), whether a cached answer
     * belongs to this question (NQ-003), and a guess must never sit inside
     * that guard: "invoicez" is one edit from two datasets on an install that
     * has both, and replaying one dataset's cached numbers for the other is
     * the confidently-wrong answer the package exists to rule out. This is
     * consulted on the generation path only, after detect() has missed.
     *
     * Local and deterministic: edit distance against the install's OWN dataset
     * names and aliases. No service, no call, nothing leaves the server.
     *
     * Words under five letters are never fuzzed. "sales" and "scale" are one
     * edit apart, and short words are where a near-miss stops being a typo and
     * starts being a different word. A misspelling two datasets match equally
     * well returns null - the LLM places it instead of this guessing.
     */
    public function detectFuzzy(string $query, int $maxDistance = 2): ?string
    {
        $words = $this->words($query);

        if ($words === [] || $maxDistance < 1) {
            return null;
        }

        $best = null;
        $tied = false;

        foreach ($this->registry->all() as $key => $schema) {
            foreach ($this->fuzzyTerms($key, $schema) as $term) {
                $length = mb_strlen($term);

                if ($length < 5) {
                    continue;
                }

                $allowed = min($maxDistance, $length >= 9 ? 2 : 1);
                $span = substr_count($term, ' ') + 1;

                for ($i = 0; $i + $span <= count($words); $i++) {
                    $distance = levenshtein(implode(' ', array_slice($words, $i, $span)), $term);

                    if ($distance > $allowed) {
                        continue;
                    }

                    if ($best === null || $distance < $best['distance']) {
                        $best = ['dataset' => $key, 'distance' => $distance, 'term' => $term];
                        $tied = false;
                    } elseif ($distance === $best['distance'] && $key !== $best['dataset']) {
                        $tied = true;
                    }
                }
            }
        }

        if ($best === null || $tied) {
            return null;
        }

        Log::debug('[Jeeves] Fuzzy dataset match', [
            'dataset' => $best['dataset'],
            'term' => $best['term'],
            'distance' => $best['distance'],
        ]);

        return $best['dataset'];
    }

    /**
     * Names a user might misspell: the key, the display name, every alias.
     * Column aliases are left out - they describe what is IN a dataset, and a
     * near-miss on one is far weaker evidence than a near-miss on its name.
     *
     * @return array<int, string>
     */
    protected function fuzzyTerms(string $key, array $schema): array
    {
        $terms = array_merge(
            [str_replace('_', ' ', $key), $schema['name'] ?? ''],
            $schema['aliases'] ?? []
        );

        $out = [];

        foreach ($terms as $term) {
            if (!is_string($term)) {
                continue;
            }

            $normalised = implode(' ', $this->words($term));

            if ($normalised !== '') {
                $out[$normalised] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Lower-cased words, split on anything that is not a letter or digit, so
     * "PMAY-G" in a schema and "pmay g" in a question compare as equals.
     *
     * @return array<int, string>
     */
    protected function words(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
