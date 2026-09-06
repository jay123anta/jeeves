<?php

namespace Jayanta\Jeeves\Schema\Introspectors;

use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;

/**
 * PostgreSQL Schema Introspector
 *
 * Reads PostgreSQL database schema for auto-discovery of tables,
 * columns, relationships, and indexes.
 */
class PostgresIntrospector implements SchemaIntrospectorInterface
{
    use Concerns\SuggestsColumnRoles;
    use Concerns\WithholdsSecretColumns;

    public function listTables(?string $connection = null, array $schemas = []): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());

        if (empty($schemas)) {
            $schemas = ['public'];
        }

        $placeholders = implode(',', array_fill(0, count($schemas), '?'));

        $tables = $conn->select("
            SELECT
                t.table_schema || '.' || t.table_name AS name,
                t.table_name AS short_name,
                t.table_schema AS schema,
                t.table_type AS type,
                -- Must match on BOTH schema and name. pg_class is database-wide,
                -- so filtering on relname alone raises a cardinality violation
                -- the moment two schemas hold a table of the same name -  the
                -- normal layout when one schema is used per subject area.
                (
                    SELECT pc.reltuples::bigint
                    FROM pg_class pc
                    JOIN pg_namespace pn ON pn.oid = pc.relnamespace
                    WHERE pc.relname = t.table_name
                      AND pn.nspname = t.table_schema
                      AND pc.relkind IN ('r', 'p', 'm', 'v', 'f')
                ) AS row_estimate,
                -- format('%I.%I') quotes the identifiers; concatenating them raw
                -- makes the regclass cast throw on any mixed-case or
                -- reserved-word table name.
                obj_description(format('%I.%I', t.table_schema, t.table_name)::regclass) AS comment
            FROM information_schema.tables t
            WHERE t.table_schema IN ({$placeholders})
            ORDER BY t.table_schema, t.table_name
        ", $schemas);

        return array_map(function ($t) {
            return [
                'name' => $t->name,
                'short_name' => $t->short_name,
                'schema' => $t->schema,
                'type' => $t->type === 'BASE TABLE' ? 'table' : 'view',
                'row_estimate' => $t->row_estimate ? (int) $t->row_estimate : null,
                'comment' => $t->comment,
            ];
        }, $tables);
    }

    public function getColumns(string $tableName, ?string $connection = null): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());
        [$schema, $table] = $this->parseTableName($tableName);

        $columns = $conn->select("
            SELECT
                c.column_name AS name,
                c.data_type AS type,
                c.udt_name || CASE
                    WHEN c.character_maximum_length IS NOT NULL THEN '(' || c.character_maximum_length || ')'
                    WHEN c.numeric_precision IS NOT NULL THEN '(' || c.numeric_precision || ',' || c.numeric_scale || ')'
                    ELSE ''
                END AS raw_type,
                c.is_nullable = 'YES' AS nullable,
                c.column_default AS \"default\",
                c.character_maximum_length AS max_length,
                col_description(format('%I.%I', c.table_schema, c.table_name)::regclass, c.ordinal_position) AS comment,
                EXISTS (
                    SELECT 1 FROM information_schema.key_column_usage kcu
                    JOIN information_schema.table_constraints tc
                        ON kcu.constraint_name = tc.constraint_name
                    WHERE kcu.table_schema = c.table_schema
                        AND kcu.table_name = c.table_name
                        AND kcu.column_name = c.column_name
                        AND tc.constraint_type = 'PRIMARY KEY'
                ) AS is_primary
            FROM information_schema.columns c
            WHERE c.table_schema = ? AND c.table_name = ?
            ORDER BY c.ordinal_position
        ", [$schema, $table]);

        return $this->withholdSecretColumns(array_map(function ($col) {
            return [
                'name' => $col->name,
                'type' => $this->normalizeType($col->type),
                'raw_type' => $col->raw_type,
                'nullable' => (bool) $col->nullable,
                'default' => $col->default,
                'is_primary' => (bool) $col->is_primary,
                'max_length' => $col->max_length ? (int) $col->max_length : null,
                'comment' => $col->comment,
                'suggested_role' => $this->suggestRole($col->name, $col->type, (bool) $col->is_primary),
            ];
        }, $columns), $tableName);
    }

    public function getRelationships(string $tableName, ?string $connection = null): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());
        [$schema, $table] = $this->parseTableName($tableName);

        // Columns are paired by position within the constraint.
        //
        // Joining key_column_usage to constraint_column_usage on the
        // constraint name alone is the widely copied version of this query,
        // and it is wrong for any composite foreign key: it returns the
        // cartesian product of the two column lists. A two-column key came
        // back as four "relationships", two of them pairing entirely unrelated
        // columns -  which would then be handed to the model as join
        // conditions. referential_constraints lets each local column be
        // matched to the referenced column in the same ordinal position.
        $fks = $conn->select("
            SELECT
                kcu.column_name AS column,
                target.table_schema || '.' || target.table_name AS referenced_table,
                target.column_name AS referenced_column,
                tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON kcu.constraint_name = tc.constraint_name
                AND kcu.constraint_schema = tc.constraint_schema
            JOIN information_schema.referential_constraints rc
                ON rc.constraint_name = tc.constraint_name
                AND rc.constraint_schema = tc.constraint_schema
            JOIN information_schema.key_column_usage target
                ON target.constraint_name = rc.unique_constraint_name
                AND target.constraint_schema = rc.unique_constraint_schema
                AND target.ordinal_position = kcu.position_in_unique_constraint
            WHERE tc.constraint_type = 'FOREIGN KEY'
                AND tc.table_schema = ? AND tc.table_name = ?
            ORDER BY tc.constraint_name, kcu.ordinal_position
        ", [$schema, $table]);

        return array_map(fn ($fk) => (array) $fk, $fks);
    }

    public function getIndexes(string $tableName, ?string $connection = null): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());
        [$schema, $table] = $this->parseTableName($tableName);

        $indexes = $conn->select("
            SELECT
                i.relname AS name,
                array_to_string(array_agg(a.attname ORDER BY x.ordinality), ',') AS columns,
                ix.indisunique AS is_unique,
                ix.indisprimary AS is_primary
            FROM pg_index ix
            JOIN pg_class t ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            CROSS JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS x(attnum, ordinality)
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = x.attnum
            WHERE n.nspname = ? AND t.relname = ?
            GROUP BY i.relname, ix.indisunique, ix.indisprimary
            ORDER BY i.relname
        ", [$schema, $table]);

        return array_map(function ($idx) {
            return [
                'name' => $idx->name,
                'columns' => explode(',', $idx->columns),
                'is_unique' => (bool) $idx->is_unique,
                'is_primary' => (bool) $idx->is_primary,
            ];
        }, $indexes);
    }

    public function getDriver(?string $connection = null): string
    {
        return 'pgsql';
    }

    public function getSchemas(?string $connection = null): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());

        $schemas = $conn->select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
            ORDER BY schema_name
        ");

        return array_map(fn ($s) => $s->schema_name, $schemas);
    }

    public function getDialect(?string $connection = null): string
    {
        return 'postgresql';
    }

    protected function defaultConnection(): ?string
    {
        return config('jeeves.sql.database_connection');
    }

    protected function parseTableName(string $tableName): array
    {
        if (str_contains($tableName, '.')) {
            return explode('.', $tableName, 2);
        }

        return ['public', $tableName];
    }

    protected function normalizeType(string $pgType): string
    {
        return match (true) {
            in_array($pgType, ['integer', 'bigint', 'smallint', 'serial', 'bigserial']) => 'integer',
            in_array($pgType, ['numeric', 'decimal', 'real', 'double precision', 'money']) => 'decimal',
            in_array($pgType, ['character varying', 'character', 'text', 'varchar', 'char']) => 'varchar',
            in_array($pgType, ['boolean']) => 'boolean',
            in_array($pgType, ['date']) => 'date',
            in_array($pgType, ['timestamp without time zone', 'timestamp with time zone']) => 'timestamp',
            in_array($pgType, ['time without time zone', 'time with time zone']) => 'time',
            in_array($pgType, ['json', 'jsonb']) => 'json',
            in_array($pgType, ['uuid']) => 'uuid',
            default => $pgType,
        };
    }

    // suggestRole() lives in the SuggestsColumnRoles trait -  it was duplicated
    // here and in MysqlIntrospector, and the two copies had already drifted.
}
