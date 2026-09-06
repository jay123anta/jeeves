<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Jayanta\Jeeves\LlmProviders\AbstractProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Backoff must stay bounded: a provider outage may not pin a PHP worker.
 * These tests assert the schedule without ever really sleeping.
 */
class RetryBackoffTest extends TestCase
{
    private function provider(int $maxRetries = 3, array $retryConfig = []): object
    {
        config(['jeeves.retry' => array_merge([
            'base_delay_ms' => 250,
            'max_delay_ms' => 2000,
            'total_budget_ms' => 4000,
            'respect_retry_after' => true,
            'jitter' => false, // deterministic assertions
        ], $retryConfig)]);

        return new class(['max_retries' => $maxRetries]) extends AbstractProvider
        {
            /** @var int[] Milliseconds passed to sleepMs(), in order */
            public array $slept = [];

            public function getName(): string
            {
                return 'test';
            }

            protected function sleepMs(int $milliseconds): void
            {
                $this->slept[] = $milliseconds; // never actually sleep
            }

            public function attemptWait(int $attempt, ?int $retryAfter = null): ?int
            {
                return $this->waitBeforeRetry($attempt, $retryAfter);
            }

            public function delayFor(int $attempt, ?int $retryAfter = null): int
            {
                return $this->backoffDelayMs($attempt, $retryAfter);
            }

            public function retryAfterSeconds($header): ?int
            {
                return $this->parseRetryAfter($header);
            }

            public function call(): array
            {
                return $this->callWithRetry('https://provider.test/v1/generate', ['prompt' => 'x']);
            }
        };
    }

    #[Test]
    public function backoff_grows_exponentially_from_the_configured_base()
    {
        $p = $this->provider();

        $this->assertSame(250, $p->delayFor(1));
        $this->assertSame(500, $p->delayFor(2));
        $this->assertSame(1000, $p->delayFor(3));
    }

    #[Test]
    public function a_single_wait_never_exceeds_max_delay()
    {
        $p = $this->provider(10, ['max_delay_ms' => 800]);

        $this->assertSame(800, $p->delayFor(5));
        $this->assertSame(800, $p->delayFor(50));
    }

    #[Test]
    public function jitter_stays_within_half_range_and_never_reaches_zero()
    {
        $p = $this->provider(5, ['jitter' => true]);

        for ($i = 0; $i < 25; $i++) {
            $delay = $p->delayFor(3); // nominal 1000ms
            $this->assertGreaterThanOrEqual(500, $delay);
            $this->assertLessThanOrEqual(1000, $delay);
        }
    }

    #[Test]
    public function it_never_sleeps_after_the_final_attempt()
    {
        $p = $this->provider(3);

        $this->assertNotNull($p->attemptWait(1));
        $this->assertNotNull($p->attemptWait(2));
        // Attempt 3 of 3 -  nothing follows it, so sleeping is pure waste
        $this->assertNull($p->attemptWait(3));
        $this->assertSame([250, 500], $p->slept);
    }

    #[Test]
    public function cumulative_waiting_is_capped_by_the_total_budget()
    {
        // Budget only covers the first two waits (250 + 500 = 750)
        $p = $this->provider(10, ['total_budget_ms' => 800]);

        $this->assertSame(250, $p->attemptWait(1));
        $this->assertSame(500, $p->attemptWait(2));
        // Third would need 1000ms more → over budget → stop retrying
        $this->assertNull($p->attemptWait(3));
        $this->assertSame([250, 500], $p->slept);
        $this->assertSame(750, array_sum($p->slept));
    }

    #[Test]
    public function worst_case_wait_stays_under_one_second_by_default()
    {
        // Regression guard for the original bug: blocking sleep(2)+sleep(4)
        // meant ~6s per API call, and up to ~19s per user question.
        $p = $this->provider(3);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $p->attemptWait($attempt);
        }

