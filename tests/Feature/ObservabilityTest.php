<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\ReportsUsage;
use Jayanta\Jeeves\Engine\ErrorCode;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Events\QuestionAnswered;
use Jayanta\Jeeves\Events\QuestionAsked;
use Jayanta\Jeeves\Events\QuestionFailed;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * What an application can see about its own AI feature.
 *
 * The package answered questions and told nobody anything. There was no way to
 * attribute cost to a user, no way to alert on a provider outage, no way to
 * review the questions being answered badly -  short of patching the package or
 * scraping logs. For a feature that spends money on every request and can be
 * confidently wrong, that is the gap that matters most in production.
 *
 * Events give an application somewhere to stand, and token counts give it
 * something to count. Both are additive: an app that listens to nothing behaves
 * exactly as before.
 */
class ObservabilityTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
        $app['config']->set('jeeves.routes.middleware', []);
        $app['config']->set('jeeves.cache.enabled', false);
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
            ['customer_name' => 'Grace', 'region' => 'East', 'status' => 'pending', 'revenue' => 500],
        ]);
    }

    private function provider(array $overrides = []): RecordingProvider
    {
        $provider = new RecordingProvider;
        $provider->intentResponse = array_merge([
            'success' => true,
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_by' => 'region',
            'confidence' => 0.9,
            'needs_clarification' => false,
        ], $overrides);

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $provider;
    }

    private function orchestrator(): QueryOrchestrator
    {
        return $this->app->make(QueryOrchestrator::class);
    }

    // ------------------------------------------------------------- events

    #[Test]
    public function a_question_is_announced_before_anything_is_spent()
    {
        $this->provider();
        Event::fake([QuestionAsked::class]);

        $this->orchestrator()->query('revenue by region');

        Event::assertDispatched(QuestionAsked::class, function ($e) {
            $this->assertSame('revenue by region', $e->question);
            $this->assertSame('auto', $e->queryMode);

            return true;
        });
    }

    #[Test]
    public function a_successful_answer_reports_how_it_was_understood_and_what_ran()
    {
        $this->provider();
        Event::fake([QuestionAnswered::class]);

        $this->orchestrator()->query('revenue by region');

        Event::assertDispatched(QuestionAnswered::class, function ($e) {
            $this->assertSame('region', $e->parsedQuery['group_by'] ?? null);
            $this->assertSame(2, $e->rowCount);
            $this->assertNotNull($e->sql, 'the SQL that ran is not on the event');
            $this->assertGreaterThan(0, $e->durationMs);

            return true;
        });
    }

    /**
     * Result rows are deliberately absent. A listener that wanted the data can
     * re-run the SQL; putting rows on an event walks them into log drivers,
     * queue payloads and error trackers -  the one direction this package
     * exists to keep data out of.
     */
    #[Test]
    public function the_answered_event_carries_a_row_count_and_not_the_rows()
    {
        $this->provider();
        $captured = null;
        Event::listen(QuestionAnswered::class, function ($e) use (&$captured) {
            $captured = $e;
        });

        $this->orchestrator()->query('revenue by region');

        $this->assertNotNull($captured);
        $this->assertFalse(property_exists($captured, 'rows'), 'result rows were put on an event');
        $this->assertStringNotContainsString('Ada', json_encode(get_object_vars($captured)));
    }

    #[Test]
    public function a_failure_is_its_own_event_carrying_the_machine_code()
    {
        $unreachable = ['success' => false, 'error' => 'Unable to connect to AI service.'];
        $provider = $this->provider();
        $provider->intentResponse = $unreachable;
        $provider->sqlResponse = $unreachable;

        Event::fake([QuestionFailed::class, QuestionAnswered::class]);

        $this->orchestrator()->query('revenue by region');

        Event::assertNotDispatched(QuestionAnswered::class);
        Event::assertDispatched(QuestionFailed::class, function ($e) {
            $this->assertSame(ErrorCode::PROVIDER_ERROR, $e->errorCode);

            return true;
        });
    }

    /**
     * Being asked which measure you meant is the system working. Firing a
     * failure event for it would fill an alerting channel with successes.
     */
    #[Test]
    public function a_clarification_is_not_reported_as_a_failure()
    {
        $this->provider(['needs_clarification' => true, 'clarification_type' => 'metric', 'metric' => null]);
        Event::fake([QuestionFailed::class, QuestionAnswered::class]);

        $result = $this->orchestrator()->query('who is the best');

        $this->assertSame('clarification_needed', $result['status']);
        Event::assertNotDispatched(QuestionFailed::class);
        Event::assertNotDispatched(QuestionAnswered::class);
    }

    #[Test]
    public function listening_to_nothing_changes_nothing()
    {
        $this->provider();

        $result = $this->orchestrator()->query('revenue by region');

        $this->assertSame('success', $result['status']);
        $this->assertCount(2, $result['rows']);
    }

    // -------------------------------------------------------------- cost

    #[Test]
    public function token_counts_reach_the_response_when_the_provider_reports_them()
    {
        // Gemini's dialect. The orchestrator must not care which one it is.
        $provider = new UsageReportingProvider;
        $provider->body = ['usageMetadata' => [
            'promptTokenCount' => 1200,
            'candidatesTokenCount' => 80,
            'thoughtsTokenCount' => 25,
            'totalTokenCount' => 1305,
        ]];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $usage = $this->orchestrator()->query('revenue by region')['metadata']['usage'] ?? [];

        $this->assertSame(1200, $usage['prompt_tokens'] ?? null);
        $this->assertSame(1305, $usage['total_tokens'] ?? null);
        // Gemini bills thinking separately from candidates, so a total built by
        // adding the two visible numbers is short by the least predictable part.
        $this->assertSame(25, $usage['thinking_tokens'] ?? null);
    }

    #[Test]
    public function the_openai_dialect_is_read_the_same_way()
    {
        $provider = new UsageReportingProvider;
        $provider->body = ['usage' => [
            'prompt_tokens' => 900,
            'completion_tokens' => 60,
            'total_tokens' => 960,
        ]];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $usage = $this->orchestrator()->query('revenue by region')['metadata']['usage'] ?? [];

        $this->assertSame(900, $usage['prompt_tokens'] ?? null);
        $this->assertSame(960, $usage['total_tokens'] ?? null);
    }

    /**
     * Answering one question can take several calls -  a fallback, a retry, the
     * steps of a decomposed question. Reporting only the last would understate
     * exactly the questions that cost the most.
     */
    #[Test]
    public function several_calls_for_one_question_add_up()
    {
        $provider = new UsageReportingProvider;
        $provider->body = ['usage' => ['prompt_tokens' => 100, 'total_tokens' => 120]];

        $provider->recordUsagePublic(['usage' => ['prompt_tokens' => 100, 'total_tokens' => 120]]);
        $provider->recordUsagePublic(['usage' => ['prompt_tokens' => 100, 'total_tokens' => 120]]);

        $this->assertSame(200, $provider->lastUsage()['prompt_tokens']);
        $this->assertSame(2, $provider->lastUsage()['calls']);
    }

    /**
     * The provider is a singleton for the request, so without a reset the
     * second question of a conversation reports the first one's tokens too -
     * turning per-question cost into a running total nobody asked for.
     */
    #[Test]
    public function each_question_is_counted_on_its_own()
    {
        $provider = new UsageReportingProvider;
        $provider->body = ['usage' => ['prompt_tokens' => 500, 'total_tokens' => 550]];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $first = $this->orchestrator()->query('revenue by region')['metadata']['usage'] ?? [];
        $second = $this->orchestrator()->query('revenue by region')['metadata']['usage'] ?? [];

        $this->assertSame($first['total_tokens'], $second['total_tokens'], 'the second question inherited the first one\'s tokens');
    }

    /**
     * A provider that reports nothing must not be made to look free. An
     * omitted figure is honest; a zero would understate a bill.
     */
    #[Test]
    public function a_provider_that_reports_no_usage_reports_none()
    {
        $this->provider();

        $metadata = $this->orchestrator()->query('revenue by region')['metadata'];

        $this->assertArrayNotHasKey('usage', $metadata);
    }
}

