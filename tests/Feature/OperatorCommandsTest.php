<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Cache\TwoTierQueryCache;
use Jayanta\Jeeves\Console\DoctorCommand;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The three operator-facing additions.
 *
 * Each answers a question an adopter could not previously answer from inside
 * the package: is my config current, is my model good enough, and is the cache
 * doing anything.
 */
class OperatorCommandsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/single-dataset-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
    }

    /**
     * Config drift. Laravel merges package config one level deep, so a
     * published block from an older version hides every key added inside it
     * since -  silently, with no error to search for. Two settings hit this in
     * 2.1.0 alone.
     */
    #[Test]
    public function it_detects_settings_missing_from_a_published_config()
    {
        $doctor = new DoctorCommand;
        $missing = (fn () => $this->missingKeys(
            ['prompts' => ['system_role' => null, 'max_chars' => null], 'cache' => ['enabled' => true]],
            ['prompts' => ['system_role' => null], 'cache' => ['enabled' => true]]
        ))->call($doctor);

        $this->assertSame(
            ['prompts.max_chars'],
            $missing,
            'a key the package ships and the published config lacks was not reported, so an adopter sets '
                . 'its env var and nothing happens'
        );
    }

    /** A differing VALUE is not drift -  that is the whole point of publishing. */
    #[Test]
    public function a_customised_value_is_not_reported_as_drift()
    {
        $doctor = new DoctorCommand;
        $missing = (fn () => $this->missingKeys(
            ['cache' => ['enabled' => true, 'ttl' => 86400]],
            ['cache' => ['enabled' => false, 'ttl' => 60]]
        ))->call($doctor);

        $this->assertSame([], $missing, 'customising a published value was reported as a missing key');
    }

    /**
     * Model capability. Measured here: 70B-class scores 17/17, Llama 3.1 8B
     * scores 12/17 and drops filters. Someone running an 8B model and seeing
     * wrong answers will blame the package.
     */
    #[Test]
    public function doctor_warns_about_a_small_model()
    {
        config([
            'jeeves.llm.driver' => 'ollama',
            'jeeves.llm.providers.ollama.model' => 'llama3.1:8b',
        ]);

        Artisan::call('jeeves:doctor', ['--skip-api' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('llama3.1:8b', $out);
        $this->assertStringContainsString('12B or under', $out,
            'doctor did not warn about a model size that measured badly here');
    }

    /** And stays quiet about one that measured fine. */
    #[Test]
    public function doctor_does_not_warn_about_a_capable_model()
    {
        config([
            'jeeves.llm.driver' => 'gemini',
            'jeeves.llm.providers.gemini.model' => 'gemini-2.5-flash',
        ]);

        Artisan::call('jeeves:doctor', ['--skip-api' => true]);

        $this->assertStringNotContainsString('12B or under', Artisan::output(),
            'doctor warned about a model that measured 17/17 here');
    }

    /** Cache stats: nothing cached yet must not look like a failure. */
    #[Test]
    public function cache_stats_reports_an_empty_cache_plainly()
    {
        $this->artisan('migrate', ['--force' => true])->run();

        $this->artisan('jeeves:cache-stats')
            ->expectsOutputToContain('Nothing cached yet')
            ->assertExitCode(0);
    }

    /** And counts reuse the way an operator would: asks avoided, not a ratio. */
    #[Test]
    public function cache_stats_counts_reuse()
    {
        $this->artisan('migrate', ['--force' => true])->run();

        $cache = $this->app->make(TwoTierQueryCache::class);
        $cache->store('total revenue', ['dataset' => 'nq_orders', 'metric' => 'revenue', '_asking_scope' => 'nq_orders']);

        // One row written on a miss (hit_count 1), then reused three times.
        $table = config('jeeves.cache.table_name', 'jeeves_cache');
        DB::table($table)->update(['hit_count' => 4]);

        $this->artisan('jeeves:cache-stats')
            ->expectsOutputToContain('Distinct questions cached   1')
            ->expectsOutputToContain('Answers reused              3')
            ->assertExitCode(0);
    }

    /** --json stays parseable, for anyone wiring it into monitoring. */
    #[Test]
    public function cache_stats_can_emit_json()
    {
        $this->artisan('migrate', ['--force' => true])->run();

        $this->artisan('jeeves:cache-stats', ['--json' => true])->assertExitCode(0);
    }
}
