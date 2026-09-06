<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\PromptBudget;
use Jayanta\Jeeves\Support\EnvValue;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `FOO=` with nothing after it is the empty string, not an absent value.
 *
 * Every numeric setting in config/jeeves.php that reaches a typed
 * parameter is one blank line in .env away from a fatal:
 *
 *   JEEVES_PROMPT_MAX_CHARS=  -> PromptBudget::__construct(?int) fatals
 *                                      resolving QueryOrchestrator, so every
 *                                      query 500s with a TypeError naming a
 *                                      class the user has never heard of.
 *   JEEVES_TIMEOUT=           -> Http::timeout(int|float) fatals on
 *                                      every request, in all seven providers.
 *
 * OllamaProvider guarded exactly this for num_ctx, in its own constructor,
 * with a comment describing the trap. The guard covered one setting because
 * it was attached to one reader.
 */
class EmptyEnvValueDoesNotFatalTest extends TestCase
{
    /** Values a .env can actually hold, and what each should resolve to. */
    public static function envValues(): array
    {
        return [
            'absent' => [null,     30],
            'present but empty' => ['',       30],
            'whitespace only' => ['   ',    30],
            'not a number' => ['thirty', 30],
            'a number' => ['45',     45],
            'a quoted number' => ['60',     60],
            'zero' => ['0',      0],
        ];
    }

    #[DataProvider('envValues')]
    #[Test]
    public function an_unusable_env_value_falls_back_to_the_documented_default(?string $raw, int $expected)
    {
        if ($raw === null) {
            unset($_ENV['NQ_PROBE_INT'], $_SERVER['NQ_PROBE_INT']);
        } else {
            $_ENV['NQ_PROBE_INT'] = $_SERVER['NQ_PROBE_INT'] = $raw;
        }

        $this->assertSame($expected, EnvValue::int('NQ_PROBE_INT', 30));

        unset($_ENV['NQ_PROBE_INT'], $_SERVER['NQ_PROBE_INT']);
    }

    /** Unbounded stays unbounded -  the default is null, not zero. */
    #[Test]
    public function an_empty_value_for_an_unbounded_setting_stays_null()
    {
        $_ENV['NQ_PROBE_NULLABLE'] = $_SERVER['NQ_PROBE_NULLABLE'] = '';

        $this->assertNull(EnvValue::int('NQ_PROBE_NULLABLE', null));

        unset($_ENV['NQ_PROBE_NULLABLE'], $_SERVER['NQ_PROBE_NULLABLE']);
    }

    /**
     * The point of the whole exercise: the shipped config, fed a blank value,
     * must produce something the typed constructors below it accept.
     */
    #[Test]
    public function the_shipped_config_survives_blank_values_end_to_end()
    {
        foreach (['JEEVES_PROMPT_MAX_CHARS', 'JEEVES_TIMEOUT'] as $key) {
            $_ENV[$key] = $_SERVER[$key] = '';
        }

        $config = require __DIR__ . '/../../config/jeeves.php';

        // PromptBudget takes ?int and fatals on a string.
        $budget = new PromptBudget($config['prompts']['max_chars']);
        $this->assertInstanceOf(PromptBudget::class, $budget);

        // Http::timeout() takes int|float and fatals on a string. Check every
        // provider's configured timeout, not just the first.
        foreach ($config['llm']['providers'] as $name => $provider) {
            if (!array_key_exists('timeout', $provider)) {
                continue;
            }

            $this->assertIsNumeric(
                $provider['timeout'],
                "provider [{$name}] would pass a non-numeric timeout to Http::timeout() and fatal every request"
            );
        }

        foreach (['JEEVES_PROMPT_MAX_CHARS', 'JEEVES_TIMEOUT'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }
}
