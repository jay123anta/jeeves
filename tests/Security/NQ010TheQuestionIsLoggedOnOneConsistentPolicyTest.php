<?php

namespace Jayanta\Jeeves\Tests\Security;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Cache\TwoTierQueryCache;
use Jayanta\Jeeves\Security\InputGuard;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-010 - the question reached the log nine different ways.
 *
 * A question carries whatever the person typed into it: a customer's name, an
 * account number, a diagnosis. It is written to the application log at nine
 * places across four files, and no two of them agreed on how much:
 *
 *   InputGuard, five sites            200 characters
 *   QueryOrchestrator, guard block    100 characters
 *   QueryOrchestrator, two others     the whole thing
 *   TwoTierQueryCache, cache miss     the whole thing
 *
 * Nobody chose that. It accumulated, one call site at a time, which is this
 * project's signature defect wearing different clothes: a policy that lives at
 * the call sites is not a policy, it is a collection of accidents.
 *
 * The decision recorded here is that questions ARE logged - they are the single
 * most useful line in the log when someone reports a wrong answer, and removing
 * them by default would cost every adopter that for the benefit of a few. What
 * changes is that it happens once, to one length, and can be switched off in
 * one place by an install under a data regime that forbids it.
 */
class NQ010TheQuestionIsLoggedOnOneConsistentPolicyTest extends TestCase
{
    /** Long enough that any of the old truncations would cut it differently. */
    private const QUESTION = 'total revenue for Rekha-Stores-CONFIDENTIAL-9f21 in Guwahati during July 2026 broken down by category and then by carrier and then by region and then by status and then by client and by month as well please';

    /** The tail, which only an untruncated log would contain. */
    private const TAIL = 'and by month as well please';

    /**
     * The cache table has to exist, or `findForDataset()` throws and degrades
     * to a warning instead of reaching the `Cache miss` line - and that line is
     * one of the two sites that logged the question WHOLE. Without this the
     * first test below passes on InputGuard's existing 200-character cut and
     * proves nothing about the sites that had no cut at all.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->run();
    }

    /** @return array<int, string> every scalar written to the log */
    private function captureLogWhile(callable $work): array
    {
        $written = [];

        Log::listen(function (MessageLogged $entry) use (&$written) {
            array_walk_recursive($entry->context, function ($value) use (&$written) {
                if (is_scalar($value)) {
                    $written[] = (string) $value;
                }
            });
        });

        $work();

        return $written;
    }

    #[Test]
    public function no_site_writes_the_whole_question_to_the_log(): void
    {
        $written = $this->captureLogWhile(function () {
            (new TwoTierQueryCache)->findForDataset(self::QUESTION, null);
            (new InputGuard)->validate(self::QUESTION . ' ; DROP TABLE users --');
        });

        $this->assertNotSame([], $written, 'nothing was logged, so this test observed nothing');

        $untruncated = array_values(array_filter(
            $written,
            static fn (string $line) => str_contains($line, self::TAIL)
        ));

        $this->assertSame(
            [],
            $untruncated,
            "The whole question reached the log:\n  " . implode("\n  ", $untruncated)
        );
    }

    #[Test]
    public function the_question_can_be_kept_out_of_the_log_entirely(): void
    {
        config(['jeeves.logging.log_question' => false]);

        $written = $this->captureLogWhile(function () {
            (new TwoTierQueryCache)->findForDataset(self::QUESTION, null);
            (new InputGuard)->validate(self::QUESTION . ' ; DROP TABLE users --');
        });

        $this->assertNotSame([], $written, 'nothing was logged, so this test observed nothing');

        $leaked = array_values(array_filter(
            $written,
            static fn (string $line) => str_contains($line, 'Rekha-Stores-CONFIDENTIAL-9f21')
        ));

        $this->assertSame(
            [],
            $leaked,
            "log_question was false and the question was logged anyway:\n  "
            . implode("\n  ", $leaked)
        );
    }

    /**
     * Counterweight. Switching it off must not blank the log line itself - the
     * entry still has to say what happened, or turning this on becomes the only
     * way to debug anything and nobody leaves it off.
     */
    #[Test]
    public function switching_it_off_leaves_the_rest_of_the_entry_intact(): void
    {
        config(['jeeves.logging.log_question' => false]);

        $written = $this->captureLogWhile(function () {
            (new InputGuard)->validate(self::QUESTION . ' ; DROP TABLE users --');
        });

        $joined = implode(' ', $written);

        $this->assertNotSame('', trim($joined), 'the log entry lost its context entirely');
    }

    /**
     * And with the default configuration - which is what every existing install
     * has - the question is still there, just bounded.
     */
    #[Test]
    public function the_default_still_logs_the_question(): void
    {
        $written = $this->captureLogWhile(function () {
            (new InputGuard)->validate(self::QUESTION . ' ; DROP TABLE users --');
        });

        $joined = implode(' ', $written);

        $this->assertStringContainsString(
            'total revenue for Rekha-Stores',
            $joined,
            'the default stopped logging the question, which removes the most useful '
            . 'line in the log when somebody reports a wrong answer'
        );
    }
}
