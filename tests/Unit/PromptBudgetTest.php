<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\PromptBudget;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-001-REDUCE, RED for G2.
 *
 * The G1-round-3 ruling cuts PromptBudget down to the bare bound: a PURE
 * function of a finished prompt string and how many datasets were actually
 * RENDERED into it -  `check(string $prompt, int $datasetsRendered): ?string`.
 * No `PromptScope` argument, no scoping knowledge at all.
 *
 * Today's `PromptBudget::check()` still requires a `PromptScope` as its
 * second argument (src/Engine/PromptBudget.php:35), so every test below that
 * calls `check($prompt, <int>)` fails RIGHT NOW with a TypeError -  the
 * reduction has not happened yet. That is the correct RED failure: it proves
 * the bare-bound signature does not exist, not that arithmetic is wrong.
 *
 * WHAT WRONG BEHAVIOUR THIS FILE CATCHES, once the signature exists:
 *
 * 1. `the_boundary_is_exact` -  a size-refusal that is off by even one
 *    character either lets an over-budget prompt through (a provider call
 *    that predictably fails, or worse, a truncated schema silently
 *    answered -  the OllamaProvider failure mode) or refuses a prompt that
 *    would have fit, for no reason a user can see.
 *
 * 2. `the_refusal_names_bytes_needed_bytes_allowed_and_the_config_lever` -
 *    a refusal that does not name the actual lever
 *    (`jeeves.prompts.max_chars`) sends an adopter guessing at which
 *    of a dozen config keys to change, which is indistinguishable from "could
 *    not understand the query" in practice even though the cause here is
 *    entirely mechanical and fixable in one edit.
 *
 * 3. `a_single_rendered_dataset_is_never_told_to_narrow_to_fewer_datasets` -
 *    THE defect the ruling names by name: "note the current implementation
 *    picks that wording from scope key count, which is not what was
 *    rendered. That incoherence must not survive." The OLD code
 *    (src/Engine/PromptBudget.php:51) chose this sentence from
 *    `count($scope->keys())` -  the SEEDED/EXPANDED scope -  which is not the
 *    same number as how many datasets actually went into the prompt string
 *    (a single-dataset `buildSqlPrompt()` call renders exactly one schema
 *    section regardless of how many keys the scope object carried). Telling
 *    someone asking a single-dataset question to "narrow to fewer datasets"
 *    is nonsense advice that sends them looking for a lever that cannot
 *    help. The reduced design closes the gap structurally: the caller must
 *    pass the actual rendered count, so there is no second number left to
 *    disagree with it.
 *
 * 4. `multiple_rendered_datasets_are_offered_the_narrowing_lever` -  the
 *    mirror image: when more than one dataset really was rendered, dropping
 *    the narrowing advice entirely would leave a genuinely fixable question
 *    ("ask about one topic at a time") with no actionable lever at all,
 *    which is exactly as unhelpful as offering it wrongly.
 *
 * 5. `an_unbounded_budget_never_refuses` -  baseline (C1): `max_chars = null`
 *    must never refuse, at any size or dataset count, or a fresh install
 *    with the feature untouched starts failing questions that worked in
 *    v2.0.0.
 */
class PromptBudgetTest extends TestCase
{
    /**
     * Hand-checkable: a 10-character prompt against max_chars=10 must fit
     * (needed 10 <= allowed 10). The same prompt plus one more character
     * (11 <= 10 is false) must refuse. A human can count both strings by
     * eye -  this is not an estimate.
     */
    #[Test]
    public function the_boundary_is_exact()
    {
        $budget = new PromptBudget(10);

        $exactlyAtLimit = str_repeat('x', 10); // strlen = 10, allowed = 10
        $oneOverLimit = str_repeat('x', 11);   // strlen = 11, allowed = 10

        $this->assertNull(
            $budget->check($exactlyAtLimit, 1),
            'a prompt exactly at max_chars must fit, not refuse'
        );

        $refusal = $budget->check($oneOverLimit, 1);
        $this->assertNotNull(
            $refusal,
            'a prompt one character over max_chars must refuse'
        );
    }

    #[Test]
    public function the_refusal_names_bytes_needed_bytes_allowed_and_the_config_lever()
    {
        $budget = new PromptBudget(50);
        $prompt = str_repeat('x', 73); // hand-checkable: strlen() = 73

        $refusal = $budget->check($prompt, 2);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('73', $refusal, 'bytes needed (strlen of the prompt) must be stated');
        $this->assertStringContainsString('50', $refusal, 'bytes allowed (max_chars) must be stated');
        $this->assertStringContainsString(
            'jeeves.prompts.max_chars',
            $refusal,
            'the literal config key must be named so a reword cannot drop the lever'
        );
    }

    /**
     * THE defect named in the ruling. Old code picked this sentence from
     * `count($scope->keys())`, a number that can diverge from what was
     * actually rendered. The new signature removes the second number
     * entirely -  the caller passes the true rendered count directly -  so
     * this must never suggest narrowing when only one dataset was rendered.
     */
    #[Test]
    public function a_single_rendered_dataset_is_never_told_to_narrow_to_fewer_datasets()
    {
        $budget = new PromptBudget(10);
        $prompt = str_repeat('x', 500);

        $refusal = $budget->check($prompt, 1);

        $this->assertNotNull($refusal);
        $this->assertStringNotContainsStringIgnoringCase(
            'fewer datasets',
            $refusal,
            'a single rendered dataset has nothing left to narrow to -  advising it anyway is the exact '
                . 'incoherence the ruling says must not survive'
        );
        $this->assertStringContainsStringIgnoringCase(
            'no scoping',
            $refusal,
            'a single-dataset floor must say plainly that no scoping configuration can help'
        );
    }

    /**
     * The mirror property: when the prompt genuinely rendered more than one
     * dataset, the narrowing lever IS real advice and must still be offered.
     */
    #[Test]
    public function multiple_rendered_datasets_are_offered_the_narrowing_lever()
    {
        $budget = new PromptBudget(10);
        $prompt = str_repeat('x', 500);

        $refusal = $budget->check($prompt, 3);

        $this->assertNotNull($refusal);
        $this->assertStringContainsStringIgnoringCase(
            'fewer datasets',
            $refusal,
            'with 3 datasets actually rendered, narrowing the question is real, useful advice'
        );
    }

    /**
     * The wording must actually depend on what was rendered, not just
     * silently be the same sentence with a different number spliced in
     * -  proves the two code paths above are genuinely distinct, not one
     * string that happens to contain both substrings by accident.
     */
    #[Test]
    public function the_single_and_multi_dataset_refusals_are_different_sentences()
    {
        $budget = new PromptBudget(10);
        $prompt = str_repeat('x', 500);

        $single = $budget->check($prompt, 1);
        $multi = $budget->check($prompt, 4);

        $this->assertNotSame($single, $multi);
    }

    /** C1 baseline: unbounded (max_chars null) never refuses, any size, any count. */
    #[Test]
    public function an_unbounded_budget_never_refuses()
    {
        $budget = new PromptBudget(null);

        $this->assertNull($budget->check(str_repeat('x', 1_000_000), 1));
        $this->assertNull($budget->check(str_repeat('x', 1_000_000), 50));
    }

    /** A prompt that fits must not refuse regardless of how many datasets rendered it. */
    #[Test]
    public function a_prompt_that_fits_never_refuses_regardless_of_dataset_count()
    {
        $budget = new PromptBudget(1000);
        $prompt = str_repeat('x', 999);

        $this->assertNull($budget->check($prompt, 1));
        $this->assertNull($budget->check($prompt, 20));
    }
}