        $this->assertLessThan(1000, array_sum($p->slept));
    }

    #[Test]
    public function retry_after_header_is_honoured_when_it_fits_the_budget()
    {
        $p = $this->provider(3, ['total_budget_ms' => 5000]);

        $this->assertSame(2000, $p->attemptWait(1, 2)); // Retry-After: 2
        $this->assertSame([2000], $p->slept);
    }

    #[Test]
    public function an_oversized_retry_after_fails_fast_instead_of_half_waiting()
    {
        $p = $this->provider(3, ['total_budget_ms' => 4000]);

        // Provider says "come back in 60s" -  waiting 4s helps nobody
        $this->assertNull($p->attemptWait(1, 60));
        $this->assertSame([], $p->slept);
    }

    #[Test]
    public function retry_after_can_be_ignored_by_config()
    {
        $p = $this->provider(3, ['respect_retry_after' => false]);

        // Falls back to the computed backoff rather than the 60s hint
        $this->assertSame(250, $p->attemptWait(1, 60));
    }

    #[Test]
    public function it_parses_both_retry_after_formats()
    {
        $p = $this->provider();

        $this->assertSame(30, $p->retryAfterSeconds('30'));
        $this->assertSame(30, $p->retryAfterSeconds(' 30 '));
        $this->assertNull($p->retryAfterSeconds(null));
        $this->assertNull($p->retryAfterSeconds(''));
        $this->assertNull($p->retryAfterSeconds('not-a-date'));

        // HTTP-date form → seconds from now (allow a second of clock drift)
        $httpDate = gmdate('D, d M Y H:i:s \G\M\T', time() + 10);
        $this->assertEqualsWithDelta(10, $p->retryAfterSeconds($httpDate), 1);

        // A date in the past must never yield a negative wait
        $past = gmdate('D, d M Y H:i:s \G\M\T', time() - 500);
        $this->assertSame(0, $p->retryAfterSeconds($past));
    }

    #[Test]
    public function a_zero_budget_disables_the_cumulative_cap()
    {
        $p = $this->provider(5, ['total_budget_ms' => 0, 'max_delay_ms' => 100000]);

        $this->assertSame(250, $p->attemptWait(1));
        $this->assertSame(500, $p->attemptWait(2));
        $this->assertSame(1000, $p->attemptWait(3));
        $this->assertSame(2000, $p->attemptWait(4));
    }

    // =====================================================================
    // End-to-end through callWithRetry() with a faked HTTP layer
    // =====================================================================

    #[Test]
    public function it_retries_a_rate_limited_call_then_reports_status_429()
    {
        Http::fake(['*' => Http::response(['error' => 'quota'], 429)]);
        $p = $this->provider(3);

        $result = $p->call();

        $this->assertFalse($result['success']);
        $this->assertSame(429, $result['status']);
        Http::assertSentCount(3);          // all attempts used
        $this->assertSame([250, 500], $p->slept); // no sleep after the last
    }

    #[Test]
    public function it_stops_early_when_retry_after_exceeds_the_budget()
    {
        Http::fake(['*' => Http::response(['error' => 'quota'], 429, ['Retry-After' => '120'])]);
        $p = $this->provider(3);

        $result = $p->call();

        $this->assertSame(429, $result['status']);
        // Failed fast on the FIRST response -  no pointless second/third call
        Http::assertSentCount(1);
        $this->assertSame([], $p->slept);
    }

    #[Test]
    public function it_retries_transient_5xx_and_succeeds_on_a_later_attempt()
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => 'bad gateway'], 502)
            ->push(['ok' => true], 200)]);
        $p = $this->provider(3);

        $result = $p->call();

        $this->assertTrue($result['success']);
        $this->assertSame(['ok' => true], $result['data']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_does_not_retry_client_errors()
    {
        // A bad API key returns 401 every time -  retrying just wastes time
        Http::fake(['*' => Http::response(['error' => 'invalid key'], 401)]);
        $p = $this->provider(3);

        $result = $p->call();

        $this->assertFalse($result['success']);
        $this->assertSame(401, $result['status']);
        Http::assertSentCount(1);
        $this->assertSame([], $p->slept);
    }

    #[Test]
    public function an_exhausted_5xx_retry_still_reports_its_status()
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $p = $this->provider(2);

        $result = $p->call();

        $this->assertFalse($result['success']);
        $this->assertSame(503, $result['status']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_falls_back_to_safe_defaults_when_config_is_missing()
    {
        // Apps that published config/jeeves.php before this block existed
        config(['jeeves.retry' => null]);

        $p = new class(['max_retries' => 3]) extends AbstractProvider
        {
            public function getName(): string
            {
                return 'test';
            }

            public function delayFor(int $attempt): int
            {
                return $this->backoffDelayMs($attempt);
            }
        };

        // Defaults have jitter on, so assert the bounded range
        $delay = $p->delayFor(1);
        $this->assertGreaterThanOrEqual(125, $delay);
        $this->assertLessThanOrEqual(250, $delay);
    }
}
