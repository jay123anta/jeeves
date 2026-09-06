<?php

namespace Jayanta\Jeeves\Exceptions;

/**
 * Model-authored SQL has nowhere safe to run. NQ-001.
 *
 * Both messages name the setting and the fix, because this is a configuration
 * fault an adopter corrects in one line, and a refusal that does not say how
 * is a dead end.
 */
class UnsafeConnectionException extends \RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'Jeeves has no database connection configured to run generated SQL on. '
            . "Set 'sql.database_connection' in config/jeeves.php to a connection whose "
            . 'database user holds SELECT only, and which is NOT your application connection. '
            . 'Generated SQL is written by a language model, and SELECT-only validation is the '
            . 'only thing between a bypass and write access. See docs/CONNECTION.md for the grants.'
        );
    }

    public static function isApplicationDefault(string $name): self
    {
        return new self(
            "Jeeves is configured to run generated SQL on '{$name}', which is your "
            . "application's default connection - the one it writes with. Point "
            . "'sql.database_connection' at a separate connection whose database user holds "
            . 'SELECT only. See docs/CONNECTION.md for the grants.'
        );
    }
}
