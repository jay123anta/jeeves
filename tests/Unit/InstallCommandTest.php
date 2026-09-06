<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The installer's closing advice is the first thing anyone reads, and most of
 * them will copy it verbatim. It is documentation that happens to be code, so
 * Rule 1 applies to it as much as to the README.
 */
class InstallCommandTest extends TestCase
{
    private ?string $sandbox = null;

    /** Files the installer created that were not there before. */
    private array $created = [];

    /**
     * Run the installer, then put everything back.
     *
     * This command writes real files, and in Testbench `config_path()` and
     * `database_path()` point INSIDE vendor/orchestra/testbench-core -  a
     * directory that survives between runs. Two things had already gone wrong
     * because of that:
     *
     *   1. The example schema landed in tests/Stubs/schemas, so a later case
     *      counted three datasets where it expected two.
     *   2. A published config/jeeves.php sat in the skeleton and SHADOWED
     *      the package config for the entire suite. Every test after it ran
     *      against a stale snapshot -  which is how a changed middleware default
     *      went unnoticed until a test asserted on it directly.
     *
     * The second is the dangerous kind: nothing failed, the suite just stopped
     * testing the current code. So the installer runs against a temp schema
     * directory, and anything else it creates is recorded and deleted.
     */
    private function install(): string
    {
        $this->sandbox = sys_get_temp_dir() . '/nq-install-' . getmypid();
        config(['jeeves.schema.config_path' => $this->sandbox]);

        $publishes = [config_path('jeeves.php')];
        foreach (glob(database_path('migrations') . '/*jeeves*') ?: [] as $existing) {
            $publishes[] = $existing;
        }

        $before = array_filter($publishes, 'file_exists');

        Artisan::call('jeeves:install');
        $output = Artisan::output();

        foreach (array_merge(
            [config_path('jeeves.php')],
            glob(database_path('migrations') . '/*jeeves*') ?: []
        ) as $path) {
            if (file_exists($path) && !in_array($path, $before, true)) {
                $this->created[] = $path;
            }
        }

        return $output;
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }
        $this->created = [];

        if ($this->sandbox && is_dir($this->sandbox)) {
            foreach (glob($this->sandbox . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->sandbox);
        }

        parent::tearDown();
    }

    /**
     * A key name that does not match the config sends the new user to set an
     * environment variable nothing reads, and the failure that follows -
     * "API key is empty" -  points at the variable they just set. The installer
     * said CLAUDE_API_KEY while the config read ANTHROPIC_API_KEY.
     */
    #[Test]
    public function every_api_key_it_names_is_one_the_config_actually_reads()
    {
        preg_match_all('/\b([A-Z][A-Z0-9_]*_API_KEY)\b/', $this->install(), $matches);

        $named = array_unique($matches[1]);
        $this->assertNotEmpty($named, 'no key names found -  the installer text changed shape');

        $config = (string) file_get_contents(__DIR__ . '/../../config/jeeves.php');

        foreach ($named as $envVar) {
            $this->assertStringContainsString(
                "env('{$envVar}')",
                $config,
                "the installer tells people to set {$envVar}, which config/jeeves.php never reads"
            );
        }
    }

    /**
     * Running a model yourself must be presented as an ordinary choice.
     *
     * Step 1 used to name GEMINI_API_KEY and nothing else, which made one
     * hosted vendor the default for every new install -  an accident of where
     * this package was extracted from, not a decision anyone made. Plenty of
     * adopters run Ollama or a self-hosted server, and some cannot send data
     * to a third party at all.
     */
    #[Test]
    public function it_offers_a_local_model_alongside_the_hosted_ones()
    {
        $output = $this->install();

        $this->assertStringContainsString('ollama', $output, 'no local option offered');
        $this->assertStringContainsString('no API key', $output, 'the keyless path is not mentioned');
        $this->assertStringContainsString('OpenAI-compatible', $output, 'self-hosted servers are not mentioned');
    }

    #[Test]
    public function it_points_at_doctor_for_whatever_goes_wrong_next()
    {
        $this->assertStringContainsString('jeeves:doctor', $this->install());
    }

    /**
     * 3.0.0 shipped without this line, and no test here found it. Installing
     * the BUILT package into a clean Laravel app did.
     *
     * The installer walked an adopter through five steps to "try it" and never
     * mentioned the one setting that release made required, so the last step
     * failed on every fresh install. Doctor caught it one step earlier, which
     * is why that was a patch and not a recall - but the first thing somebody
     * reads should not lead them into a wall the package already knows about.
     */
    #[Test]
    public function it_names_the_connection_that_generated_sql_requires()
    {
        $output = $this->install();

        $this->assertStringContainsString(
            'database_connection',
            $output,
            'the installer does not mention the setting without which no question can be answered'
        );

        $this->assertStringContainsString(
            'docs/CONNECTION.md',
            $output,
            'the installer names the setting but not where the GRANT statements are'
        );

        // And before the step that cannot work without it.
        $this->assertLessThan(
            strpos($output, 'Try it'),
            strpos($output, 'database_connection'),
            'the connection is mentioned AFTER the step that fails without it'
        );
    }
}
