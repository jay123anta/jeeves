<?php

namespace Jayanta\Jeeves\Engine;

use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * Does the intent contract have somewhere to put this question?
 *
 * Intent mode expresses a deliberately small slice of SQL: one measure, one
 * grouping, one name filter, one period, an order and a limit. That covers most
 * questions and is safer than free SQL generation -  deterministic, no
 * hallucinated columns, no clause the validator has to catch.
 *
 * The danger is what happens at the edge. A question needing something the
 * contract cannot represent did not fail: the unrepresentable part was dropped
 * and the remainder answered, with no sign that a narrower question had been
 * substituted. Four defects of exactly that shape were found in three days -
 * a dropped GROUP BY, a dropped metric, a dropped clarification, a dropped
 * period -  each fixed by widening the contract by one field.
 *
 * Widening it one field at a time never closes the gap; there is always
 * another clause. So this asks the opposite question: does the wording show
 * the question needs more than the contract holds? If so, route it to SQL
 * generation, which can express arbitrary (still validated, still SELECT-only)
 * SQL.
 *
 * Escalating is close to free -  intent parsing and SQL generation are one API
 * call each -  so a false positive costs nothing but determinism, while a false
 * negative is a confidently wrong number. The patterns lean towards escalating.
 */
class IntentCoverage
{
    /**
     * Optional so an IntentCoverage built by hand keeps working. Without it,
     * the non_sum_aggregate rule cannot tell a schema that already provides an
     * average from one that does not, and escalates either way -  the safe
     * direction, at the cost of one extra call.
     */
    public function __construct(protected ?SchemaRegistry $registry = null) {}

