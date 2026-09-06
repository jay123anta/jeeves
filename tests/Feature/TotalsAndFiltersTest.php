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
 * A total is one number, and a filter narrows it.
 *
 * All of this came out of running the same questions through two providers.
 * "How many invoices are pending" answered "Rekha Stores: 1 records" -  the
 * count was right and the shape was a different question, which is the worse
 * kind of wrong because nothing about it looks broken. A reader takes it to
 * mean only Rekha Stores has pending invoices.
 *
 * Two separate causes, both here:
 *
 *   1. SqlBuilder checked "does this have a filter" BEFORE "is this a total",
 *      so any total carrying a filter became a filtered ranking.
 *   2. The model put the same narrowing in `filters` AND in `group_value`, and
 *      a non-empty group_value disqualifies a query from being a total at all.
 */
class TotalsAndFiltersTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.query_mode', 'intent');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gb_sales', function ($t) {
            $t->id();
            $t->string('customer_name');
            $t->string('region');
            $t->string('status');
            $t->decimal('revenue', 12, 2);
        });

        DB::table('gb_sales')->insert([
            ['customer_name' => 'Ada', 'region' => 'West', 'status' => 'delivered', 'revenue' => 300],
            ['customer_name' => 'Grace', 'region' => 'West', 'status' => 'pending', 'revenue' => 500],
            ['customer_name' => 'Alan', 'region' => 'East', 'status' => 'delivered', 'revenue' => 200],
        ]);
    }

    /** @param array<string, mixed> $intent */
    private function answer(array $intent): array
    {
        $provider = new RecordingProvider;
        $provider->intentResponse = array_merge([
            'success' => true,
            'dataset' => 'gb_sales',
            'confidence' => 0.9,
            'needs_clarification' => false,
        ], $intent);

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('total revenue in West');
    }

    #[Test]
    public function a_total_with_a_filter_is_still_one_number()
    {
        $result = $this->answer([
            'metric' => 'revenue',
            'query_type' => 'aggregation',
            'filters' => [['column' => 'region', 'value' => 'West']],
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertCount(1, $result['rows'], 'a filtered total came back as a list');
        // West only: 300 + 500. Not 1,000, which would mean the filter was lost.
        $this->assertEquals(800, (float) array_values((array) $result['rows'][0])[0]);
        $this->assertSame('aggregation', $result['parsed_query']['query_type']);
    }

    /**
     * The filter must actually be applied, not merely survive the reshaping.
     * A total that quietly covers everything is the failure this package exists
     * to prevent.
     */
    #[Test]
    public function the_filter_is_not_silently_dropped_on_the_way()
    {
        $unfiltered = $this->answer(['metric' => 'revenue', 'query_type' => 'aggregation']);
        $filtered = $this->answer([
            'metric' => 'revenue',
            'query_type' => 'aggregation',
            'filters' => [['column' => 'region', 'value' => 'West']],
        ]);

        $this->assertEquals(1000, (float) array_values((array) $unfiltered['rows'][0])[0]);
        $this->assertEquals(800, (float) array_values((array) $filtered['rows'][0])[0]);
    }

    /**
     * The same narrowing said twice. group_value matches against the GROUP
     * column, so the copy asks for a customer named "West" -  and its presence
     * alone stops the query being a total.
     */
    #[Test]
    public function a_group_value_duplicating_a_filter_is_discarded()
    {
        $result = $this->answer([
            'metric' => 'revenue',
            'query_type' => 'aggregation',
            'filters' => [['column' => 'region', 'value' => 'West']],
            'group_value' => 'West',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertCount(1, $result['rows']);
        $this->assertEquals(800, (float) array_values((array) $result['rows'][0])[0]);
    }

    /**
     * A group_value that is NOT a duplicate is a real instruction and stays.
     * "Revenue for Ada" names one record and must not be mistaken for noise.
     */
    #[Test]
    public function a_genuine_group_value_is_left_alone()
    {
        $result = $this->answer([
            'metric' => 'revenue',
            'group_value' => 'Ada',
            'filters' => [['column' => 'region', 'value' => 'West']],
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Ada', $result['parsed_query']['group_value'] ?? null);
    }

    /**
     * "Total amount by city" then "only in Guwahati".
     *
     * That produces group_by=city with filters=[city:Guwahati] -  a filter on
     * the very column being grouped by -  and resolveFilters skipped exactly
     * that case, so every city came back and the narrowing vanished without a
     * word. All three providers were emitting the filter correctly; it was
     * discarded here.
     *
     * GROUP BY region WHERE region = 'West' is well-formed and returns the one
     * row the question asked for.
     */
    #[Test]
    public function a_filter_on_the_grouping_column_is_applied_not_skipped()
    {
        $provider = new RecordingProvider;
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_by' => 'region',
            'filters' => [['column' => 'region', 'value' => 'West']],
            'confidence' => 0.9,
            'needs_clarification' => false,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)->query('revenue by region only in West');

        $this->assertSame('success', $result['status']);
        $this->assertCount(1, $result['rows'], 'the narrowing was dropped and every region came back');

        $row = (array) $result['rows'][0];
        $this->assertSame('West', $row['region']);
        $this->assertEquals(800, (float) $row['revenue']);
    }

    /**
     * And it must still be visible. A filter that runs but goes unmentioned is
     * only half the fix -  the point of the summary is that a narrowing can be
     * seen rather than assumed.
     */
    #[Test]
    public function that_filter_is_reported_in_the_parsed_query()
    {
        $provider = new RecordingProvider;
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_by' => 'region',
            'filters' => [['column' => 'region', 'value' => 'West']],
            'confidence' => 0.9,
            'needs_clarification' => false,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $filters = $this->app->make(QueryOrchestrator::class)
            ->query('revenue by region only in West')['parsed_query']['filters'] ?? [];

        $this->assertCount(1, $filters);
        $this->assertSame('West', $filters[0]['value'] ?? null);
    }

    #[Test]
    public function a_breakdown_that_was_asked_for_is_still_a_breakdown()
    {
        $provider = new RecordingProvider;
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_by' => 'region',
            'confidence' => 0.9,
            'needs_clarification' => false,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)->query('revenue by region');

        $this->assertCount(2, $result['rows'], 'a requested breakdown was collapsed into a total');
    }
}
