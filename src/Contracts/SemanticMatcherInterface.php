<?php

namespace Jayanta\Jeeves\Contracts;

use Jayanta\Jeeves\Engine\Semantic\SemanticMatchResult;

/**
 * Contract for the semantic dataset-matching stage.
 *
 * An implementation maps a natural-language question to the dataset KEY most
 * likely to answer it, using meaning rather than the literal words. It is
 * consulted ONLY after exact routing, schema aliases and column aliases have
 * all missed (DatasetSeeder::detect()) - exact always wins, so turning this on
 * can never change an answer the package already routed correctly.
 *
 * Rule 2. An implementation may send the QUESTION TEXT and the list of dataset
 * KEYS it is allowed to choose from. Both are things already sent to the LLM
 * provider on the very next line if this stage declines. Nothing else may
 * leave: no rows, no values, no counts, no schema beyond the keys themselves.
 *
 * Rule 3. Business logic depends on this contract and never on a driver. A
 * driver that cannot answer degrades to a no-match rather than an exception -
 * a matcher that is disabled, misconfigured, timing out or switched off
 * mid-request must leave the query path behaving exactly as it does today.
 * That is not politeness: this stage sits in front of the LLM fallback, so
 * anything it throws would take down questions that used to be answered.
 */
interface SemanticMatcherInterface
{
    /**
     * Rank the registered datasets against a question.
     *
     * @param  string  $query  The English natural-language question.
     * @param  array<string>  $allowed  Dataset keys the caller will accept. A
     *                                  match outside this set is discarded -
     *                                  the matcher's corpus is maintained
     *                                  separately and can name a dataset this
     *                                  install no longer has registered.
     */
    public function match(string $query, array $allowed = []): SemanticMatchResult;

    /**
     * Whether this matcher is configured and its backend is reachable.
     *
     * Diagnostics only (jeeves:doctor). The query path never calls this: it
     * would cost a second round trip to learn what match() reports anyway.
     */
    public function isAvailable(): bool;
}
