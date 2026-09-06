<?php

namespace Jayanta\Jeeves\Schema;

/**
 * Dataset Catalog
 *
 * Renders the compact, one-line-per-dataset index used to tell an LLM what
 * datasets exist without paying for every column of every one of them.
 *
 * A PURE function of the dataset-list array `SchemaRegistry::getDatasetListForLlm()`
 * produces -  no registry, no config, no I/O -  so it can be unit-tested with a
 * hand-built array and reused anywhere that list already exists.
 *
 * One caller today: `AbstractProvider::buildDatasetInfo()`, the intent-parsing
 * prompt every provider sends. `QueryPlanner::buildPlanPrompt()` has its own
 * near-identical loop and deliberately still does -  pointing it here changed
 * the planning prompt on every provider, which is a prompt change needing a
 * live conformance run, not a refactor. Merge them when someone is willing to
 * pay for that run.
 *
 * So this is an extraction, not yet a deduplication. It exists because the
 * line format wants one owner before a third caller appears.
 */
class DatasetCatalog
{
    /**
     * Render one line per dataset.
     *
     * @param  array<int, array<string, mixed>>  $datasetList  as returned by
     *                                                         SchemaRegistry::getDatasetListForLlm() -  each entry carries
     *                                                         key, name, aliases, metrics, dimensions, default_dimension.
     * @param  array<int, string>|null  $onlyKeys  restrict the index to these
     *                                             dataset keys, in $datasetList's own order; null renders all of
     *                                             them.
     */
    public static function render(array $datasetList, ?array $onlyKeys = null): string
    {
        $lines = [];

        foreach ($datasetList as $dataset) {
            if ($onlyKeys !== null && !in_array($dataset['key'] ?? null, $onlyKeys, true)) {
                continue;
            }

            $lines[] = self::line($dataset);
        }

        return implode("\n", $lines);
    }

    /**
     * `dimensions` matters as much as `metrics`: without it the model cannot
     * know that "by region" is a breakdown this dataset supports, and the
     * request is silently answered with the default grouping instead.
     *
     * @param  array<string, mixed>  $dataset
     */
    protected static function line(array $dataset): string
    {
        $aliases = implode(', ', array_slice($dataset['aliases'] ?? [], 0, 3));
        $metrics = implode(', ', array_keys($dataset['metrics'] ?? []));
        $dimensions = implode(', ', $dataset['dimensions'] ?? []);

        $line = "- {$dataset['key']} ({$dataset['name']}): aliases=[{$aliases}], metrics=[{$metrics}]";

        if ($dimensions !== '') {
            $line .= ", group_by=[{$dimensions}]";
        }

        if (!empty($dataset['default_dimension'])) {
            $line .= ", default group_by={$dataset['default_dimension']}";
        }

        return $line;
    }
}
