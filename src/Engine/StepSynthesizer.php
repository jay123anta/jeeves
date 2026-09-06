<?php

namespace Jayanta\Jeeves\Engine;

/**
 * Turn several step results into one answer.
 *
 * The arithmetic is done here, in PHP, on values already fetched from the
 * database -  never by asking a model to compare two numbers. Models are
 * unreliable at that and there is no reason to risk it: the figures are in
 * hand, and a percentage is a percentage.
 */
class StepSynthesizer
{
    /**
     * @param  array<int, array>  $steps  Each: {question, status, answer, rows, ...}
     * @return array{answer: string, comparison: ?array}
     */
    public function synthesize(string $originalQuery, array $steps, bool $comparison): array
    {
        $ok = array_values(array_filter($steps, fn ($s) => ($s['status'] ?? '') === 'success'));
        $failed = count($steps) - count($ok);

        if (empty($ok)) {
            return [
                'answer' => 'None of the steps in this question could be answered.',
                'comparison' => null,
            ];
        }

        $delta = $comparison && count($ok) >= 2 ? $this->compare($ok) : null;

        if ($delta) {
            $answer = sprintf(
                '%s is %s (%s) versus %s (%s) -  %s %s%%.',
                $delta['metric_label'],
                $delta['first_formatted'],
                $delta['first_question'],
                $delta['second_formatted'],
                $delta['second_question'],
                $delta['direction'],
                $delta['change_pct']
            );
        } else {
            // No safe comparison: report each step in its own words rather than
            // inventing a relationship between them.
            $answer = implode(' ', array_map(
                fn ($s) => rtrim((string) ($s['answer'] ?? ''), '.') . '.',
                $ok
            ));
        }

        if ($failed > 0) {
            $answer .= sprintf(
                ' (%d of %d steps could not be answered.)',
                $failed,
                count($steps)
            );
        }

        return ['answer' => trim($answer), 'comparison' => $delta];
    }

    /**
     * Compare the first two steps, but only when they are actually comparable.
     *
     * Two single-value results measuring the same metric can be compared. A
     * ranking against a total cannot, and reporting a percentage between them
     * would be a fabricated number presented with the same confidence as a real
     * one -  the worst thing this package can do.
     */
    protected function compare(array $steps): ?array
    {
        [$first, $second] = [$steps[0], $steps[1]];

        $a = $this->singleValue($first);
        $b = $this->singleValue($second);

        if ($a === null || $b === null) {
            return null;
        }

        if (($first['metric'] ?? null) !== ($second['metric'] ?? null)) {
            return null;
        }

        $change = $b == 0.0
            ? null
            : round((($a - $b) / abs($b)) * 100, 1);

        return [
            'metric' => $first['metric'] ?? null,
            'metric_label' => $first['metric_description'] ?? ($first['metric'] ?? 'The value'),
            'first_question' => $first['question'] ?? '',
            'second_question' => $second['question'] ?? '',
            'first_value' => $a,
            'second_value' => $b,
            'first_formatted' => $this->format($a),
            'second_formatted' => $this->format($b),
            'change_pct' => $change,
            'direction' => $change === null
                ? 'not comparable'
                : ($change > 0 ? 'up' : ($change < 0 ? 'down' : 'unchanged')),
        ];
    }

    /**
     * The one number a step produced, or null if it produced anything else.
     */
    protected function singleValue(array $step): ?float
    {
        $rows = $step['rows'] ?? [];

        if (count($rows) !== 1) {
            return null;
        }

        $row = (array) $rows[0];
        $metric = $step['metric'] ?? null;

        // Name the column rather than hoping there is only one number in the
        // row. A detail row commonly carries the measure AND a record count,
        // and "whichever number is present" would compare revenue in one step
        // against a row count in the next.
        if ($metric !== null && isset($row[$metric]) && is_numeric($row[$metric])) {
            return (float) $row[$metric];
        }

        $numbers = array_filter($row, fn ($v) => is_numeric($v));

        if (count($numbers) !== 1) {
            return null;
        }

        return (float) reset($numbers);
    }

    protected function format(float $value): string
    {
        return $value == (int) $value
            ? number_format($value)
            : number_format($value, 2);
    }
}
