<?php

namespace Jayanta\Jeeves\Events;

/**
 * A question could not be answered.
 *
 * Separate from QuestionAnswered because the two want different treatment: a
 * failure is worth alerting on, and the pattern of failures is the best guide
 * to what a schema is missing. `errorCode` is the machine-readable one from
 * Engine\ErrorCode, so a listener can tell a provider outage worth paging
 * someone about from a question the schema simply cannot answer.
 *
 * Clarifications are NOT failures and do not fire this -  being asked which
 * measure you meant is the system working.
 */
class QuestionFailed
{
    public function __construct(
        public readonly string $question,

        /** See Engine\ErrorCode: provider_error, rate_limited, cannot_answer, … */
        public readonly string $errorCode,

        /** The message shown to the user. Written for a person; do not match on it. */
        public readonly string $message,

        public readonly string $provider,
        public readonly float $durationMs,
    ) {}
}