    /**
     * Wording that needs SQL the intent contract cannot express, by the SQL
     * component it implies. Named so the metadata can say WHY it escalated.
     */
    protected const BEYOND_CONTRACT = [
        // HAVING -  filtering groups by an aggregate
        'having' => [
            '/\b(?:with|having|that\s+have|who\s+have)\s+(?:more|less|fewer|greater|at\s+least|at\s+most|over|under)\b/i',
            '/\bmore\s+than\s+\d+\s+\w+/i',
            // The same question spelled with a word. "more than 1 ticket"
            // escalated and "more than one order" did not, so whether the rule
            // fired depended on how the user typed the number.
            '/\bmore\s+than\s+(?:one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|'
                . 'twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundred|a\s+dozen|dozen)\s+\w+/i',
        ],
        // WHERE on a measure -  the contract filters by name and period only
        'numeric_filter' => [
            '/\b(?:over|above|under|below|greater\s+than|less\s+than|at\s+least|at\s+most)\s+[\d£$€₹]/i',
            '/\bbetween\s+[\d£$€₹][\d,.]*\s+and\s+[\d£$€₹]/i',
            // Compared against an aggregate rather than a number: "weight
            // below the average" needs WHERE x < (SELECT AVG(x)), a subquery
            // the contract has no room for. The digit-anchored patterns above
            // miss it precisely because there is no number in the sentence.
            '/\b(?:above|below|over|under|more\s+than|less\s+than|greater\s+than|higher\s+than|lower\s+than|worse\s+than|better\s+than)\s+(?:the\s+)?(?:average|mean|median|typical|maximum|minimum|max|min|overall)\b/i',
        ],
        // Negation -  no NOT anywhere in the contract
        'exclusion' => [
            '/\b(?:excluding|except|other\s+than|apart\s+from|but\s+not|without\s+(?:the\s+)?(?:any\s+)?\w+)\b/i',
            '/\bnot\s+(?:including|counting|in)\b/i',
        ],

        // NEGATED EXISTENCE -  "customers who have never ordered", "orders
        // that have not shipped". These need NOT EXISTS, NOT IN, or a LEFT
        // JOIN with an IS NULL test, and the contract has room for none of
        // them: it holds one measure, one grouping, one name filter, one
        // period, an order and a limit.
        //
        // So the negation was simply DROPPED and the remainder answered. Asked
        // which orders have not shipped, it returned every order -  the exact
        // shape of failure this class exists to prevent, and worse than most,
        // because "all of them" looks like a real answer to that question.
        //
        // The `exclusion` rule above does not catch these. It matches
        // excluding/except/apart from/without, and none of those words appear
        // in the phrasings people actually use for an anti-join.
        //
        // Measured before adding: across all 164 questions in the benchmark
        // and Spider sets, these patterns newly escalate 4, and the GOLD SQL
        // for all 4 contains NOT EXISTS, NOT IN or a subquery. Zero
        // over-escalation.
        // Placed here, above the aggregate rules, deliberately. exceeds()
        // returns the FIRST match, so position decides only which reason
        // `metadata.escalated_for` reports -  both routes escalate either way.
        // A negation is the more specific fact: the contract cannot express it
        // at ALL, whereas an aggregate merely needs a different function. And
        // across all 164 benchmark and Spider questions, no question matches
        // anti_join AND an aggregate rule, so the ordering is not currently
        // observable in any case.
        'anti_join' => [
            '/\bnever\b/i',
            '/\b(?:have|has|had|is|are|was|were|do|does|did)(?:n\'t|\s+not)\s+(?:been\s+)?\w+/i',
            '/\bwith\s+no\s+\w+/i',
        ],
        // DISTINCT
        'distinct' => [
            '/\b(?:distinct|unique)\s+\w+/i',
            '/\bhow\s+many\s+different\b/i',
            // "the different countries with singers above 20" wants the list
            // of distinct values, not a count per country.
            '/\bdifferent\s+\w+/i',
        ],
        // Ratios and shares -  arithmetic between two aggregates
        'ratio' => [
            '/\b(?:percentage|percent|share|proportion|ratio)\s+of\b/i',
            '/\bwhat\s+(?:percent|percentage)\b/i',
            '/\bper\s+(?:customer|order|user|head|capita)\b/i',
        ],
        // More than one aggregate in a single answer. The contract has ONE
        // metric, so "the average, minimum and maximum age" came back as a
        // single number with the other two silently missing.
        //
        // Kept ABOVE non_sum_aggregate: exceeds() returns the first match, and
        // both of these fire on "the average and maximum age". Either would
        // escalate, but the reported reason should be the more specific one.
        'multi_aggregate' => [
            '/\b(?:average|mean|minimum|maximum|min|max|total|sum|count)\b[^?]{0,40}?,\s*(?:and\s+)?(?:average|mean|minimum|maximum|min|max|total|sum|count)\b/i',
            '/\b(?:average|mean|minimum|maximum|min|max|total|sum|count)\s+and\s+(?:the\s+)?(?:average|mean|minimum|maximum|min|max|total|sum|count)\b/i',
        ],

        // A single aggregate that is not a sum.
        //
        // The intent contract names a METRIC and nothing about what to do with
        // it, and SqlBuilder wraps every aggregatable column in SUM(). So
        // "average amount" summed: 12,100 where the answer is 4,033.33. Not a
        // refusal, not an obviously odd figure -  a plausible number, three
        // times too large, labelled "average". Found by asking questions whose
        // answers could be checked by hand.
        //
        // Escalated rather than guessed at, because SQL generation can write
        // AVG/MIN/MAX and the contract cannot express them at all.
        //
        // Deliberately not matched: "total", "sum" and "how many", which the
        // contract does handle, and comparisons like "above the average",
        // which numeric_filter already catches for a different reason.
        'non_sum_aggregate' => [
            '/\b(?:average|avg|mean|median)\s+(?:\w+\s+){0,2}?\w+/i',
            '/\b(?:the\s+)?(?:minimum|maximum)\s+(?:\w+\s+){0,2}?\w+/i',
            // "max" and "min" alongside the words they abbreviate, and the
            // contracted and imperative forms. Every one of these is the same
            // question; which of them escalated used to depend on how the user
            // happened to type it, and the ones that did not were summed.
            '/\bwhat(?:\x27s|\s+is|\s+was)\s+the\s+(?:average|mean|median|minimum|maximum|max|min)\b/i',
            // The same question in the words people actually use. "maximum"
            // escalated and "highest" did not, so "what is the highest unit
            // price" stayed in intent mode, where SqlBuilder wraps every
            // aggregatable column in SUM(). Measured on the benchmark: it
            // answered 670, which is 120 + 250 + 300, and labelled it the
            // highest price. The right answer is 300. A plausible number,
            // twice too large, described as something it is not.
            //
            // Deliberately anchored to "what is the ..." -  the SCALAR form.
            // "The car with the largest acceleration" is a row lookup that
            // ORDER BY ... LIMIT 1 expresses perfectly well, and measuring
            // showed a looser pattern escalates five of those for nothing.
            '/\bwhat(?:\x27s|\s+is|\s+was)\s+the\s+(?:highest|lowest|largest|smallest|greatest|biggest)\s+\w+/i',
            '/\b(?:show|give|tell)\s+(?:me\s+)?the\s+(?:highest|lowest|largest|smallest|greatest|biggest|max|min)\s+\w+/i',
        ],

        // A list of columns to show. The contract projects one label and one
        // measure, so "name, country and age" returned name and age -  the
        // missing column is not reported, it simply is not there.
        'multi_column' => [
            '/\b(?:show|list|display|give\s+me|what\s+are|return)\b[^?]{0,60}?\b\w+\s*,\s*\w+\s*,\s*(?:and\s+)?\w+/i',
            '/\b\w+\s*,\s*\w+\s*,\s*and\s+\w+/i',
            // Two columns joined by "and" and then attached to a subject:
            // "the name and capacity FOR the stadium", "names and release
            // years FOR all the songs". The trailing preposition is what keeps
            // this from matching "revenue by region" or "orders and revenue".
            '/\b(?:show|list|display|give\s+me|what\s+(?:is|are)|return)\b[^?]{0,40}?\b\w+\s+and\s+(?:the\s+)?\w+(?:\s+\w+)?\s+(?:for|of|by|from|in)\b/i',
            // "the full name of each maker, ALONG WITH its id and how many
            // models" -  an explicit request for extra columns beside the one
            // being measured.
            '/\balong\s+with\b/i',
            '/\b(?:list|show|give)\b[^?]{0,50}?\b(?:its|their)\s+\w+\s+and\s+\w+/i',
        ],

        // Per-group superlatives -  a window function or correlated subquery
        'per_group_top' => [
            '/\btop\s+\d+\s+\w+\s+(?:in|for|per)\s+each\b/i',
            '/\b(?:for|in)\s+each\s+\w+.*\b(?:top|best|highest|lowest)\b/i',
            '/\bper\s+\w+\s+(?:top|best|highest)\b/i',
        ],
    ];

