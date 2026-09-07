<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-011. A question that names a measure the SCHEMA knows is not ambiguous,
 * even when it uses none of the words that sound like arithmetic.
 *
 * When intent parsing ends in a clarification, the orchestrator decides whether
 * to accept that or let auto mode try SQL generation instead. It accepts the
 * clarification unless the question "states its own measure", and that test was
 * a fixed list of aggregate words - total, average, highest, count, how many.
 *
 * "top 3 customers by revenue" contains none of them. So the engine resolved a
 * dataset, found no revenue measure on it, and asked the user to pick a metric
 * that the sentence already named. Measured across four benchmark runs, that
 * question failed every time - not with a wrong answer, but with a question
 * asked back.
 *
 * `revenue` is not a word the package should have to know. It is in the
 * adopter's schema, as a name or an alias of an aggregatable column, which is
 * exactly where this project keeps domain vocabulary. So the check now asks the
 * registry rather than a hardcoded list.
 *
 * Widening this is safe by construction, and the existing comment at the call
 * site says why: the clarification is kept unless generation actually succeeds,
 * so a genuinely open question still gets asked rather than guessed at.
 */
class AQuestionThatNamesAMeasureIsNotAmbiguousTest extends TestCase
{
    private function statesItsOwnMeasure(string $question): bool
    {
        $orchestrator = $this->app->make(QueryOrchestrator::class);

        $method = new \ReflectionMethod($orchestrator, 'statesItsOwnMeasure');
        $method->setAccessible(true);

        return (bool) $method->invoke($orchestrator, $question);
    }

    /**
     * The case from the benchmark. `revenue` is an alias of the aggregatable
     * column `amount` in tests/Stubs/schemas/test_orders.php.
     */
    #[Test]
    public function a_measure_named_by_a_schema_alias_counts_as_stating_its_own_measure()
    {
        $this->assertTrue(
            $this->statesItsOwnMeasure('top 3 customers by revenue'),
            'the question names `revenue`, an alias of an aggregatable column in the '
            . 'schema, but the engine treated it as not naming a measure - so it asks '
            . 'the user for a metric the sentence already contains'
        );
    }

    /** And by the column's own name, not only by an alias. */
    #[Test]
    public function a_measure_named_by_the_column_itself_counts_too()
    {
        $this->assertTrue(
            $this->statesItsOwnMeasure('top 3 customers by amount'),
            'the question names the aggregatable column `amount` directly'
        );
    }

    /**
     * The aggregate words still work. This is the behaviour that existed
     * before, and widening the check must not have replaced it.
     */
    #[Test]
    public function the_arithmetic_words_still_count()
    {
        foreach (['how many orders are there', 'what is the average order value', 'the highest unit price'] as $q) {
            $this->assertTrue($this->statesItsOwnMeasure($q), "lost an aggregate word: {$q}");
        }
    }

    /**
     * COUNTERWEIGHT, and the one that keeps this honest.
     *
     * A question that names no measure at all must still be treated as
     * genuinely open, or every clarification becomes a fallback and the engine
     * guesses instead of asking. A fix that returned true for everything would
     * pass all three tests above.
     */
    #[Test]
    public function a_question_that_names_no_measure_is_still_open()
    {
        foreach (['which is the best?', 'show me the customers', 'what about last year'] as $q) {
            $this->assertFalse(
                $this->statesItsOwnMeasure($q),
                "\"{$q}\" names no measure, so it must stay a clarification rather than "
                . 'becoming a guess'
            );
        }
    }
}
