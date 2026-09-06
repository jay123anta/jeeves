<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Console\DoctorCommand;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The doctor is the first thing a stuck user runs. Its job is not to be
 * pretty -  it is to name the real cause and print the exact fix.
 */
class DoctorCommandTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // NQ-001: an app connection to seed through, and a separate
        // name the package reads through, over one database.
        $this->isolatedDatabase($app, 'testing');
        // Feedback is on by default and needs a published migration; individual
        // tests opt back in rather than every case tripping over it.
        $app['config']->set('jeeves.feedback.enabled', false);

        // The suite runs on in-memory SQLite, which is now a supported driver -
        // so these cases exercise the real SqliteIntrospector rather than a
        // stand-in, and doctor reports on it exactly as it would in an app.
    }

    /** Build the tables the stub schema declares, so schema checks pass. */
    private function createStubTables(): void
    {
        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('customer_name');
            $table->decimal('amount', 12, 2);
            $table->string('status');
        });

        Schema::create('district_stats', function ($table) {
            $table->id();
            $table->string('district_name');
            $table->integer('total_households');
        });
    }

    #[Test]
    public function it_reports_a_healthy_setup_when_everything_lines_up()
    {
        $this->createStubTables();

        $this->artisan('jeeves:doctor --skip-api')
            ->assertExitCode(0)
            ->expectsOutputToContain('LLM driver: gemini')
            ->expectsOutputToContain('2 schema(s) loaded');
    }

    #[Test]
    public function it_flags_a_schema_whose_table_does_not_exist()
    {
        // No tables created -  the stub schemas point at tables that aren't there.
        $this->artisan('jeeves:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain('not found in the database')
            ->expectsOutputToContain('jeeves:discover');
    }

    #[Test]
    public function it_flags_a_column_declared_in_the_schema_but_missing_from_the_table()
    {
        // 'status' is declared by the stub schema; omit it here.
        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('customer_name');
            $table->decimal('amount', 12, 2);
        });
        Schema::create('district_stats', function ($table) {
            $table->id();
            $table->string('district_name');
            $table->integer('total_households');
        });

        $this->artisan('jeeves:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain('status');
    }

    /**
     * Discovering a subset of tables leaves foreign keys pointing at tables
     * with no schema file. Those joins are deliberately never offered -  the
     * validator would reject the SQL -  but a user seeing only the symptom
     * concludes the AI cannot do joins. Doctor names the cause instead.
     */
    #[Test]
    public function it_flags_foreign_keys_pointing_at_tables_that_were_never_described()
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/subset-schemas']);
        $this->app->forgetInstance(SchemaRegistry::class);

        Schema::create('sub_orders', function ($table) {
            $table->id();
            $table->integer('customer_id');
            $table->integer('quantity');
            $table->string('status');
        });

        // Captured rather than expectsOutputToContain(): that helper matches
        // one expectation per write, so asserting on both the warning and its
        // fix line is order-sensitive and brittle.
        Artisan::call('jeeves:doctor', ['--skip-api' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('no schema file', $output);
        $this->assertStringContainsString('sub_customers', $output);
        $this->assertStringContainsString(
            'jeeves:discover --table=sub_customers --merge',
            $output,
            'the warning must print the exact command that fixes it'
        );
    }

    #[Test]
    public function it_stays_quiet_when_every_related_table_has_a_schema_file()
    {
        $this->createStubTables();

        Artisan::call('jeeves:doctor', ['--skip-api' => true]);

        $this->assertStringNotContainsString('no schema file', Artisan::output());
    }

    /**
     * Measured on a fourteen-table schema: with both order_items.line_total
     * and payments.amount available, the model answered "revenue" from
     * payments, so unpaid customers vanished and a region came back at half
     * its real revenue. Three sentences of system_instructions moved accuracy
     * from 79% to 86% and fixed every multi-table question.
     *
     * The package cannot make that choice -  the columns are identical in kind.
     * It can say the choice exists.
     */
    /** rel_orders and rel_sales each carry their own measures. */
    private function useCompetingMeasureSchemas(): void
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/related-schemas']);
        $this->app->forgetInstance(SchemaRegistry::class);
    }

    #[Test]
    public function it_points_out_that_several_datasets_could_answer_the_same_question()
    {
        $this->useCompetingMeasureSchemas();
        config(['jeeves.system_instructions' => '']);

        Artisan::call('jeeves:doctor', ['--skip-api' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('datasets have their own measures', $output);
        $this->assertStringContainsString('system_instructions', $output);
    }

    #[Test]
    public function it_stays_quiet_once_the_ambiguity_has_been_settled()
    {
        $this->useCompetingMeasureSchemas();
        config(['jeeves.system_instructions' => 'Revenue means SUM(rel_orders.quantity * unit_price).']);

        Artisan::call('jeeves:doctor', ['--skip-api' => true]);

        $this->assertStringNotContainsString('datasets have their own measures', Artisan::output());
    }

    #[Test]
    public function a_single_dataset_with_measures_is_not_ambiguous()
    {
        // Nothing to choose between, so saying so would be noise.
        $this->createStubTables();
        config(['jeeves.system_instructions' => '']);

        Artisan::call('jeeves:doctor', ['--skip-api' => true]);

        $this->assertStringNotContainsString('datasets have their own measures', Artisan::output());
    }

    /**
     * Without CORS the browser blocks the response before any JavaScript sees
     * it, so a correctly answered request surfaces as a network error with no
     * mention of policy. Cheap to detect, baffling to debug.
     */
    #[Test]
    public function it_flags_a_separate_front_end_with_no_cors_entry()
    {
        $this->createStubTables();
        config([
            'jeeves.routes.middleware' => ['api', 'auth:sanctum'],
            'cors.paths' => ['api/*'],
        ]);

        Artisan::call('jeeves:doctor', ['--skip-api' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('not in cors.paths', $output);
        $this->assertStringContainsString('config/cors.php', $output);
    }

    #[Test]
    public function it_stays_quiet_once_cors_covers_the_prefix()
    {
        $this->createStubTables();
        config([
            'jeeves.routes.middleware' => ['api', 'auth:sanctum'],
            'cors.paths' => ['api/*', 'jeeves/*'],
        ]);

        Artisan::call('jeeves:doctor', ['--skip-api' => true]);

        $this->assertStringNotContainsString('not in cors.paths', Artisan::output());
    }

    #[Test]
    public function a_same_domain_blade_app_is_never_asked_about_cors()
    {
        // Session cookies on one origin need no CORS at all, and warning about
        // it there would be noise on the most common setup.
        $this->createStubTables();
        config(['jeeves.routes.middleware' => ['web', 'auth'], 'cors.paths' => []]);

        Artisan::call('jeeves:doctor', ['--skip-api' => true]);

        $this->assertStringNotContainsString('cors.paths', Artisan::output());
    }

    #[Test]
    public function it_flags_a_missing_api_key()
    {
        $this->createStubTables();
        config(['jeeves.llm.providers.gemini.api_key' => '']);

        $this->artisan('jeeves:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain('API key is empty')
            ->expectsOutputToContain('GEMINI_API_KEY');
    }

    #[Test]
    public function it_never_prints_the_api_key()
    {
        $this->createStubTables();
        config(['jeeves.llm.providers.gemini.api_key' => 'AIzaSy-SUPER-SECRET-VALUE']);

        // Captured directly rather than via doesntExpectOutputToContain(),
        // which is not available across every supported Laravel version.
        $exitCode = Artisan::call('jeeves:doctor', ['--skip-api' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('AIzaSy-SUPER-SECRET-VALUE', $output);
        $this->assertStringContainsString('API key is set', $output);
        $this->assertSame(0, $exitCode);
    }

    #[Test]
    public function it_flags_disabled_ssl_verification_as_a_security_warning()
    {
        $this->createStubTables();
        config(['jeeves.ssl_verify' => false]);

        $this->artisan('jeeves:doctor --skip-api')
            ->expectsOutputToContain('SSL verification is DISABLED')
            ->expectsOutputToContain('cacert.pem')
            // A warning must not fail the command -  nothing is broken
            ->assertExitCode(0);
    }

    #[Test]
    public function it_flags_a_ca_bundle_path_that_does_not_exist()
    {
        $this->createStubTables();
        config(['jeeves.ssl_verify' => '/no/such/path/cacert.pem']);

        $this->artisan('jeeves:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain('CA bundle not found');
    }

    /**
     * Verification on, but no CA store to verify against.
     *
     * This was a green tick regardless, with the truth only emerging from the
     * live provider check -  so `--skip-api`, the fast check the docs recommend,
     * reported a healthy setup that could not reach a provider at all. It cost
     * this project an afternoon: a benchmark run scored 0/36 and read exactly
     * like a package regression, when PHP simply had no certificates.
     *
     * Common enough on XAMPP and WAMP to be the default experience there, and
     * Rule 0 says those are stacks the package has to work on.
     */
    #[Test]
    public function it_flags_ssl_verification_with_no_ca_store_behind_it()
    {
        $this->createStubTables();
        config(['jeeves.ssl_verify' => true]);

        $this->swap(
            DoctorCommand::class,
            new class extends DoctorCommand
            {
                protected function phpCaBundle(): ?string
                {
                    return null;
                }

                protected function bundleOnDisk(): ?string
                {
                    return 'C:/xampp/apache/bin/curl-ca-bundle.crt';
                }
            }
        );

        // Captured rather than expectsOutputToContain(): that helper matches
        // one expectation per write, so asserting on both the warning and its
        // fix line is order-sensitive and brittle.
        $exit = Artisan::call('jeeves:doctor', ['--skip-api' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('no CA certificate store', $output);
        // It found one on disk, so the fix is a single line rather than a
        // download and a hunt for where to put it.
        $this->assertStringContainsString('curl-ca-bundle.crt', $output);
        $this->assertStringContainsString('JEEVES_SSL_VERIFY', $output);
        // Nothing is broken yet -  it may still work. A warning, not a fault.
        $this->assertSame(0, $exit);
    }

    #[Test]
    public function it_confirms_the_ca_store_php_will_actually_use()
    {
        $this->createStubTables();
        config(['jeeves.ssl_verify' => true]);

        $this->swap(
            DoctorCommand::class,
            new class extends DoctorCommand
            {
                protected function phpCaBundle(): ?string
                {
                    return '/etc/ssl/certs/ca-certificates.crt';
                }
            }
        );

        $this->artisan('jeeves:doctor --skip-api')
            ->expectsOutputToContain('ca-certificates.crt')
            ->assertExitCode(0);
    }

    /**
     * A brand-new install must not report a problem the user did not cause.
     *
     * `jeeves:install` writes the shipped template, which points at a
     * placeholder table by design. Doctor called that a red ✗ and exited
     * non-zero -  so the very first run of the command the docs recommend as a
     * deployment smoke test failed, on a fresh install, before the user had
     * done anything wrong. Verified against a real composer install into
     * Laravel 13.
     *
     * The real stub is copied rather than a fixture written, so changing the
     * placeholder in stubs/schema-example.php without telling doctor fails
     * here instead of on somebody's first day.
     */
    private function useShippedTemplate(): string
    {
        $dir = sys_get_temp_dir() . '/nq-doctor-template-' . getmypid();

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        copy(__DIR__ . '/../../stubs/schema-example.php', $dir . '/example.php');

        config(['jeeves.schema.config_path' => $dir]);
        $this->app->forgetInstance(SchemaRegistry::class);

        return $dir;
    }

    #[Test]
    public function the_shipped_template_is_named_as_such_rather_than_reported_as_broken()
    {
        $dir = $this->useShippedTemplate();

        try {
            $exit = Artisan::call('jeeves:doctor', ['--skip-api' => true]);
            $output = Artisan::output();

            $this->assertStringContainsString('shipped template', $output);
            $this->assertStringNotContainsString(
                'not found in the database',
                $output,
                'the template was reported as a broken schema'
            );

            // Nothing but the template IS a problem -  there is no dataset any
            // question could be answered from -  but the reason given must be
            // that, not a phantom missing table.
            $this->assertStringContainsString('No usable schema files', $output);
            $this->assertSame(1, $exit);
        } finally {
            @unlink($dir . '/example.php');
            @rmdir($dir);
        }
    }

    /**
     * The realistic fresh install: `install` writes the template, `discover`
     * adds a real dataset. That setup works, so doctor must pass -  and must
     * still mention the template, since the engine now ignores it and the file
     * would otherwise disappear from view entirely.
     */
    #[Test]
    public function a_template_alongside_a_real_schema_is_a_working_install()
    {
        $dir = $this->useShippedTemplate();
        copy(__DIR__ . '/../Stubs/schemas/test_orders.php', $dir . '/test_orders.php');
        $this->app->forgetInstance(SchemaRegistry::class);

        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('customer_name');
            $table->decimal('amount', 12, 2);
            $table->string('status');
        });

        try {
            $exit = Artisan::call('jeeves:doctor', ['--skip-api' => true]);
            $output = Artisan::output();

            $this->assertStringContainsString('shipped template', $output, 'the template went unmentioned');
            $this->assertStringNotContainsString('No usable schema files', $output);
            $this->assertSame(0, $exit, 'install + discover must be a clean bill of health');
        } finally {
            @unlink($dir . '/example.php');
            @unlink($dir . '/test_orders.php');
            @rmdir($dir);
        }
    }

    /**
     * The reason the registry drops it at all.
     *
     * On a fresh install the template was a selectable dataset whose table is
     * a placeholder, so a plain "total amount" chose it perhaps half the time
     * and came back "Database query failed: no such table: schema" -  a name
     * appearing nowhere the user has ever looked. Found with DeepSeek; Gemini
     * had simply been picking the other one.
     */
    #[Test]
    public function the_engine_is_never_offered_the_template_as_a_dataset()
    {
        $dir = $this->useShippedTemplate();
        copy(__DIR__ . '/../Stubs/schemas/test_orders.php', $dir . '/test_orders.php');
        $this->app->forgetInstance(SchemaRegistry::class);

        try {
            $registry = $this->app->make(SchemaRegistry::class);
            $keys = array_column($registry->getAvailableDatasets(), 'key');

            $this->assertNotContains('example', $keys, 'the model can still pick the template');
            $this->assertContains('test_orders', $keys, 'a real schema was dropped too');
        } finally {
            @unlink($dir . '/example.php');
            @unlink($dir . '/test_orders.php');
            @rmdir($dir);
        }
    }

    /**
     * And it is not counted as a rival dataset. Counting it manufactured an
     * ambiguity between one real dataset and a file that queries nothing.
     */
    #[Test]
    public function the_shipped_template_does_not_count_as_a_competing_dataset()
    {
        $dir = $this->useShippedTemplate();
        copy(__DIR__ . '/../Stubs/schemas/test_orders.php', $dir . '/test_orders.php');
        $this->app->forgetInstance(SchemaRegistry::class);
        config(['jeeves.system_instructions' => '']);

        try {
            Artisan::call('jeeves:doctor', ['--skip-api' => true]);

            $this->assertStringNotContainsString(
                'datasets have their own measures',
                Artisan::output(),
                'the template was counted as a dataset that could answer a question'
            );
        } finally {
            @unlink($dir . '/example.php');
            @unlink($dir . '/test_orders.php');
            @rmdir($dir);
        }
    }

    /**
     * A self-hosted model is not an exception to be special-cased.
     *
     * Only 'ollama', 'localhost' and '127.0.0.1' counted as keyless, so vLLM
     * on a LAN address or LM Studio behind a container name was told its API
     * key was empty -  and doctor exited non-zero -  over a key that service
     * does not want. Hosted was assumed; local was the exception.
     */
    public static function selfHostedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1:8000/v1'],
            'localhost' => ['http://localhost:1234/v1'],
            'private 192.168' => ['http://192.168.1.50:8000/v1'],
            'private 10.x' => ['http://10.0.0.7:8000/v1'],
            'private 172.16' => ['http://172.16.4.2:8000/v1'],
            'container name' => ['http://vllm:8000/v1'],
            'mdns' => ['http://gpubox.local:8000/v1'],
        ];
    }

    #[DataProvider('selfHostedUrls')]
    #[Test]
    public function a_self_hosted_model_is_not_nagged_for_an_api_key(string $baseUrl)
    {
        $this->createStubTables();
        config([
            'jeeves.llm.driver' => 'selfhosted',
            'jeeves.llm.providers.selfhosted' => [
                'base_url' => $baseUrl,
                'model' => 'qwen2.5-coder:14b',
                'api_key' => null,
            ],
        ]);

        $exit = Artisan::call('jeeves:doctor', ['--skip-api' => true]);

        $this->assertStringContainsString('No API key needed', Artisan::output());
        $this->assertSame(0, $exit, "{$baseUrl} was treated as a hosted service");
    }

    #[Test]
    public function a_hosted_service_with_no_key_is_still_a_problem()
    {
        $this->createStubTables();
        config([
            'jeeves.llm.driver' => 'groq',
            'jeeves.llm.providers.groq' => [
                'base_url' => 'https://api.groq.com/openai/v1',
                'model' => 'llama-3.3-70b',
                'api_key' => null,
            ],
        ]);

        $exit = Artisan::call('jeeves:doctor', ['--skip-api' => true]);

        $this->assertStringContainsString('API key is empty', Artisan::output());
        $this->assertSame(1, $exit);
    }

    #[Test]
    public function it_flags_a_default_dataset_that_matches_no_schema()
    {
        $this->createStubTables();
        config(['jeeves.default_dataset' => 'typo_dataset']);

        $this->artisan('jeeves:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain("default_dataset 'typo_dataset'");
    }

    #[Test]
    public function it_warns_when_endpoints_are_public_and_unthrottled()
    {
        $this->createStubTables();
        config(['jeeves.routes.middleware' => ['web']]);

        $this->artisan('jeeves:doctor --skip-api')
            ->expectsOutputToContain('public and unthrottled')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_flags_a_missing_cache_table_when_caching_is_enabled()
    {
        $this->createStubTables();
        config([
            'jeeves.cache.enabled' => true,
            'jeeves.cache.table_name' => 'jeeves_cache',
        ]);

        $this->artisan('jeeves:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain('php artisan migrate');
    }

    #[Test]
    public function it_flags_a_missing_feedback_table_when_feedback_is_enabled()
    {
        $this->createStubTables();
        config(['jeeves.feedback.enabled' => true]);

        $this->artisan('jeeves:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain("Feedback table 'jeeves_feedback' is missing");
    }

    #[Test]
    public function it_notes_when_the_query_cache_is_switched_off()
    {
        $this->createStubTables();
        config(['jeeves.cache.enabled' => false]);

        // Not a failure, but worth surfacing: every question costs an API call,
        // which is what exhausts free-tier quotas.
        $this->artisan('jeeves:doctor --skip-api')
            ->expectsOutputToContain('Query cache disabled')
            ->assertExitCode(0);
    }

    /**
     * A stock Laravel 11/12 app runs on SQLite, and the package used to report
     * "✓ Connected (sqlite …)" and exit 0 while every route died with
     * "Unsupported database driver". SQLite is supported now, so the healthy
     * path is the one to pin: the most common install must simply work.
     */
    #[Test]
    public function a_stock_sqlite_app_is_healthy()
    {
        $this->createStubTables();

        $this->artisan('jeeves:doctor --skip-api')
            ->assertExitCode(0)
            ->expectsOutputToContain('Connected (sqlite');
    }

    /**
     * Connecting is not the same as being usable, and a checkup that says
     * "healthy" about a setup that cannot serve a single query is the worst
     * thing this command can do. Still true for a driver nobody has written an
     * introspector for.
     */
    #[Test]
    public function it_flags_a_driver_it_cannot_introspect_even_though_the_connection_works()
    {
        $this->createStubTables();

        // Point the package at a connection whose driver has no introspector.
        // The connection itself is SQLite so it genuinely opens -  which is the
        // situation being tested: reachable, but not introspectable.
        config([
            'database.connections.nq_unsupported' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'jeeves.sql.introspectors' => ['sqlite' => null],
        ]);

        // One substring per output line: expectsOutputToContain sets up a
        // Mockery expectation per write, and two substrings on the same line
        // would leave the second unconsumed.
        $this->artisan('jeeves:doctor --skip-api')
            ->assertExitCode(1)
            ->expectsOutputToContain("Driver 'sqlite' is connected but Jeeves cannot introspect it")
            ->expectsOutputToContain('sql.database_connection');
    }

    /**
     * The driver check must read the connection Jeeves is configured to
     * use, not blindly the app default -  `sql.database_connection` can point
     * the package at a different one.
     */
    #[Test]
    public function it_checks_the_connection_jeeves_is_configured_to_use()
    {
        $this->createStubTables();

        // NQ-001: naming the app's own connection here is now a doctor
        // PROBLEM, not a configuration. The reader is the separate name.
        config(['jeeves.sql.database_connection' => 'testing_reader']);

        $this->artisan('jeeves:doctor --skip-api')
            ->expectsOutputToContain('connection "testing_reader"')
            ->assertExitCode(0);
    }

    /**
     * NQ-002. A tenant column the package cannot scope by.
     *
     * Generated SQL is DB::select(), so a global scope and a policy are both
     * ignored - the two things a Laravel developer would reach for. Doctor
     * cannot fix that, and must not pretend to; what it can do is say so
     * where the person who can act on it will read it.
     *
     * A WARNING rather than a problem: a shared connection over tenant data
     * is a risk the adopter may have already handled with row-level security,
     * and failing the exit code would make doctor unusable for them.
     */
    #[Test]
    public function it_warns_when_a_schema_has_a_tenant_column_it_cannot_scope_by()
    {
        $this->createStubTables();

        // The table has to exist, or doctor fails on the schema check and the
        // test would be red for a reason that has nothing to do with tenancy.
        Schema::create('tn_orders', function ($table) {
            $table->id();
            $table->string('customer_name');
            $table->integer('tenant_id');
            $table->decimal('amount', 12, 2);
        });

        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/tenant-schemas']);

        $this->artisan('jeeves:doctor --skip-api')
            ->expectsOutputToContain('tenant_id')
            ->assertExitCode(0);
    }

    /**
     * NQ-001. Doctor is where an adopter finds out BEFORE shipping.
     *
     * The identity rule is enforced at execution too, by ExecutionConnection -
     * but that refusal reaches whoever asked a question, and this one reaches
     * the person who can fix it.
     */
    #[Test]
    public function it_fails_when_generated_sql_would_run_on_the_application_connection()
    {
        $this->createStubTables();
        config(['jeeves.sql.database_connection' => config('database.default')]);

        $this->artisan('jeeves:doctor --skip-api')
            ->expectsOutputToContain("application's own connection")
            ->assertExitCode(1);
    }

    #[Test]
    public function it_fails_when_no_connection_is_configured_for_generated_sql()
    {
        $this->createStubTables();
        config(['jeeves.sql.database_connection' => null]);

        $this->artisan('jeeves:doctor --skip-api')
            ->expectsOutputToContain('No connection is configured for generated SQL')
            ->assertExitCode(1);
    }

    /**
     * Found during a fresh-install run on Laravel 12: a new app has no route
     * named 'login' until a starter kit is added, but Laravel's auth
     * middleware redirects guests to exactly that. So the very first request
     * an adopter makes after installing dies with RouteNotFoundException -  a
     * 500 naming neither the middleware nor the missing route -  while doctor
     * happily reported "Protected by auth middleware".
     */
    #[Test]
    public function it_warns_when_auth_middleware_is_on_but_the_app_has_no_login_route()
    {
        $this->createStubTables();
        config(['jeeves.routes.middleware' => ['web', 'auth', 'throttle:60,1']]);

        $this->artisan('jeeves:doctor --skip-api')
            ->expectsOutputToContain("no route named 'login'");
    }
}
