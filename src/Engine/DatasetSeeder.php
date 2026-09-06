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
}
