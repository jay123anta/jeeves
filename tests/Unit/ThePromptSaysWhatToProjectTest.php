<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\PromptBuilder;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The biggest cluster of benchmark failures was the right rows with an extra
 * column.
 *
 *   list all product names           got [1|chair ; 1|desk]   want [chair ; desk]
 *   which products cost more than 200  got [250|desk]         want [desk]
 *   which customers joined in 2025     got [1|ada lovelace]   want [ada lovelace]
 *   which orders have not shipped yet  got [2026-06-28|5|…]   want [3 ; 5]
 *
 * Four questions, 8.7 points, every one of them finding the correct rows and
 * projecting something nobody asked for. Two had already escalated to SQL
 * generation and still did it, so it was never a routing fault - the model was
 * being told to do it. The multi-dataset prompt carries rule 8d, "SELECT the
 * measure alongside the label", which is right for a RANKING and wrong for a
 * question that has no measure in it at all.
 *
 * These assertions exist so the counterbalancing rules cannot be tidied away,
 * and so 8d cannot be deleted in an attempt to fix the same cluster from the
 * other side - a ranking that returns bare names is equally wrong.
 */
class ThePromptSaysWhatToProjectTest extends TestCase
{
    private function builder(): PromptBuilder
    {
        return $this->app->make(PromptBuilder::class);
    }

    /** @return array<string, string> */
    private function bothPrompts(): array
    {
        return [
            'single-dataset' => $this->builder()->buildSqlPrompt('test_orders', 'which products cost more than 200'),
            'multi-dataset' => $this->builder()->buildMultiDatasetPrompt('which products cost more than 200'),
        ];
    }

    #[Test]
    public function both_prompts_say_to_project_only_what_was_asked()
    {
        foreach ($this->bothPrompts() as $which => $prompt) {
            $this->assertMatchesRegularExpression(
                '/identifying column ALONE/',
                $prompt,
                "the {$which} prompt no longer tells the model to return the identifier alone for a "
                    . 'question with no measure in it, which is how a filter column ends up in the answer'
            );
        }
    }

    #[Test]
    public function both_prompts_say_a_singular_superlative_wants_one_row()
    {
        foreach ($this->bothPrompts() as $which => $prompt) {
            $this->assertStringContainsString(
                'LIMIT 1',
                $prompt,
                "the {$which} prompt no longer distinguishes 'the most' from 'top 5'"
            );
        }
    }

    /**
     * THE COUNTERWEIGHT. The projection rule must not be read as "never return
     * the measure" - a ranking without the column it is ranked by does not
     * answer the question either.
     */
    #[Test]
    public function the_multi_dataset_prompt_still_asks_for_the_measure_on_a_ranking()
    {
        $this->assertStringContainsString(
            'SELECT the measure alongside the label',
            $this->builder()->buildMultiDatasetPrompt('top 5 customers by revenue'),
            'rule 8d was removed, so a ranking may now come back as bare names'
        );
    }
}
