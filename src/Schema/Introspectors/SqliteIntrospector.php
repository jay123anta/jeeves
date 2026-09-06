<?php

namespace Jayanta\Jeeves\Schema\Introspectors;

use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;

/**
 * SQLite Schema Introspector.
 *
 * Laravel 11 and 12 create new applications on SQLite, so this is the database
 * most people who try this package already have. Without it the first
 * experience of installing was an exception telling them to go and set up a
 * different database -  which is not a first experience anyone recovers from.
 *
 * SQLite has no information_schema; structure comes from sqlite_master and the
 * PRAGMA functions. Three differences from the other drivers matter:
 *
 *  - There are no schemas. Everything lives in `main`, so table names are
 *    unqualified rather than `public.orders` or `mydb.orders`.
 *  - Types are advisory. A column declared VARCHAR(255) has TEXT affinity and
 *    can hold anything, so the declared type is a statement of intent -  which
 *    is exactly what a schema file wants, but it means the declarations vary
 *    wildly and have to be normalised by affinity rules.
 *  - Foreign keys frequently omit the referenced column, meaning "the primary
 *    key". Left unresolved that produces a JOIN with no right-hand side.
 */
class SqliteIntrospector implements SchemaIntrospectorInterface
{
    use Concerns\SuggestsColumnRoles;
    use Concerns\WithholdsSecretColumns;

    /** SQLite's own bookkeeping tables, never interesting to a user. */
    protected const INTERNAL_TABLES = [
        'sqlite_sequence',
        'sqlite_stat1',
        'sqlite_stat2',
        'sqlite_stat3',
        'sqlite_stat4',
    ];

