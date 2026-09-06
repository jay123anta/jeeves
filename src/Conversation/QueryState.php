<?php

namespace Jayanta\Jeeves\Conversation;

use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * What the conversation currently means, as slots rather than sentences.
 *
 * A follow-up used to be resolved by rewriting it into a new sentence and
 * handing that back to the model. Every rewrite lost something -  "only in West"
 * became "show only in West details in Orders" and the metric disappeared -  and
 * every turn re-parsed the whole dialogue, so a misread early on propagated
 * unpredictably through the ones after it.
 *
 * The state is the thing that moves between turns now. The model resolves one
 * utterance against a compact structured summary; the merge happens here, in
 * PHP, where it is deterministic. That shrinks what the model reasons over to a
 * single instruction, and it makes "no, go back" a restore rather than another
 * interpretation.
 */
class QueryState
{
    /** Slots that carry between turns. */
    public const SLOTS = [
        'dataset', 'metric', 'group_by', 'filter_column', 'group_value',
        // Filters ACCUMULATE. "Only in West" then "and what about
        // Electronics?" means both, and holding one at a time made the second
        // silently replace the first -  the answer covered every region and
        // read exactly like a correct answer to the question asked.
        'filters',
        'date_from', 'date_to', 'order', 'limit', 'query_type',
    ];

    /** @var array<string, mixed> */
    protected array $slots;

    /**
     * The period this ONE answer covered, as a label, for summary() only.
     *
     * Deliberately NOT a slot. It is a rendered string like "July 2026" rather
     * than a range that can be intersected with a follow-up's, so carrying it
     * forward would caption the next answer with a window that answer never
     * applied.
     *
     * The first attempt made it a slot and excluded it inside merge() and
     * toIntent(). That failed twice over, in both directions at once. It still
     * carried, because merge() begins by copying every slot and only ITERATES
     * the carrying list, and because drillDown() and withoutFilters() copy the
     * slots too and were never touched. And it was simultaneously destroyed on
     * the conversation route, because StateValidator rebuilds the state from
     * toIntent(), so the label never survived to reach state_summary at all.
     *
     * Keeping it off the slot array makes both impossible rather than
     * remembered: nothing that copies slots can move it, and nothing that
     * rebuilds from slots can lose what it never held. A comment asserting an
     * invariant is not an invariant.
     */
    protected ?string $period = null;

    /** Which turn produced this state, for rewinding. */
    public int $seq;

    /** How many consecutive refinements have been applied. */
    public int $refinements;

    public function __construct(array $slots = [], int $seq = 0, int $refinements = 0)
    {
        $this->slots = array_intersect_key($slots, array_flip(self::SLOTS));
        $this->seq = $seq;
        $this->refinements = $refinements;
    }

    public static function fromIntent(array $intent, int $seq = 1): self
    {
        return (new self([
            'dataset' => $intent['dataset'] ?? null,
            'metric' => $intent['metric'] ?? null,
            'group_by' => $intent['group_by'] ?? null,
            'filter_column' => $intent['filter_column'] ?? null,
            // Carried, because summary() reads this slot and every path that
            // builds a state from an intent was leaving it empty -  so a
            // question that narrowed to one status described itself exactly
            // like the unnarrowed total, and a follow-up inherited a filter
            // the state had never recorded.
            'filters' => $intent['filters'] ?? [],
            'group_value' => $intent['group_value'] ?? null,
            'date_from' => $intent['date_from'] ?? null,
            'date_to' => $intent['date_to'] ?? null,
            'order' => $intent['order'] ?? null,
            'limit' => $intent['limit'] ?? null,
            'query_type' => $intent['query_type'] ?? null,
        ], $seq))->withPeriod(
            // Descriptive only, and never a slot: see the property docblock.
            isset($intent['period']) ? (string) $intent['period'] : null
        );
    }

    /**
     * A copy labelled with the period THIS answer covered.
     *
     * Returns a new instance rather than mutating, so a state held for the
     * conversation cannot acquire a label from the answer being rendered.
     */
    public function withPeriod(?string $period): self
    {
        $clone = clone $this;
        $clone->period = ($period === null || $period === '') ? null : $period;

        return $clone;
    }

    public function period(): ?string
    {
        return $this->period;
    }

    public function get(string $slot): mixed
    {
        return $this->slots[$slot] ?? null;
    }

