<?php

namespace Jayanta\Jeeves\Engine\Semantic;

use Jayanta\Jeeves\Contracts\SemanticMatcherInterface;

/**
 * The matcher every install gets until one is deliberately configured.
 *
 * It matches nothing, costs nothing, and opens no socket, so the dataset
 * cascade runs exactly as it did before this feature existed: exact detection,
 * then the LLM. This is the DEFAULT binding - semantic matching is opt-in, and
 * an install that never sets JEEVES_SEMANTIC_MATCH_ENABLED cannot be affected
 * by any of it.
 */
class NullSemanticMatcher implements SemanticMatcherInterface
{
    public function match(string $query, array $allowed = []): SemanticMatchResult
    {
        return SemanticMatchResult::none();
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
