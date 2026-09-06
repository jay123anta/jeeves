<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\PromptBuilder;
use Jayanta\Jeeves\Feedback\FeedbackStore;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `feedback.max_per_prompt` was documented and never read.
 *
 * The config said "Max corrections to include per prompt (too many =
 * slow/expensive)" and set it to 5. The cap existed - `FeedbackStore` takes a
 * `$limit` - but `PromptBuilder` called it without an argument, so the real
 * ceiling was the method's own default and the setting did nothing.
 *
 * That is the quiet kind of broken: the feature works, the number is
 * plausible, and an adopter lowering it to control prompt size gets no error
 * and no effect. Every past correction keeps going into every prompt.
 */
class FeedbackCorrectionsAreCappedByConfigTest extends TestCase
{
    private function storeCorrections(int $count): void
    {
        $store = $this->app->make(FeedbackStore::class);

        for ($i = 1; $i <= $count; $i++) {
            $store->recordCorrection(
                "question number {$i}",
                'test_orders',
                "correction number {$i}",
                "SELECT {$i} FROM public.orders"
            );
        }
    }

    #[Test]
    public function lowering_the_cap_puts_fewer_corrections_in_the_prompt()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->storeCorrections(8);

        config(['jeeves.feedback.max_per_prompt' => 2]);
        $this->app->forgetInstance(PromptBuilder::class);
        $few = $this->app->make(PromptBuilder::class)->buildSqlPrompt('test_orders', 'total revenue');

        config(['jeeves.feedback.max_per_prompt' => 8]);
        $this->app->forgetInstance(PromptBuilder::class);
        $many = $this->app->make(PromptBuilder::class)->buildSqlPrompt('test_orders', 'total revenue');

        $countIn = fn (string $p) => substr_count($p, 'When user asked:');

        $this->assertGreaterThan(
            $countIn($few),
            $countIn($many),
            'feedback.max_per_prompt does not change how many corrections reach the prompt, so '
                . 'an adopter lowering it to control prompt size gets no effect and no error'
        );

        $this->assertLessThanOrEqual(
            2,
            $countIn($few),
            'the cap was exceeded'
        );
    }
}
