<?php

namespace Jayanta\Jeeves\Security;

use Jayanta\Jeeves\Exceptions\UnsafeConnectionException;

/**
 * Which connection may execute model-authored SQL. NQ-001.
 *
 * `sql.database_connection` shipped as null, and the executor fell through to
 * `DB::select()` - the application's own connection, with the privileges the
 * application writes with. SELECT-only in SqlValidator was then the entire
 * thing standing between a validator bypass and a write, and three attack
 * sweeps in one week found three bypasses.
 *
 * The guarantee is about the RESOLVED CONNECTION, not about a line of code.
 * A guard bolted to the single site that executes SQL today leaves tomorrow's
 * site free to reintroduce the hole while the test on today's site stays
 * green - this project's signature defect, found three separate times. So
 * resolution and refusal happen together, here, and no caller can obtain a
 * connection name without passing the check. There is no `DB::select()`
 * fallback left anywhere in `src/`.
 *
 * Two conditions fail closed:
 *
 *   1. nothing configured -  there is no safe default to fall back to, and
 *      falling back to the application's is the finding itself
 *   2. configured, but naming the application's default -  the same hole with
 *      a value in the config file, which reads as safe and is not
 *
 * A third condition, "the connection can be shown to hold write privileges",
 * belongs in `jeeves:doctor` rather than here. Proving it costs a round
 * trip per query, it is unprovable on SQLite where every connection can write,
 * and a diagnostic that runs once says it better than a check on every
 * question.
 *
 * Schema INTROSPECTION is deliberately not routed through this. Reading
 * `information_schema` on the application's connection is metadata, not rows,
 * and `discover` has to read the schema in order to write the config that
 * names the connection - requiring one first is a circle.
 */
class ExecutionConnection
{
    /**
     * The connection name model-authored SQL may run on.
     *
     * @param  string|null  $preferred  a dataset's own connection, when it declares one
     *
     * @throws UnsafeConnectionException when no safe connection is configured
     */
    public static function resolve(?string $preferred = null): string
    {
        $name = $preferred ?: config('jeeves.sql.database_connection');

        if ($name === null || $name === '') {
            throw UnsafeConnectionException::notConfigured();
        }

        if ($name === config('database.default')) {
            throw UnsafeConnectionException::isApplicationDefault($name);
        }

        return $name;
    }
}
