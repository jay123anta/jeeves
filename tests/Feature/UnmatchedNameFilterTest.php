<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Schema\Introspectors\MysqlIntrospector;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Hit in a browser on the demo app: "top 5 customers by revenue" came back
 * with "No data found for customers in Orders."
 *
 * The intent contract lets the model name one record to filter by, and it had
 * filled that in with "customers" -  the grouping dimension, not a customer.
 * The WHERE clause matched nothing, so a question with a perfectly good answer
 * became a dead end. It is non-deterministic: the same question usually parses
 * with no filter at all, which is exactly what makes it worth pinning down in
 * a test rather than hoping.
 */
class UnmatchedNameFilterTest extends TestCase
{
    private RecordingProvider $provider;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/privacy-schemas');
        // NQ-001: an app connection to seed through, and a separate
        // name the package reads through, over one database.
        $this->isolatedDatabase($app, 'nq_filter');
        $app['config']->set('jeeves.query_mode', 'intent');
        // Keep failures visible: the retry wrapper would otherwise replace the
        // real error with a generic "could not understand the query".
        $app['config']->set('jeeves.errors.retry_on_failure', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('nq_privacy_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('buyer');
            $table->decimal('total', 12, 2);
            $table->string('state');
        });

        DB::table('nq_privacy_orders')->insert([
            ['buyer' => 'Patgiri Traders', 'total' => 900.00, 'state' => 'settled'],
            ['buyer' => 'Kalita Stores', 'total' => 400.00, 'state' => 'settled'],
        ]);

        $this->app->instance(SchemaIntrospectorInterface::class, new MysqlIntrospector);
        $this->provider = new RecordingProvider;
        $this->app->instance(LlmProviderInterface::class, $this->provider);
    }

    /** Intent as the model returned it in the browser: the dimension as a filter. */
    private function intentFilteringOn(?string $filter): array
    {
        return [
            'success' => true,
            'dataset' => 'privacy_orders',
            'metric' => 'total',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => $filter,
            'confidence' => 0.9,
            'needs_clarification' => false,
        ];
    }

    #[Test]
    public function a_name_filter_that_matches_nothing_falls_back_to_the_unfiltered_answer()
    {
        $this->provider->intentResponse = $this->intentFilteringOn('buyers');

        $result = $this->app->make(QueryOrchestrator::class)->query('top 5 buyers by total');

        $this->assertSame('success', $result['status']);
        $this->assertNotSame('no_data', $result['type'] ?? null, 'a dead end is the bug being fixed');
        $this->assertCount(2, $result['rows']);
    }

    #[Test]
    public function the_answer_says_the_name_did_not_match_rather_than_hiding_it()
    {
        // Silently dropping the filter would be its own kind of wrong: the user
        // asked for something specific and must be told it was not honoured.
        $this->provider->intentResponse = $this->intentFilteringOn('buyers');

        $result = $this->app->make(QueryOrchestrator::class)->query('top 5 buyers by total');

        $this->assertStringContainsString('No match for "buyers"', $result['answer']);
        $this->assertSame('buyers', $result['metadata']['unmatched_filter']);
        $this->assertTrue($result['metadata']['filter_dropped']);
    }

    #[Test]
    public function a_filter_that_does_match_is_left_alone()
    {
        $this->provider->intentResponse = $this->intentFilteringOn('Kalita');

        $result = $this->app->make(QueryOrchestrator::class)->query('total for Kalita');

        $this->assertSame('success', $result['status']);
        $this->assertArrayNotHasKey('filter_dropped', $result['metadata']);
        foreach ($result['rows'] as $row) {
            $this->assertStringContainsString('Kalita', ((array) $row)['buyer']);
        }
    }

    #[Test]
    public function an_unfiltered_query_that_genuinely_has_no_data_still_reports_no_data()
    {
        // The fallback must not invent an answer when the table really is empty.
        DB::table('nq_privacy_orders')->delete();
        $this->provider->intentResponse = $this->intentFilteringOn('buyers');

        $result = $this->app->make(QueryOrchestrator::class)->query('top 5 buyers by total');

        $this->assertSame('no_data', $result['type'] ?? null);
    }

    #[Test]
    public function the_fallback_costs_no_extra_provider_call()
    {
        // It is a second local query, not a second round trip to the model.
        $this->provider->intentResponse = $this->intentFilteringOn('buyers');

        $this->app->make(QueryOrchestrator::class)->query('top 5 buyers by total');

        $this->assertCount(1, $this->provider->calls);
    }
}
