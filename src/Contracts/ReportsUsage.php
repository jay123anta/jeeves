<?php

namespace Jayanta\Jeeves\Contracts;

/**
 * A provider that can say what its last call cost.
 *
 * Every provider API returns a usage block -  Gemini's `usageMetadata`,
 * OpenAI's `usage` -  and the package threw all of it away. That left an
 * adopter unable to answer the first question any finance or platform team
 * asks about an AI feature: what does it cost, and who is spending it?
 *
 * The daily budget counts QUESTIONS, which is a poor proxy. A question against
 * a two-table schema and one against a fourteen-table schema differ by an
 * order of magnitude in prompt tokens, and a decomposed multi-step question
 * costs several calls. Counting requests treats those as equal.
 *
 * Deliberately a SEPARATE interface rather than a method on
 * LlmProviderInterface: adding to that contract would break every custom
 * provider an adopter has already written, and Rule 3 asks for capabilities
 * that degrade gracefully. Callers check `instanceof` and carry on without
 * usage when a provider does not report it.
 */
interface ReportsUsage
{
    /**
     * Token counts for the most recent call on this instance.
     *
     * Empty when the provider returned no usage block, which some
     * OpenAI-compatible servers do not. Absent numbers are omitted rather than
     * reported as zero -  zero is a real value and would quietly understate a
     * bill.
     *
     * @return array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int, calls?: int}
     */
    public function lastUsage(): array;

    /**
     * Start counting again, at the beginning of a question.
     *
     * On the interface rather than left to implementations, because the caller
     * has to be able to call it after an `instanceof` check -  a provider that
     * reported usage but could not be reset would accumulate across every
     * question in the request and report a running total as a per-question
     * cost.
     */
    public function resetUsage(): void;
}
