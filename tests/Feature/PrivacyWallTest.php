<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Conversation\ConversationManager;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Schema\Introspectors\MysqlIntrospector;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The privacy wall is the product: only schema structure and the user's own
 * question may ever reach an LLM provider -  never data values, never result
 * rows, never anything derived from them.
 *
 * Until now that was guaranteed by design and checked by hand in a browser's
 * Network tab, which protects exactly one code state. This suite makes it a
 * standing guarantee: a real SQLite table is seeded with sentinel values, real
 * queries are run end to end, and every byte the provider was handed is
 * searched for those sentinels.
 *
 * Two rules for anything added here:
 *   1. The sentinels must exist ONLY in the database -  never in the schema
 *      stub, the question text, or a test name. Otherwise a "leak" is just the
 *      test finding its own fixture.
 *   2. Every test must prove the query actually returned sentinel-bearing rows.
 *      A query that silently failed transmits no data either, and would pass a
 *      leak assertion while testing nothing.
 */
class PrivacyWallTest extends TestCase
{
    /** Values that exist only inside the database. */
    private const SENTINELS = [
        'Zarquon Holdings LLP',   // a row value in `buyer`
        'quarantined-batch-77',   // a row value in `state`
        '8675309.42',             // a row value in `total`
    ];

    private RecordingProvider $provider;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/privacy-schemas');
        // NQ-001: an app connection to seed through, and a separate
        // name the package reads through, over one database.
        $this->isolatedDatabase($app, 'nq_privacy');
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
            ['buyer' => 'Zarquon Holdings LLP', 'total' => 8675309.42, 'state' => 'quarantined-batch-77'],
            ['buyer' => 'Zarquon Holdings LLP', 'total' => 1200.00, 'state' => 'settled'],
            ['buyer' => 'Ordinary Trading Co', 'total' => 450.75, 'state' => 'settled'],
        ]);

        // The package supports Postgres and MySQL; SQLite introspection was
        // deliberately dropped. SQLite is used here only as an executable store
        // for the seeded rows -  the data flow under test is engine-independent,
        // and this keeps the suite service-free in CI. PromptBuilder asks the
        // introspector for one thing, `getDialect()`, so the real MySQL
        // introspector is bound and the prompt path stays genuine.
        $this->app->instance(SchemaIntrospectorInterface::class, new MysqlIntrospector);

        $this->provider = new RecordingProvider;
        $this->app->instance(LlmProviderInterface::class, $this->provider);
    }

    #[Test]
    public function the_schema_fixture_does_not_itself_contain_the_sentinels()
    {
        // Guards the first rule above: if a sentinel leaks into the stub, every
        // other test in this file quietly stops meaning anything.
        $stub = file_get_contents(__DIR__ . '/../Stubs/privacy-schemas/privacy_orders.php');

        foreach (self::SENTINELS as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $stub);
        }
    }

    #[Test]
    public function intent_mode_sends_no_row_data()
    {
        config()->set('jeeves.query_mode', 'intent');

        $result = $this->orchestrator()->query('top buyers by revenue');

        $this->assertReturnedSentinelBearingRows($result);
        $this->assertNothingLeaked();
        $this->assertContains('parseIntent', $this->provider->methodsCalled());
    }

    #[Test]
    public function sql_generation_mode_sends_no_row_data()
    {
        config()->set('jeeves.query_mode', 'sql_generation');

        $result = $this->orchestrator()->query('top buyers by revenue');

        $this->assertReturnedSentinelBearingRows($result);
        $this->assertNothingLeaked();
        $this->assertContains('generateSql', $this->provider->methodsCalled());
    }

    #[Test]
    public function self_verification_sends_the_sql_but_no_row_data()
    {
        // The verifier re-contacts the provider with the question and the SQL.
        // SQL text is not data -  but a verifier that ever showed the model a
        // sample of the rows would breach the privacy wall, so it is checked
        // explicitly.
        config()->set('jeeves.query_mode', 'sql_generation');
        config()->set('jeeves.verification.enabled', true);

        $result = $this->orchestrator()->query('top buyers by revenue');

        $this->assertReturnedSentinelBearingRows($result);
        $this->assertNothingLeaked();
        $this->assertGreaterThanOrEqual(
            2,
            count($this->provider->calls),
            'Verification did not run, so this test proved nothing about it'
        );
    }

    #[Test]
    public function the_retry_path_after_a_failure_sends_no_row_data()
    {
        config()->set('jeeves.query_mode', 'sql_generation');
        config()->set('jeeves.errors.retry_on_failure', true);

        $attempt = 0;
        $this->provider->sqlResponse = function () use (&$attempt) {
            if (++$attempt === 1) {
                return ['success' => false, 'error' => 'model returned malformed JSON'];
            }

            return [
                'success' => true,
                'data' => [
                    'sql' => 'SELECT buyer, total FROM nq_privacy_orders ORDER BY total DESC LIMIT 10',
                    'dataset' => 'privacy_orders',
                    'metric' => 'total',
                    'query_type' => 'ranking',
                ],
            ];
        };

        $result = $this->orchestrator()->query('top buyers by revenue');

        $this->assertGreaterThan(1, $attempt, 'The retry path never ran');
        $this->assertReturnedSentinelBearingRows($result);
        $this->assertNothingLeaked();
    }

    #[Test]
    public function a_conversation_follow_up_does_not_replay_previous_results()
    {
        // The riskiest flow: turn 2 is enriched from turn 1's context. If that
        // enrichment ever pulled from the result ROWS rather than the parsed
        // question, the second prompt would carry data from the first answer.
        config()->set('jeeves.query_mode', 'intent');

        $conversation = $this->app->make(ConversationManager::class);

        $first = $conversation->query('sess-1', 'top buyers by revenue');
        $this->assertReturnedSentinelBearingRows($first);

        $second = $conversation->query('sess-1', 'now filter by settled');

        $this->assertNothingLeaked();
        $this->assertGreaterThan(1, count($this->provider->calls), 'The follow-up turn never reached the provider');
        $this->assertSame('success', $second['status'] ?? null);
    }

    #[Test]
    public function a_clarification_round_trip_sends_no_row_data()
    {
        config()->set('jeeves.query_mode', 'intent');

        $this->provider->intentResponse = [
            'success' => true,
            'dataset' => null,
            'metric' => null,
            'needs_clarification' => true,
            'clarification_type' => 'dataset',
            'confidence' => 0.2,
        ];

        $result = $this->orchestrator()->query('show me the numbers');

        // The fixture deliberately omits 'metric' and 'district' -  a provider
        // only has to return what it resolved, and a clarification must still
        // come back rather than a generic error.
        $this->assertSame('clarification_needed', $result['status'] ?? null);
        $this->assertNothingLeaked();
    }

    /**
     * A spoken question is a text question by the time the package sees it.
     *
     * The browser does the listening and posts words, so there is no separate
     * audio path to police -  which is itself a privacy property worth stating:
     * no recording of anyone's voice ever reaches this server, let alone a
     * model provider. The package used to accept uploaded audio; that is gone,
     * and this replaces the test that covered it.
     */
    #[Test]
    public function the_package_has_no_way_to_receive_audio()
    {
        $this->assertFalse(
            method_exists(QueryOrchestrator::class, 'voiceQuery'),
            'an audio entry point came back'
        );

        $this->assertFalse(
            method_exists(LlmProviderInterface::class, 'parseVoiceQuery'),
            'the provider contract can be handed audio again'
        );

        $routes = collect(Route::getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($uri) => str_contains($uri, 'voice'));

        $this->assertCount(0, $routes, 'an audio endpoint is registered: ' . $routes->implode(', '));
    }

    #[Test]
    public function the_prompt_carries_schema_structure_and_the_question_only()
    {
        // The positive half of the wall: prove the provider IS given what it
        // legitimately needs, so "nothing leaked" isn't passing because the
        // prompt is empty.
        config()->set('jeeves.query_mode', 'sql_generation');

        $this->orchestrator()->query('top buyers by revenue');

        $prompt = $this->provider->calls[0]['args']['prompt'];

        $this->assertStringContainsString('nq_privacy_orders', $prompt, 'schema table name should be sent');
        $this->assertStringContainsString('buyer', $prompt, 'schema column name should be sent');
        $this->assertStringContainsString('top buyers by revenue', $prompt, "the user's question should be sent");
    }

    // ------------------------------------------------------------------

    private function orchestrator(): QueryOrchestrator
    {
        return $this->app->make(QueryOrchestrator::class);
    }

    /**
     * Fail unless the query really did read the sentinel rows out of the
     * database. Without this, a broken query would pass every leak assertion.
     */
    private function assertReturnedSentinelBearingRows(array $result): void
    {
        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'Query did not succeed, so no data was ever available to leak: '
            . ($result['message'] ?? json_encode($result))
        );

        $payload = json_encode($result);
        $found = array_filter(
            self::SENTINELS,
            fn (string $sentinel) => str_contains($payload, $sentinel) || str_contains($payload, rtrim($sentinel, '.42'))
        );

        $this->assertNotEmpty(
            $found,
            'The response carried none of the sentinel values, so this test is not exercising real data'
        );
    }

    private function assertNothingLeaked(): void
    {
        $transmitted = $this->provider->transmitted();

        foreach (self::SENTINELS as $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $transmitted,
                "PRIVACY WALL BREACH -  the value '{$sentinel}' exists only in the database, "
                . 'and it was sent to the LLM provider'
            );
        }
    }
}
