<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Schema\IntrospectorRegistry;
use Jayanta\Jeeves\Schema\Introspectors\MysqlIntrospector;
use Jayanta\Jeeves\Schema\Introspectors\PostgresIntrospector;
use Jayanta\Jeeves\Schema\Introspectors\SqliteIntrospector;
use Jayanta\Jeeves\Tests\Support\SqliteTestIntrospector;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class IntrospectorRegistryTest extends TestCase
{
    /**
     * The critical one. Laravel's mergeConfigFrom is a SHALLOW merge, so an app
     * that published config/jeeves.php under an older version never
     * receives nested keys added later. If the driver map lived only in config,
     * upgrading would silently leave those apps with zero supported drivers and
     * a package that refuses every query with "unsupported driver".
     */
    #[Test]
    public function the_built_in_drivers_survive_a_published_config_that_predates_them()
    {
        // Exactly what an older published config looks like: the key is absent.
        config(['jeeves.sql' => ['database_connection' => null]]);

        $this->assertTrue(IntrospectorRegistry::supports('mysql'));
        $this->assertTrue(IntrospectorRegistry::supports('pgsql'));
        $this->assertTrue(IntrospectorRegistry::supports('mariadb'));
    }

    #[Test]
    public function it_supports_the_built_in_drivers_and_nothing_else_by_default()
    {
        config(['jeeves.sql.introspectors' => []]);

        $this->assertSame(['pgsql', 'mysql', 'mariadb', 'sqlite'], IntrospectorRegistry::supportedDrivers());
        $this->assertFalse(IntrospectorRegistry::supports('sqlsrv'));
        $this->assertSame(PostgresIntrospector::class, IntrospectorRegistry::classFor('pgsql'));
        $this->assertSame(MysqlIntrospector::class, IntrospectorRegistry::classFor('mariadb'));
    }

    /**
     * SQLite is what `laravel new` creates, so it has to work out of the box.
     * Requiring a database swap before the package does anything at all was
     * the single biggest barrier to a first successful install.
     */
    #[Test]
    public function sqlite_works_out_of_the_box()
    {
        config(['jeeves.sql.introspectors' => []]);

        $this->assertTrue(IntrospectorRegistry::supports('sqlite'));
        $this->assertSame(
            SqliteIntrospector::class,
            IntrospectorRegistry::classFor('sqlite')
        );
    }

    /** A built-in can be turned off, not only replaced. */
    #[Test]
    public function a_built_in_driver_can_be_disabled_with_null()
    {
        config(['jeeves.sql.introspectors' => ['sqlite' => null]]);

        $this->assertFalse(IntrospectorRegistry::supports('sqlite'));
        $this->assertNotContains('sqlite', IntrospectorRegistry::supportedDrivers());
        $this->assertNull(IntrospectorRegistry::classFor('sqlite'));
    }

    #[Test]
    public function config_can_register_an_additional_driver()
    {
        config(['jeeves.sql.introspectors' => ['sqlite' => SqliteTestIntrospector::class]]);

        $this->assertTrue(IntrospectorRegistry::supports('sqlite'));
        $this->assertSame(SqliteTestIntrospector::class, IntrospectorRegistry::classFor('sqlite'));
        // …without displacing the built-ins.
        $this->assertTrue(IntrospectorRegistry::supports('mysql'));
    }

    #[Test]
    public function config_can_override_a_built_in_driver()
    {
        config(['jeeves.sql.introspectors' => ['mysql' => SqliteTestIntrospector::class]]);

        $this->assertSame(SqliteTestIntrospector::class, IntrospectorRegistry::classFor('mysql'));
    }

    #[Test]
    public function a_malformed_config_value_does_not_break_the_built_ins()
    {
        config(['jeeves.sql.introspectors' => 'not-an-array']);

        $this->assertTrue(IntrospectorRegistry::supports('mysql'));
    }

    #[Test]
    public function the_service_provider_resolves_the_registered_introspector()
    {
        config([
            'database.default' => 'nq_reg',
            'database.connections.nq_reg' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'jeeves.sql.introspectors' => ['sqlite' => SqliteTestIntrospector::class],
        ]);

        $resolved = $this->app->make(SchemaIntrospectorInterface::class);

        $this->assertInstanceOf(SqliteTestIntrospector::class, $resolved);
    }
}
