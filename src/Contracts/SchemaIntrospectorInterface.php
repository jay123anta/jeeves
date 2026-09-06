<?php

namespace Jayanta\Jeeves\Contracts;

/**
 * Schema Introspector Interface
 *
 * Database-specific implementations read schema structure for auto-discovery.
 * Supports PostgreSQL, MySQL, SQL Server, SQLite and extensible for others.
 *
 * Used by the `jeeves:discover` artisan command to auto-generate
 * schema configuration files from an existing database.
 */
interface SchemaIntrospectorInterface
{
    /**
     * List all tables and views in the given schemas/databases.
     *
     * @param  string|null  $connection  Laravel database connection name (null = default)
     * @param  array  $schemas  Schema names to scan (e.g., ['public', 'reporting'])
     * @return array [{
     *               name: string (fully qualified: schema.table),
     *               short_name: string (table name only),
     *               schema: string,
     *               type: 'table'|'view',
     *               row_estimate: int|null (approximate row count),
     *               comment: string|null (table comment if available)
     *               }]
     */
    public function listTables(?string $connection = null, array $schemas = []): array;

    /**
     * Get column metadata for a table or view.
     *
     * @param  string  $tableName  Fully qualified table name (schema.table)
     * @param  string|null  $connection  Laravel database connection name
     * @return array [{
     *               name: string,
     *               type: string (e.g., 'varchar', 'integer', 'decimal', 'date', 'boolean'),
     *               raw_type: string (database-native type e.g., 'character varying(255)'),
     *               nullable: bool,
     *               default: mixed|null,
     *               is_primary: bool,
     *               max_length: int|null,
     *               comment: string|null,
     *               suggested_role: 'dimension'|'measure'|'date_filter'|'identifier'|'unknown'
     *               }]
     */
    public function getColumns(string $tableName, ?string $connection = null): array;

    /**
     * Get foreign key relationships for a table.
     *
     * @param  string  $tableName  Fully qualified table name
     * @param  string|null  $connection  Laravel database connection name
     * @return array [{
     *               column: string (local column),
     *               referenced_table: string (foreign table),
     *               referenced_column: string (foreign column),
     *               constraint_name: string
     *               }]
     */
    public function getRelationships(string $tableName, ?string $connection = null): array;

    /**
     * Get indexes on a table (useful for suggesting group-by columns).
     *
     * @param  string  $tableName  Fully qualified table name
     * @param  string|null  $connection  Laravel database connection name
     * @return array [{name: string, columns: string[], is_unique: bool, is_primary: bool}]
     */
    public function getIndexes(string $tableName, ?string $connection = null): array;

    /**
     * Get the database driver name.
     *
     * @param  string|null  $connection  Laravel database connection name
     * @return string 'pgsql', 'mysql', 'sqlsrv', 'sqlite'
     */
    public function getDriver(?string $connection = null): string;

    /**
     * Get available schema names in the database.
     *
     * @param  string|null  $connection  Laravel database connection name
     * @return array List of schema names (e.g., ['public', 'reporting', 'sales'])
     */
    public function getSchemas(?string $connection = null): array;

    /**
     * Get the SQL dialect identifier for prompt building.
     *
     * @return string 'postgresql', 'mysql', 'sqlserver', 'sqlite'
     */
    public function getDialect(?string $connection = null): string;
}