    /**
     * The SQL component this question needs and the contract lacks, or null
     * when intent mode can express it.
     */
    public function exceeds(string $query): ?string
    {
        if (!config('jeeves.sql.escalate_beyond_intent', true)) {
            return null;
        }

        foreach (self::BEYOND_CONTRACT as $component => $patterns) {
            // A schema that defines the aggregate as a computed metric can
            // express it perfectly well -  computed_metrics is precisely the
            // place a schema says "average order value means ROUND(AVG(amount),
            // 2)". Escalating those would spend a second call to reach the same
            // answer, and lose the determinism intent mode is for.
            if ($component === 'non_sum_aggregate' && $this->schemaAlreadyProvidesIt($query)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $query)) {
                    return $component;
                }
            }
        }

        return null;
    }

    /**
     * Does a schema define this aggregate as a metric of its own?
     *
     * computed_metrics is how a schema expresses anything SUM cannot -  an
     * average, a rate, a ratio. If the question names one, intent mode answers
     * it correctly and there is nothing to escalate.
     *
     * Matched on the metric's key and its aliases, with underscores read as
     * spaces, so `avg_amount` with alias "average" covers "what is the average
     * order value".
     */
    protected function schemaAlreadyProvidesIt(string $query): bool
    {
        if (!$this->registry) {
            return false;
        }

        $haystack = strtolower($query);

        // WHICH aggregate the question wants, not merely that it names a
        // metric the schema knows.
        //
        // This used to be a bare name match, and the bypass fired whenever any
        // computed-metric alias appeared anywhere in the sentence. A schema
        // with `total_amount` aliased "revenue" -  which is exactly what
        // `discover --ai` writes -  therefore disarmed the guard for "what is
        // the highest revenue", and SqlBuilder answered it with SUM(). The
        // question named a metric the schema provides; it did not ask for the
        // aggregate the schema provides.
        $wanted = match (true) {
            (bool) preg_match('/\b(?:average|avg|mean)\b/', $haystack) => 'AVG(',
            (bool) preg_match('/\b(?:median)\b/', $haystack) => 'MEDIAN(',
            (bool) preg_match('/\b(?:maximum|max|highest|largest|greatest|biggest)\b/', $haystack) => 'MAX(',
            (bool) preg_match('/\b(?:minimum|min|lowest|smallest)\b/', $haystack) => 'MIN(',
            default => null,
        };

        foreach (array_keys($this->registry->all()) as $dataset) {
            foreach ($this->registry->getComputedMetrics($dataset) as $key => $definition) {
                $names = array_merge([$key], (array) ($definition['aliases'] ?? []));

                foreach ($names as $name) {
                    $name = strtolower(str_replace('_', ' ', trim((string) $name)));

                    if ($name === '' || !str_contains($haystack, $name)) {
                        continue;
                    }

                    // No aggregate word: the old behaviour, which is right -
                    // the question just names the metric.
                    if ($wanted === null) {
                        return true;
                    }

                    if (str_contains(strtoupper((string) ($definition['expression'] ?? '')), $wanted)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
