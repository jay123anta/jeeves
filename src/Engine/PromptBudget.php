<?php

namespace Jayanta\Jeeves\Engine;

/**
 * Prompt Budget
 *
 * NQ-001-REDUCE cut this down to a bare bound (the G1-round-3 ruling struck
 * out the scoping half entirely -  no seeding, no FK expansion, no per-question
 * SQL gate, no scope-filtered prompt). A pure function of a finished prompt
 * string and how many datasets were actually rendered into it -  no loop, no
 * shrinking, no provider knowledge. It answers exactly one question: does
 * this prompt fit inside `prompts.max_chars`?
 *
 * R4: the bound may only REFUSE. There is no second, smaller attempt in
 * here or anywhere downstream of it -  a refusal from `check()` is always
 * `_unretriable` at the call site, because the only retry strategy this
 * package has sends a SMALLER, single-dataset prompt, which is exactly the
 * wrong move for a refusal caused by size rather than by what the model
 * made of the question.
 */
class PromptBudget
{
    /** Null means unbounded -  today's behaviour, byte for byte (C1). */
    protected ?int $maxChars;

    public function __construct(?int $maxChars)
    {
        $this->maxChars = $maxChars;
    }

    /**
     * @param  int  $datasetsRendered  how many datasets' full schema actually
     *                                 went into $prompt -  the caller's own count, not a guess this
     *                                 class makes. The wording below must pick its sentence from THIS
     *                                 number, not from anything the caller may separately know about
     *                                 seeds or scope: an earlier version chose the sentence from a
     *                                 scope object's key count, which could disagree with what was
     *                                 actually rendered, and told a single-dataset question to
     *                                 "narrow to fewer datasets" -  nonsense advice for a number that
     *                                 was never more than one to begin with.
     * @return string|null null when the prompt fits (or the budget is
     *                     unbounded); otherwise a refusal message naming bytes needed,
     *                     bytes allowed, and what a person can do about it.
     */
    public function check(string $prompt, int $datasetsRendered): ?string
    {
        if ($this->maxChars === null) {
            return null;
        }

        $needed = strlen($prompt);
        if ($needed <= $this->maxChars) {
            return null;
        }

        // A single dataset alone over budget has nothing left to scope down
        // TO -  say so plainly rather than offering a lever that cannot help.
        if ($datasetsRendered <= 1) {
            return "This question needs {$needed} bytes of schema context, "
                . "but jeeves.prompts.max_chars allows only {$this->maxChars} -  "
                . 'no scoping configuration can help; raise prompts.max_chars, or the provider\'s '
                . 'own context window, to answer this question.';
        }

        return "This question needs {$needed} bytes of schema context across {$datasetsRendered} "
            . "datasets, but jeeves.prompts.max_chars allows only {$this->maxChars}. Narrow "
            . 'the question to fewer datasets, add a more specific jeeves.query_routing rule, '
            . 'or raise prompts.max_chars.';
    }
}