    public function listTables(?string $connection = null, array $schemas = []): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());

        $tables = $conn->select("
            SELECT name, type
            FROM sqlite_master
            WHERE type IN ('table', 'view')
              AND name NOT LIKE 'sqlite_%'
            ORDER BY type, name
        ");

        $out = [];

        foreach ($tables as $table) {
            if (in_array($table->name, self::INTERNAL_TABLES, true)) {
                continue;
            }

            $out[] = [
                'name' => $table->name,
                'short_name' => $table->name,
                // SQLite has one schema. Reported as 'main' so callers that
                // group by schema behave, rather than seeing null.
                'schema' => 'main',
                'type' => $table->type,
                // No statistics table to read a cheap estimate from, and
                // COUNT(*) on every table during discovery is not worth the
                // wait. Honest null beats a guess.
                'row_estimate' => null,
                'comment' => null,
            ];
        }

        return $out;
    }

    public function getColumns(string $tableName, ?string $connection = null): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());
        $table = $this->parseTableName($tableName);

        // PRAGMA does not take bindings, so the name is quoted rather than
        // bound. It comes from listTables() or a schema file, never from a
        // user, and the quoting closes the gap regardless.
        $columns = $conn->select('PRAGMA table_info(' . $this->quote($table) . ')');

        return $this->withholdSecretColumns(array_map(function ($col) {
            $declared = (string) ($col->type ?? '');

            return [
                'name' => $col->name,
                'type' => $this->normalizeType($declared),
                'raw_type' => $declared !== '' ? $declared : null,
                'nullable' => !((int) $col->notnull === 1),
                'default' => $col->dflt_value,
                'is_primary' => (int) $col->pk > 0,
                'max_length' => $this->declaredLength($declared),
                'comment' => null,
                'suggested_role' => $this->suggestRole($col->name, $declared, (int) $col->pk > 0),
            ];
        }, $columns), $tableName);
    }

    public function getRelationships(string $tableName, ?string $connection = null): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());
        $table = $this->parseTableName($tableName);

        $fks = $conn->select('PRAGMA foreign_key_list(' . $this->quote($table) . ')');
        $out = [];

        foreach ($fks as $fk) {
            $referencedColumn = $fk->to;

            // A NULL target means "the primary key of that table" -  the common
            // form when a foreign key is declared with REFERENCES other(id)
            // omitted. Resolving it here keeps composite keys paired correctly
            // and stops the prompt emitting a JOIN with nothing on one side.
            if ($referencedColumn === null || $referencedColumn === '') {
                $referencedColumn = $this->primaryKeyColumn($conn, (string) $fk->table, (int) $fk->seq);
            }

            if ($referencedColumn === null) {
                continue;
            }

            $out[] = [
                'column' => $fk->from,
                'referenced_table' => $fk->table,
                'referenced_column' => $referencedColumn,
                // PRAGMA gives no constraint name, but it does number each key
                // in `id` and order its columns by `seq`. Synthesising a stable
                // name from that is what lets a composite key be rendered as
                // ONE join with AND rather than several.
                'constraint_name' => "fk_{$table}_{$fk->id}",
            ];
        }

        return $out;
    }

    public function getIndexes(string $tableName, ?string $connection = null): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());
        $table = $this->parseTableName($tableName);

        $indexes = $conn->select('PRAGMA index_list(' . $this->quote($table) . ')');
        $out = [];

        foreach ($indexes as $index) {
            $columns = $conn->select('PRAGMA index_info(' . $this->quote((string) $index->name) . ')');

            $out[] = [
                'name' => $index->name,
                'columns' => array_values(array_filter(
                    array_map(fn ($c) => $c->name, $columns),
                    fn ($name) => $name !== null
                )),
                'is_unique' => (int) $index->unique === 1,
                // origin 'pk' marks the index backing a PRIMARY KEY.
                'is_primary' => ($index->origin ?? null) === 'pk',
            ];
        }

        return $out;
    }

    public function getDriver(?string $connection = null): string
    {
        return 'sqlite';
    }

    public function getSchemas(?string $connection = null): array
    {
        // One database per connection. Named so schema-aware callers have
        // something consistent to work with.
        return ['main'];
    }

    public function getDialect(?string $connection = null): string
    {
        return 'sqlite';
    }

    protected function defaultConnection(): ?string
    {
        return config('jeeves.sql.database_connection');
    }

    /**
     * SQLite has no schemas, so a qualified name means someone carried a
     * convention over from another driver. Take the last segment.
     */
    protected function parseTableName(string $tableName): string
    {
        if (!str_contains($tableName, '.')) {
            return $tableName;
        }

        $parts = explode('.', $tableName);

        return (string) end($parts);
    }

    /** Quote an identifier for a PRAGMA, which cannot take bindings. */
    protected function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * The column a foreign key points at when the target was left implicit.
     *
     * For a composite key the position matters: the Nth column of this key
     * refers to the Nth column of the target's primary key.
     */
    protected function primaryKeyColumn($conn, string $table, int $position): ?string
    {
        $columns = $conn->select('PRAGMA table_info(' . $this->quote($this->parseTableName($table)) . ')');

        $primary = [];

        foreach ($columns as $column) {
            if ((int) $column->pk > 0) {
                // `pk` is 1-based ordering within the primary key, not a flag.
                $primary[(int) $column->pk] = $column->name;
            }
        }

        if (empty($primary)) {
            return null;
        }

        ksort($primary);
        $ordered = array_values($primary);

        return $ordered[$position] ?? $ordered[0];
    }

    /**
     * Normalise a declared type to the vocabulary schema files use.
     *
     * SQLite applies type AFFINITY: any declared type is accepted and mapped by
     * substring rules, so real databases contain INT, INTEGER, BIGINT,
     * VARCHAR(255), NVARCHAR, CLOB, REAL, DOUBLE PRECISION, NUMERIC(10,2) and
     * empty strings. The affinity rules are followed here, with date and
     * boolean checked FIRST -  both have NUMERIC affinity in SQLite, but a
     * column declared DATE is meant as a date, and the time filter depends on
     * recognising it.
     */
    protected function normalizeType(string $declared): string
    {
        $type = strtolower(trim($declared));

        if ($type === '') {
            return 'varchar';
        }

        if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
            return 'timestamp';
        }

        if (str_contains($type, 'date')) {
            return 'date';
        }

        if (str_contains($type, 'time')) {
            return 'time';
        }

        if (str_contains($type, 'bool')) {
            return 'boolean';
        }

        if (str_contains($type, 'json')) {
            return 'json';
        }

        // SQLite's own affinity rules, in their documented order.
        if (str_contains($type, 'int')) {
            return 'integer';
        }

        if (str_contains($type, 'char') || str_contains($type, 'clob') || str_contains($type, 'text')) {
            return 'varchar';
        }

        if (str_contains($type, 'blob')) {
            return 'blob';
        }

        if (str_contains($type, 'real') || str_contains($type, 'floa') || str_contains($type, 'doub')) {
            return 'decimal';
        }

        return 'decimal';
    }

    /** VARCHAR(255) → 255. */
    protected function declaredLength(string $declared): ?int
    {
        return preg_match('/\((\d+)/', $declared, $m) ? (int) $m[1] : null;
    }
}
