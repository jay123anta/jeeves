<?php

namespace Jayanta\Jeeves\Benchmark;

/**
 * Is this answer the same answer, allowing for the ways SQL differs?
 *
 * Text-to-SQL is graded the way Spider and BIRD grade it: not by comparing
 * query strings, because many different queries are correct, but by running
 * the generated query and a hand-written reference against the same database
 * and comparing the RESULT SETS. A query is right when its answer is right.
 *
 * Extracted so the package's own benchmark and the adopter-facing
 * `jeeves:benchmark` grade identically. Two implementations of "is this
 * the same result" would drift, and the number this package quotes about
 * itself has to mean the same thing as the number an adopter measures on their
 * own schema -  otherwise neither is worth stating.
 */
class ResultComparator
{
    /**
     * Reduce a result set to the numbers a person would read off it.
     *
     * Aliases and column ORDER are ignored: `SELECT SUM(x) AS total` and
     * `SELECT SUM(x) AS revenue` are the same answer, and no user cares which
     * order two columns came back in. Row order is ignored too -  unless the
     * question asked for a ranking, in which case the order IS the answer and
     * a correct set in the wrong sequence is a wrong answer.
     *
     * Numbers are rounded to two places so a float that differs in the
     * fifteenth decimal does not read as a different total.
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, string>
     */
    public function normalize(array $rows, bool $ordered): array
    {
        $out = [];

        foreach ($rows as $row) {
            $values = [];

            foreach ((array) $row as $value) {
                if ($value === null) {
                    continue;
                }

                $values[] = is_numeric($value)
                    ? (string) round((float) $value, 2)
                    : strtolower(trim((string) $value));
            }

            sort($values);
            $out[] = implode('|', $values);
        }

        if (!$ordered) {
            sort($out);
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $expected  Rows from the reference query
     * @param  array<int, mixed>  $actual  Rows the package produced
     */
    public function matches(array $expected, array $actual, bool $ordered = false): bool
    {
        return $this->normalize($expected, $ordered) === $this->normalize($actual, $ordered);
    }

    /**
     * A short, readable form of a normalized set, for a failure message.
     *
     * @param  array<int, string>  $normalized
     */
    public function preview(array $normalized): string
    {
        return '[' . implode(' ; ', array_slice($normalized, 0, 3))
            . (count($normalized) > 3 ? ' …' : '') . ']';
    }
}
