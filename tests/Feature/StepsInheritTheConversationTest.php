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
 * A decomposed question inside a conversation is still inside the conversation.
 *
 * runSteps() re-enters query() as `$this->query($question, $datasetHint)` -
 * no third argument -  so the conversation context is dropped at the door.
 * Two consequences, and the second is the one that outlives the request.
 *
 * The steps cannot see the conversation. A follow-up like "compare those two
 * regions" is decomposed into sub-questions that are then answered with no
 * knowledge of the metric, period or filters established in earlier turns.
 *
 * And every step WRITES to the query cache. The guard added in this release -
 * "a conversation turn is neither read from nor written to this cache" -  is
 * keyed on `!empty($context['state'])`, and with the context gone each step
 * looks like an ordinary standalone question. The cache key is the question's
 * text and carries no session, so a row written by one conversation's step is
 * read back by every other session that asks those words. docs/CONVERSATIONS.md
 * promises the opposite in as many words.
 *
 * It is the write, not the read, that does the damage: the row outlives the
 * conversation that created it.
 */
class StepsInheritTheConversationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.chat.multi_step', true);
    }

    private function seedTables(): void
    {
        foreach (['nq_orders', 'nq_products'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($t) {
                $t->id();
                $t->decimal('revenue', 12, 2);
            });
            DB::table($table)->insert(['revenue' => 100]);
        }
    }

    private function planningProvider(): RecordingProvider
    {
        $provider = new RecordingProvider;
        $n = 0;
        $provider->sqlResponse = function (string $prompt) use (&$n) {
            $n++;
            if ($n === 1) {
                return ['success' => true, 'data' => ['steps' => [
                    'total revenue for nq_orders',
                    'total revenue for nq_products',
                ]]];
            }

            $table = str_contains($prompt, 'nq_products') ? 'nq_products' : 'nq_orders';

            return [
                'success' => true,
                'data' => [
                    'sql' => "SELECT SUM(revenue) AS revenue FROM {$table}",
                    'dataset' => $table,
                    'query_type' => 'aggregation',
                ],
            ];
        };
        $this->app->instance(LlmProviderInterface::class, $provider);

        return $provider;
    }

    /** No step of a conversation turn may reach the shared, session-less cache. */
    #[Test]
    public function steps_of_a_conversation_turn_are_not_written_to_the_cache()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $this->planningProvider();

        $table = config('jeeves.cache.table_name', 'jeeves_cache');
        $before = DB::table($table)->count();

        $result = $this->app->make(QueryOrchestrator::class)->query(
            'compare revenue for nq_orders with nq_products',
            'nq_orders',
            ['state' => ['dataset' => 'nq_orders', 'metric' => 'revenue']]
        );

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));

        $this->assertSame(
            $before,
            DB::table($table)->count(),
            'the steps of a conversation turn were written to the text-keyed query cache; the key carries '
                . 'no session, so another session asking those words reads this conversation\'s rows back'
        );
    }

    /**
     * And the guarantee this must not cost: a decomposed question OUTSIDE a
     * conversation still caches its steps, which is what makes a repeat cheap.
     */
    #[Test]
    public function steps_of_an_ordinary_question_are_still_cached()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $this->planningProvider();

        $table = config('jeeves.cache.table_name', 'jeeves_cache');

        $this->app->make(QueryOrchestrator::class)
            ->query('compare revenue for nq_orders with nq_products', 'nq_orders');

        $this->assertGreaterThan(
            0,
            DB::table($table)->count(),
            'an ordinary decomposed question stopped caching its steps entirely'
        );
    }
}
