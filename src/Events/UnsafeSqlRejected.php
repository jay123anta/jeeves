<?php

namespace Jayanta\Jeeves\Events;

/**
 * The validator refused generated SQL. It was never executed.
 *
 * Its own event rather than a variety of QuestionFailed, because it is a
 * security signal and belongs wherever an application sends those. Under
 * normal use it should essentially never fire: the model is asked for SELECT
 * statements against a whitelisted set of tables, so a rejection means either
 * a model producing something well outside its instructions, or someone
 * probing the endpoint.
 *
 * A handful in a day is noise. A burst from one user is not.
 */
class UnsafeSqlRejected
{
    public function __construct(
        /** The question that led here -  usually the more telling half. */
        public readonly string $question,

        /** The SQL as generated. Rejected before execution. */
        public readonly string $sql,

        /** Which rule refused it. */
        public readonly string $reason,

        public readonly string $provider,
    ) {}
}
