<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Questions that span two tables, answered instead of guessed.
 *
 * Both failures were found by installing the package into a fresh app over the
 * public Chinook music-store database and asking it live:
 *
 *   "How many albums does Iron Maidan have?"  answered a confident 0. The
 *   intent route placed it on the album table and searched for the ARTIST in
 *   the album-title column. The right answer needed a join intent mode cannot
 *   express.
 *
 *   "Top 3 genres by revenue"  came back as "What metric would you like?".
 *   The genre table holds no money; the measure was two joins away.
 *
 * Auto mode already falls back to SQL generation when intent mode cannot
 * answer. Neither case was recognised as "cannot answer", so neither fell back.
 */
class CrossTableQuestionsTest extends TestCase
{
    private RecordingProvider $provider;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/cross-table-schemas');
        $app['config']->set('jeeves.query_mode', 'auto');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
        $app['config']->set('jeeves.errors.retry_on_failure', false);
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
        DB::table('xt_sales')->insert([
            ['channel' => 'web', 'amount' => 100, 'artist_id' => 1],
            ['channel' => 'store', 'amount' => 50, 'artist_id' => 1],
            ['channel' => 'web', 'amount' => 30, 'artist_id' => 2],
        ]);
    }

    private function ask(string $question, array $intent, string $sql): array
    {
        $this->provider = new RecordingProvider;
        $this->provider->intentResponse = $intent + ['success' => true, 'confidence' => 0.9];
        $this->provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => $sql,
            'dataset' => $intent['dataset'] ?? null,
            'metric' => 'n',
            'query_type' => 'aggregation',
        ]];

        $this->app->instance(LlmProviderInterface::class, $this->provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query($question);
    }

    private function generations(): int
    {
        return count(array_filter($this->provider->calls, fn ($c) => $c['method'] === 'generateSql'));
    }

    private function firstCell(array $result): mixed
    {
        $row = (array) (($result['rows'] ?? [])[0] ?? []);

        return $row === [] ? null : array_values($row)[0];
    }

    /** The count: the last cell, since a name filter also returns the name column. */
    private function countIn(array $result): mixed
    {
        $row = (array) (($result['rows'] ?? [])[0] ?? []);

        return $row === [] ? null : array_values($row)[count($row) - 1];
    }

    /** An artist's name, filtered in the album-title column, as the model did live. */
    private function artistNameOnAlbums(string $name): array
    {
        return [
            'dataset' => 'xt_albums',
            'metric' => 'record_count',
            'group_value' => $name,
            'query_type' => 'aggregation',
            'needs_clarification' => false,
        ];
    }

    private const JOINED_COUNT = "SELECT COUNT(*) AS n FROM xt_albums al JOIN xt_artists ar ON ar.id = al.artist_id WHERE ar.name = 'Iron Maiden'";

    /** GUARD. Every escalation below depends on the schemas being linked. */
    #[Test]
    public function the_schemas_are_linked(): void
    {
        $this->assertTrue($this->app->make(SchemaRegistry::class)->hasLinkedSchemas());
    }

    #[Test]
    public function a_name_that_belongs_to_a_related_table_is_answered_through_the_join(): void
    {
        $result = $this->ask('how many albums does Iron Maiden have', $this->artistNameOnAlbums('Iron Maiden'), self::JOINED_COUNT);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertEquals(
            2,
            $this->firstCell($result),
            'an artist searched for in the album-title column was answered from that column: ' . json_encode($result)
        );
        $this->assertSame(1, $this->generations(), 'SQL generation was not given the question');
        $this->assertStringContainsString('name', (string) ($result['metadata']['escalated_for'] ?? ''), 'the answer does not say why it escalated');
    }

    /** COUNTERWEIGHT. A name that IS in the column is answered where it is, with no second call. */
    #[Test]
    public function a_name_stored_in_the_column_is_answered_where_it_is(): void
    {
        $result = $this->ask('how many albums are called Jazz', $this->artistNameOnAlbums('Jazz'), self::JOINED_COUNT);

        $this->assertEquals(1, $this->countIn($result), json_encode($result));
        $this->assertSame(0, $this->generations(), 'a name that matched was escalated anyway');
    }

    /**
     * COUNTERWEIGHT. A word from the schema in the name slot is a misread
     * breakdown ("top 5 customers by revenue" with the filter "customers").
     * Dropping it IS the answer, and it has always cost no second call.
     */
    #[Test]
    public function a_schema_word_in_the_name_slot_is_dropped_without_a_second_call(): void
    {
        $result = $this->ask('how many albums are there', $this->artistNameOnAlbums('albums'), self::JOINED_COUNT);

        $this->assertEquals(3, $this->countIn($result), json_encode($result));
        $this->assertSame(0, $this->generations(), 'a misread breakdown word cost a provider call');
        $this->assertTrue($result['metadata']['filter_dropped'] ?? false);
    }

    #[Test]
    public function a_ranking_by_a_measure_another_table_holds_is_answered_not_asked(): void
    {
        $result = $this->ask(
            'top 3 artists by revenue',
            ['dataset' => 'xt_artists', 'metric' => null, 'needs_clarification' => true, 'clarification_type' => 'metric'],
            'SELECT ar.name, SUM(s.amount) AS revenue FROM xt_sales s JOIN xt_artists ar ON ar.id = s.artist_id GROUP BY ar.name ORDER BY revenue DESC LIMIT 3'
        );

        $this->assertSame('success', $result['status'] ?? null, 'the question was asked back instead of answered: ' . json_encode($result));
        $this->assertSame(1, $this->generations());
        $this->assertSame('Iron Maiden', $this->firstCell($result));
    }

    /** COUNTERWEIGHT. A question that names no measure still deserves to be asked. */
    #[Test]
    public function a_question_that_names_no_measure_is_still_asked(): void
    {
        $result = $this->ask(
            'which is the best?',
            ['dataset' => 'xt_artists', 'metric' => null, 'needs_clarification' => true, 'clarification_type' => 'metric'],
            'SELECT name FROM xt_artists LIMIT 1'
        );

        $this->assertSame('clarification_needed', $result['status'] ?? null, json_encode($result));
        $this->assertSame(0, $this->generations(), 'a question with no measure was guessed at');
    }

    /**
     * COUNTERWEIGHT. With no links there is nowhere else for the name to live,
     * so the existing answer stands - the unfiltered result, saying the name
     * did not match - and no second call is made.
     */
    #[Test]
    public function on_an_unlinked_schema_an_unmatched_name_keeps_the_existing_answer(): void
    {
        config()->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/cross-table-single');
        $this->app->forgetInstance(SchemaRegistry::class);

        $result = $this->ask('how many albums does Iron Maiden have', $this->artistNameOnAlbums('Iron Maiden'), self::JOINED_COUNT);

        $this->assertSame(0, $this->generations(), 'an unlinked schema escalated');
        $this->assertTrue($result['metadata']['filter_dropped'] ?? false, json_encode($result));
        $this->assertStringContainsString('No match for "Iron Maiden"', (string) ($result['answer'] ?? ''));
    }
}
