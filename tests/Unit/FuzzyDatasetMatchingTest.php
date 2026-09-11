<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\DatasetSeeder;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * DatasetSeeder::detectFuzzy() - a misspelled dataset name placed locally.
 *
 * Every assertion names the install's OWN aliases from a stub schema. Nothing
 * here knows about any particular domain, because the mechanism must not: the
 * project this was learned from kept a hand-written table of its own
 * misspellings, and a package installed by strangers cannot.
 */
class FuzzyDatasetMatchingTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/semantic-schemas');
    }

    private function seeder(): DatasetSeeder
    {
        return new DatasetSeeder($this->app->make(SchemaRegistry::class));
    }

    private function useSchemas(string $dir): void
    {
        config()->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/' . $dir);
        $this->app->forgetInstance(SchemaRegistry::class);
    }

    /**
     * GUARD AGAINST A VACUOUS SUITE, and the NQ-003 boundary in one.
     *
     * Exact detection must MISS these misspellings, or every test below would
     * pass without detectFuzzy() doing anything. It must also go on missing
     * them, because detect() is what the cache consults to decide whether a
     * stored answer belongs to this question - and a guess has no place there.
     */
    #[Test]
    public function exact_detection_still_misses_every_misspelling_used_here(): void
    {
        foreach (['how many dwelings were sanctioned', 'is the helpdsk busy'] as $question) {
            $this->assertNull(
                $this->seeder()->detect($question),
                "detect() placed '{$question}' on its own - fuzzy matching has leaked into "
                . 'the exact path the cache guard relies on, or the stub gained an alias'
            );
        }
    }

    #[Test]
    public function a_one_letter_misspelling_of_an_alias_is_placed(): void
    {
        $this->assertSame('nq_dwellings', $this->seeder()->detectFuzzy('how many dwelings were sanctioned'));
    }

    /** A second dataset, so the test above is not passing because one dataset wins everything. */
    #[Test]
    public function a_different_misspelling_reaches_a_different_dataset(): void
    {
        $this->assertSame('nq_tickets', $this->seeder()->detectFuzzy('is the helpdsk busy'));
    }

    #[Test]
    public function a_question_near_no_alias_places_nothing(): void
    {
        $this->assertNull($this->seeder()->detectFuzzy('what is the weather like today'));
    }

    /**
     * Two datasets one edit away from the same misspelling. Picking either is
     * a guess, and the LLM is better placed to read the rest of the sentence.
     */
    #[Test]
    public function two_datasets_equally_close_is_left_to_the_llm(): void
    {
        $this->useSchemas('fuzzy-tie-schemas');

        $this->assertNull(
            $this->seeder()->detectFuzzy('show the invoicez for march'),
            'a misspelling equally close to two datasets was routed to one of them'
        );
    }

    /**
     * The counterweight to the tie: one dataset genuinely closer wins, even
     * when another is also within reach.
     */
    #[Test]
    public function the_closer_dataset_wins_when_one_is_closer(): void
    {
        $this->useSchemas('fuzzy-tie-schemas');

        $this->assertSame(
            'purchase_ledger',
            $this->seeder()->detectFuzzy('what has been invoiced'),
            'an exact alias for one dataset lost to a one-edit match on another'
        );
    }

    /** "bills" is one edit from the alias "bill", which is too short to fuzz. */
    #[Test]
    public function a_short_alias_is_never_fuzzed(): void
    {
        $this->useSchemas('fuzzy-tie-schemas');

        $this->assertNull(
            $this->seeder()->detectFuzzy('unpaid bills'),
            'a four-letter alias was fuzzed - short words are where near-misses become different words'
        );
    }

    #[Test]
    public function a_zero_distance_budget_turns_it_off(): void
    {
        $this->assertNull($this->seeder()->detectFuzzy('how many dwelings were sanctioned', 0));
    }

    /** Punctuation in a schema name compares equal to spaces in a question. */
    #[Test]
    public function punctuation_does_not_block_a_match(): void
    {
        $this->assertSame('nq_tickets', $this->seeder()->detectFuzzy('SUPPORT-TICKETZ this week'));
    }
}
