<?php

namespace Jayanta\Jeeves\Console;

use Illuminate\Console\Command;

/**
 * Install Command
 *
 * Sets up Jeeves in a Laravel project:
 * 1. Publishes config file
 * 2. Publishes migrations
 * 3. Creates schema directory
 * 4. Copies example schema
 * 5. Runs migrations (optional)
 */
class InstallCommand extends Command
{
    protected $signature = 'jeeves:install
                            {--migrate : Run migrations after install}
                            {--force : Overwrite existing files}';

    protected $description = 'Install Jeeves - publish config, migrations, and create schema directory';

    public function handle(): int
    {
        $this->info('Installing Jeeves...');
        $this->newLine();

        // Step 1: Publish config
        $this->comment('Publishing configuration...');
        $this->callSilently('vendor:publish', [
            '--tag' => 'jeeves-config',
            '--force' => $this->option('force'),
        ]);
        $this->line('  ✓ Config published to config/jeeves.php');

        // Step 2: Publish migrations
        $this->comment('Publishing migrations...');
        $this->callSilently('vendor:publish', [
            '--tag' => 'jeeves-migrations',
            '--force' => $this->option('force'),
        ]);
        $this->line('  ✓ Migrations published');

        // Step 3: Create schema directory
        $schemaPath = config('jeeves.schema.config_path', config_path('jeeves-schemas'));

        if (!is_dir($schemaPath)) {
            mkdir($schemaPath, 0755, true);
            $this->line("  ✓ Schema directory created: {$schemaPath}");
        } else {
            $this->line("  - Schema directory already exists: {$schemaPath}");
        }

        // Step 4: Copy example schema
        $exampleDest = $schemaPath . '/example.php';
        $exampleSrc = dirname(__DIR__, 2) . '/stubs/schema-example.php';

        if (!file_exists($exampleDest) || $this->option('force')) {
            if (file_exists($exampleSrc)) {
                copy($exampleSrc, $exampleDest);
                $this->line('  ✓ Example schema created: config/jeeves-schemas/example.php');
            }
        } else {
            $this->line('  - Example schema already exists (use --force to overwrite)');
        }

        // Step 5: Run migrations (optional)
        if ($this->option('migrate')) {
            $this->comment('Running migrations...');
            $this->call('migrate');
        }

        $this->newLine();
        $this->info('Jeeves installed successfully!');
        $this->newLine();
        // Step 1 named GEMINI_API_KEY, which is the first thing every new
        // adopter reads and quietly made one vendor the default for everyone.
        // The package works the same on a model running on your own machine,
        // and an installer that mentions only a hosted API is choosing for
        // people who have not been told there is a choice.
        $this->line('Next steps:');
        $this->line('  1. Choose an LLM in .env -  a hosted API or one you run yourself:');
        $this->line('       JEEVES_LLM_DRIVER=ollama      (local, no API key)');
        $this->line('       JEEVES_LLM_DRIVER=gemini      GEMINI_API_KEY=…');
        $this->line('       JEEVES_LLM_DRIVER=openai      OPENAI_API_KEY=…');
        $this->line('       JEEVES_LLM_DRIVER=claude      ANTHROPIC_API_KEY=…');
        $this->line('     Any OpenAI-compatible service (DeepSeek, Groq, vLLM, LM Studio,');
        $this->line('     LocalAI, llama.cpp) works by adding an llm.providers block.');
        $this->line('  2. Run migrations: php artisan migrate');
        // Listed here because without it step 6 fails on every fresh install.
        // An earlier release walked people to "try it" and left this out -
        // doctor caught it at step 5, but the first thing an adopter reads
        // should not lead them into a wall the package already knows about.
        $this->line('  3. Give generated SQL its own connection - REQUIRED:');
        $this->line("       'sql' => ['database_connection' => 'jeeves'],");
        $this->line('     It is written by a language model, so it does not run on the');
        $this->line('     connection your app writes with. Point it at a database user');
        $this->line('     holding SELECT only. docs/CONNECTION.md has the GRANT statements.');
        $this->line('  4. Generate a schema from your database: php artisan jeeves:discover');
        $this->line('  5. Check everything is wired up: php artisan jeeves:doctor');
        $this->line('  6. Try it: Jeeves::query("show me the top 10 items")');
        $this->newLine();
        $this->line('  Stuck at any point? php artisan jeeves:doctor tells you');
        $this->line('  what is wrong and exactly how to fix it.');

        return self::SUCCESS;
    }
}
