<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\JeevesServiceProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guards what an adopter actually receives from `composer require`.
 *
 * The test suite runs against the working tree; adopters get the dist archive,
 * which is the working tree minus everything marked `export-ignore` in
 * .gitattributes. Those are two different artifacts, and the difference is
 * invisible to every other test here -  a publishable asset that is
 * accidentally export-ignored, or a publish path with a typo, breaks only on
 * a real install.
 *
 * So: every runtime path the ServiceProvider touches must exist AND must
 * survive `git archive`.
 */
class PackagingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function every_path_the_service_provider_references_exists()
    {
        $paths = $this->serviceProviderPaths();

        $this->assertNotEmpty($paths, 'Failed to parse any paths out of the ServiceProvider');

        foreach ($paths as $relative) {
            $this->assertFileOrDirectoryExists(
                $this->root . '/' . $relative,
                "ServiceProvider references '{$relative}', which does not exist"
            );
        }
    }

    #[Test]
    public function no_runtime_asset_is_excluded_from_the_distributed_package()
    {
        $ignored = $this->exportIgnoredPaths();

        foreach ($this->serviceProviderPaths() as $relative) {
            foreach ($ignored as $prefix) {
                $this->assertFalse(
                    $relative === $prefix || str_starts_with($relative, $prefix . '/'),
                    "'{$relative}' is needed at runtime but .gitattributes marks '{$prefix}' export-ignore -  "
                    . 'it would be missing after `composer require`'
                );
            }
        }
    }

    #[Test]
    public function development_only_files_are_kept_out_of_the_distributed_package()
    {
        $ignored = $this->exportIgnoredPaths();

        foreach ([
            'tests', '.github', 'phpunit.xml',
            // Tooling and contributor docs. Small, but an adopter downloads
            // them into vendor/ on every install and can do nothing with them.
            'pint.json', 'phpstan.neon.dist', 'CONTRIBUTING.md', 'SECURITY.md',
        ] as $devPath) {
            $this->assertContains(
                $devPath,
                $ignored,
                "'{$devPath}' should be export-ignore'd so it is not shipped to adopters"
            );
        }
    }

    #[Test]
    public function the_declared_license_is_backed_by_a_license_file()
    {
        $composer = $this->composerJson();

        $this->assertSame('MIT', $composer['license'] ?? null);
        $this->assertFileExists($this->root . '/LICENSE', 'composer.json declares a license but no LICENSE file ships');
        $this->assertStringContainsString('MIT License', file_get_contents($this->root . '/LICENSE'));

        // A LICENSE that gets export-ignored is the same as no LICENSE.
        $this->assertNotContains('LICENSE', $this->exportIgnoredPaths());
    }

    #[Test]
    public function the_autoloaded_directories_and_registered_provider_resolve()
    {
        $composer = $this->composerJson();

        foreach ($composer['autoload']['psr-4'] ?? [] as $namespace => $dir) {
            $this->assertDirectoryExists($this->root . '/' . rtrim($dir, '/'), "PSR-4 dir for {$namespace} is missing");
        }

        $providers = $composer['extra']['laravel']['providers'] ?? [];
        $this->assertContains(JeevesServiceProvider::class, $providers);

        foreach ($providers as $provider) {
            $this->assertTrue(class_exists($provider), "Auto-discovered provider {$provider} does not exist");
        }

        foreach ($composer['extra']['laravel']['aliases'] ?? [] as $alias => $class) {
            $this->assertTrue(class_exists($class), "Auto-discovered alias {$alias} points at a missing class");
        }
    }

    #[Test]
    public function the_widget_asset_served_by_the_route_is_the_one_that_ships()
    {
        // The widget is served straight from the package (no publish step), so
        // its absence from the dist would 500 every page using the component.
        $widget = $this->root . '/resources/js/jeeves-widget.js';

        $this->assertFileExists($widget);

        $controllers = implode('', array_map(
            'file_get_contents',
            glob($this->root . '/src/Http/Controllers/*.php') ?: []
        ));

        $this->assertStringContainsString(
            'resources/js/jeeves-widget.js',
            file_get_contents($this->root . '/routes/api.php') . $controllers,
            'No route or controller reads the widget file -  the {prefix}/widget.js promise may be broken'
        );
    }

    // ------------------------------------------------------------------

    /**
     * Package-root-relative paths the ServiceProvider reaches for, parsed from
     * its `__DIR__ . '/../…'` references.
     *
     * @return string[]
     */
    private function serviceProviderPaths(): array
    {
        $source = file_get_contents($this->root . '/src/JeevesServiceProvider.php');

        preg_match_all("#__DIR__\s*\.\s*'/\.\./([^']+)'#", $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Top-level paths .gitattributes keeps out of the dist archive.
     *
     * @return string[]
     */
    private function exportIgnoredPaths(): array
    {
        $lines = file($this->root . '/.gitattributes', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $ignored = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, 'export-ignore')) {
                continue;
            }

            $ignored[] = trim(strtok($line, " \t"), '/');
        }

        return $ignored;
    }

    private function composerJson(): array
    {
        return json_decode(file_get_contents($this->root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertFileOrDirectoryExists(string $path, string $message): void
    {
        $this->assertTrue(file_exists($path), $message);
    }
}