/** A provider whose HTTP body the test controls, to exercise usage parsing. */
class UsageReportingProvider extends RecordingProvider implements ReportsUsage
{
    public array $body = [];

    /** @var array<string, int> */
    private array $usage = [];

    public function parseIntent(string $text, array $datasetList): array
    {
        $this->recordUsagePublic($this->body);

        return [
            'success' => true,
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_by' => 'region',
            'confidence' => 0.9,
            'needs_clarification' => false,
        ];
    }

    public function lastUsage(): array
    {
        return $this->usage;
    }

    public function resetUsage(): void
    {
        $this->usage = [];
    }

    /** Mirrors AbstractProvider::recordUsage so both dialects are covered here. */
    public function recordUsagePublic(array $body): void
    {
        $gemini = $body['usageMetadata'] ?? null;
        $openai = $body['usage'] ?? null;

        $values = [
            'prompt_tokens' => $gemini['promptTokenCount'] ?? $openai['prompt_tokens'] ?? null,
            'completion_tokens' => $gemini['candidatesTokenCount'] ?? $openai['completion_tokens'] ?? null,
            'thinking_tokens' => $gemini['thoughtsTokenCount'] ?? null,
            'total_tokens' => $gemini['totalTokenCount'] ?? $openai['total_tokens'] ?? null,
        ];

        if (!array_filter($values, fn ($v) => $v !== null)) {
            return;
        }

        $this->usage['calls'] = ($this->usage['calls'] ?? 0) + 1;

        foreach ($values as $key => $value) {
            if (is_numeric($value)) {
                $this->usage[$key] = ($this->usage[$key] ?? 0) + (int) $value;
            }
        }
    }
}
