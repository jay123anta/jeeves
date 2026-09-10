<?php

namespace Jayanta\Jeeves\Engine\Semantic;

/**
 * What a semantic matcher decided, and how sure it was.
 *
 * `dataset` is null whenever nothing was matched: the feature is off, the
 * corpus is empty, every candidate was outside the allowed set, or the backend
 * could not be reached. Callers route on `confident`, never on `dataset` alone
 * - a dataset is reported below the threshold so it can be logged and tuned,
 * and acting on it would be acting on a guess.
 *
 * Rule 8. `score` and `candidates` describe the ranking that actually came
 * back. When the backend failed there is no ranking, so they stay empty and
 * `error` says why, rather than a zero that reads like a real low score.
 */
class SemanticMatchResult
{
    /**
     * @param  array<int, array{dataset: string, score: float}>  $candidates
     */
    public function __construct(
        public readonly ?string $dataset,
        public readonly float $score = 0.0,
        public readonly bool $confident = false,
        public readonly array $candidates = [],
        public readonly ?string $error = null,
    ) {}

    /**
     * No match. `$error` distinguishes "the service answered and nothing
     * cleared the bar" (null) from "the service never answered" - the second
     * is an operational fault worth seeing in a log, the first is routine.
     */
    public static function none(?string $error = null): self
    {
        return new self(dataset: null, score: 0.0, confident: false, candidates: [], error: $error);
    }
}
