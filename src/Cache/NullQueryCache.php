<?php

namespace Jayanta\Jeeves\Cache;

use Jayanta\Jeeves\Contracts\QueryCacheInterface;
use Jayanta\Jeeves\Contracts\ScopesCacheByDataset;

/**
 * Null Query Cache - used when caching is disabled.
 * All operations are no-ops.
 */
class NullQueryCache implements QueryCacheInterface, ScopesCacheByDataset
{
    public function findForDataset(string $query, ?string $datasetHint = null): ?array
    {
        return null;
    }

    public function find(string $query): ?array
    {
        return null;
    }

    public function store(string $query, array $intent): bool
    {
        return true;
    }

    public function getStatistics(): array
    {
        return ['enabled' => false, 'total_entries' => 0, 'total_hits' => 0];
    }

    public function clear(?string $dataset = null, int $olderThanDays = 0, int $minHits = 0): int
    {
        return 0;
    }
}
