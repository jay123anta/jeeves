<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The corpus a matching service embeds is generated from THIS install.
 *
 * That is the whole reason the command exists. A package cannot ship a corpus,
 * because it does not know whether the application it lands in is about orders,
 * tickets, patients or welfare schemes - and a hand-written one goes stale the
 * first time a schema file is edited.
 */
class SemanticCorpusCommandTest extends TestCase
{
    private string $output;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/semantic-schemas');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = sys_get_temp_dir() . '/jeeves-corpus-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->output)) {
            @unlink($this->output);
        }

        parent::tearDown();
    }

    private function generate(): array
    {
        $status = Artisan::call('jeeves:semantic-corpus', ['--output' => $this->output]);

        $this->assertSame(0, $status, Artisan::output());
        $this->assertFileExists($this->output);

        $corpus = json_decode((string) file_get_contents($this->output), true);

        $this->assertIsArray($corpus, 'the generated corpus is not valid JSON');

        return $corpus;
    }

    /**
     * The shape a matching service reads: a `schemes` list of key/name/text.
     * Getting this wrong is silent - the service boots, reports an empty
     * corpus, and answers every question with no match.
     */
    #[Test]
    public function it_writes_the_shape_a_matching_service_reads(): void
    {
        $corpus = $this->generate();

        $this->assertSame(2, $corpus['count']);
        $this->assertCount(2, $corpus['schemes']);

        foreach ($corpus['schemes'] as $entry) {
            $this->assertArrayHasKey('key', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('text', $entry);
            $this->assertNotSame('', trim($entry['text']), 'an entry has nothing to embed');
        }

        $this->assertSame(
            ['nq_dwellings', 'nq_tickets'],
            array_column($corpus['schemes'], 'key')
        );
    }

    /**
     * It describes whatever THIS install has. The assertions name datasets
     * from the stub schemas, not anything the package knows about.
     */
    #[Test]
    public function the_text_carries_the_vocabulary_users_actually_type(): void
    {
        $corpus = $this->generate();
        $text = collect($corpus['schemes'])->firstWhere('key', 'nq_dwellings')['text'];

        $this->assertStringContainsString('Dwellings', $text, 'the name is missing');
        $this->assertStringContainsString('Sanctioned and completed', $text, 'the description is missing');
        $this->assertStringContainsString('sanctioned units', $text, 'a schema alias is missing');
        $this->assertStringContainsString(
            'zila',
            $text,
            'column aliases are missing, and they are most of what makes a question match'
        );
    }

    /**
     * Column identifiers are not vocabulary. `dist_cd` is not a word anyone
     * says, and embedding it adds noise rather than meaning.
     */
    #[Test]
    public function column_identifiers_are_left_out(): void
    {
        $corpus = $this->generate();
        $text = collect($corpus['schemes'])->firstWhere('key', 'nq_tickets')['text'];

        $this->assertStringContainsString('bucket', $text, 'the column alias is missing');
        $this->assertStringNotContainsString(
            'queue',
            $text,
            'a raw column identifier was embedded as if it were a word users say'
        );
    }

    /**
     * RULE 2. The corpus is metadata, and it leaves the server the moment it
     * is mounted into a service. Nothing that reads the database may end up
     * in it - this command never opens a connection, and this is the
     * assertion that says so.
     */
    #[Test]
    public function no_row_data_can_reach_the_corpus(): void
    {
        $corpus = $this->generate();
        $everything = json_encode($corpus);

        foreach (['Kamrup', 'Nagaon'] as $value) {
            $this->assertStringNotContainsString(
                $value,
                (string) $everything,
                "'{$value}' is a value from a table, and it reached a file written to be "
                . 'handed to another service'
            );
        }
    }

    /** An alias repeated across columns must not dominate the vector. */
    #[Test]
    public function repeated_vocabulary_appears_once(): void
    {
        $corpus = $this->generate();
        $text = collect($corpus['schemes'])->firstWhere('key', 'nq_dwellings')['text'];

        $this->assertSame(
            1,
            substr_count(mb_strtolower($text), 'zila'),
            'a repeated alias was written more than once'
        );
    }

    /**
     * Found by the release gate, on a real install rather than a fixture:
     * `discover` writes the same placeholder for a dataset and for its table,
     * so an undescribed schema produced "Users. Data from Users. Data from
     * Users. users" - the same clause weighted twice. Repetition is not
     * meaning, and it tilts the vector toward whichever dataset repeats most.
     */
    #[Test]
    public function a_sentence_written_twice_is_embedded_once(): void
    {
        config()->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/thin-schemas');
        $this->app->forgetInstance(SchemaRegistry::class);

        $corpus = $this->generate();
        $text = $corpus['schemes'][0]['text'];

        $this->assertSame(
            1,
            substr_count($text, 'Data from Users'),
            'the placeholder description was embedded more than once: ' . $text
        );
    }

    /**
     * A corpus with nothing in it loads fine and matches almost nothing, which
     * reads as a broken service rather than an empty one. Say so at the point
     * the file is written, while the person is still looking.
     */
    #[Test]
    public function it_says_when_a_dataset_has_almost_nothing_to_embed(): void
    {
        config()->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/thin-schemas');
        $this->app->forgetInstance(SchemaRegistry::class);

        Artisan::call('jeeves:semantic-corpus', ['--output' => $this->output]);
        $out = Artisan::output();

        $this->assertStringContainsString('almost nothing to embed', $out);
        $this->assertStringContainsString('users', $out);
        $this->assertStringContainsString('jeeves:audit-schema', $out, 'no route to fixing it was offered');
    }

    /** The counterweight: a described schema must NOT be warned about. */
    #[Test]
    public function a_described_dataset_draws_no_warning(): void
    {
        Artisan::call('jeeves:semantic-corpus', ['--output' => $this->output]);

        $this->assertStringNotContainsString(
            'almost nothing to embed',
            Artisan::output(),
            'the warning fires on schemas that are perfectly well described'
        );
    }

    #[Test]
    public function it_can_print_instead_of_writing(): void
    {
        $status = Artisan::call('jeeves:semantic-corpus', ['--stdout' => true]);
        $printed = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertIsArray(json_decode($printed, true), 'stdout was not valid JSON');
        $this->assertFileDoesNotExist($this->output);
    }

    #[Test]
    public function it_fails_rather_than_writing_an_empty_corpus(): void
    {
        config()->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/does-not-exist');
        $this->app->forgetInstance(SchemaRegistry::class);

        $status = Artisan::call('jeeves:semantic-corpus', ['--output' => $this->output]);

        $this->assertSame(
            1,
            $status,
            'an empty corpus was written, which a service boots on and then silently '
            . 'matches nothing'
        );
        $this->assertFileDoesNotExist($this->output);
    }
}
