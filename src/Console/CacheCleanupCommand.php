<?php

namespace Jayanta\Jeeves\Console;

use Illuminate\Console\Command;
use Jayanta\Jeeves\Contracts\QueryCacheInterface;

/**
 * Cache Cleanup Command
 *
 * Cleans up stale or low-hit cache entries.
 * Designed to be run via scheduler (e.g., daily).
 */
class CacheCleanupCommand extends Command
{
    // --days and --min-hits carry NO default in the signature, so the command
    // can tell "not given" from "given as 30". See handle(): the conservative
    // defaults belong to a bare run, and applying them silently on top of
    // --dataset made that flag unable to remove the rows people run it for.
    protected $signature = 'jeeves:cache-cleanup
                            {--days= : Remove entries not hit in this many days (bare run: 30)}
                            {--min-hits= : Only remove entries with fewer than this many hits (bare run: 2)}
                            {--dataset= : Clean every entry for a dataset, unless --days/--min-hits narrow it}
                            {--all : Remove ALL cache entries (use with caution)}';

    protected $description = 'Clean up stale Jeeves cache entries';

    public function handle(QueryCacheInterface $cache): int
    {
        if ($this->option('all')) {
            if (!$this->confirm('This will remove ALL cache entries. Continue?')) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }

            $deleted = $cache->clear();
            $this->info("Cleared all {$deleted} cache entries.");

            return self::SUCCESS;
        }

        $dataset = $this->option('dataset');

        // clear() ANDs its filters. A bare run wants the conservative pair -
        // remove what is both stale and unused. Naming a dataset is a
        // different request: purge that dataset. ANDing the defaults in behind
        // the user meant --dataset could only ever delete rows nobody was
        // using, while the reason to run it -  a schema file changed and its
        // cached answers are now wrong -  describes rows being hit daily. The
        // documented remedy removed nothing and said "Cleaned up 0".
        //
        // Given explicitly, --days and --min-hits still narrow a dataset purge.
        $bareRun = $dataset === null;
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : ($bareRun ? 30 : 0);
        $minHits = $this->option('min-hits') !== null
            ? (int) $this->option('min-hits')
            : ($bareRun ? 2 : 0);

        $this->info('Cleaning Jeeves cache...');
        if ($dataset) {
            $this->line("  Dataset: {$dataset}");
        }
        $this->line($days > 0 ? "  Older than: {$days} days" : '  Age: any');
        $this->line($minHits > 0 ? "  Fewer hits than: {$minHits}" : '  Hits: any');

        $deleted = $cache->clear($dataset, $days, $minHits);

        $this->info("Cleaned up {$deleted} stale cache entries.");

        // Show remaining stats
        $stats = $cache->getStatistics();
        $this->line('Remaining entries: ' . ($stats['total_entries'] ?? 0));
        $this->line('Total hits: ' . ($stats['total_hits'] ?? 0));

        return self::SUCCESS;
    }
}
