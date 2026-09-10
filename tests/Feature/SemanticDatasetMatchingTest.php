<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\SemanticMatcherInterface;
use Jayanta\Jeeves\Engine\DatasetSeeder;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Semantic dataset matching: the optional stage between keyword routing and
 * the model.
 *
 * The claim being tested is narrow, and worth stating exactly, because a stage
 * that sits in front of the query path is only justified if it is ADDITIVE.
 * Switching it on must never change a question the package already routed
 * correctly; switching it off - or having it fail - must leave the cascade
 * behaving exactly as it did before the feature existed. Every test below is
 * one half of that claim.
 */
class SemanticDatasetMatchingTest extends TestCase
{
    /** A question no alias in either stub schema can catch. */
    private const QUESTION = 'how many houses were built';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/semantic-schemas');
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
    }

    private function seedTables(): void
    {
        Schema::dropIfExists('nq_dwellings');
        Schema::create('nq_dwellings', function (Blueprint $t) {
            $t->id();
            $t->string('district');
        });

        // Values distinctive enough to search an outgoing request for.
        foreach (['Kamrup', 'Kamrup', 'Nagaon'] as $d) {
            DB::table('nq_dwellings')->insert(['district' => $d]);
        }

        Schema::dropIfExists('nq_tickets');
        Schema::create('nq_tickets', function (Blueprint $t) {
            $t->id();
            $t->string('queue');
        });
    }

    private function enableMatcher(array $overrides = []): void
    {
        config()->set('jeeves.semantic_matching', array_merge([
            'enabled' => true,
            'driver' => 'local_minilm',
            'endpoint' => 'http://127.0.0.1:8001',
            'path' => '/match-scheme',
            'threshold' => 0.3,
            'timeout' => 2,
            'fallback' => 'llm',
        ], $overrides));

        $this->app->forgetInstance(SemanticMatcherInterface::class);
        $this->app->forgetInstance(QueryOrchestrator::class);
    }

    private function provider(): RecordingProvider
    {
        $provider = new RecordingProvider;

        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => 'SELECT COUNT(*) AS record_count FROM nq_dwellings',
            'dataset' => 'nq_dwellings',
            'metric' => 'record_count',
            'query_type' => 'aggregation',
        ]];

        // Only reached when the semantic stage declines.
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'nq_dwellings',
            'metric' => 'record_count',
            'needs_clarification' => false,
            'confidence' => 0.9,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $provider;
    }

    private function calls(RecordingProvider $p, string $method): int
    {
        return count(array_filter($p->calls, fn ($c) => $c['method'] === $method));
    }

    private function ranked(string $dataset, float $score): \Closure
    {
        return fn () => Http::response(['top_n' => [['dataset' => $dataset, 'score' => $score]]]);
    }

    /**
     * GUARD AGAINST A VACUOUS SUITE.
     *
     * Every test here assumes exact detection MISSES, so that the matcher is
     * reached at all. If an alias ever starts catching this question, the
     * assertions below would go on passing while testing nothing, because the
     * dataset would be settled before the semantic stage runs. This is the
     * test that fails first when that happens.
     */
    #[Test]
    public function the_question_this_suite_uses_is_one_no_keyword_catches(): void
    {
        $this->seedTables();

        $this->assertNull(
            $this->app->make(DatasetSeeder::class)->detect(self::QUESTION),
            'keyword detection now places this question on its own, so every other '
            . 'test in this file is passing without ever reaching the matcher'
        );
    }

    #[Test]
    public function a_confident_match_places_the_question_without_asking_the_model(): void
    {
        $this->seedTables();
        $this->enableMatcher();
        Http::fake(['127.0.0.1:8001/*' => $this->ranked('nq_dwellings', 0.42)]);
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            0,
            $this->calls($provider, 'parseIntent'),
            'the model was asked to place a question the matcher had already placed - '
            . 'the call this whole stage exists to save was spent anyway'
        );
    }

    /**
     * COUNTERWEIGHT 1. Exact routing wins, and the service is not even asked.
     */
    #[Test]
    public function an_exact_alias_still_wins_and_the_service_is_never_asked(): void
    {
        $this->seedTables();
        $this->enableMatcher();
        Http::fake();
        $provider = $this->provider();

        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => 'SELECT COUNT(*) AS record_count FROM nq_tickets',
            'dataset' => 'nq_tickets',
            'metric' => 'record_count',
            'query_type' => 'aggregation',
        ]];

        $result = $this->app->make(QueryOrchestrator::class)->query('how many tickets are open');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        Http::assertNothingSent();
    }

    /**
     * COUNTERWEIGHT 2. Off by default. An install that never configures this
     * opens no socket, and the question takes exactly its old route.
     */
    #[Test]
    public function it_is_off_until_it_is_switched_on(): void
    {
        $this->seedTables();
        Http::fake();
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        Http::assertNothingSent();
        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            1,
            $this->calls($provider, 'parseIntent'),
            'the question did not take the route it takes without this feature'
        );
    }

    #[Test]
    public function a_score_below_the_threshold_falls_through_to_the_model(): void
    {
        $this->seedTables();
        $this->enableMatcher(['threshold' => 0.5]);
        Http::fake(['127.0.0.1:8001/*' => $this->ranked('nq_dwellings', 0.31)]);
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            1,
            $this->calls($provider, 'parseIntent'),
            'an unsure match was acted on instead of being handed to the model'
        );
    }

    #[Test]
    public function a_service_that_is_down_costs_the_question_nothing(): void
    {
        $this->seedTables();
        $this->enableMatcher();
        Http::fake(['127.0.0.1:8001/*' => fn () => Http::response('gateway down', 502)]);
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'a matcher outage took down a question that has nothing to do with it: '
            . json_encode($result)
        );
        $this->assertSame(1, $this->calls($provider, 'parseIntent'));
    }

    /**
     * The corpus is maintained separately from the schema files, and drifts.
     * A confident match on a dataset this install does not register is not a
     * reason to route to it.
     */
    #[Test]
    public function a_dataset_this_install_does_not_have_is_discarded(): void
    {
        $this->seedTables();
        $this->enableMatcher();
        Http::fake(['127.0.0.1:8001/*' => $this->ranked('nq_schemes_from_another_app', 0.98)]);
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            1,
            $this->calls($provider, 'parseIntent'),
            'a dataset outside this install was routed to on the strength of its score'
        );
    }

    /**
     * RULE 2. The request carries the question and nothing else. Not a row,
     * not a value, not a count - and not the dataset list either, which is
     * filtered on this side precisely so that it never has to be sent.
     */
    #[Test]
    public function only_the_question_text_leaves_the_server(): void
    {
        $this->seedTables();
        $this->enableMatcher();
        Http::fake(['127.0.0.1:8001/*' => $this->ranked('nq_dwellings', 0.42)]);
        $this->provider();

        $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $sent = [];
        foreach (Http::recorded() as $exchange) {
            $sent[] = $exchange[0]->body();
        }

        $this->assertNotEmpty($sent, 'nothing was sent, so this test proves nothing');

        $body = implode("\n", $sent);

        $this->assertStringContainsString(self::QUESTION, $body);

        foreach (['Kamrup', 'Nagaon', 'nq_tickets', 'district'] as $mustNotLeak) {
            $this->assertStringNotContainsString(
                $mustNotLeak,
                $body,
                "'{$mustNotLeak}' reached the matching service, which is only ever "
                . 'entitled to the question text'
            );
        }
    }

    #[Test]
    public function the_clarification_fallback_asks_instead_of_calling_the_model(): void
    {
        $this->seedTables();
        $this->enableMatcher(['threshold' => 0.9, 'fallback' => 'clarification']);
        Http::fake(['127.0.0.1:8001/*' => $this->ranked('nq_dwellings', 0.31)]);
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $this->assertSame('clarification_needed', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            0,
            $this->calls($provider, 'parseIntent'),
            'clarification was returned AND the model was called - the stage that '
            . 'exists to avoid that call made it anyway'
        );
    }

    /**
     * The clarification policy must not fire on an install that never turned
     * semantic matching on. Reading the setting without first asking whether
     * the stage is live would turn every unrouted question into a prompt.
     */
    #[Test]
    public function the_clarification_fallback_is_inert_while_the_feature_is_off(): void
    {
        $this->seedTables();
        config()->set('jeeves.semantic_matching.enabled', false);
        config()->set('jeeves.semantic_matching.fallback', 'clarification');
        $this->app->forgetInstance(SemanticMatcherInterface::class);
        $this->app->forgetInstance(QueryOrchestrator::class);

        Http::fake();
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'a disabled feature truncated the cascade: ' . json_encode($result)
        );
        $this->assertSame(1, $this->calls($provider, 'parseIntent'));
    }
}
