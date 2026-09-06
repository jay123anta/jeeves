<?php

namespace Jayanta\Jeeves\Contracts;

/**
 * Query Cache Interface
 *
 * Two-tier cache for storing parsed intents and avoiding repeated AI calls.
 * Tier 1: Fast cache (Redis/memory) for exact matches
 * Tier 2: Database, exact matches on the normalised question
 */
interface QueryCacheInterface
{
    /**
     * Find a cached result for a query.
     *
     * Deliberately unchanged since 1.0.0. Dataset-aware lookup arrived in
     * 2.1.0 and lives in `ScopesCacheByDataset` instead of here, because
     * adding even an OPTIONAL parameter to an interface method is a fatal
     * error for every class already implementing the old signature -  the
     * app does not boot. A minor release cannot do that, and the package
     * already had the right precedent in `ReportsUsage`.
     *
     * THE SHAPE TO RETURN, which is what `store()` was given plus a little:
     *
     *   [
     *     'cached'           => true,
     *     'cache_match_type' => 'exact' | 'fuzzy',   // reported as metadata.
     *                                                // The bundled cache only
     *                                                // ever returns 'exact'
     *                                                // since 2.3.0; 'fuzzy'
     *                                                // stays valid for a
     *                                                // custom implementation
     *                                                // that matches on wording
     *     'intent'           => [...],               // EXACTLY what store() received
     *     'dataset'          => $intent['dataset'] ?? null,
     *   ]
     *
     * `intent` must come back byte-identical. The engine reads reserved keys
     * out of it that it never told you about -  `_sql_result` decides which
     * reader may replay the row, `_asking_scope` decides whether the row is
     * eligible for the question being asked at all -  and a row that has lost
     * them is not refused, it is silently misread: a filtered total answered
     * as an unfiltered one, or another dataset's number returned with
     * `success` and no API call. Store the array whole and hand it back whole.
     *
     * @param  string  $query  The natural language query
     * @return array|null Cached intent data in the shape above, or null
     */
    public function find(string $query): ?array;

    /**
     * Store a query result in cache.
     *
     * @param  string  $query  The original natural language query
     * @param  array  $intent  The parsed intent/result to cache -  opaque; keep it whole
     * @return bool Whether storing succeeded
     */
    public function store(string $query, array $intent): bool;

    /**
     * Get cache statistics.
     *
     * @return array {total_entries, total_hits, datasets, top_queries}
     */
    public function getStatistics(): array;

    /**
     * Clear cache entries.
     *
     * @param  string|null  $dataset  Clear only entries for this dataset (null = all)
     * @param  int  $olderThanDays  Clear entries older than N days (0 = all ages)
     * @param  int  $minHits  Only clear entries with fewer than N hits (0 = all)
     * @return int Number of entries cleared
     */
    public function clear(?string $dataset = null, int $olderThanDays = 0, int $minHits = 0): int;
}
