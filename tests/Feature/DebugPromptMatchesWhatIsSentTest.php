<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Jayanta\Jeeves\Engine\DatasetSeeder;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `jeeves:debug` says it shows "EXACTLY what prompt the AI receives".
 *
 * It decided three things differently from the engine, and the whole value of
 * the command is that it decides them the same way. Someone reaches for it
 * precisely when a question is behaving oddly, so a debugger that quietly
 * models a different code path sends them looking in the wrong place.
 *
 * 1. DATASET DETECTION. The command open-coded its own str_contains() loop
 *    over keys and aliases instead of calling DatasetSeeder::detect(), which
 *    is what the engine uses and which also consults query_routing rules.
 *
 * 2. WHICH PROMPT GETS BUILT. The engine sends the MULTI-dataset prompt
 *    whenever schemas are linked, even with a dataset detected, because a
 *    question can legitimately span them and only that prompt permits a join.
 *    The command omitted the linked-schemas condition and printed the focused
 *    single-dataset prompt -  the one the engine would not have sent.
 *
 * 3. THE SIZE BOUND. prompts.max_chars refuses a prompt before it is sent.
 *    The command printed the prompt with no indication that this question
 *    would be refused rather than answered.
 */
class DebugPromptMatchesWhatIsSentTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // related-schemas declare relationships, so hasLinkedSchemas() is true
        // and the engine always builds the multi-dataset prompt.
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/related-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
    }

    /**
     * With linked schemas the engine cannot send the focused prompt, so the
     * debugger must not claim it would.
     */
    #[Test]
    public function it_reports_the_prompt_the_engine_would_actually_build()
    {
        $registry = $this->app->make(SchemaRegistry::class);
        $this->assertTrue(
            $registry->hasLinkedSchemas(),
            'precondition: this fixture must have linked schemas for the divergence to exist'
        );

        $keys = $registry->keys();
        $this->assertNotEmpty($keys);

        // Name a dataset explicitly, so detection definitely succeeds and the
        // ONLY thing that can pick the prompt type is the linked-schemas rule.
        $this->artisan('jeeves:debug', ['query' => 'total revenue', '--dataset' => $keys[0]])
            ->expectsOutputToContain('Multi-dataset')
            ->assertExitCode(0);
    }

    /**
     * A prompt the engine would refuse must be shown as refused.
     *
     * In sql_generation mode, because that is the only route prompts.max_chars
     * is consulted on. This originally left query_mode at its default and so
     * asserted the refusal appears in 'auto' -  where the engine answers this
     * question by intent parsing and never consults the bound at all. The
     * assertion was passing on behaviour that told the user their question
     * would fail when it would not.
     */
    #[Test]
    public function it_says_when_the_prompt_would_be_refused_for_size()
    {
        config([
            'jeeves.query_mode' => 'sql_generation',
            'jeeves.prompts.max_chars' => 50,
        ]);

        $this->artisan('jeeves:debug', ['query' => 'total revenue'])
            ->expectsOutputToContain('max_chars')
            ->assertExitCode(0);
    }

    /** Dataset detection must be the engine's, not a second implementation. */
    #[Test]
    public function it_detects_the_dataset_the_way_the_engine_does()
    {
        $seeder = $this->app->make(DatasetSeeder::class);
        $question = 'how much revenue did we make';

        $expected = $seeder->detect($question);

        $this->artisan('jeeves:debug', ['query' => $question])
            ->expectsOutputToContain($expected ?: 'NONE')
            ->assertExitCode(0);
    }
}
