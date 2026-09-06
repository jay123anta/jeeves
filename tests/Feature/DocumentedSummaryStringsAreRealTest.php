<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The strings README.md and docs/API.md promise must be strings the code emits.
 *
 * Both documents quote `parsed_summary` output verbatim, and for the whole of
 * the release that introduced the feature not one of those strings was
 * producible by any input -  the line was assembled from a result array whose
 * keys did not match the slots the builder read, so the breakdown and the
 * period could never appear. The documentation described a feature that did
 * not exist, confidently, which is the same failure the feature exists to
 * prevent, one level up.
 *
 * Rule 1 says documentation stays in sync with code. A comment saying so did
 * not achieve it and a reviewer reading both did not catch it. Asserting the
 * exact documented strings does: change the rendering and these fail, naming
 * the files that have gone stale.
 */
class DocumentedSummaryStringsAreRealTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/documented-summary-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
        $app['config']->set('jeeves.query_mode', 'intent');
    }

    private function seedOrders(): void
    {
        Schema::dropIfExists('doc_orders');
        Schema::create('doc_orders', function ($t) {
            $t->id();
            $t->string('region');
            $t->string('status');
            $t->date('placed_on');
            $t->decimal('revenue', 12, 2);
        });

        foreach ([
            ['West', 'pending', '2026-07-10', 2028763],
            ['East', 'pending', '2026-07-12', 1878404],
        ] as [$r, $s, $d, $v]) {
            DB::table('doc_orders')->insert([
                'region' => $r, 'status' => $s, 'placed_on' => $d, 'revenue' => $v,
            ]);
        }
    }

    private function answer(array $intent): array
    {
        $provider = new RecordingProvider;
        $provider->intentResponse = array_merge([
            'success' => true,
            'dataset' => 'doc_orders',
            'metric' => 'revenue',
            'group_by' => 'region',
            'query_type' => 'ranking',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
            'confidence' => 0.95,
            'needs_clarification' => false,
        ], $intent);

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('revenue by region', 'doc_orders');
    }

    /**
     * README.md prose, docs/API.md and CHANGELOG.md all quote this exact line.
     */
    #[Test]
    public function the_filtered_breakdown_string_in_the_docs_is_producible()
    {
        $this->seedOrders();

        $result = $this->answer([
            'filters' => [['column' => 'status', 'value' => 'pending']],
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            'Orders · revenue · by region · status is pending',
            $result['parsed_summary'] ?? null,
            'README.md, docs/API.md and CHANGELOG.md all quote this string as output; update them '
                . 'in the same change set that changes the rendering (Rule 1)'
        );
    }

    /**
     * The README response example quotes this one, period and all.
     */
    #[Test]
    public function the_dated_breakdown_string_in_the_readme_is_producible()
    {
        $this->seedOrders();

        $result = $this->answer([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            'Orders · revenue · by region · 2026-07-01 to 2026-07-31',
            $result['parsed_summary'] ?? null,
            'the README response example quotes this string; it was unproducible for an entire '
                . 'release because the period slot had no source on this route'
        );
    }
}
