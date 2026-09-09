<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Execution-guided retry, for the one shape the benchmark shows failing over
 * and over: a question that asks which ONE thing is the most, answered with a
 * ranked list.
 *
 * "which carrier shipped the most orders" is `ORDER BY ... DESC LIMIT 1`. The
 * model writes it without the LIMIT, the top row is right, and every row below
 * it is wrong - so the answer reads as a list where the question asked for a
 * winner. Two benchmark questions fail exactly this way in every run.
 *
 * Nothing about that is visible before execution, which is why the existing
 * verifier cannot catch it: it runs on the SQL, and the SQL is valid. What
 * gives it away is the SHAPE of what came back.
 *
 * **The privacy wall is why this is done the way it is.** The row count is
 * inspected HERE, on your own server, and never leaves it. The retry prompt
 * carries the question, the schema and one sentence about the shape being
 * wrong. No value, no row, no count, and no driver message - the same rule
 * that governs the first attempt governs the second.
 */
class ASingularSuperlativeGetsOneMoreAttemptTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/superlative-schemas');
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
    }

    private function seedShipments(): void
    {
        Schema::dropIfExists('nq_shipments');
        Schema::create('nq_shipments', function (Blueprint $t) {
            $t->id();
            $t->string('carrier');
        });

        foreach (['royal mail', 'royal mail', 'dpd'] as $c) {
            DB::table('nq_shipments')->insert(['carrier' => $c]);
        }
    }

    /**
     * The provider answers without a LIMIT first, and with one once the retry
     * hint appears in the prompt.
     */
    private function provider(): RecordingProvider
    {
        $provider = new RecordingProvider;

        $provider->sqlResponse = function (string $prompt) {
            $limit = str_contains($prompt, 'RETRY') ? ' LIMIT 1' : '';

            return ['success' => true, 'data' => [
                'sql' => 'SELECT carrier, COUNT(*) AS record_count FROM nq_shipments '
                    . 'GROUP BY carrier ORDER BY record_count DESC' . $limit,
                'dataset' => 'nq_shipments',
                'metric' => 'record_count',
                'query_type' => 'ranking',
            ]];
        };

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $provider;
    }

    private function generateSqlCalls(RecordingProvider $p): int
    {
        return count(array_filter($p->calls, fn ($c) => $c['method'] === 'generateSql'));
    }

    #[Test]
    public function a_winner_question_answered_with_a_list_is_asked_again(): void
    {
        $this->seedShipments();
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('which carrier shipped the most orders', 'nq_shipments');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));

        $this->assertCount(
            1,
            $result['rows'] ?? [],
            'the question asks which ONE carrier shipped the most and the answer is still '
            . 'a ranked list, so the retry did not happen or was not adopted'
        );

        $this->assertSame(
            2,
            $this->generateSqlCalls($provider),
            'exactly one extra generation - never a loop'
        );
    }

    /**
     * COUNTERWEIGHT 1. A question that asks for a list must not be retried.
     * "top 5" is a ranking; many rows is the correct answer.
     */
    #[Test]
    public function a_question_that_asks_for_a_list_is_left_alone(): void
    {
        $this->seedShipments();
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('top 5 carriers by orders', 'nq_shipments');

        $this->assertSame('success', $result['status'] ?? null);
        $this->assertGreaterThan(1, count($result['rows'] ?? []), 'the ranked list was truncated');
        $this->assertSame(
            1,
            $this->generateSqlCalls($provider),
            'a ranking question was retried - the retry is firing on healthy answers, '
            . 'which costs a provider call on every list anyone asks for'
        );
    }

    /**
     * COUNTERWEIGHT 2. The success path must cost exactly what it costs today.
     * A winner question already answered with one row is not retried.
     */
    #[Test]
    public function an_answer_that_is_already_the_right_shape_costs_nothing_extra(): void
    {
        $this->seedShipments();

        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => 'SELECT carrier, COUNT(*) AS record_count FROM nq_shipments '
                . 'GROUP BY carrier ORDER BY record_count DESC LIMIT 1',
            'dataset' => 'nq_shipments',
            'metric' => 'record_count',
            'query_type' => 'ranking',
        ]];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('which carrier shipped the most orders', 'nq_shipments');

        $this->assertSame('success', $result['status'] ?? null);
        $this->assertCount(1, $result['rows'] ?? []);
        $this->assertSame(
            1,
            $this->generateSqlCalls($provider),
            'a correct answer was regenerated anyway'
        );
    }
}
