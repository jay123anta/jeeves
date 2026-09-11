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
 * Typo-tolerant routing, end to end: a misspelled dataset name is placed
 * without spending the provider call that exists only to place it.
 */
class FuzzyDatasetRoutingTest extends TestCase
{
    private const QUESTION = 'how many dwelings were built';

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
        DB::table('nq_dwellings')->insert([['district' => 'Kamrup'], ['district' => 'Nagaon']]);

        Schema::dropIfExists('nq_tickets');
        Schema::create('nq_tickets', function (Blueprint $t) {
            $t->id();
            $t->string('queue');
        });
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

        // Only reached if routing falls through. The WRONG dataset on purpose,
        // so a silent fall-through shows up as a wrong answer.
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'nq_tickets',
            'metric' => 'record_count',
            'needs_clarification' => false,
            'confidence' => 0.9,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $provider;
    }

    private function parseIntentCalls(RecordingProvider $p): int
    {
        return count(array_filter($p->calls, fn ($c) => $c['method'] === 'parseIntent'));
    }

    #[Test]
    public function a_misspelled_dataset_is_placed_without_asking_the_model(): void
    {
        $this->seedTables();
        config()->set('jeeves.fuzzy_dataset_matching.enabled', true);
        $provider = $this->provider();

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(0, $this->parseIntentCalls($provider), 'the routing call was spent anyway');
        $this->assertSame('nq_dwellings', $result['parsed_query']['dataset'] ?? null);

        // Rule 8: the route recorded is the one taken.
        $this->assertSame('fuzzy', $result['metadata']['_dataset_via'] ?? null);
    }

    /** COUNTERWEIGHT. Off by default: the question takes exactly its old route. */
    #[Test]
    public function it_is_off_until_it_is_switched_on(): void
    {
        $this->seedTables();
        $provider = $this->provider();

        $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $this->assertSame(
            1,
            $this->parseIntentCalls($provider),
            'an install that never enabled fuzzy routing had a question placed by it'
        );
    }
}
