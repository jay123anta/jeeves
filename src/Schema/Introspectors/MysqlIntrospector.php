<?php

namespace Jayanta\Jeeves\Schema\Introspectors;

use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;

/**
 * MySQL/MariaDB Schema Introspector
 */
class MysqlIntrospector implements SchemaIntrospectorInterface
{
    use Concerns\SuggestsColumnRoles;
    use Concerns\WithholdsSecretColumns;

    public function listTables(?string $connection = null, array $schemas = []): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());
        $database = $conn->getDatabaseName();

        if (empty($schemas)) {
            $schemas = [$database];
        }

        $placeholders = implode(',', array_fill(0, count($schemas), '?'));

        $tables = $conn->select("
            SELECT
                TABLE_SCHEMA AS `schema`,
                TABLE_NAME AS short_name,
                CONCAT(TABLE_SCHEMA, '.', TABLE_NAME) AS name,
                CASE TABLE_TYPE WHEN 'BASE TABLE' THEN 'table' ELSE 'view' END AS type,
                TABLE_ROWS AS row_estimate,
                TABLE_COMMENT AS comment
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA IN ({$placeholders})
            ORDER BY TABLE_SCHEMA, TABLE_NAME
        ", $schemas);

        return array_map(fn ($t) => [
            'name' => $t->name,
            'short_name' => $t->short_name,
            'schema' => $t->schema,
            'type' => $t->type,
            'row_estimate' => $t->row_estimate ? (int) $t->row_estimate : null,
            'comment' => $t->comment ?: null,
        ], $tables);
    }

    public function getColumns(string $tableName, ?string $connection = null): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());
        [$schema, $table] = $this->parseTableName($tableName, $conn);

        $columns = $conn->select("
            SELECT
                COLUMN_NAME AS name,
                DATA_TYPE AS type,
                COLUMN_TYPE AS raw_type,
                IS_NULLABLE = 'YES' AS nullable,
                COLUMN_DEFAULT AS `default`,
                COLUMN_KEY = 'PRI' AS is_primary,
                CHARACTER_MAXIMUM_LENGTH AS max_length,
                COLUMN_COMMENT AS comment
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
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
                'comment' => $col->comment ?: null,
                'suggested_role' => $this->suggestRole($col->name, $col->type, (bool) $col->is_primary),
            ];
        }, $columns), $tableName);
    }

    public function getRelationships(string $tableName, ?string $connection = null): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());
        [$schema, $table] = $this->parseTableName($tableName, $conn);

        $fks = $conn->select("
            SELECT
                COLUMN_NAME AS `column`,
                CONCAT(REFERENCED_TABLE_SCHEMA, '.', REFERENCED_TABLE_NAME) AS referenced_table,
                REFERENCED_COLUMN_NAME AS referenced_column,
                CONSTRAINT_NAME AS constraint_name
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
        ", [$schema, $table]);

        return array_map(fn ($fk) => (array) $fk, $fks);
    }

    public function getIndexes(string $tableName, ?string $connection = null): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());
        [$schema, $table] = $this->parseTableName($tableName, $conn);

        $rawIndexes = $conn->select("
            SELECT
                INDEX_NAME AS name,
                GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns,
                NOT NON_UNIQUE AS is_unique,
                INDEX_NAME = 'PRIMARY' AS is_primary
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            GROUP BY INDEX_NAME, NON_UNIQUE
            ORDER BY INDEX_NAME
        ", [$schema, $table]);

        return array_map(fn ($idx) => [
            'name' => $idx->name,
            'columns' => explode(',', $idx->columns),
            'is_unique' => (bool) $idx->is_unique,
            'is_primary' => (bool) $idx->is_primary,
        ], $rawIndexes);
    }

    public function getDriver(?string $connection = null): string
    {
        return 'mysql';
    }

    public function getSchemas(?string $connection = null): array
    {
        $conn = DB::connection($connection ?? $this->defaultConnection());

        $schemas = $conn->select("
            SELECT SCHEMA_NAME FROM information_schema.SCHEMATA
            WHERE SCHEMA_NAME NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
            ORDER BY SCHEMA_NAME
        ");

        return array_map(fn ($s) => $s->SCHEMA_NAME, $schemas);
    }

    public function getDialect(?string $connection = null): string
    {
        return 'mysql';
    }

    protected function defaultConnection(): ?string
    {
        return config('jeeves.sql.database_connection');
    }

    protected function parseTableName(string $tableName, $conn = null): array
    {
        if (str_contains($tableName, '.')) {
            return explode('.', $tableName, 2);
        }

        $database = $conn ? $conn->getDatabaseName() : config('database.connections.mysql.database');

        return [$database, $tableName];
    }

    protected function normalizeType(string $mysqlType): string
    {
        return match (true) {
            in_array($mysqlType, ['int', 'bigint', 'smallint', 'mediumint', 'tinyint']) => 'integer',
            in_array($mysqlType, ['decimal', 'numeric', 'float', 'double']) => 'decimal',
            in_array($mysqlType, ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set']) => 'varchar',
            in_array($mysqlType, ['tinyint(1)', 'boolean', 'bool']) => 'boolean',
            $mysqlType === 'date' => 'date',
            in_array($mysqlType, ['datetime', 'timestamp']) => 'timestamp',
            $mysqlType === 'time' => 'time',
            $mysqlType === 'json' => 'json',
            default => $mysqlType,
        };
    }

    // suggestRole() lives in the SuggestsColumnRoles trait -  it was duplicated
    // here and in PostgresIntrospector, and this copy had already drifted:
    // it never treated TEXT columns as dimensions.
}
