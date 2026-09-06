<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\LlmProviders\OllamaProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Characterisation test for `AbstractProvider::buildDatasetInfo()`, written
 * BEFORE it was rewritten to delegate to `Schema\DatasetCatalog` (NQ-001).
 *
 * The line format here is what every provider has sent on every intent
 * prompt since the package existed. Pinning it first, then moving the
 * rendering into a shared, unit-testable class, proves the move is
 * behaviour-identical rather than merely "looks the same on inspection".
 * `QueryPlanner::buildPlanPrompt()` had its own, slightly different, copy
 * of this loop -  that one was NOT pinned, because nothing in this codebase
 * asserts on its exact text, and absorbing it into the same renderer is a
 * deliberate, harmless enrichment (it gains `aliases=` and
 * `default group_by=`), not a behaviour this test protects.
 */
class DatasetInfoRenderingTest extends TestCase
{
    /** buildDatasetInfo() is protected -  the wire format is what matters, not the access. */
    private function render(array $datasetList): string
    {
        $provider = new OllamaProvider(['model' => 'llama3']);

        $method = new \ReflectionMethod($provider, 'buildDatasetInfo');
        $method->setAccessible(true);

        return $method->invoke($provider, $datasetList);
    }

    #[Test]
    public function a_dataset_with_every_field_renders_one_line_with_all_of_them()
    {
        $line = $this->render([
            [
                'key' => 'orders',
                'name' => 'Orders',
                'aliases' => ['sales', 'purchases', 'transactions', 'ignored-fourth-alias'],
                'metrics' => ['revenue' => [], 'quantity' => []],
                'dimensions' => ['region', 'status'],
                'default_dimension' => 'status',
            ],
        ]);

        $this->assertSame(
            '- orders (Orders): aliases=[sales, purchases, transactions], metrics=[revenue, quantity]'
            . ', group_by=[region, status], default group_by=status',
            $line
        );
    }

    #[Test]
    public function a_dataset_with_nothing_but_a_key_and_name_omits_the_optional_parts()
    {
        $line = $this->render([
            ['key' => 'orders', 'name' => 'Orders'],
        ]);

        $this->assertSame('- orders (Orders): aliases=[], metrics=[]', $line);
    }

    #[Test]
    public function multiple_datasets_render_one_line_each_newline_joined()
    {
        $rendered = $this->render([
            ['key' => 'orders', 'name' => 'Orders', 'metrics' => ['revenue' => []]],
            ['key' => 'customers', 'name' => 'Customers', 'dimensions' => ['region']],
        ]);

        $this->assertSame(
            "- orders (Orders): aliases=[], metrics=[revenue]\n"
            . '- customers (Customers): aliases=[], metrics=[], group_by=[region]',
            $rendered
        );
    }
}
