<?php

namespace Jayanta\Jeeves\Facades;

use Illuminate\Support\Facades\Facade;
use Jayanta\Jeeves\Engine\QueryOrchestrator;

/**
 * Jeeves Facade
 *
 * The signatures below are what an IDE completes against, so they are kept
 * exact -  a facade docblock that has drifted from the class is worse than none,
 * because it is believed.
 *
 * @method static array query(string $naturalLanguageQuery, ?string $datasetHint = null, array $context = [])
 * @method static array getDatasets()
 * @method static array getDatasetMetrics(string $datasetKey)
 * @method static array healthCheck()
 * @method static \Jayanta\Jeeves\Schema\SchemaRegistry registry()
 * @method static array getCacheStats()
 * @method static int clearCache(?string $dataset = null, int $olderThanDays = 0, int $minHits = 0)
 *
 * @see QueryOrchestrator
 */
class Jeeves extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return QueryOrchestrator::class;
    }
}
