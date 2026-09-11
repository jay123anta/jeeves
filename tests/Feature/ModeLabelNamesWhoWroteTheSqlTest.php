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
 * `metadata.query_mode_used` names who wrote the SQL that RAN.
 *
 * Found asking live questions of the public Chinook database: "Which artist has
 * the most albums?" reported `intent` over
 * `SELECT T1.Name, COUNT(T2.AlbumId) ... INNER JOIN ... LIMIT 1` - a join the
 * intent contract cannot express, written by the model. Intent mode had built a
 * ranked list, the shape retry had regenerated it through the provider, and the
 * label set on entry to intent mode was never changed. The refined-prompt retry
 * after an intent failure did the same.
 *
 * Rule 8: the label describes the artifact, not the route that was entered.
 * The audit log and the QuestionAnswered event both read it.
 */
class ModeLabelNamesWhoWroteTheSqlTest extends TestCase
{
    private RecordingProvider $provider;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/cross-table-schemas');
        $app['config']->set('jeeves.query_mode', 'intent');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
        $app['config']->set('jeeves.errors.retry_on_failure', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('xt_artists', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('xt_albums', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->unsignedBigInteger('artist_id')->nullable();
        });
        Schema::create('xt_sales', function (Blueprint $t) {
            $t->id();
            $t->string('channel');
            $t->decimal('amount', 10, 2);
            $t->unsignedBigInteger('artist_id');
        });

        DB::table('xt_artists')->insert([['id' => 1, 'name' => 'Iron Maiden'], ['id' => 2, 'name' => 'Queen']]);
        DB::table('xt_albums')->insert([
            ['title' => 'Killers', 'artist_id' => 1],
            ['title' => 'Powerslave', 'artist_id' => 1],
            ['title' => 'Jazz', 'artist_id' => 2],
        ]);
    }

    private const MOST_ALBUMS = 'SELECT ar.name, COUNT(al.id) AS albums FROM xt_albums al JOIN xt_artists ar ON ar.id = al.artist_id GROUP BY ar.name ORDER BY albums DESC LIMIT 1';

    private function ask(string $question, array $intent): array
    {
        $this->provider = new RecordingProvider;
        $this->provider->intentResponse = $intent + ['success' => true, 'confidence' => 0.9, 'needs_clarification' => false];
        $this->provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => self::MOST_ALBUMS,
            'dataset' => 'xt_albums',
            'metric' => 'albums',
            'query_type' => 'ranking',
        ]];

        $this->app->instance(LlmProviderInterface::class, $this->provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query($question);
    }

    private function generations(): int
    {
        return count(array_filter($this->provider->calls, fn ($c) => $c['method'] === 'generateSql'));
    }

    #[Test]
    public function a_shape_retry_reports_the_sql_the_model_wrote(): void
    {
        // Intent ranks album titles - three rows for a "the most" question.
        $result = $this->ask('which album title has the most albums', [
            'dataset' => 'xt_albums', 'metric' => 'record_count', 'query_type' => 'ranking', 'group_value' => null,
        ]);

        $this->assertSame(1, $this->generations(), 'the shape retry did not run: ' . json_encode($result));
        $this->assertTrue($result['metadata']['shape_retry'] ?? false, json_encode($result));
        $this->assertCount(1, $result['rows']);
        $this->assertSame(
            'sql_generation',
            $result['metadata']['query_mode_used'] ?? null,
            'model-written SQL was reported as built by intent mode'
        );
    }

    #[Test]
    public function a_refined_prompt_retry_reports_the_sql_the_model_wrote(): void
    {
        // An intent that cannot be built fails; the retry regenerates on the
        // dataset the question's own words name ("albums").
        $result = $this->ask('how many albums', [
            'dataset' => 'no_such_dataset', 'metric' => 'record_count', 'query_type' => 'aggregation',
        ]);

        $this->assertSame(1, $this->generations(), 'the refined-prompt retry did not run: ' . json_encode($result));
        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            'sql_generation',
            $result['metadata']['query_mode_used'] ?? null,
            'model-written SQL was reported as built by intent mode'
        );
    }

    /** COUNTERWEIGHT. SQL that SqlBuilder wrote is still reported as intent. */
    #[Test]
    public function an_answer_built_from_the_intent_is_still_reported_as_intent(): void
    {
        $result = $this->ask('how many albums are there', [
            'dataset' => 'xt_albums', 'metric' => 'record_count', 'query_type' => 'aggregation', 'group_value' => null,
        ]);

        $this->assertSame(0, $this->generations());
        $this->assertSame('intent', $result['metadata']['query_mode_used'] ?? null, json_encode($result));
    }

    /** COUNTERWEIGHT. A "the most" answer that is already one row costs nothing and stays intent. */
    #[Test]
    public function a_single_row_superlative_needs_no_retry(): void
    {
        $result = $this->ask('which album title has the most albums', [
            'dataset' => 'xt_albums', 'metric' => 'record_count', 'query_type' => 'ranking', 'group_value' => null, 'limit' => 1,
        ]);

        $this->assertSame(0, $this->generations(), json_encode($result));
        $this->assertSame('intent', $result['metadata']['query_mode_used'] ?? null);
    }
}
