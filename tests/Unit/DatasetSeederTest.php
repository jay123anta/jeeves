<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\DatasetSeeder;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-001-REDUCE trimmed this to `detect()` alone. `seeds()` and its three
 * per-signal helpers existed only to feed `SchemaShortlister`, the
 * scope-narrowing half of NQ-001-v2 the G1-round-3 ruling struck out
 * entirely, and were deleted with it.
 *
 * `detect()` is load-bearing on its own: `QueryOrchestrator::
 * resolveAskingDataset()` calls it, and that is what stops the NQ-003 cache
 * fix from replaying one dataset's cached answer for another (see
 * `tests/Feature/FuzzyCacheDatasetIsolationTest.php`).
 *
 * Fixture reused from `tests/Stubs/related-schemas` -  it was not written for
 * this test, but its two aliased, unrelated datasets (rel_orders / "orders",
 * rel_customers / "customers") are exactly the shape `detect()` needs.
 */
class DatasetSeederTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/related-schemas');
    }

    private function seeder(): DatasetSeeder
    {
        return new DatasetSeeder($this->app->make(SchemaRegistry::class));
    }

    /** Priority 1: a configured query_routing rule wins. */
    #[Test]
    public function a_routing_keyword_is_detected()
    {
        config(['jeeves.query_routing' => ['tenant sales' => 'rel_sales']]);

        $this->assertSame('rel_sales', $this->seeder()->detect('show me tenant sales for last month'));
    }

    /**
     * A single best guess, never more than one dataset -  the whole reason
     * NQ-001-v2 needed a second method (`seeds()`, now deleted) for the
     * scope-widening half of the design.
     */
    #[Test]
    public function only_one_dataset_is_ever_returned_even_when_two_keywords_match()
    {
        config([
            'jeeves.query_routing' => [
                'gizmo' => 'rel_customers',
                'widget' => 'rel_orders',
            ],
        ]);

        $result = $this->seeder()->detect('report on gizmo and widget sales');

        $this->assertIsString($result);
        $this->assertContains($result, ['rel_customers', 'rel_orders']);
    }

    /**
     * Property 13 / I10: with no routing rule and no alias match, two
     * questions differing only in a domain noun the schema has never heard
     * of must produce the SAME result -  a synonym table or a column-name
     * heuristic would make them differ, and I10 forbids both.
     */
    #[Test]
    public function two_unmatched_domain_nouns_produce_the_same_result()
    {
        config(['jeeves.query_routing' => []]);

        $seeder = $this->seeder();

        $this->assertSame(
            $seeder->detect('tell me about the walruses'),
            $seeder->detect('tell me about the giraffes')
        );
    }
}
