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
 * The sentence over a generated answer names the column that RAN.
 *
 * Found asking live questions of the public Chinook database once they were
 * routed to SQL generation, where both numbers were right and both sentences
 * were wrong:
 *
 *   "21: N/A  (Counts the number of albums by the artist 'Iron Maiden'.)"
 *   "Top 3 by This query retrieves the top 3 genres by revenue. It joins..."
 *
 * The value was looked up under the model's self-reported metric name, which
 * the SQL did not use, so it read N/A - and the count itself became the label.
 * And the prompt asks for `explanation` as "Brief explanation of what the
 * query does", a sentence, which was then set into the metric's noun slot.
 * Both are Rule 8: a caption built from the plan, not from the rows.
 */
class GeneratedAnswersNameWhatRanTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/cross-table-schemas');
        $app['config']->set('jeeves.query_mode', 'sql_generation');
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

    private function generate(string $question, array $data): array
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => $data];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query($question);
    }

    #[Test]
    public function a_count_under_a_name_the_model_did_not_use_is_still_read(): void
    {
        $result = $this->generate('how many albums does Iron Maiden have', [
            'sql' => "SELECT COUNT(al.id) FROM xt_albums al JOIN xt_artists ar ON ar.id = al.artist_id WHERE ar.name = 'Iron Maiden'",
            'dataset' => 'xt_albums',
            'metric' => 'album_count',
            'group_value' => 'Iron Maiden',
            'query_type' => 'group_detail',
            'explanation' => "Counts the number of albums by the artist 'Iron Maiden'.",
        ]);

        $answer = (string) ($result['answer'] ?? '');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertStringNotContainsString('N/A', $answer, 'the count was looked up under a name the SQL did not use');
        $this->assertStringContainsString('2', $answer);
        $this->assertDoesNotMatchRegularExpression('/^\s*2\s*:/', $answer, 'the count was used as its own label');
        $this->assertStringNotContainsString('Counts the number', $answer, 'the explanation sentence was set in as the measure');
    }

    #[Test]
    public function a_ranking_is_described_by_its_measure_not_by_the_explanation(): void
    {
        $result = $this->generate('top 3 artists by revenue', [
            'sql' => 'SELECT ar.name, SUM(s.amount) AS Revenue FROM xt_sales s JOIN xt_artists ar ON ar.id = s.artist_id GROUP BY ar.name ORDER BY Revenue DESC LIMIT 3',
            'dataset' => 'xt_sales',
            'metric' => 'revenue',
            'query_type' => 'ranking',
            'explanation' => 'This query retrieves the top 3 artists by revenue. It joins sales and artists.',
        ]);

        $answer = (string) ($result['answer'] ?? '');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertStringNotContainsString('This query', $answer, 'the explanation sentence was set in as the measure');
        $this->assertStringContainsStringIgnoringCase('by revenue', $answer);
        $this->assertStringContainsString('Iron Maiden', $answer);
        $this->assertStringNotContainsString('This query', (string) ($result['speech_text'] ?? ''));
    }

    /** COUNTERWEIGHT. A schema's own description, over the column it names, is kept. */
    #[Test]
    public function a_schema_description_over_its_own_column_is_kept(): void
    {
        $result = (new ResponseFormatter)->format([
            'dataset' => 'orders', 'dataset_name' => 'Orders',
            'metric' => 'amount', 'metric_description' => 'Order amount', 'metric_unit' => '',
            'group_value' => 'Alice', 'query_type' => 'group_detail', 'group_column' => 'name',
        ], [(object) ['name' => 'Alice', 'amount' => 1000]]);

        $this->assertSame('Alice: 1,000  (Order amount)', $result['answer']);
    }
}
