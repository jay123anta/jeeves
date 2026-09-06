<?php

namespace Jayanta\Jeeves\Contracts;

/**
 * SQL Validator Interface
 *
 * Validates AI-generated SQL before execution.
 * Ensures security: only SELECT queries on whitelisted tables.
 */
interface SqlValidatorInterface
{
    /**
     * Validate a SQL query against security rules.
     *
     * @param  string  $sql  The SQL to validate
     * @param  array  $allowedTables  List of allowed table/view names
     * @param  array  $options  Additional validation options (max_limit, forbidden_keywords, etc.)
     * @return array {valid: bool, reason: string|null}
     */
    public function validate(string $sql, array $allowedTables, array $options = []): array;
}
