<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a front end needs to show people what they can ask.
 *
 * The hardest part of querying your own data in words is not phrasing a
 * question -  it is knowing which questions the data can answer. An empty text
 * box offers no clue, so the first thing anyone builds is a panel of available
 * measures, breakdowns and examples.
 *
 * That was impossible: the endpoint returned a key and a name, metrics were
 * behind a second call, and dimensions were not exposed at all.
 */
class DatasetsEndpointTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/time-schemas');
        $app['config']->set('jeeves.routes.middleware', []);
    }

    #[Test]
    public function one_call_returns_everything_needed_to_build_a_question_panel()
    {
        $response = $this->getJson('/jeeves/datasets');

        $response->assertStatus(200);
        $response->assertJsonPath('total', 1);

        $dataset = $response->json('datasets.0');

        foreach (['key', 'name', 'description', 'aliases', 'metrics', 'dimensions',
            'default_dimension', 'date_column', 'examples'] as $field) {
            $this->assertArrayHasKey($field, $dataset, "missing {$field}");
        }
    }

    #[Test]
    public function it_lists_the_breakdowns_a_client_may_offer()
    {
        $dimensions = $this->getJson('/jeeves/datasets')->json('datasets.0.dimensions');

        $this->assertContains('region', $dimensions);
        // A measure is not something to group by, and offering it would produce
        // a question the builder is required to refuse.
        $this->assertNotContains('revenue', $dimensions);
    }

    #[Test]
    public function it_says_which_date_a_period_applies_to()
    {
        // A table often has several. A client showing a date picker needs to
        // know which one "last month" will narrow on.
        $this->getJson('/jeeves/datasets')
            ->assertJsonPath('datasets.0.date_column', 'order_date');
    }

    #[Test]
    public function measures_come_with_the_descriptions_written_for_humans()
    {
        $metrics = $this->getJson('/jeeves/datasets')->json('datasets.0.metrics');
        $keys = array_column($metrics, 'key');

        $this->assertContains('revenue', $keys);
        $this->assertContains('record_count', $keys);
        $this->assertArrayHasKey('description', $metrics[0]);
    }

    #[Test]
    public function a_single_dataset_can_be_asked_for_directly()
    {
        $this->getJson('/jeeves/datasets?dataset=tf_sales')
            ->assertStatus(200)
            ->assertJsonPath('key', 'tf_sales')
            ->assertJsonPath('date_column', 'order_date');
    }

    #[Test]
    public function asking_for_a_dataset_that_does_not_exist_says_which_ones_do()
    {
        // Previously this returned an empty metrics list and a 200, which reads
        // as "that dataset exists and has nothing in it".
        $response = $this->getJson('/jeeves/datasets?dataset=not_a_dataset');

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'cannot_answer');
        $this->assertContains('tf_sales', $response->json('available'));
    }
}
