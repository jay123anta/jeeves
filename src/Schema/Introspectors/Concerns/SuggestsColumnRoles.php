<?php

namespace Jayanta\Jeeves\Schema\Introspectors\Concerns;

/**
 * Decides what a column is FOR, which drives everything downstream.
 *
 * A "measure" is marked aggregatable, so the query builder sums it and the
 * model is told it is a number worth totalling. Getting this wrong is not
 * cosmetic: classifying `customer_id` as a measure tells the model to compute
 * the total of a foreign key, which is meaningless, and pushes it away from
 * the join that would have answered the question.
 *
 * Both introspectors used to carry their own copy of this and had already
 * drifted -  the MySQL one did not treat TEXT columns as dimensions.
 */
trait SuggestsColumnRoles
{
    /**
     * @return string one of: identifier, foreign_key, date_filter, measure,
     *                dimension, unknown
     */
    protected function suggestRole(string $name, string $type, bool $isPrimary): string
    {
        if ($isPrimary) {
            return 'identifier';
        }

        $nameLower = strtolower($name);

        // A key that points at another row is not a quantity. Detected by name
        // because a column can reference another table without a declared
        // constraint, which is common enough to matter -  and discovery
        // upgrades this to `foreign_key` when a real constraint is found.
        if ($this->looksLikeKeyColumn($nameLower)) {
            return 'foreign_key';
        }

        // `_on` covers the ordered_on / shipped_on / created_on style, which is
        // as common as `_at` and was previously classified as unknown.
        if (preg_match('/(_at|_on|_date|_time|date$|time$|timestamp)/i', $nameLower)) {
            return 'date_filter';
        }

        $normalizedType = $this->normalizeType($type);

        if (in_array($normalizedType, ['integer', 'decimal'], true)) {
            return 'measure';
        }

        if (in_array($normalizedType, ['varchar', 'text', 'char'], true)) {
            return 'dimension';
        }

        return 'unknown';
    }

    /**
     * Does this column name read like a key rather than a quantity?
     *
     * Deliberately conservative. `_count`, `_total` and `_amount` are measures
     * that happen to end in a noun; only the key-ish suffixes are excluded.
     */
    protected function looksLikeKeyColumn(string $nameLower): bool
    {
        return $nameLower === 'id'
            || $nameLower === 'uuid'
            || (bool) preg_match('/(^|_)(id|uuid|ulid|guid)$/', $nameLower);
    }
}
