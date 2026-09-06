<?php

namespace Jayanta\Jeeves\Engine;

use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * Safe SQL Builder
 *
 * Builds SQL queries from structured intents.
 * NEVER accepts raw SQL from AI - only structured intent data.
 * All table/column names come from schema config, not user input.
 *
 * Extracted from VoiceSqlBuilderService in CMDashboard.
 */
class SqlBuilder
{
    protected SchemaRegistry $registry;

    public function __construct(SchemaRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Build SQL query from parsed intent.
     *
     * @param  array  $intent  Parsed intent {dataset, metric, district, limit, order}
     * @return array {success, sql, dataset, dataset_name, metric, query_type, ...}
     */
    public function buildQuery(array $intent): array
    {
        try {
            $datasetKey = $intent['dataset'] ?? null;
            if (!$datasetKey) {
                return $this->errorResponse('No dataset specified');
            }

            $schema = $this->registry->get($datasetKey);
            if (!$schema) {
                return $this->errorResponse("Unknown dataset: {$datasetKey}");
            }

            $tableName = $this->registry->getTableName($datasetKey);
            if (!$tableName) {
                return $this->errorResponse("No table configured for dataset: {$datasetKey}");
            }

            // "revenue by region" asks for a different breakdown than the
            // schema's default. Without this the dimension was dropped and the
            // answer came back grouped by group_column -  correctly formatted,
            // confidently worded, and about a question nobody asked.
            $requestedDimension = $intent['group_by'] ?? null;
            $groupColumn = $requestedDimension
                ? $this->registry->resolveGroupColumn($datasetKey, $requestedDimension)
                : $this->registry->getGroupColumn($datasetKey);

            if ($requestedDimension && !$groupColumn) {
                $groupable = $this->registry->getGroupableColumns($datasetKey);

                return $this->errorResponse(
                    "Cannot group by '{$requestedDimension}'."
                    . ($groupable
                        ? ' Available breakdowns: ' . implode(', ', $groupable) . '.'
                        : ' This dataset has no columns marked groupable.')
                );
            }

            // Resolve metric.
            //
            // A metric the user NAMED that this dataset does not have is a
            // refusal, not a reason to reach for the default. "Top 3 customers
            // by revenue" against a customers table has no revenue in it, and
            // quietly ranking by whatever the default is returns the right
            // shape of answer to a different question -  the same silent
            // substitution as a dropped breakdown or a dropped period. Failing
            // here lets auto mode try SQL generation, which can join to the
            // table the measure actually lives in.
            $namedMetric = $intent['metric'] ?? null;
            $metric = $this->registry->resolveMetric($datasetKey, $namedMetric);

            if (!$metric && $namedMetric) {
                $available = array_column($this->registry->getDatasetMetrics($datasetKey), 'key');

                return $this->errorResponse(
                    "'{$namedMetric}' is not a measure of this dataset."
                    . ($available ? ' Available: ' . implode(', ', $available) . '.' : '')
                );
            }

            if (!$metric) {
                $metric = $this->registry->getDefaultMetric($datasetKey);
            }

            if (!$metric) {
                return $this->errorResponse('No metric could be resolved');
            }

            // Get metric SQL expression (handles computed metrics)
            $metricExpr = $this->getMetricExpression($datasetKey, $metric);
            $metricData = $this->getMetricData($datasetKey, $metric);

            // Validate and sanitize parameters
            // 'district' is the pre-1.0 name for this field. Still accepted so
            // a custom prompt override, a cached intent, or a third-party
            // provider written against the old contract keeps working.
            $groupValue = $this->sanitizeGroupValue(
                $intent['group_value'] ?? $intent['district'] ?? null
            );
            $maxLimit = $this->registry->getMaxLimit($datasetKey) ?? config('jeeves.sql.max_limit');
            $defaultLimit = $schema['defaults']['limit'] ?? config('jeeves.sql.default_limit', 100);
            $limit = intval($intent['limit'] ?? $defaultLimit);
            $limit = max(1, $maxLimit ? min($limit, $maxLimit) : $limit);

            $order = strtoupper($intent['order'] ?? $schema['defaults']['order'] ?? 'DESC');
            $order = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';

            // Check if this schema requires a JOIN (e.g., for district name lookup)
            $joinClause = $schema['tables']['primary']['required_join'] ?? null;
            $selectOverride = $schema['tables']['primary']['select_override'] ?? null;

            // If JOIN is needed, the group column reference comes from the
            // joined table. select_override maps the schema's DEFAULT group
            // column through that join, so it must not be applied when the user
            // asked to group by something else -  doing so would label region
            // totals with district names.
            $fromClause = $tableName . ($joinClause ? ' ' . $joinClause : '');
            $useOverride = $selectOverride && !$requestedDimension;
            $groupColumnSelect = $useOverride
                ? "{$selectOverride} AS {$groupColumn}"
                : $groupColumn;
            $groupColumnRef = $useOverride ? $selectOverride : $groupColumn;

            // Transactional tables (many rows per group value, e.g. one row per
            // order) need GROUP BY + SUM; pre-aggregated tables (one row per
            // group value, e.g. one row per district) must be read as-is.
            // The schema decides: a plain column marked 'aggregatable' is
            // summed per group; a computed metric whose expression already
            // aggregates (SUM/COUNT/AVG/...) is grouped as-is.
            $isComputed = isset($this->registry->getComputedMetrics($datasetKey)[$metric]);
            if ($isComputed) {
                $aggregate = $this->isAggregateExpression($metricExpr);
            } else {
                $aggregate = $this->isAggregatableColumn($datasetKey, $metric);
                if ($aggregate) {
                    $metricExpr = "SUM({$metricExpr})";
                }
            }

            // "Revenue last month" narrows on a date. Without this the filter
            // had nowhere to live in the intent, was dropped, and the answer
            // came back covering all time -  correctly totalled, confidently
            // worded, and about a period nobody asked for.
            $time = $this->resolveTimeFilter($datasetKey, $intent);

            if (isset($time['error'])) {
                return $this->errorResponse($time['error']);
            }

            // Determine query type and build SQL
            // "What is the total revenue" is one number, not a ranking of every
            // row. The model says so -  query_type comes back as 'aggregation' -
            // and this branch was missing, so the field was read in the
            // SQL-generation path and ignored here. The answer came back as a
            // list of individual line items, which is not what was asked and
            // does not look wrong at a glance.
            //
            // A named breakdown or a named record wins: "revenue by region" and
            // "revenue for West" are still a grouping and a detail view even if
            // the model also calls them aggregations.
            $wantsTotal = ($intent['query_type'] ?? null) === 'aggregation'
                && !$groupValue
                && !$requestedDimension;

            // A filter on a column OTHER than the one being grouped by.
            //
            // group_value alone always matched against the group column, so
            // "quantity by customer_name for Grocery" looked for a CUSTOMER
            // called Grocery, found none, and fell back to every customer -
            // with the category filter silently gone. The package's own
            // drill-down suggestions generate exactly that shape, so it was
            // offering questions it could not answer.
            // A conversation accumulates filters. "Only in West" then "and what
            // about Electronics?" means BOTH, and holding one at a time made the
            // second silently replace the first -  the region vanished and the
            // answer covered every region, which reads exactly like a correct
            // answer to the question that was asked.
            $filters = $this->resolveFilters($datasetKey, $intent, $groupColumn);

            if (isset($filters['error'])) {
                return $this->errorResponse($filters['error']);
            }

            $filterColumn = $this->registry->resolveGroupColumn($datasetKey, $intent['filter_column'] ?? null);
            $filtersAnotherColumn = !empty($filters['clauses']);

            if (($intent['filter_column'] ?? null) && !$filterColumn) {
                $groupable = $this->registry->getGroupableColumns($datasetKey);

                return $this->errorResponse(
                    "Cannot filter by '{$intent['filter_column']}'."
                    . ($groupable ? ' Available: ' . implode(', ', $groupable) . '.' : '')
                );
            }

            $bindings = [];
            if ($wantsTotal) {
                // Before the filter branch, because a filter NARROWS a total -
                // it does not turn it into a league table. "How many invoices
                // are pending" is one number over the pending ones.
                //
                // The other order shipped, and that question came back grouped
                // by client: "Rekha Stores: 1 records" where the answer is "1".
                // Right count, wrong question -  and the wrong question is the
                // one a reader believes, because nothing about it looks broken.
                //
                // wantsTotal is already narrow: the model said aggregation AND
                // no breakdown was requested AND no single record was named. A
                // question that asked for a breakdown never gets here.
                $result = $this->buildTotalQuery($fromClause, $metricExpr, $metric, $aggregate, $time, $filters);
                $sql = $result['sql'];
                $bindings = $result['bindings'];
                $queryType = 'aggregation';
                // One number over everything. No dimension to report, whatever
                // the schema's default group column happens to be.
                $groupedBy = null;
            } elseif ($filtersAnotherColumn) {
                // Still a ranking -  "quantity by customer for Grocery" wants
                // every customer within that category, not one row.
                $result = $this->buildFilteredRankingQuery(
                    $fromClause,
                    $groupColumnSelect,
                    $groupColumnRef,
                    $metricExpr,
                    $metric,
                    $order,
                    $limit,
                    $aggregate,
                    $filters,
                    $time
                );
                $sql = $result['sql'];
                $bindings = $result['bindings'];
                $queryType = 'ranking';
                $groupedBy = $groupColumn;
            } elseif ($groupValue) {
                $result = $this->buildGroupValueQuery($fromClause, $groupColumnSelect, $groupColumnRef, $metricExpr, $metric, $groupValue, $datasetKey, $time);
                $sql = $result['sql'];
                $bindings = $result['bindings'];
                $queryType = 'group_detail';
                // One named value, not a breakdown across values. The value
                // itself is already reported as group_value.
                $groupedBy = null;
            } else {
                $result = $this->buildRankingQuery($fromClause, $groupColumnSelect, $groupColumnRef, $metricExpr, $metric, $order, $limit, $aggregate, $time);
                $sql = $result['sql'];
                $bindings = $result['bindings'];
                $queryType = 'ranking';
                $groupedBy = $groupColumn;
            }

            // Apply required filter if defined
            $requiredFilter = $schema['tables']['primary']['required_filter'] ?? null;
            if ($requiredFilter) {
                $sql = $this->applyRequiredFilter($sql, $requiredFilter);
            }

            return [
                'success' => true,
                'sql' => $sql,
                'bindings' => $bindings,
                'dataset' => $datasetKey,
                'dataset_name' => $schema['name'] ?? $datasetKey,
                'metric' => $metric,
                'metric_description' => $metricData['description'] ?? $metric,
                'metric_unit' => $metricData['unit'] ?? 'units',
                'metric_type' => $metricData['type'] ?? 'neutral',
                'group_value' => $groupValue,
                'limit' => $limit,
                'order' => $order,
                'query_type' => $queryType,
                'group_column' => $groupColumn,
                // The dimension the rows ARE broken down by, as opposed to
                // `group_column`, which is the schema's default dimension and
                // is set on every result including a single ungrouped total.
                //
                // Reported by the builder because the builder WROTE the SQL and
                // took the branch — this is the artifact, not a plan. The
                // orchestrator previously tried to recover it by regex over the
                // finished statement, which announced a breakdown over a total
                // whenever a CTE or subquery contained a GROUP BY.
                //
                // Absent on the sql_generation route, where the SQL is the
                // model's and nothing here can honestly say what it did.
                'grouped_by' => $groupedBy,
                'time_filter' => $time['label'] ?? null,
                // Set only when a period was actually resolved and written into
                // the WHERE clause, so its presence is what makes `time_filter`
                // trustworthy.
                'time_column' => $time['column'] ?? null,
                // The bounds themselves, so a conversation can carry the period
                // forward. A label cannot be merged with a follow-up's; two
                // dates can.
                'date_from' => $time['from'] ?? null,
                'date_to' => $time['to'] ?? null,
                'filter_column' => $filtersAnotherColumn ? ($filters['columns'][0] ?? null) : null,
                'filter_columns' => $filters['columns'] ?? [],
                'filters' => $filters['pairs'] ?? [],
            ];

        } catch (\Exception $e) {
            Log::error('[Jeeves:SqlBuilder] Error', ['error' => $e->getMessage()]);

            return $this->errorResponse('SQL build error: ' . $e->getMessage());
        }
    }

    /**
     * Build ranking query (top/bottom N).
     *
     * @param  string  $fromClause  Table name, possibly with JOIN clause
     * @param  string  $groupColumnSelect  The SELECT expression for the group column (may include alias)
     * @param  string  $groupColumnRef  The reference to group column for ORDER BY / WHERE
     * @param  string  $metricExpr  SQL expression for the metric (already wrapped
     *                              in SUM() for aggregatable plain columns)
     * @param  string  $metricAlias  Alias name for the metric
     * @param  string  $order  ASC or DESC
     * @param  int  $limit  Result limit
     * @param  bool  $aggregate  Whether to GROUP BY the group column (transactional tables)
     */
    protected function buildRankingQuery(
        string $fromClause,
        string $groupColumnSelect,
        string $groupColumnRef,
        string $metricExpr,
        string $metricAlias,
        string $order,
        int $limit,
        bool $aggregate = false,
        array $time = []
    ): array {
        $groupBy = $aggregate ? " GROUP BY {$groupColumnRef}" : '';
        $where = !empty($time['clause']) ? " WHERE {$time['clause']}" : '';

        return [
            'sql' => "SELECT {$groupColumnSelect}, {$metricExpr} AS {$metricAlias} FROM {$fromClause}{$where}{$groupBy} ORDER BY {$metricExpr} {$order} LIMIT {$limit}",
            'bindings' => $time['bindings'] ?? [],
        ];
    }

    /**
     * A ranking narrowed to one value of a DIFFERENT column.
     *
     * "Quantity by customer for Grocery": group by customer, filter on
     * product_category. The filter and the grouping are separate columns, which
     * is the case group_value alone could not express.
     *
     * @return array{sql: string, bindings: array}
     */
    protected function buildFilteredRankingQuery(
        string $fromClause,
        string $groupColumnSelect,
        string $groupColumnRef,
        string $metricExpr,
        string $metricAlias,
        string $order,
        int $limit,
        bool $aggregate,
        array $filters,
        array $time
    ): array {
        $conditions = $filters['clauses'];
        $bindings = $filters['bindings'];

        if (!empty($time['clause'])) {
            $conditions[] = $time['clause'];
            $bindings = array_merge($bindings, $time['bindings'] ?? []);
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);
        $groupBy = $aggregate ? " GROUP BY {$groupColumnRef}" : '';

        return [
            'sql' => "SELECT {$groupColumnSelect}, {$metricExpr} AS {$metricAlias} FROM {$fromClause}{$where}{$groupBy} ORDER BY {$metricExpr} {$order} LIMIT {$limit}",
            'bindings' => $bindings,
        ];
    }

    /**
     * Every column filter the intent carries, as bound WHERE fragments.
     *
     * Accepts a list -  `filters: [{column, value}, …]` -  because a
     * conversation accumulates them, and also the single filter_column /
     * group_value pair a one-shot question produces. Filters on the column
     * being grouped by are left out: those are a detail view, handled
     * elsewhere.
     *
     * @return array{clauses: array, bindings: array, columns: array, error?: string}
     */
    protected function resolveFilters(string $datasetKey, array $intent, string $groupColumn): array
    {
        $pairs = [];

        foreach ($intent['filters'] ?? [] as $filter) {
            $column = $filter['column'] ?? null;
            $value = $filter['value'] ?? null;

            if ($column && $value !== null && $value !== '') {
                $pairs[] = [$column, $value];
            }
        }

        // The one-filter form, for questions that arrive outside a conversation.
        if (!$pairs && ($intent['filter_column'] ?? null) && ($intent['group_value'] ?? null)) {
            $pairs[] = [$intent['filter_column'], $intent['group_value']];
        }

        $clauses = [];
        $bindings = [];
        $columns = [];
        $resolvedPairs = [];

        foreach ($pairs as [$column, $value]) {
            $resolved = $this->registry->resolveGroupColumn($datasetKey, (string) $column);

            if (!$resolved) {
                $groupable = $this->registry->getGroupableColumns($datasetKey);

                // Same keys as the success branch below. A branch that
                // returns a narrower shape makes every reader guard for a key
                // the other branch always has.
                return ['clauses' => [], 'bindings' => [], 'columns' => [], 'pairs' => [],
                    'error' => "Cannot filter by '{$column}'."
                        . ($groupable ? ' Available: ' . implode(', ', $groupable) . '.' : '')];
            }

            // A filter on the column being grouped by is NOT redundant, and
            // skipping it here silently discarded the narrowing.
            //
            // "Total amount by city" then "only in Guwahati" produces exactly
            // that shape -  group_by=city, filters=[city:Guwahati] -  and every
            // city came back, the instruction gone without trace. All three
            // providers were emitting the filter correctly; it was thrown away
            // here. GROUP BY city WHERE city = 'Guwahati' is well-formed and
            // returns the one row the question asked for.
            //
            // The skip presumably dated from group_value being the only way to
            // name a value on the group column, which the group_detail branch
            // handles separately. Filters carry a column of their own, so
            // there is nothing to disambiguate.
            $value = $this->sanitizeGroupValue((string) $value);

            if ($value === null || $value === '') {
                continue;
            }

            // Parenthesised individually, so the OR inside one cannot swallow
            // the AND joining it to the next.
            $clauses[] = "(LOWER({$resolved}) = LOWER(?) OR LOWER({$resolved}) LIKE LOWER(?) ESCAPE '!')";
            $bindings[] = $value;
            $bindings[] = '%' . $this->escapeLikeValue($value) . '%';
            $columns[] = $resolved;
            $resolvedPairs[] = ['column' => $resolved, 'value' => $value];
        }

        // Columns and values must travel together. Reported apart, a caller
        // pairing the first column with the newest value produced "region is
        // Electronics" -  a filter that was never applied and never existed.
        return ['clauses' => $clauses, 'bindings' => $bindings, 'columns' => $columns, 'pairs' => $resolvedPairs];
    }

    /**
     * Turn a requested period into a bound WHERE fragment.
     *
     * Dates arrive from a model, so they are checked against a strict ISO
     * pattern AND passed as bindings -  the pattern keeps a malformed value from
     * reaching the database at all, the bindings mean nothing is interpolated
     * even if the pattern were ever loosened.
     *
     * A period asked for on a table with no date column is refused. Answering
     * over all time instead is exactly the silent substitution this exists to
     * stop.
     *
     * @return array{clause?: string, bindings?: array, label?: string, column?: string, error?: string}
     */
    protected function resolveTimeFilter(string $datasetKey, array $intent): array
    {
        $requestedFrom = $intent['date_from'] ?? null;
        $requestedTo = $intent['date_to'] ?? null;

        $from = $this->sanitizeDate($requestedFrom);
        $to = $this->sanitizeDate($requestedTo);

        // Rejection is checked BEFORE "no period requested". A period that was
        // asked for but could not be parsed must be refused, not quietly
        // treated as absent -  treating it as absent answers over all time,
        // which is the exact bug this exists to prevent.
        if ($this->wasRequested($requestedFrom) && $from === null) {
            return ['error' => 'Could not understand the start of that period.'];
        }

        if ($this->wasRequested($requestedTo) && $to === null) {
            return ['error' => 'Could not understand the end of that period.'];
        }

        if ($from === null && $to === null) {
            return [];
        }

        $column = $this->registry->getDateColumn($datasetKey);

        if (!$column) {
            return ['error' => 'This dataset has no date column, so it cannot be narrowed to a period.'];
        }

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $conditions = [];
        $bindings = [];

        if ($from !== null) {
            $conditions[] = "{$column} >= ?";
            $bindings[] = $from;
        }

        if ($to !== null) {
            $conditions[] = "{$column} <= ?";
            $bindings[] = $to;
        }

        return [
            'clause' => implode(' AND ', $conditions),
            'bindings' => $bindings,
            'column' => $column,
            'label' => $this->describePeriod($from, $to),
            // The resolved bounds, not just the label rendered from them.
            // Only the label used to leave this method, so a conversation
            // could never carry a period: ConversationManager builds the next
            // state from `parsed_query`, and `date_from`/`date_to` had no
            // route into it. "Revenue in July" then "only in West" answered
            // West ALL TIME, six times the right figure, with nothing in the
            // response saying the month had gone.
            'from' => $from,
            'to' => $to,
        ];
    }

    /** Did the caller actually ask for this bound? */
    protected function wasRequested(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    /** Only a plain ISO date is ever accepted. */
    protected function sanitizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }

    protected function describePeriod(?string $from, ?string $to): string
    {
        if ($from !== null && $to !== null) {
            return "{$from} to {$to}";
        }

        return $from !== null ? "from {$from}" : "up to {$to}";
    }

    /**
     * Build group-value-specific query (e.g., district detail).
     *
     * Smart matching: exact match first, then partial match fallback.
     */
    /**
     * Build group-value-specific query (e.g., district detail).
     *
     * Smart matching: exact match first, then partial match fallback.
     * Returns [sql, bindings] for parameterized execution.
     */
    protected function buildGroupValueQuery(
        string $fromClause,
        string $groupColumnSelect,
        string $groupColumnRef,
        string $metricExpr,
        string $metricAlias,
        string $groupValue,
        string $datasetKey,
        array $time = []
    ): array {
        // Transactional tables need per-group aggregation for the detail view
        // too -  otherwise "revenue for <customer>" returns one arbitrary row.
        $plainMetrics = $this->registry->getMetrics($datasetKey);
        $computedMetrics = $this->registry->getComputedMetrics($datasetKey);

        $aggregate = false;
        foreach ($plainMetrics as $key => $data) {
            if ($data['aggregatable'] ?? false) {
                $aggregate = true;
                break;
            }
        }

        // Get all metric columns for detail view
        $metricColumns = [];
        foreach ($plainMetrics as $key => $data) {
            if ($aggregate) {
                // SUM aggregatable columns; MAX is a safe representative for
                // the rest (identical value on pre-aggregated tables)
                $fn = ($data['aggregatable'] ?? false) ? 'SUM' : 'MAX';
                $metricColumns[] = "{$fn}({$key}) AS {$key}";
            } else {
                $metricColumns[] = $key;
            }
        }
        foreach ($computedMetrics as $key => $data) {
            // In aggregate mode, only expressions that aggregate themselves
            // are valid under GROUP BY; scalar expressions are skipped.
            if (!$aggregate || $this->isAggregateExpression($data['expression'])) {
                $metricColumns[] = "{$data['expression']} AS {$key}";
            }
        }

        $columnsStr = $groupColumnSelect . ', ' . implode(', ', $metricColumns);
        $groupBy = $aggregate ? " GROUP BY {$groupColumnRef}" : '';

        // Use parameterized queries -  NEVER interpolate user values into SQL.
        //
        // LOWER(col) LIKE LOWER(?) rather than ILIKE: ILIKE is PostgreSQL-only
        // and is a hard syntax error on MySQL and MariaDB, which this package
        // also supports -  every named-record lookup failed there. The LOWER()
        // form is ANSI and behaves identically on all of them. It does forgo
        // an index, but this query is a single-record lookup with LIMIT 1.
        // The name match is parenthesised before AND-ing the period on, or the
        // OR inside it would swallow the date and "revenue for West last month"
        // would quietly cover all time again.
        $nameMatch = "(LOWER({$groupColumnRef}) = LOWER(?) OR LOWER({$groupColumnRef}) LIKE LOWER(?) ESCAPE '!')";
        $bindings = [$groupValue, '%' . $this->escapeLikeValue($groupValue) . '%'];

        if (!empty($time['clause'])) {
            $nameMatch .= " AND {$time['clause']}";
            $bindings = array_merge($bindings, $time['bindings'] ?? []);
        }

        $sql = "SELECT {$columnsStr} FROM {$fromClause} WHERE {$nameMatch}{$groupBy} ORDER BY CASE WHEN LOWER({$groupColumnRef}) = LOWER(?) THEN 0 ELSE 1 END LIMIT 1";
        $bindings[] = $groupValue;

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /**
     * One number over the whole (optionally date-narrowed) table.
     *
     * @return array{sql: string, bindings: array}
     */
    /**
     * One number over the whole set -  narrowed, if the question narrowed it.
     *
     * Filters used to be missing here, and the branch that handles them ran
     * first, so "how many invoices are pending" never reached this method at
     * all: it was answered as a filtered RANKING and came back grouped by
     * client. The count was right and the shape was a different question.
     *
     * @param  array{clauses?: array<int, string>, bindings?: array<int, mixed>}  $filters
     * @param  array{clause?: string, bindings?: array<int, mixed>}  $time
     */
    protected function buildTotalQuery(
        string $fromClause,
        string $metricExpr,
        string $metricAlias,
        bool $aggregate,
        array $time,
        array $filters = []
    ): array {
        // By this point an aggregatable column is already wrapped in SUM() and
        // a computed metric may aggregate on its own. Only a bare column still
        // needs wrapping -  and wrapping an aggregate twice is invalid SQL.
        $expr = ($aggregate || $this->isAggregateExpression($metricExpr))
            ? $metricExpr
            : "SUM({$metricExpr})";

        $conditions = $filters['clauses'] ?? [];

        if (!empty($time['clause'])) {
            $conditions[] = $time['clause'];
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

        return [
            'sql' => "SELECT {$expr} AS {$metricAlias} FROM {$fromClause}{$where}",
            // Filters first, then the period: the same order the clauses are
            // concatenated in, which is the order the placeholders bind in.
            'bindings' => array_merge($filters['bindings'] ?? [], $time['bindings'] ?? []),
        ];
    }

    /**
     * Totals, as their own entry point.
     *
     * Kept because it is public API, but it no longer builds SQL of its own -
     * it goes through buildQuery so that the time filter, required filters,
     * metric resolution and response shape are the same ones every other query
     * gets. The separate implementation had drifted: it applied no period and
     * returned none of the metadata the formatter reads.
     */
    public function buildAggregationQuery(array $intent): array
    {
        return $this->buildQuery(['query_type' => 'aggregation'] + $intent);
    }

    /**
     * Does this SQL expression contain an aggregate function?
     */
    protected function isAggregateExpression(string $expr): bool
    {
        return (bool) preg_match('/\b(SUM|COUNT|AVG|MIN|MAX)\s*\(/i', $expr);
    }

    /**
     * Is this plain column marked aggregatable in the schema?
     */
    protected function isAggregatableColumn(string $datasetKey, string $column): bool
    {
        $columns = $this->registry->getColumns($datasetKey);

        return (bool) ($columns[$column]['aggregatable'] ?? false);
    }

    /**
     * Get SQL expression for a metric (handles computed metrics).
     */
    protected function getMetricExpression(string $datasetKey, string $metric): string
    {
        $computedMetrics = $this->registry->getComputedMetrics($datasetKey);

        if (isset($computedMetrics[$metric])) {
            return $computedMetrics[$metric]['expression'];
        }

        return $metric;
    }

    /**
     * Get metric metadata.
     */
    protected function getMetricData(string $datasetKey, string $metric): array
    {
        $metrics = $this->registry->getMetrics($datasetKey);
        if (isset($metrics[$metric])) {
            return $metrics[$metric];
        }

        $computed = $this->registry->getComputedMetrics($datasetKey);

        return $computed[$metric] ?? [];
    }

    /**
     * Clean the value naming a single record, without destroying it.
     *
     * This used to strip quotes, %, _ and backslashes, then reject anything
     * that was not purely ASCII letters, spaces, hyphens and dots. That threw
     * away most real identifiers -  "A-01", "Bin 7", "3M", "H&M",
     * "INV-2024-88", "Zürich" were all rejected, and "ACME_CORP" was silently
     * rewritten to "ACMECORP".
     *
     * Rejecting was the dangerous part. A null value means no filter, so the
     * builder fell through to a ranking query and answered a question about
     * one specific record with the top-ranked row instead -  confidently, with
     * no warning. That is the worst thing this package can do.
     *
     * Injection is already prevented by binding the value as a parameter, not
     * by mangling it, so this now only removes control characters and caps the
     * length. LIKE wildcards inside the value are escaped where the LIKE
     * pattern is built, so they match literally rather than being deleted.
     */
    protected function sanitizeGroupValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Control characters (including NUL) have no place in an identifier
        // and can confuse logging and drivers.
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, 255);
    }

    /**
     * Escape LIKE wildcards so the value matches literally.
     *
     * Without this, an underscore in something like ACME_CORP is a
     * single-character wildcard. ESCAPE is given explicitly because the
     * default escape character is not consistent across engines.
     */
    protected function escapeLikeValue(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Apply a required filter to a SQL query.
     */
    protected function applyRequiredFilter(string $sql, string $filter): string
    {
        if (stripos($sql, 'WHERE') !== false) {
            return preg_replace('/\bWHERE\b/i', "WHERE ({$filter}) AND", $sql, 1);
        }

        // Insert WHERE before GROUP BY, ORDER BY or LIMIT (whichever comes first)
        if (preg_match('/\b(GROUP BY|ORDER BY|LIMIT)\b/i', $sql, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1];

            return substr($sql, 0, $pos) . "WHERE {$filter} " . substr($sql, $pos);
        }

        return $sql . " WHERE {$filter}";
    }

    protected function errorResponse(string $message): array
    {
        return ['success' => false, 'error' => $message, 'sql' => null];
    }
}
