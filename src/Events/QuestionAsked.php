<?php

namespace Jayanta\Jeeves\Events;

/**
 * A question is about to be sent to a model.
 *
 * Fired after the input guard has cleared it and before any provider call, so
 * a listener sees exactly what will be spent on. Use it to attribute cost to a
 * user or team, to enforce a quota the package does not know about, or to keep
 * an audit trail of who asked what.
 *
 * The question text is here in full. That is the user's own sentence, already
 * bound for a provider under Rule 2, so nothing new is exposed -  but a
 * listener that ships it somewhere is making its own decision about that.
 */
class QuestionAsked
{
    public function __construct(
        /** The question, after the input guard sanitised it. */
        public readonly string $question,

        /** Dataset the caller pinned, if any. Null means the engine routes it. */
        public readonly ?string $dataset,

        /** intent | sql_generation | auto -  as configured for this call. */
        public readonly string $queryMode,

        /** Set when the question arrived through the conversation endpoint. */
        public readonly ?string $sessionId = null,
    ) {}
}
