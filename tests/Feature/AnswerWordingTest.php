<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Jayanta\Jeeves\Engine\ResponseFormatter;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The answer sentence reads like English, and claims nothing that did not run.
 *
 * Every one of these was read off a live answer on the public Chinook database,
 * with the number right each time:
 *
 *   "Total Number of album: 1 records"      - "How many albums are called Killers?"
 *   "Iron Maiden: 21 records (NumberOfAlbums)"
 *   "Rock: 1,297 records (count)"
 *
 * "Number of album": the implicit count metric took the dataset's name as it
 * was, and `jeeves:discover` names a dataset after its table, often singular.
 * "Total Number of": the prefix went on every total, including one whose
 * description is already a quantity. "records" after "Number of albums" says
 * the same thing twice. And "records" over `NumberOfAlbums` was the unit of
 * the schema's count metric, which the model CLAIMED - the column that ran was
 * its own, with no unit anyone declared (Rule 8).
 */
class AnswerWordingTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/singular-name-schemas');
    }

    private function answer(array $queryResult, array $rows): string
    {
        return (new ResponseFormatter)->format($queryResult + [
            'dataset' => 'albums', 'dataset_name' => 'Album', 'group_column' => 'title', 'order' => 'DESC',
        ], array_map(fn ($r) => (object) $r, $rows))['answer'];
    }

    private function countDescription(string $dataset): string
    {
        return $this->app->make(SchemaRegistry::class)
            ->getComputedMetrics($dataset)[SchemaRegistry::COUNT_METRIC]['description'];
    }

    #[Test]
    public function the_count_of_a_singular_dataset_is_plural(): void
    {
        $this->assertSame('Number of albums', $this->countDescription('sn_album'));
        $this->assertSame('Number of invoice lines', $this->countDescription('sn_invoice_line'));
    }

    /** COUNTERWEIGHT. An already-plural name is left alone. */
    #[Test]
    public function an_already_plural_name_is_not_pluralised_again(): void
    {
        $this->assertSame('Number of orders', $this->countDescription('sn_orders'));
    }

    #[Test]
    public function a_count_total_is_not_called_a_total_or_given_a_unit(): void
    {
        $this->assertSame('Number of albums: 1', $this->answer([
            'metric' => 'record_count', 'metric_description' => 'Number of albums', 'metric_unit' => 'records',
            'query_type' => 'aggregation', 'group_value' => null,
        ], [['record_count' => 1]]));
    }

    /** COUNTERWEIGHT. A sum is still a total, and keeps its unit. */
    #[Test]
    public function a_summed_total_still_reads_as_a_total(): void
    {
        $this->assertSame('Total order value: 500 USD', $this->answer([
            'metric' => 'amount', 'metric_description' => 'order value', 'metric_unit' => 'USD',
            'query_type' => 'aggregation', 'group_value' => null,
        ], [['amount' => 500]]));
    }

    #[Test]
    public function a_generated_column_is_named_in_words_without_the_claimed_metrics_unit(): void
    {
        // The model claimed the schema's count metric; the column that ran is its own.
        $this->assertSame('Iron Maiden: 21 (number of albums)', $this->answer([
            'metric' => 'record_count', 'metric_description' => null, 'metric_unit' => 'records',
            'query_type' => 'group_detail', 'group_value' => 'Iron Maiden', 'group_column' => 'Name',
        ], [['Name' => 'Iron Maiden', 'NumberOfAlbums' => 21]]));
    }

    /** COUNTERWEIGHT. A schema metric over its own column keeps its description and its unit. */
    #[Test]
    public function a_schema_metric_over_its_own_column_keeps_its_unit(): void
    {
        $this->assertSame('Alice: 1,000 USD (Order amount)', $this->answer([
            'metric' => 'amount', 'metric_description' => 'Order amount', 'metric_unit' => 'USD',
            'query_type' => 'group_detail', 'group_value' => 'Alice', 'group_column' => 'name',
        ], [['name' => 'Alice', 'amount' => 1000]]));
    }

    #[Test]
    public function a_single_count_under_its_own_description_carries_no_unit(): void
    {
        $this->assertSame('Jazz: 1 (Number of albums)', $this->answer([
            'metric' => 'record_count', 'metric_description' => 'Number of albums', 'metric_unit' => 'records',
            'query_type' => 'group_detail', 'group_value' => 'Jazz',
        ], [['title' => 'Jazz', 'record_count' => 1]]));
    }
}
