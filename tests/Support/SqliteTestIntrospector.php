<?php

namespace Jayanta\Jeeves\Tests\Support;

use Jayanta\Jeeves\Schema\Introspectors\MysqlIntrospector;

/**
 * A stand-in introspector class, used only to test the extension point.
 *
 * SQLite is a supported driver now, with a real introspector, so nothing needs
 * this to make the suite run. What it still provides is an arbitrary class to
 * point `sql.introspectors` at, proving that config can register a driver the
 * package does not ship and override one it does.
 *
 * It is deliberately NOT a plausible SQLite implementation -  anything relying
 * on it for real introspection would be testing the wrong thing.
 */
class SqliteTestIntrospector extends MysqlIntrospector
{
    public function getDialect(?string $connection = null): string
    {
        return 'sqlite';
    }

    public function getDriver(?string $connection = null): string
    {
        return 'sqlite';
    }
}
