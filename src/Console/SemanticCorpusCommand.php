<?php

namespace Jayanta\Jeeves\Console;

use Illuminate\Console\Command;
use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * Write the corpus an embedding service needs, from THIS install's schemas.
 *
 * The matching service ranks a question against a set of short descriptions,
 * one per dataset, embedded when it starts. Those descriptions have to be the
 * ones this application actually has - orders and tickets here, patients and
 * claims there, welfare schemes somewhere else. A corpus that ships with the
 * package would describe none of them, and a hand-written one goes stale the
 * first time somebody edits a schema file.
 *
 * So it is generated. The schema files are already the single source of truth
 * for what the model is told; this makes them the single source of truth for
 * what the matcher is told as well.
 *
 * WHAT GOES IN THE TEXT. Name, description, the aliases users say instead of
 * the name, and the column aliases - the vocabulary of the dataset rather than
 * its structure. Column aliases matter more than they look: "how many houses
 * were built" reaches a housing dataset largely because somebody wrote
 * `houses` next to a column, and a corpus of bare table names ranks almost
 * nothing correctly.
 *
 * Rule 2. Metadata only, and only what already goes to the LLM in every
 * prompt: names, descriptions, aliases. No rows, no values, no sample data.
 * Nothing here reads the database at all.
 */
class SemanticCorpusCommand extends Command
{
    protected $signature = 'jeeves:semantic-corpus
                            {--output= : Where to write it (default: storage/app/jeeves/semantic-corpus.json)}
                            {--stdout : Print the JSON instead of writing a file}';

    protected $description = 'Generate the dataset corpus for a semantic matching service';

    public function handle(SchemaRegistry $registry): int
    {
        $datasets = $registry->all();

        if ($datasets === []) {
            $this->error('No schema files found, so there is nothing to describe.');
            $this->line('Run <info>php artisan jeeves:discover</info> first, or check schema.config_path.');

            return self::FAILURE;
        }

        $corpus = [
            // The service reads `schemes`; these three are for whoever opens
            // the file in six months wondering where it came from.
            'generated_by' => 'php artisan jeeves:semantic-corpus',
            'source' => (string) config('jeeves.schema.config_path'),
            'count' => count($datasets),
            'schemes' => [],
        ];

        foreach ($datasets as $key => $schema) {
            $corpus['schemes'][] = [
                'key' => $key,
                'name' => $schema['name'] ?? $key,
                'text' => $this->describe($key, $schema),
            ];
        }

        $json = json_encode($corpus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->error('Could not encode the corpus: ' . json_last_error_msg());

            return self::FAILURE;
        }

        if ($this->option('stdout')) {
            $this->line($json);

            return self::SUCCESS;
        }

        $path = $this->option('output') ?: storage_path('app/jeeves/semantic-corpus.json');
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->error('Could not create ' . $directory);

            return self::FAILURE;
        }

        if (@file_put_contents($path, $json . "\n") === false) {
            $this->error('Could not write ' . $path);

            return self::FAILURE;
        }

        $this->info('Wrote ' . count($datasets) . ' datasets to ' . $path);
        $this->newLine();
        $this->line('Point your matching service at it and restart the service - the corpus is');
        $this->line('embedded once at startup, so a regenerated file changes nothing until then.');
        $this->newLine();
        $this->line('Regenerate whenever a schema file changes, or the matcher will keep routing');
        $this->line('to a description of your data that is no longer true.');

        return self::SUCCESS;
    }

    /**
     * One line of vocabulary per dataset.
     *
     * Sentences first so the embedding has something to be about, then the
     * words users actually type. Duplicates are dropped case-insensitively -
     * an alias repeated across ten columns would otherwise dominate the vector
     * and pull unrelated questions toward that dataset.
     */
    protected function describe(string $key, array $schema): string
    {
        $parts = [];

        $parts[] = $schema['name'] ?? $key;

        if (!empty($schema['description'])) {
            $parts[] = rtrim((string) $schema['description'], '.');
        }

        foreach ($schema['tables'] ?? [] as $table) {
            if (!empty($table['description'])) {
                $parts[] = rtrim((string) $table['description'], '.');
            }
        }

        $sentences = implode('. ', array_filter($parts));

        $vocabulary = array_merge(
            $schema['aliases'] ?? [],
            $this->columnVocabulary($schema),
        );

        $seen = [];
        $unique = [];

        foreach ($vocabulary as $word) {
            if (!is_string($word)) {
                continue;
            }

            $word = trim($word);
            $fold = mb_strtolower($word);

            if ($word === '' || isset($seen[$fold])) {
                continue;
            }

            $seen[$fold] = true;
            $unique[] = $word;
        }

        return $unique === []
            ? $sentences . '.'
            : $sentences . '. ' . implode(', ', $unique);
    }

    /**
     * The words a schema author wrote next to columns.
     *
     * Column NAMES are deliberately not included. They are identifiers -
     * `dist_cd`, `tot_amt` - and embedding them adds noise in the shape of
     * words nobody says out loud. The aliases beside them are the human
     * vocabulary, which is the whole reason this file is worth generating.
     *
     * @return array<int, string>
     */
    protected function columnVocabulary(array $schema): array
    {
        $words = [];

        foreach ($schema['tables'] ?? [] as $table) {
            foreach ($table['columns'] ?? [] as $column) {
                foreach ($column['aliases'] ?? [] as $alias) {
                    $words[] = $alias;
                }
            }
        }

        foreach ($schema['metrics'] ?? [] as $name => $metric) {
            $words[] = is_array($metric) ? ($metric['label'] ?? $name) : $name;
        }

        return $words;
    }
}
