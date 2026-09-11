<?php

namespace Jayanta\Jeeves\Engine;

/**
 * Response Formatter
 *
 * Formats query results into a structured response with:
 * - Display text (human-readable summary)
 * - Speech text (TTS-friendly version)
 * - Statistical insights
 * - Visualization type hint
 */
class ResponseFormatter
{
    use Concerns\HumanizesNames;

    /**
     * Format a complete query response.
     */
    public function format(array $queryResult, array $rows, array $options = []): array
    {
        $responseType = $this->determineResponseType($queryResult, $rows);
        $texts = $this->generateAnswerText($queryResult, $rows, $responseType);
        $insights = $this->generateInsights($queryResult, $rows);
        $visualization = $this->determineVisualization($responseType, count($rows));

        $response = [
            'status' => 'success',
            'type' => $responseType,
            'rows' => $rows,
            'parsed_query' => [
                'dataset' => $queryResult['dataset'],
                'metric' => $queryResult['metric'],
                'group_value' => $queryResult['group_value'] ?? null,
                // The dimension the rows are actually broken down by. Exposed
                // so "revenue by region" answered as a customer ranking is
                // visible in the response instead of only in the row labels.
                'group_by' => $queryResult['group_column'] ?? null,
                // Which column group_value was matched against, when it is not
                // the one being grouped by. Without this in the response a
                // filtered ranking and an unfiltered one look identical.
                'filter_column' => $queryResult['filter_column'] ?? null,
                // Every column narrowed on, so a caller can tell a
                // two-filter answer from a one-filter one.
                'filter_columns' => $queryResult['filter_columns'] ?? [],
                // Paired, so the conversation can carry them forward without
                // guessing which value belongs to which column.
                'filters' => $queryResult['filters'] ?? [],
                // "Last year" is read as calendar 2025 by some models and as a
                // trailing twelve months by others, and the difference is
                // invisible in the number alone.
                //
                // What arrives here on the SQL route is the model's own claim
                // about the WHERE clause it wrote, which is not always true.
                // QueryOrchestrator overwrites this with null unless the
                // executed SQL demonstrably narrowed on the date column, so
                // what a caller reads is a period that was applied or nothing.
                'period' => $queryResult['time_filter'] ?? null,
                // The bounds behind that label. `period` is for a human to
                // read; these are what a conversation carries forward, because
                // ConversationManager builds the next turn's state from this
                // array. Without them a period could never survive a
                // narrowing turn, and "revenue in July" followed by "only in
                // West" answered West all-time.
                'date_from' => $queryResult['date_from'] ?? null,
                'date_to' => $queryResult['date_to'] ?? null,
                'limit' => $queryResult['limit'] ?? null,
                'order' => $queryResult['order'] ?? null,
                'query_type' => $queryResult['query_type'] ?? 'ranking',
            ],
            'answer' => $texts['display'],
            'visualization' => $visualization,
        ];

        if (config('jeeves.response.include_speech_text', true)) {
            $response['speech_text'] = $texts['speech'];
        }

        if (config('jeeves.response.include_insights', true)) {
            $response['insights'] = $insights;
        }

        if (config('jeeves.response.include_visualization_hint', true)) {
            $response['visualization'] = $visualization;
        }

        return $response;
    }

    /**
     * Format a "no data" response.
     */
    public function formatNoData(array $queryResult): array
    {
        $groupValue = $queryResult['group_value'] ?? null;
        $datasetName = $queryResult['dataset_name'] ?? $queryResult['dataset'];

        $message = $groupValue
            ? "No data found for {$groupValue} in {$datasetName}. The name may be spelled differently in the database."
            : "No data found for {$datasetName}.";

        return [
            'status' => 'success',
            'type' => 'no_data',
            'rows' => [],
            'parsed_query' => [
                'dataset' => $queryResult['dataset'],
                'metric' => $queryResult['metric'],
                'group_value' => $groupValue,
            ],
            'answer' => $message,
            'visualization' => 'message',
        ];
    }

    /**
     * Format a clarification response.
     */
    public function formatClarification(array $intent, array $availableDatasets, array $availableMetrics = []): array
    {
        $rawType = $intent['clarification_type'] ?? 'dataset';
        $clarificationType = in_array($rawType, ['dataset', 'dataset_clarification'])
            ? 'dataset_clarification'
            : 'metric_clarification';

        // Dataset choices belong on a dataset question and nowhere else.
        //
        // They were sent on every clarification, so a metric question rendered
        // the dataset name as an extra button among the metrics -  "Orders"
        // sitting beside "Quantity" and "Revenue". Clicking it re-sent the same
        // question with the dataset it already had, got the same response, and
        // redrew the same card: a button that looks broken because there is
        // nothing for it to do.
        $alternatives = $clarificationType === 'dataset_clarification'
            ? array_map(fn ($s) => [
                'dataset_name' => $s['name'],
                'dataset_key' => $s['key'],
                'confidence' => 0.5,
            ], $availableDatasets)
            : [];

        return [
            'status' => 'clarification_needed',
            'type' => $clarificationType,
            'message' => $clarificationType === 'metric_clarification'
                ? 'What metric would you like to see for this dataset?'
                : 'Which dataset would you like to query? Please select from the options.',
            // Null-coalesced on purpose: a provider is only required to return
            // the keys it actually resolved. A self-hosted or OpenAI-compatible
            // model that omits 'group_value' must still produce a clarification,
            // not an undefined-key error that the orchestrator turns into a
            // generic failure. Provider portability matters more than strict
            // response shapes here.
            'parsed_query' => [
                'dataset' => $intent['dataset'] ?? null,
                'metric' => $intent['metric'] ?? null,
                'group_value' => $intent['group_value'] ?? null,
            ],
            'alternatives' => $alternatives,
            'available_metrics' => $availableMetrics,
            'metadata' => [
                'confidence' => $intent['confidence'] ?? 0,
            ],
        ];
    }

