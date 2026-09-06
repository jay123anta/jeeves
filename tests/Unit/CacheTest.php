<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Cache\NullQueryCache;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CacheTest extends TestCase
{
    #[Test]
    public function null_cache_find_returns_null()
    {
        $cache = new NullQueryCache;
        $this->assertNull($cache->find('any query'));
    }

    #[Test]
    public function null_cache_store_returns_true()
    {
        $cache = new NullQueryCache;
        $this->assertTrue($cache->store('query', ['dataset' => 'test']));
    }

    #[Test]
    public function null_cache_stats_show_disabled()
    {
        $cache = new NullQueryCache;
        $stats = $cache->getStatistics();
        $this->assertFalse($stats['enabled']);
        $this->assertEquals(0, $stats['total_entries']);
    }

    #[Test]
    public function null_cache_clear_returns_zero()
    {
        $cache = new NullQueryCache;
        $this->assertEquals(0, $cache->clear());
    }
}