    public function isEmpty(): bool
    {
        return empty(array_filter($this->slots, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Apply a turn's parsed intent on top of this state.
     *
     * Only slots the new turn actually filled are overwritten. A refinement
     * that mentions a region says nothing about the metric, and inheriting the
     * metric is the entire point.
     */
    public function merge(array $intent, int $seq, ?string $question = null): self
    {
        // Every derivation here returns a NEW instance, so the period label
        // never travels with it — it is not a slot, and this copies slots.
        $merged = $this->slots;

        // What the instruction is actually allowed to change.
        //
        // "Only in Guwahati" narrows. It does not choose a new measure and it
        // does not choose a new breakdown, so an intent that came back with
        // different ones has re-guessed rather than answered -  and merging
        // those silently swaps the question. A weaker model on Groq did exactly
        // that: after "total amount by city", the narrowing turn returned
        // record_count by client, and the answer counted invoices per client
        // while the user was looking at amounts per city.
        //
        // Stronger models carry state without being told. Guarding it locally
        // is what makes the feature work on the small open-weight models this
        // package is meant to run on, where that inference is exactly what is
        // missing.
        $namesMeasure = $question === null || $this->namesAMeasure($question);
        $namesBreakdown = $question === null || $this->namesABreakdown($question);
        $widensToAllTime = $question !== null && $this->namesEveryPeriod($question);

        foreach (self::SLOTS as $slot) {
            if ($slot === 'filters') {
                continue; // accumulated below, never overwritten wholesale
            }

            if ($slot === 'metric' && !$namesMeasure && ($this->slots['metric'] ?? null)) {
                continue;
            }

            if ($slot === 'group_by' && !$namesBreakdown && ($this->slots['group_by'] ?? null)) {
                continue;
            }

            $value = $intent[$slot] ?? null;

            if ($value !== null && $value !== '') {
                $merged[$slot] = $value;
            }
        }

        // A filter names its column. Changing one without the other leaves a
        // value matched against the previous turn's column, which is how
        // "Grocery" ended up being looked for among customer names.
        if (($intent['group_value'] ?? null) && !($intent['filter_column'] ?? null)) {
            $merged['filter_column'] = null;
        }

        // "and across all time" widens, and merge() cannot see that in the
        // intent: a slot the turn did not fill and a slot the turn deliberately
        // cleared both arrive as null, so an established window simply survived
        // and the answer stayed pinned to a month the user had just asked to
        // leave. Every natural spelling of it classifies as a refinement, so
        // there was no escape but New topic or rewind.
        //
        // Before the period carried between turns this state was unreachable -
        // making it carry is what turned it into a wrong answer. Widening needs
        // a route of its own for the same reason removing a filter does: an
        // omission by the model must never be read as an instruction.
        if ($widensToAllTime) {
            $merged['date_from'] = null;
            $merged['date_to'] = null;
        }

        $merged['filters'] = $this->accumulateFilters($intent);

        return new self($merged, $seq, $this->refinements + 1);
    }

    /**
     * Does the instruction ask to DROP the period rather than narrow it?
     *
     * Narrow on purpose. A false positive throws away a window the user asked
     * for, so nothing here matches a phrase that could just as well introduce
     * one - "overall revenue in July" names a period and must keep it.
     */
    protected function namesEveryPeriod(string $question): bool
    {
        return (bool) preg_match(
            '/\b(?:all[\s-]*time|all\s+of\s+time|all\s+dates|all\s+years|every\s+year|'
            . 'any\s+date|no\s+date\s+filter|without\s+the\s+date(?:\s+filter)?|'
            . '(?:remove|drop|ignore|forget)\s+the\s+date(?:\s+filter|\s+range)?|'
            . '(?:whole|entire|full)\s+(?:period|history)|every\s+period)\b/i',
            $question
        );
    }

    /**
     * Does the instruction pick a measure of its own?
     *
     * Anything naming a quantity or an aggregate is choosing one; "only in
     * West" is not. Deliberately generous -  a false positive merely allows a
     * change the model asked for, while a false negative pins the wrong
     * measure in place.
     */
    protected function namesAMeasure(string $question): bool
    {
        return (bool) preg_match(
            '/\b(?:how\s+many|how\s+much|number\s+of|count|total|sum|average|avg|mean|median|'
            . 'minimum|maximum|min|max|revenue|amount|value|quantity|price|cost|spend|sales)\b/i',
            $question
        );
    }

    /** "by X", "per X", "for each X", "breakdown", "split", "grouped". */
    protected function namesABreakdown(string $question): bool
    {
        return (bool) preg_match('/\b(?:by|per|each|breakdown|split|grouped|group)\b/i', $question);
    }

    /**
     * Add this turn's filter to the ones already in force.
     *
     * Narrowing twice narrows twice: West, then Electronics, means Electronics
     * within West. Naming the SAME column again replaces that one -  "actually,
     * East" is a correction, not a second region.
     *
     * @return array<int, array{column: string, value: mixed}>
     */
    protected function accumulateFilters(array $intent): array
    {
        $filters = $this->get('filters') ?? [];
        $incoming = is_array($intent['filters'] ?? null) ? $intent['filters'] : [];

        // Merged by column, never taken as the complete set.
        //
        // The model is asked to restate every filter still in force, and it
        // does not reliably do so -  asked to add Electronics to an existing
        // region filter it returns Electronics alone. Treating that as the
        // whole truth silently dropped the region and widened the answer.
        //
        // Merging by column gets both cases right without depending on the
        // model's memory: a NEW column is added, a REPEATED column is
        // corrected. What it cannot express is removing a filter, which is
        // deliberate -  that happens explicitly, through withoutFilters(),
        // rewind, or New topic, never as a side effect of a model omission.
        if (!$incoming && ($intent['filter_column'] ?? null) && ($intent['group_value'] ?? null)) {
            $incoming = [['column' => $intent['filter_column'], 'value' => $intent['group_value']]];
        }

        foreach ($incoming as $filter) {
            $column = $filter['column'] ?? null;
            $value = $filter['value'] ?? null;

            if (!$column || $value === null || $value === '') {
                continue;
            }

            $filters = array_values(array_filter(
                $filters,
                fn ($existing) => strtolower((string) ($existing['column'] ?? '')) !== strtolower((string) $column)
            ));

            $filters[] = ['column' => $column, 'value' => $value];
        }

        return $filters;
    }

    /** Drop every filter, keeping the measure and the breakdown. */
    public function withoutFilters(int $seq): self
    {
        $slots = $this->slots;
        $slots['filters'] = [];
        $slots['group_value'] = null;
        $slots['filter_column'] = null;

        return new self($slots, $seq, $this->refinements + 1);
    }

    /** A fresh state -  a new question inherits nothing. */
    public function replace(array $intent, int $seq): self
    {
        return self::fromIntent($intent, $seq);
    }

    /** Add a breakdown while keeping everything else. */
    public function drillDown(?string $groupBy, int $seq): self
    {
        $merged = $this->slots;

        if ($groupBy) {
            $merged['group_by'] = $groupBy;
        }

        return new self($merged, $seq, $this->refinements + 1);
    }

    /**
     * @return array<string, mixed> the intent this state represents
     *
     * Descriptive slots are excluded: this feeds the state block of the next
     * turn's prompt, and a label describing the previous answer is not an
     * instruction the model should carry forward.
     */
    public function toIntent(): array
    {
        return array_filter($this->slots, fn ($v) => $v !== null && $v !== '');
    }

    public function toArray(): array
    {
        return ['slots' => $this->slots, 'seq' => $this->seq, 'refinements' => $this->refinements];
    }

    public static function fromArray(?array $data): self
    {
        return new self(
            $data['slots'] ?? [],
            (int) ($data['seq'] ?? 0),
            (int) ($data['refinements'] ?? 0)
        );
    }

    /**
     * The state in words, to be shown above the answer.
     *
     * A user who can see "revenue · by customer · where region is West" catches
     * a misread immediately, instead of trusting a number that answers a
     * question they did not ask. It also turns "no, I meant X" into a
     * correction of something visible rather than a guess.
     */
    public function summary(?SchemaRegistry $registry = null): string
    {
        $parts = [];

        if ($dataset = $this->get('dataset')) {
            $parts[] = $registry && $registry->has($dataset)
                ? ($registry->get($dataset)['name'] ?? $dataset)
                : $dataset;
        }

        if ($metric = $this->get('metric')) {
            $parts[] = $this->humanize($metric);
        }

        if ($groupBy = $this->get('group_by')) {
            $parts[] = 'by ' . $this->humanize($groupBy);
        }

        // Every filter in force, not just the newest. A line that shows one
        // while two are applied is worse than showing none: it invites the
        // reader to trust a narrowing they cannot see.
        $filters = $this->get('filters') ?? [];

        foreach ($filters as $filter) {
            $parts[] = $this->humanize((string) $filter['column']) . ' is ' . $filter['value'];
        }

        if (!$filters && ($value = $this->get('group_value'))) {
            $parts[] = ($column = $this->get('filter_column'))
                ? $this->humanize($column) . ' is ' . $value
                : 'for ' . $value;
        }

        // A resolved range when the intent route worked one out, otherwise the
        // label the SQL routes report. Without the fallback, every answer that
        // wrote its own WHERE printed no period at all -  so a month's total
        // and an all-time total were one indistinguishable sentence.
        if ($this->get('date_from') || $this->get('date_to')) {
            $parts[] = trim(($this->get('date_from') ?? '') . ' to ' . ($this->get('date_to') ?? 'now'), ' to ');
        } elseif ($this->period !== null) {
            $parts[] = $this->period;
        }

        return implode(' · ', $parts);
    }

    protected function humanize(string $name): string
    {
        return str_replace('_', ' ', $name);
    }
}
