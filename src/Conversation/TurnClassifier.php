<?php

namespace Jayanta\Jeeves\Conversation;

use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * What kind of turn is this?
 *
 * Decided explicitly and locally, before anything is resolved, because the
 * failure users actually notice is a NEW question treated as a refinement:
 * they ask about something unrelated and get results still filtered by a
 * region they mentioned three turns ago, with nothing on screen to explain it.
 *
 * So the bias runs toward `new_query`. A refinement misread as a new question
 * loses inherited context and the user simply says it again; a new question
 * misread as a refinement returns a confident wrong number.
 *
 * No API call. This runs on every turn and the cost of asking a model would be
 * paid on every turn too, for a decision that patterns make well.
 */
class TurnClassifier
{
    public const NEW_QUERY = 'new_query';

    public const REFINEMENT = 'refinement';

    public const DRILL_DOWN = 'drill_down';

    public const REFERENCE = 'reference';

    /** "Why is that?" -  reuse the state, change what is shown. */
    protected const REFERENCE_PATTERNS = [
        '/^\s*(?:why|how come|explain|what does (?:that|this) mean|tell me more|say more|elaborate)\b/i',
        '/^\s*(?:what|who|which)\s+(?:is|are|was|were)\s+(?:that|those|it|they)\b/i',
    ];

    /**
     * "Break THAT down by category" -  points at the last answer in so many
     * words, so it is a drill-down whatever else it mentions. "Break that down
     * by revenue" names a measure and is still plainly about the answer on
     * screen.
     */
    protected const ANAPHORIC_DRILL_PATTERNS = [
        '/\bbreak\s+(?:it|that|this|them)\s+down\b/i',
        '/^\s*split\s+(?:it|that|this|them)\b/i',
        '/^\s*group\s+(?:it|that|this|them)\s+by\b/i',
        '/^\s*drill\s+(?:down|into)\b/i',
    ];

    /**
     * "Breakdown by client" -  names a breakdown but not what to break down, so
     * it only makes sense against the previous answer. Checked AFTER
     * standsAlone(), because these words also appear in self-contained
     * questions: "what is the breakdown of revenue by city" names its own
     * measure and is a new question.
     *
     * The one-word spelling is here because it was missing, and that is the
     * spelling people type. "breakdown by client" fell past every drill pattern
     * -  `break\s+…` needs whitespace -  and landed on the refinement default. It
     * still inherited correctly, so the answer was right; it was reported as a
     * narrowing when nothing had been narrowed, and it let the model's
     * re-reading of the measure overwrite the one already established.
     */
    protected const BARE_DRILL_PATTERNS = [
        '/\bbreak[\s-]*down\b/i',
        '/^\s*(?:in|by)\s+which\b/i',
        '/^\s*split\s+by\b/i',
        '/^\s*group(?:ed)?\s+by\b/i',
    ];

    /** "Only in West" -  same question, narrower. */
    protected const REFINEMENT_PATTERNS = [
        '/^\s*(?:only|just|now|and|but|also|then|instead|rather)\b/i',
        // Corrections. "Actually make that East" changes one filter and leaves
        // the rest standing; treated as a new question it silently dropped
        // every other narrowing that was in force.
        '/^\s*(?:actually|sorry|make\s+(?:it|that)|change\s+(?:it|that)|switch\s+to|use)\b/i',
        // Separate, because a trailing \b after "no," never matches: a comma
        // and a space are both non-word characters, so there is no boundary
        // between them.
        '/^\s*no[,\s]/i',
        '/^\s*(?:what|how)\s+about\b/i',
        '/^\s*(?:filter|restrict|narrow|limit)\b/i',
        '/^\s*(?:for|in|during|within|excluding|without)\b/i',
        '/^\s*(?:top|bottom|first|last)\s+\d+\s*$/i',
    ];

    protected SchemaRegistry $registry;

    public function __construct(SchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function classify(string $query, QueryState $state): string
    {
        // Nothing to refine yet.
        if ($state->isEmpty()) {
            return self::NEW_QUERY;
        }

        $q = trim($query);

        foreach (self::REFERENCE_PATTERNS as $pattern) {
            if (preg_match($pattern, $q)) {
                return self::REFERENCE;
            }
        }

        foreach (self::ANAPHORIC_DRILL_PATTERNS as $pattern) {
            if (preg_match($pattern, $q)) {
                return self::DRILL_DOWN;
            }
        }

        // A question that stands on its own is a new one, whatever it starts
        // with. "Only 5 customers by revenue" names its own measure; inheriting
        // last turn's filters would quietly narrow it.
        if ($this->standsAlone($q, $state)) {
            return self::NEW_QUERY;
        }

        // Below standsAlone on purpose: these words appear in self-contained
        // questions too, and only the ones that name no measure of their own
        // are actually asking about the previous answer.
        foreach (self::BARE_DRILL_PATTERNS as $pattern) {
            if (preg_match($pattern, $q)) {
                return self::DRILL_DOWN;
            }
        }

        foreach (self::REFINEMENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $q)) {
                return self::REFINEMENT;
            }
        }

        // A bare fragment after a real question is a refinement: "West",
        // "last month". Anything longer is treated as new, which is the safe
        // direction to be wrong in.
        return str_word_count($q) <= 3 ? self::REFINEMENT : self::NEW_QUERY;
    }

    /**
     * Does this utterance carry its own measure or dataset?
     *
     * If it names a metric, or names a dataset, it is asking something rather
     * than adjusting something.
     */
    protected function standsAlone(string $query, QueryState $state): bool
    {
        $q = strtolower($query);
        $dataset = $state->get('dataset');

        if (!$dataset || !$this->registry->has($dataset)) {
            return str_word_count($q) > 6;
        }

        foreach ($this->registry->getDatasetMetrics($dataset) as $metric) {
            if ($this->mentions($q, (string) $metric['key'])) {
                return true;
            }
        }

        foreach ($this->registry->all() as $key => $schema) {
            foreach (array_merge([$key], $schema['aliases'] ?? []) as $name) {
                if ($this->mentions($q, (string) $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whole-word match, so "order" does not fire on "recorder". */
    protected function mentions(string $haystack, string $needle): bool
    {
        $needle = trim(str_replace('_', ' ', strtolower($needle)));

        return $needle !== ''
            && (bool) preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $haystack);
    }
}
