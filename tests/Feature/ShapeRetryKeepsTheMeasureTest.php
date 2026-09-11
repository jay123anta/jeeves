<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Engine\ResponseFormatter;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A one-row answer to "which X has the most Y" keeps the number.
 *
 * Found asking live questions of the public Chinook database. The shape retry
 * asks the model to keep the label AND the measure, and Gemini sometimes kept
 * only the label:
 *
 *   SELECT T1.Name FROM Artist AS T1 JOIN Album AS T2 ... GROUP BY T1.Name
 *   ORDER BY COUNT(T2.AlbumId) DESC LIMIT 1
 *
 * answered "Iron Maiden: N/A records (Number of artist)". The artist was right,
 * the number the question was about was gone, and the sentence described a
 * count that never ran. The measure was still in the statement - in the ORDER
 * BY - so it is put back into the SELECT locally: no second provider call,
 * nothing leaves the server, and the rewritten statement is validated like any
 * other.
 */
class ShapeRetryKeepsTheMeasureTest extends TestCase
{
    private RecordingProvider $provider;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/cross-table-schemas');
        $app['config']->set('jeeves.query_mode', 'intent');
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
    }

    /** Intent ranks album titles - three rows for a "the most" question - so the shape retry runs with $retrySql. */
    private function askWithRetry(string $retrySql): array
    {
        $this->provider = new RecordingProvider;
        $this->provider->intentResponse = [
            'success' => true, 'confidence' => 0.9, 'needs_clarification' => false,
            'dataset' => 'xt_albums', 'metric' => 'record_count', 'query_type' => 'ranking', 'group_value' => null,
        ];
        $this->provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => $retrySql, 'dataset' => 'xt_albums', 'metric' => 'albums', 'query_type' => 'ranking',
        ]];

        $this->app->instance(LlmProviderInterface::class, $this->provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('which artist has the most albums');
    }

    private function generations(): int
    {
        return count(array_filter($this->provider->calls, fn ($c) => $c['method'] === 'generateSql'));
    }

    /** @return array<string, mixed> */
    private function onlyRow(array $result): array
    {
        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertCount(1, $result['rows'] ?? [], json_encode($result));

        return (array) $result['rows'][0];
    }

    #[Test]
    public function a_measure_the_retry_left_in_the_order_by_is_put_back(): void
    {
        $result = $this->askWithRetry(
            'SELECT ar.name FROM xt_albums al JOIN xt_artists ar ON ar.id = al.artist_id GROUP BY ar.name ORDER BY COUNT(al.id) DESC LIMIT 1'
        );

        $row = $this->onlyRow($result);

        $this->assertSame('Iron Maiden', $row['name'] ?? null);
        $this->assertContains(2, array_map('intval', array_values(array_slice($row, 1))), 'the count was not put back: ' . json_encode($row));
        $this->assertSame(1, $this->generations(), 'putting the measure back cost a provider call');
        $this->assertTrue($result['metadata']['measure_restored'] ?? false);
        $this->assertStringNotContainsString('N/A', (string) $result['answer']);
        $this->assertStringContainsString('2', (string) $result['answer']);
    }

    /** COUNTERWEIGHT. A retry that kept its measure runs exactly as written. */
    #[Test]
    public function a_retry_that_kept_its_measure_is_left_alone(): void
    {
        $result = $this->askWithRetry(
            'SELECT ar.name, COUNT(al.id) AS albums FROM xt_albums al JOIN xt_artists ar ON ar.id = al.artist_id GROUP BY ar.name ORDER BY albums DESC LIMIT 1'
        );

        $this->assertSame(['name', 'albums'], array_keys($this->onlyRow($result)));
        $this->assertArrayNotHasKey('measure_restored', $result['metadata']);
    }

    /** COUNTERWEIGHT. The aggregate is already selected under no alias - still nothing to restore. */
    #[Test]
    public function an_aggregate_already_selected_is_not_added_twice(): void
    {
        $result = $this->askWithRetry(
            'SELECT ar.name, COUNT(al.id) FROM xt_albums al JOIN xt_artists ar ON ar.id = al.artist_id GROUP BY ar.name ORDER BY COUNT(al.id) DESC LIMIT 1'
        );

        $this->assertCount(2, $this->onlyRow($result));
        $this->assertArrayNotHasKey('measure_restored', $result['metadata']);
    }

    /** COUNTERWEIGHT. Ordering by a plain column is not a dropped measure. */
    #[Test]
    public function an_order_by_a_plain_column_is_not_rewritten(): void
    {
        $result = $this->askWithRetry('SELECT ar.name FROM xt_artists ar ORDER BY ar.name DESC LIMIT 1');

        $this->assertSame(['name' => 'Queen'], $this->onlyRow($result));
        $this->assertArrayNotHasKey('measure_restored', $result['metadata']);
        $this->assertSame('Queen', $result['answer'], 'a row with no measure was captioned with one');
    }

    /** The word FROM inside a string literal is not where the SELECT list ends. */
    #[Test]
    public function a_literal_that_says_from_is_not_mistaken_for_the_from_clause(): void
    {
        $result = $this->askWithRetry(
            "SELECT ar.name, 'from the shop' AS src FROM xt_albums al JOIN xt_artists ar ON ar.id = al.artist_id GROUP BY ar.name ORDER BY COUNT(al.id) DESC LIMIT 1"
        );

        $row = $this->onlyRow($result);

        $this->assertSame('from the shop', $row['src'] ?? null, json_encode($row));
        $this->assertCount(3, $row, 'the count was not put back after the literal');
        $this->assertTrue($result['metadata']['measure_restored'] ?? false);
    }

    /** With no measure in the row, the answer names the row and claims no number. */
    #[Test]
    public function a_row_with_no_measure_is_answered_with_its_label_only(): void
    {
        $formatter = new ResponseFormatter;
        $base = [
            'dataset' => 'artists', 'dataset_name' => 'Artists', 'metric' => 'record_count',
            'metric_description' => 'Number of artist', 'metric_unit' => 'records', 'group_column' => 'name',
            'order' => 'DESC',
        ];

        $single = $formatter->format($base + ['query_type' => 'group_detail', 'group_value' => 'x'], [(object) ['name' => 'Iron Maiden']]);
        $this->assertSame('Iron Maiden', $single['answer']);

        $ranking = $formatter->format($base + ['query_type' => 'ranking', 'group_value' => null], [
            (object) ['name' => 'Iron Maiden'], (object) ['name' => 'Queen'],
        ]);
        $this->assertStringNotContainsString('Number of artist', $ranking['answer']);
        $this->assertStringContainsString('Iron Maiden, Queen', $ranking['answer']);
    }
}
