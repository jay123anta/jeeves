<?php

namespace Jayanta\Jeeves\Events;

/**
 * A question was answered successfully.
 *
 * This is the event most applications will listen to: it carries what was
 * asked, how it was understood, the SQL that ran, what it cost and how long it
 * took. Enough to build a usage dashboard, a cost report per team, or a
 * "questions we answer badly" review queue, without patching the package.
 *
 * `rowCount` rather than the rows themselves, deliberately. A listener that
 * wanted the data can re-run the SQL; putting result rows on an event invites
 * them into log drivers, queue payloads and error trackers, which is the one
 * direction this package is built to keep data out of.
 */
class QuestionAnswered
{
    /**
     * @param  array<string, mixed>  $parsedQuery  How the question was understood
     * @param  array<string, int>  $usage  Token counts, empty if unreported
     */
    public function __construct(
        public readonly string $question,
        public readonly array $parsedQuery,

        /** The SQL that ran. Null for a cache hit or a non-SQL answer. */
        public readonly ?string $sql,

        public readonly int $rowCount,

        /** intent | sql_generation -  which strategy actually answered. */
        public readonly ?string $modeUsed,

        public readonly bool $cacheHit,
        public readonly float $durationMs,
        public readonly string $provider,
        public readonly array $usage = [],
    ) {}
}
