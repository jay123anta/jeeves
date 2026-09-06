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
 * Every answer says how the question was understood.
 *
 * Roughly one question in five is misread on an uncurated schema. The package
 * has always returned `parsed_query` -  dataset, metric, grouping, filters,
 * period -  but as structure a caller had to assemble into something readable,
 * and the bundled widget never did. A conversation turn got a summary line
 * from ConversationManager; an ordinary question, which is the first thing
 * anyone asks, got nothing at all.
 *
 * So the one signal that turns a silent misreading into a visible one was
 * shown on follow-ups and hidden on first questions. §0 names the worst
 * failure as a wrong number rendered confidently; this line is the cheapest
 * defence there is against it, and it was missing where it mattered most.
 */
class AnswerShowsHowItWasUnderstoodTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.query_mode', 'sql_generation');
    }

    private function seedOrders(): void
    {
        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_orders')->insert(['revenue' => 100]);
    }

    private function answer(array $sqlData): array
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => $sqlData];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');
    }

    /** A plain one-off question carries the line. */
    #[Test]
    public function an_ordinary_answer_states_how_it_was_read()
    {
        $this->seedOrders();

        $result = $this->answer([
            'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders',
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertArrayHasKey(
            'parsed_summary',
            $result,
            'an answer arrives with no statement of how the question was understood, so a misreading is '
                . 'indistinguishable from a correct reading: ' . json_encode($result)
        );

        // The dataset's display name, not its key -  this is read by a person.
        $this->assertStringContainsString('Orders', $result['parsed_summary']);
        $this->assertStringContainsString('revenue', $result['parsed_summary']);
    }

    /**
     * And it names the narrowing, which is the part worth checking: a filtered
     * answer that reads like an unfiltered one is the failure.
     *
     * INTENT mode, because that is where a filter is a contractual field the
     * model returns. SQL generation returns only a finished statement -  the
     * predicate lives inside the SQL string and nothing extracts it -  so on
     * that path the line names the dataset and measure but cannot name the
     * filter. Worth knowing rather than papering over: the honest fix is to
     * parse the predicate out, and that is a bigger change than this one.
     */
    #[Test]
    public function the_line_names_the_filter_that_was_applied()
    {
        config([
            'jeeves.query_mode' => 'intent',
            'jeeves.schema.config_path' => __DIR__ . '/../Stubs/single-dataset-schemas',
        ]);

        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->string('status');
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_orders')->insert(['status' => 'pending', 'revenue' => 100]);

        $provider = new RecordingProvider;
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
            'filters' => [['column' => 'status', 'value' => 'pending']],
            'confidence' => 0.95,
            'needs_clarification' => false,
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)->query('pending revenue', 'nq_orders');
        $this->assertStringContainsString(
            'pending',
            $result['parsed_summary'] ?? '',
            'the answer was narrowed to one status and the summary does not say so, so it reads exactly '
                . 'like the unfiltered total: ' . json_encode($result['parsed_summary'] ?? null)
        );
    }
}
