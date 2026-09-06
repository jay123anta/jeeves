<?php

namespace Jayanta\Jeeves\Tests;

use Jayanta\Jeeves\JeevesServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [JeevesServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // NQ-001. Generated SQL may not run on the application's own
        // connection, so the suite has to be a CORRECTLY CONFIGURED install
        // rather than the default one. This is the same line every adopter
        // adds on upgrading to 3.0, and running the suite without it is how
        // the blast radius was measured: 113 failures out of 782.
        //
        // Both connections address ONE database through SQLite's shared cache,
        // because a test seeds through the default connection and the package
        // reads through the other. Two `:memory:` entries would be two
        // separate databases and every table would be missing.
        $this->isolatedDatabase($app, 'nq_app');

        $app['config']->set('jeeves.llm.driver', 'gemini');
        $app['config']->set('jeeves.llm.providers.gemini.api_key', 'test-key');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/Stubs/schemas');
    }

    /**
     * Give a test its own database, reachable through TWO connection names.
     *
     * NQ-001: generated SQL may not run on the application's default
     * connection, so every test has to look like a correctly configured
     * install - an app connection to seed through, and a separate name the
     * package reads through. In production those are one database and two
     * database users, the second holding SELECT only.
     *
     * A temp FILE rather than `:memory:`, because two `:memory:` entries are
     * two separate databases and the package would find no tables. A
     * monotonic counter rather than spl_object_id(), because PHPUnit destroys
     * each test instance and object ids are recycled - two tests drew the same
     * filename and the first one's cleanup deleted the database the second was
     * still using.
     */
    protected function isolatedDatabase($app, string $appConnection): void
    {
        static $seq = 0;

        $file = sys_get_temp_dir() . '/nq_' . getmypid() . '_' . (++$seq) . '.sqlite';

        if (!file_exists($file)) {
            touch($file);
        }

        $this->beforeApplicationDestroyed(static function () use ($file) {
            @unlink($file);
        });

        $sqlite = [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ];

        $app['config']->set("database.connections.{$appConnection}", $sqlite);
        $app['config']->set("database.connections.{$appConnection}_reader", $sqlite);
        $app['config']->set('database.default', $appConnection);
        $app['config']->set('jeeves.sql.database_connection', $appConnection . '_reader');
    }
}
