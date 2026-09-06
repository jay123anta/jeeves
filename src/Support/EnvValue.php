<?php

namespace Jayanta\Jeeves\Support;

/**
 * Numeric settings read from the environment.
 *
 * `FOO=` in a .env file is not an absent value. Laravel hands back the empty
 * string, `??` and `env()`'s own default both see something present, and the
 * empty string travels on to whatever consumes the setting. PHP 8 refuses a
 * non-numeric string for an int or float parameter, so it arrives as a
 * TypeError somewhere far from the line the user edited:
 *
 *   JEEVES_PROMPT_MAX_CHARS=   ->  PromptBudget::__construct(?int)
 *                                        fatals while the container is
 *                                        resolving QueryOrchestrator, so
 *                                        every question 500s.
 *
 *   JEEVES_TIMEOUT=            ->  Http::timeout(int|float) fatals on
 *                                        every provider request, for all
 *                                        seven providers at once.
 *
 * A commented-out line someone half-restored, a value deleted during
 * debugging, a `.env.example` copied and blanked -  all produce it, and none
 * of them look like a misconfiguration to the person who did it.
 *
 * OllamaProvider already guarded its own copy of this, in its constructor,
 * with a comment explaining exactly this trap. That guard protected one
 * setting because it was attached to one reader. Attaching it to the VALUE is
 * what makes it cover the ones nobody has thought of yet.
 *
 * Falling back to the documented default is right here, and is not the
 * reconciliation this package refuses elsewhere: nothing is being guessed on
 * the user's behalf and no answer is being salvaged. The setting was simply
 * never given, and behaving as though it was never given is the honest
 * reading. A wrong ANSWER must fail loudly; an absent SETTING has a default.
 */
final class EnvValue
{
    /**
     * An integer setting, or $default when the variable is absent, empty, or
     * not a number.
     */
    public static function int(string $key, ?int $default = null): ?int
    {
        $raw = env($key);

        return is_numeric($raw) ? (int) $raw : $default;
    }

    /**
     * A float setting, or $default when the variable is absent, empty, or not
     * a number.
     */
    public static function float(string $key, ?float $default = null): ?float
    {
        $raw = env($key);

        return is_numeric($raw) ? (float) $raw : $default;
    }
}
