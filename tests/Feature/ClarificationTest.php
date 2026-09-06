<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Engine\QueryPlanner;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Asking "which is the best?" of a single-dataset app produced "Which dataset
 * would you like to query?" with one button on it. Clicking that button re-sent
 * the same question, got back the same response, and redrew the same card -
 * so the widget looked broken while behaving exactly as written.
 *
 * The dataset was never in doubt. The model says clarification_type=dataset
 * when it cannot tell what "best" measures, and that was taken literally.
 */
class ClarificationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
        $app['config']->set('jeeves.query_mode', 'intent');
    }

    /** Answer every intent parse with the given payload. */
    private function provider(array $intent): RecordingProvider
    {
        $provider = new RecordingProvider;
        $provider->intentResponse = $intent + [
            'success' => true,
            'confidence' => 0.5,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);
        $this->app->forgetInstance(QueryPlanner::class);

        return $provider;
    }

    #[Test]
    public function a_single_dataset_app_is_never_asked_which_dataset()
    {
        $this->provider([
            'dataset' => null,
            'metric' => null,
            'needs_clarification' => true,
            'clarification_type' => 'dataset',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('which is the best?');

        $this->assertSame('clarification_needed', $result['status']);
        $this->assertSame(
            'metric_clarification',
            $result['type'],
            'there is only one dataset, so the open question is what to measure'
        );
    }

    #[Test]
    public function a_metric_clarification_actually_offers_metrics()
    {
        // An empty options list is what made the card a dead end.
        $this->provider([
            'dataset' => 'gb_sales',
            'metric' => null,
            'needs_clarification' => true,
            'clarification_type' => 'dataset',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('which is the best?');

        $this->assertNotEmpty($result['available_metrics']);

        $keys = array_column($result['available_metrics'], 'key');
        $this->assertContains('revenue', $keys);
        $this->assertContains('record_count', $keys);
    }

    /**
     * "Revenue by region" is line_total in one table and region in another.
     * The model correctly reports it cannot group the dataset it chose by a
     * column that dataset does not have -  and the prompt tells it to say
     * 'ambiguous' in exactly that case.
     *
     * Turning that into "which metric did you mean?" is a dead end: the user
     * already named the metric, and no button on the card can fix a missing
     * JOIN. Auto mode should try SQL generation, which can write the join.
     */
    #[Test]
    public function an_unavailable_breakdown_across_related_tables_is_retried_as_sql()
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/related-schemas']);
        config(['jeeves.query_mode' => 'auto']);
        $this->app->forgetInstance(SchemaRegistry::class);

        $provider = $this->provider([
            'dataset' => 'rel_orders',
            'metric' => 'quantity',
            'needs_clarification' => true,
            'clarification_type' => 'ambiguous',
        ]);

        $this->app->make(QueryOrchestrator::class)->query('quantity by customer name');

        // The attempt is the behaviour. Whether the generated SQL then answers
        // depends on the model, but stopping at a menu without trying is the
        // bug: no button on that card can supply a missing JOIN.
        $this->assertContains(
            'generateSql',
            $provider->methodsCalled(),
            'a joinable question must be retried as SQL, not answered with a menu'
        );
    }

    #[Test]
    public function a_genuine_metric_question_is_still_asked_not_guessed()
    {
        // Only 'ambiguous' means "wrong dataset for this breakdown". A real
        // metric ambiguity must still be put to the user rather than resolved
        // by letting the model write whatever SQL it likes.
        $this->provider([
            'dataset' => 'gb_sales',
            'metric' => null,
            'needs_clarification' => true,
            'clarification_type' => 'metric',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('which is the best?');

        $this->assertSame('clarification_needed', $result['status']);
    }

    /**
     * Every clarification used to carry the dataset list, so a METRIC question
     * rendered the dataset name as an extra button among the metrics -
     * "Orders" beside "Quantity" and "Revenue". Clicking it re-sent the same
     * question with the dataset it already had, received the same response, and
     * redrew the same card. A button that does nothing, on the screen whose
     * only job is to give the user somewhere to go.
     */
    #[Test]
    public function a_metric_question_offers_metrics_and_not_datasets()
    {
        $this->provider([
            'dataset' => 'gb_sales',
            'metric' => null,
            'needs_clarification' => true,
            'clarification_type' => 'metric',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('who is the best');

        $this->assertSame('metric_clarification', $result['type']);
        $this->assertNotEmpty($result['available_metrics']);
        $this->assertSame([], $result['alternatives'], 'the dataset is already settled');
    }

    #[Test]
    public function a_dataset_question_still_offers_datasets()
    {
        // The guard on the fix: removing them everywhere would break the one
        // clarification that genuinely needs them.
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/related-schemas']);
        $this->app->forgetInstance(SchemaRegistry::class);

        $this->provider([
            'dataset' => null,
            'metric' => null,
            'needs_clarification' => true,
            'clarification_type' => 'dataset',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('show me something');

        $this->assertSame('dataset_clarification', $result['type']);
        $this->assertNotEmpty($result['alternatives']);
    }

    #[Test]
    public function a_date_is_never_offered_as_something_to_be_best_at()
    {
        // Sorting by a date is reasonable; "best by order_date" is not, and a
        // dead option among live ones makes the whole list look untrustworthy.
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/time-schemas']);
        $this->app->forgetInstance(SchemaRegistry::class);

        $metrics = array_column(
            $this->app->make(SchemaRegistry::class)->getDatasetMetrics('tf_sales'),
            'key'
        );

        $this->assertContains('revenue', $metrics);
        $this->assertNotContains('order_date', $metrics);
        $this->assertNotContains('shipped_at', $metrics);
    }

    #[Test]
    public function the_resolved_dataset_is_reported_back()
    {
        $this->provider([
            'dataset' => null,
            'metric' => null,
            'needs_clarification' => true,
            'clarification_type' => 'dataset',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('which is the best?');

        $this->assertSame('gb_sales', $result['parsed_query']['dataset']);
    }
}
