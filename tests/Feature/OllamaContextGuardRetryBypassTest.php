<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Engine\ErrorCode;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-002: the retry path undoes OllamaProvider's context-window refusal.
 *
 * v2.0.0 shipped OllamaProvider::willNotFit() so a prompt that would not fit
 * in num_ctx is refused BEFORE anything is sent -  because Ollama does not
 * reject an oversized prompt, it silently drops the beginning (the schema)
 * and answers from what is left.
 *
 * That refusal is undone one layer up. QueryOrchestrator::handle() treats the
 * refusal exactly like any other provider failure: status 'error',
 * config('jeeves.errors.retry_on_failure') defaults true, no
 * '_retried' or '_rate_limited' flag set -  so it retries.
 * retryWithRefinedPrompt() then detects a dataset from keywords and builds a
 * SINGLE-dataset prompt, dramatically smaller than the multi-dataset prompt
 * that was just refused. That prompt clears the same num_ctx check and is
 * sent for real.
 *
 * Net effect: a question on a linked, multi-table schema that SHOULD produce
 * the loud "raise num_ctx" refusal instead produces a confident answer
 * computed from ONE table, under a prompt that told the model that table was
 * all there was. metadata.retry = true is the only trace.
 *
 * SIZING (tests/Stubs/related-schemas, 4 linked tables, query "total revenue
 * from orders"), measured directly from PromptBuilder before this test was
 * written, using OllamaProvider's own arithmetic (bytes/3, +512 for the
 * answer):
 *   - buildMultiDatasetPrompt(): 4,541 bytes -> needs ~2,026 tokens
 *   - buildSqlPrompt('rel_orders', ...): 2,608 bytes -> needs ~1,382 tokens
 * num_ctx is set to 1700, comfortably between the two (326 tokens of margin
 * against the multi-dataset figure, 318 against the single-dataset one), so
 * the multi-dataset prompt is refused and the single-dataset retry prompt is
 * not.
 */
class OllamaContextGuardRetryBypassTest extends TestCase
{
    private const QUERY = 'total revenue from orders';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/related-schemas');
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.llm.driver', 'ollama');
        $app['config']->set('jeeves.llm.providers.ollama.model', 'llama3');
        $app['config']->set('jeeves.llm.providers.ollama.num_ctx', 1700);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // rel_orders per tests/Stubs/related-schemas/rel_orders.php:
        // columns customer_id, quantity, unit_price, status.
        Schema::create('rel_orders', function ($t) {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->integer('quantity');
            $t->decimal('unit_price', 10, 2);
            $t->string('status')->default('paid');
        });

        // Hand-checkable: (2*100) + (1*150) + (3*50) = 200 + 150 + 150 = 500.
        DB::table('rel_orders')->insert([
            ['customer_id' => 1, 'quantity' => 2, 'unit_price' => 100.00],
            ['customer_id' => 2, 'quantity' => 1, 'unit_price' => 150.00],
            ['customer_id' => 1, 'quantity' => 3, 'unit_price' => 50.00],
        ]);
    }

    /**
     * A plausible response for the RETRY's single-dataset prompt -  the
     * multi-dataset prompt is never sent, so no fake is needed for it.
     */
    private function fakeSingleDatasetSqlResponse(): void
    {
        Http::fake(['*' => Http::response([
            'response' => json_encode([
                'sql' => 'SELECT SUM(quantity * unit_price) AS revenue FROM rel_orders',
                'dataset' => 'rel_orders',
                'query_type' => 'aggregation',
                'metric' => 'revenue',
                'group_value' => null,
                'order' => 'DESC',
                'limit' => 100,
                'period' => null,
                'explanation' => 'Total revenue from rel_orders',
            ]),
        ], 200)]);
    }

    /**
     * Catches: the refusal is a LOCAL decision (no HTTP call). If the retry
     * fires with a smaller prompt, that prompt DOES clear num_ctx and a real
     * request goes out -  one request where a refused question should produce
     * none. Today that count is 1.
     */
    #[Test]
    public function the_refused_prompt_is_never_sent_by_any_route()
    {
        $this->fakeSingleDatasetSqlResponse();

        $this->app->make(QueryOrchestrator::class)->query(self::QUERY);

        Http::assertNothingSent();
    }

    /**
     * Catches: the CALLER ends up with a success -  a number -  instead of the
     * actionable refusal OllamaProvider tried to give them. The refusal named
     * num_ctx and how much context the question needed; none of that reaches
     * the caller once the retry has silently answered a narrower question.
     */
    #[Test]
    public function the_caller_receives_the_refusal_not_a_confident_answer()
    {
        $this->fakeSingleDatasetSqlResponse();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUERY);

        $this->assertSame('error', $result['status'] ?? null, 'expected the num_ctx refusal, got: ' . json_encode($result));
        $this->assertSame(ErrorCode::PROVIDER_ERROR, $result['error_code'] ?? null);
        $this->assertStringContainsString('num_ctx', $result['error'] ?? '');
    }

    /**
     * Pins the one workaround an adopter has today. With retries off, the
     * local refusal from willNotFit() is never overwritten, so this already
     * passes -  it is not evidence the defect is fixed, only that turning off
     * jeeves.errors.retry_on_failure avoids it.
     */
    #[Test]
    public function turning_off_retry_on_failure_already_avoids_the_bypass()
    {
        config(['jeeves.errors.retry_on_failure' => false]);
        $this->fakeSingleDatasetSqlResponse();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUERY);

        Http::assertNothingSent();
        $this->assertSame('error', $result['status'] ?? null);
        $this->assertStringContainsString('num_ctx', $result['error'] ?? '');
    }
}
