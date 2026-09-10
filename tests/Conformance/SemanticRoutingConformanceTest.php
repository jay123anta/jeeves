<?php

namespace Jayanta\Jeeves\Tests\Conformance;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\SemanticMatcherInterface;
use Jayanta\Jeeves\Engine\DatasetSeeder;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The whole path, against a real embedding service.
 *
 * SemanticMatcherConformanceTest proves the CLIENT reads a live service
 * correctly. This proves the ORCHESTRATOR acts on it: that a question no alias
 * can place reaches the right dataset, and that the provider call which exists
 * only to place it is genuinely not made.
 *
 * The stub schemas are keyed to the reference deployment's corpus (`pmayg`,
 * `sbmu`) because a matcher can only route to datasets this install registers -
 * that filter is the point of the allowed list, and it means an end-to-end test
 * has to share keys with whatever corpus is answering.
 *
 * Skipped unless a service is answering.
 */
class SemanticRoutingConformanceTest extends TestCase
{
    /** No alias, key or column name in either stub schema appears in this. */
    private const QUESTION = 'how many houses were built';

    private string $endpoint;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/live-semantic-schemas');
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->endpoint = env('JEEVES_SEMANTIC_MATCH_ENDPOINT', 'http://127.0.0.1:8001');

        $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);

        if (@file_get_contents(rtrim($this->endpoint, '/') . '/health', false, $context) === false) {
            $this->markTestSkipped('No embedding service at ' . $this->endpoint);
        }

        Schema::dropIfExists('nq_pmayg_units');
        Schema::create('nq_pmayg_units', function (Blueprint $t) {
            $t->id();
            $t->string('district');
        });
        foreach (['Kamrup', 'Kamrup', 'Nagaon'] as $d) {
            DB::table('nq_pmayg_units')->insert(['district' => $d]);
        }

        Schema::dropIfExists('nq_sbmu_toilets');
        Schema::create('nq_sbmu_toilets', function (Blueprint $t) {
            $t->id();
            $t->string('ward');
        });
    }

    /**
     * 0.2, not the shipped 0.3. The reference corpus scores this question at
     * about 0.22 - short scheme descriptions score low on MiniLM, which is the
     * documented reason the default is 0.3 rather than 0.5 and the reason a
     * deployment is expected to tune it against its OWN corpus. Hard-coding the
     * shipped default here would test the corpus, not the wiring.
     */
    private function enableLiveMatcher(float $threshold = 0.2, string $fallback = 'llm'): void
    {
        config()->set('jeeves.semantic_matching', [
            'enabled' => true,
            'driver' => 'local_minilm',
            'endpoint' => $this->endpoint,
            'path' => '/match-scheme',
            'threshold' => $threshold,
            'timeout' => 5,
            'fallback' => $fallback,
        ]);

        $this->app->forgetInstance(SemanticMatcherInterface::class);
        $this->app->forgetInstance(QueryOrchestrator::class);
    }

    private function provider(): RecordingProvider
    {
        $provider = new RecordingProvider;

        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => 'SELECT COUNT(*) AS record_count FROM nq_pmayg_units',
            'dataset' => 'pmayg',
            'metric' => 'record_count',
            'query_type' => 'aggregation',
        ]];

        // Reached only if the semantic stage declines. It answers with the
        // WRONG dataset on purpose: if routing silently fell through to the
        // model, the assertions below must notice rather than be rescued.
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'sbmu',
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

    /** Guard: if keyword detection ever places this, nothing below is tested. */
    #[Test]
    public function the_question_is_one_keyword_detection_cannot_place(): void
    {
        $this->assertNull($this->app->make(DatasetSeeder::class)->detect(self::QUESTION));
    }

    #[Test]
    public function a_live_service_places_the_question_and_the_routing_call_is_saved(): void
    {
        $this->enableLiveMatcher();
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));

        $this->assertSame(
            0,
            $this->calls($provider, 'parseIntent'),
            'the model was asked to place a question a live embedding service had '
            . 'already placed - the call this stage exists to save was spent anyway'
        );

        // parsed_query.dataset rather than the SQL string, because Rule 8
        // makes that field derive from the query that RAN.
        $this->assertSame(
            'pmayg',
            $result['parsed_query']['dataset'] ?? null,
            'a live service placed the question and the answer came from another dataset'
        );
    }

    /**
     * COUNTERWEIGHT. The same live service, the same question, a threshold it
     * cannot clear: the question must fall through to the model untouched.
     */
    #[Test]
    public function a_threshold_the_live_score_cannot_clear_falls_through_to_the_model(): void
    {
        $this->enableLiveMatcher(threshold: 0.95);
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            1,
            $this->calls($provider, 'parseIntent'),
            'an unsure live match was acted on instead of being handed to the model'
        );
    }

    /** Exact routing still wins while a live service is configured and up. */
    #[Test]
    public function an_alias_match_still_bypasses_the_service_entirely(): void
    {
        $this->enableLiveMatcher();
        $provider = $this->provider();

        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => 'SELECT COUNT(*) AS record_count FROM nq_sbmu_toilets',
            'dataset' => 'sbmu',
            'metric' => 'record_count',
            'query_type' => 'aggregation',
        ]];

        $result = $this->app->make(QueryOrchestrator::class)->query('swachh toilets built');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame('sbmu', $result['parsed_query']['dataset'] ?? null);
        $this->assertSame(0, $this->calls($provider, 'parseIntent'));
    }
}