    /**
     * Format an error response.
     */
    /**
     * @param  string  $code  Machine-readable reason -  see ErrorCode.
     */
    public function formatError(string $error, array $metadata = [], string $code = ErrorCode::INTERNAL): array
    {
        return [
            'status' => 'error',
            // The message is for a person and will be reworded. The code is for
            // a client and will not: a frontend deciding whether to retry, ask
            // again, or show a support link should never have to match on
            // English prose.
            'error_code' => $code,
            'error' => $error,
            'metadata' => $metadata,
        ];
    }

    /**
     * Determine the response type based on query result and rows.
     */
    protected function determineResponseType(array $queryResult, array $rows): string
    {
        $queryType = $queryResult['query_type'] ?? 'ranking';

        if ($queryType === 'group_detail' || !empty($queryResult['group_value'])) {
            return count($rows) === 1 ? 'single_result' : 'ranking';
        }

        if ($queryType === 'aggregation') {
            return 'aggregation';
        }

        return count($rows) === 1 ? 'single_result' : 'ranking';
    }

    /**
     * Work out what to call a row.
     *
     * The schema's group_column is the intended label, but the query does not
     * always return it -  a model that groups by something else, or joins and
     * aliases the column, produces rows without it. Emitting "?" for every row
     * made an otherwise correct answer look broken, so fall back to the first
     * value in the row that reads like a label rather than a number.
     *
     * @param  array<string, mixed>  $row
     */
    protected function labelFor(array $row, string $groupColumn, string $metric): string
    {
        // isset() already excludes null; only the empty string needs saying.
        if (isset($row[$groupColumn]) && $row[$groupColumn] !== '') {
            return (string) $row[$groupColumn];
        }

        foreach ($row as $column => $value) {
            if ($column === $metric || $value === null || $value === '') {
                continue;
            }

            if (!is_numeric($value)) {
                return (string) $value;
            }
        }

        // Everything in the row is a number: an id is still a better label
        // than a question mark.
        foreach ($row as $column => $value) {
            if ($column !== $metric && $value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return '- ';
    }

    /**
     * Generate answer text (both display and speech versions).
     */
    protected function generateAnswerText(array $queryResult, array $rows, string $type): array
    {
        $datasetName = $queryResult['dataset_name'] ?? $queryResult['dataset'];
        $metric = $queryResult['metric'] ?? 'data';
        $metricDesc = $queryResult['metric_description'] ?? $metric;
        $unit = $queryResult['metric_unit'] ?? '';
        $groupColumn = $queryResult['group_column'] ?? 'name';
        $order = strtolower($queryResult['order'] ?? 'desc');
        $count = count($rows);
        $numberFormat = config('jeeves.response.number_format', 'international');

        if ($type === 'single_result' && $count === 1) {
            $row = (array) $rows[0];
            $name = $this->labelFor($row, $groupColumn, $metric);
            $value = $row[$metric] ?? 'N/A';
            $formattedValue = is_numeric($value) ? $this->formatNumber($value, $numberFormat) : $value;

            return [
                'display' => "{$name}: {$formattedValue} {$unit} ({$metricDesc})",
                'speech' => "{$name} has {$formattedValue} {$unit} for {$metricDesc} in {$datasetName}.",
            ];
        }

        if ($type === 'aggregation') {
            $row = (array) $rows[0];
            $totalKey = "total_{$metric}";

            // `??` treats SQL NULL as absent, so both coalesces fell through to
            // the literal 0. An aggregate over zero rows is NULL, not zero:
            // "average amount for cancelled orders" with no cancelled orders
            // answered "Total Average order value: 0", and said it aloud. That
            // is a specific false quantitative claim reported as success.
            //
            // COUNT is the exception and is genuinely 0 over no rows, which is
            // why this tests for null rather than for emptiness.
            //
            // The metric's own column is consulted BEFORE falling back to the
            // first column by position. Position is not the measure: a model
            // that writes `SELECT status, SUM(revenue) AS revenue ... GROUP BY
            // status` puts the LABEL first, and a null label was then read as a
            // null aggregate. That reported "nothing matched" over a row
            // holding 800, contradicted by `insights` two fields lower in the
            // same response — a worse failure than the zero it replaced,
            // because it is a falsifiable claim about the data rather than a
            // suspicious number.
            $total = match (true) {
                array_key_exists($totalKey, $row) => $row[$totalKey],
                array_key_exists($metric, $row) => $row[$metric],
                default => array_values($row)[0] ?? null,
            };

            if ($total === null) {
                return [
                    'display' => "No {$metricDesc} found - nothing matched.",
                    'speech' => "There is no {$metricDesc} for {$datasetName}, because nothing matched.",
                ];
            }

            $formatted = $this->formatNumber($total, $numberFormat);

            return [
                'display' => "Total {$metricDesc}: {$formatted} {$unit}",
                'speech' => "The total {$metricDesc} for {$datasetName} is {$formatted} {$unit}.",
            ];
        }

        // Ranking
        $direction = $order === 'desc' ? 'highest' : 'lowest';
        $topRows = array_slice($rows, 0, 3);
        $topNames = array_map(fn ($r) => $this->labelFor((array) $r, $groupColumn, $metric), $topRows);
        $topList = implode(', ', $topNames);

        // Naming the dimension tells the reader which question was answered -
        // but only when the dimension is KNOWN. `group_column` is the schema's
        // default and is set on every result, so naming it announced
        // "Top 2 customers" over rows that were regions whenever the model
        // grouped by something else. The caption was made honest about this
        // and the sentence was not, which left the headline asserting a
        // dimension while the line under it stayed silent.
        //
        // `grouped_by` is set by SqlBuilder, which wrote the query and took
        // the branch. On the sql_generation route it is absent, because the
        // statement is the model's and nothing here can say what it grouped
        // by; the sentence then names no dimension rather than the wrong one.
        $dimension = $queryResult['grouped_by'] ?? null;
        $noun = is_string($dimension) && $dimension !== ''
            ? ' ' . $this->humanizeDimension($dimension)
            : '';

        return [
            'display' => "Top {$count}{$noun} by {$metricDesc} ({$direction}): {$topList}" . ($count > 3 ? '...' : ''),
            'speech' => "Here are the {$count}{$noun} with the {$direction} {$metricDesc} in {$datasetName}. Top entries are {$topList}.",
        ];
    }

    /** @see HumanizesNames::humanizePlural() */
    protected function humanizeDimension(string $column): string
    {
        return $this->humanizePlural($column);
    }

    /**
     * Generate statistical insights from results.
     */
    protected function generateInsights(array $queryResult, array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $metric = $queryResult['metric'] ?? null;
        if (!$metric) {
            return [];
        }

        $values = array_filter(
            array_map(fn ($r) => ((array) $r)[$metric] ?? null, $rows),
            fn ($v) => is_numeric($v)
        );

        if (empty($values)) {
            return [];
        }

        $numberFormat = config('jeeves.response.number_format', 'international');

        return [
            'count' => count($values),
            'total' => $this->formatNumber(array_sum($values), $numberFormat),
            'average' => $this->formatNumber(array_sum($values) / count($values), $numberFormat),
            'min' => $this->formatNumber(min($values), $numberFormat),
            'max' => $this->formatNumber(max($values), $numberFormat),
        ];
    }

    /**
     * Determine the best visualization type.
     */
    protected function determineVisualization(string $responseType, int $rowCount): string
    {
        if ($responseType === 'single_result') {
            return 'card';
        }

        if ($responseType === 'aggregation') {
            return 'card';
        }

        // Bars stop being readable somewhere past twenty rows; a table stays
        // readable at any length.
        return $rowCount <= 20 ? 'bar' : 'table';
    }

    /**
     * Format a number according to the configured style.
     */
    protected function formatNumber($value, string $format = 'international'): string
    {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $value = floatval($value);

        // Detect if it's a whole number
        if (floor($value) == $value && $value < PHP_INT_MAX) {
            if ($format === 'indian') {
                return $this->formatIndian((int) $value);
            }

            return number_format((int) $value);
        }

        if ($format === 'indian') {
            return $this->formatIndian($value, 2);
        }

        return number_format($value, 2);
    }

    /**
     * Format number in Indian numbering system (12,34,567).
     */
    protected function formatIndian($number, int $decimals = 0): string
    {
        $negative = $number < 0;
        $number = abs($number);

        $parts = explode('.', number_format($number, $decimals, '.', ''));
        $integer = $parts[0];
        $decimal = $parts[1] ?? '';

        // Apply Indian grouping
        if (strlen($integer) > 3) {
            $lastThree = substr($integer, -3);
            $remaining = substr($integer, 0, -3);
            $remaining = preg_replace('/(\d)(?=(\d{2})+$)/', '$1,', $remaining);
            $integer = $remaining . ',' . $lastThree;
        }

        return ($negative ? '-' : '') . $integer . ($decimal ? '.' . $decimal : '');
    }
}
