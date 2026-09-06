<?php

namespace Jayanta\Jeeves\Engine;

use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * Suggest what to ask next.
 *
 * The hardest part of querying your own data in words is not phrasing a
 * question -  it is knowing which questions the data can answer at all. A chat
 * interface makes that worse, because an empty prompt offers no clue what
 * exists. Every answer therefore carries a few concrete follow-ups.
 *
 * These are derived from the schema and the query that was just answered, not
 * from the model: no extra API call, no latency, no cost, and they cannot
 * suggest a breakdown the validator would refuse -  the same groupable columns
 * that gate SQL generation gate these.
 *
 * PRIVACY (Rule 2): a suggestion is composed here and SENT TO A PROVIDER the
 * moment the user clicks it, so nothing derived from the result rows may
 * appear in one. Only column and dataset names -  schema structure -  are used.
 *
 * This class used to offer "Break West down by category", built from the top
 * row's value and carrying that value in the query it would send. It was
 * defended on the grounds that the click makes it the user's own query text.
 * That is the argument Rule 2 exists to reject: the string was composed by the
 * package out of data, and one click sent it. On a freshly discovered schema
 * the top row's value can be a `remember_token`, because introspection marks
 * every string column groupable and nothing here knew which columns hold
 * secrets. It was gated by `chat.suggest_drilldown_values`, and Rule 2 says
 * such a feature is rejected by design, not by configuration.
 *
 * Restoring the capability means executing a drill-down WITHOUT a provider -
 * the package already knows the dataset, metric, dimension and value, so no
 * natural language needs parsing. That is a feature, not a patch.
 */
class NextStepSuggester
{
    use Concerns\HumanizesNames;

    protected SchemaRegistry $registry;

    public function __construct(SchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param  array  $queryResult  The SqlBuilder result that produced the rows.
     * @param  array  $rows  Accepted for signature compatibility; deliberately
     *                       unused, because nothing derived from a row may
     *                       reach a suggestion. See the class docblock.
     * @return array<int, array{label: string, query: string}>
     */
    public function suggest(array $queryResult, array $rows = []): array
    {
        if (!config('jeeves.chat.suggest_next_steps', true)) {
            return [];
        }

        $dataset = $queryResult['dataset'] ?? null;

        if (!$dataset || !$this->registry->has($dataset)) {
            return [];
        }

        $suggestions = [];

        foreach ([
            $this->otherBreakdowns($queryResult),
            $this->sameBreakdownOtherMetric($queryResult),
            $this->flipTheOrder($queryResult),
        ] as $group) {
            foreach ($group as $suggestion) {
                $suggestions[$suggestion['query']] = $suggestion;
            }
        }

        $max = (int) config('jeeves.chat.max_next_steps', 4);

        return array_slice(array_values($suggestions), 0, max(0, $max));
    }

    /** The same measure, sliced a different way. */
    protected function otherBreakdowns(array $queryResult): array
    {
        $dataset = $queryResult['dataset'];
        $metric = $queryResult['metric'] ?? '';
        $out = [];

        foreach ($this->otherDimensions($dataset, [$queryResult['group_column'] ?? null]) as $dimension) {
            $out[] = [
                'label' => ucfirst($this->humanize($metric)) . ' by ' . $this->humanize($dimension),
                'query' => "{$metric} by {$dimension}",
            ];
        }

        return array_slice($out, 0, 2);
    }

    /**
     * The same slice, measured differently -  usually "as a count", which is
     * the question people reach for immediately after seeing money.
     */
    protected function sameBreakdownOtherMetric(array $queryResult): array
    {
        $dataset = $queryResult['dataset'];
        $current = $queryResult['metric'] ?? '';
        $groupColumn = $queryResult['group_column'] ?? null;

        if (!$groupColumn) {
            return [];
        }

        $countMetric = SchemaRegistry::COUNT_METRIC;
        $metrics = array_column($this->registry->getDatasetMetrics($dataset), 'key');

        $candidates = [];

        if ($current !== $countMetric && in_array($countMetric, $metrics, true)) {
            $candidates[] = ['how many', $countMetric];
        }

        foreach ($metrics as $metric) {
            if (count($candidates) >= 2) {
                break;
            }
            if ($metric !== $current && $metric !== $countMetric) {
                $candidates[] = [$this->humanize($metric), $metric];
            }
        }

        $out = [];

        foreach ($candidates as [$word, $metric]) {
            $out[] = [
                'label' => 'Show ' . ($metric === $countMetric ? 'order counts' : $this->humanize($metric))
                    . ' by ' . $this->humanize($groupColumn),
                'query' => $metric === $countMetric
                    ? "how many by {$groupColumn}"
                    : "{$metric} by {$groupColumn}",
            ];
        }

        return array_slice($out, 0, 1);
    }

    /** Worst performers are as interesting as best, and rarely asked for. */
    protected function flipTheOrder(array $queryResult): array
    {
        if (($queryResult['query_type'] ?? '') !== 'ranking') {
            return [];
        }

        $order = strtoupper($queryResult['order'] ?? 'DESC');
        $metric = $queryResult['metric'] ?? '';
        $groupColumn = $queryResult['group_column'] ?? null;
        $limit = (int) ($queryResult['limit'] ?? 5);
        $limit = $limit > 0 ? min($limit, 10) : 5;

        $word = $order === 'DESC' ? 'bottom' : 'top';
        // "bottom 5 regions by revenue" -  not "bottom 5 by region by revenue",
        // which reads badly and gives the intent parser two 'by' clauses to
        // disentangle.
        $noun = $groupColumn ? ' ' . $this->humanizePlural($groupColumn) : '';

        return [[
            'label' => ucfirst($word) . " {$limit} instead",
            'query' => trim("{$word} {$limit}{$noun} by {$metric}"),
        ]];
    }

    /**
     * Groupable columns other than the ones already used.
     *
     * @return array<int, string>
     */
    protected function otherDimensions(string $dataset, array $exclude): array
    {
        $exclude = array_filter($exclude);

        return array_values(array_filter(
            $this->registry->getGroupableColumns($dataset),
            fn ($c) => !in_array($c, $exclude, true)
        ));
    }
}
