<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Jayanta\Jeeves\Http\Middleware\EnforceQueryBudget;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A rate limit is not a budget.
 *
 * `throttle:60,1` ships on these routes and stops a burst, but sixty questions
 * a minute sustained is around eighty-six thousand a day -  every one a paid API
 * call. Throttling protects the server; this protects the bill, and it matters
 * most in the configuration people reach for first: the widget on a public page
 * with `auth` removed, where the visitor is anonymous and the key is yours.
 */
class QueryBudgetTest extends TestCase
{
    private function pass(Request $request): mixed
    {
        return (new EnforceQueryBudget)->handle($request, fn () => response()->json(['status' => 'success']));
    }

    private function request(string $ip = '203.0.113.9'): Request
    {
        return Request::create('/jeeves/text', 'POST', [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function questions_are_allowed_up_to_the_daily_ceiling()
    {
        config(['jeeves.limits.queries_per_day' => 3]);

        foreach (range(1, 3) as $n) {
            $this->assertSame(200, $this->pass($this->request())->getStatusCode(), "question {$n}");
        }
    }

    #[Test]
    public function the_ceiling_is_enforced_and_says_which_limit_was_hit()
    {
        config(['jeeves.limits.queries_per_day' => 2]);

        $this->pass($this->request());
        $this->pass($this->request());
        $response = $this->pass($this->request());

        // 429 so clients already handling rate limits handle this too.
        $this->assertSame(429, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertStringContainsString('daily limit of 2', $body['error']);
        $this->assertSame('queries_per_day', $body['metadata']['limit_reached']);
    }

    #[Test]
    public function the_ceiling_is_counted_per_person_not_globally()
    {
        // One heavy user must not lock everyone else out.
        config(['jeeves.limits.queries_per_day' => 1]);

        $this->pass($this->request('203.0.113.9'));

        $this->assertSame(429, $this->pass($this->request('203.0.113.9'))->getStatusCode());
        $this->assertSame(200, $this->pass($this->request('198.51.100.4'))->getStatusCode());
    }

    #[Test]
    public function no_ceiling_is_possible_but_has_to_be_chosen()
    {
        config(['jeeves.limits.queries_per_day' => null]);

        foreach (range(1, 50) as $n) {
            $this->assertSame(200, $this->pass($this->request())->getStatusCode());
        }
    }

    /**
     * Counted before the question runs, not after -  which is also why a query
     * that then fails still counts. Counting afterwards would let a burst of
     * simultaneous requests all pass the check before any of them recorded
     * anything, the one moment the limit exists for. And a failed query has
     * usually already made the paid API call.
     */
    #[Test]
    public function a_failed_query_still_counts_against_the_ceiling()
    {
        config(['jeeves.limits.queries_per_day' => 1]);

        try {
            (new EnforceQueryBudget)->handle($this->request(), function () {
                throw new \RuntimeException('provider exploded');
            });
        } catch (\RuntimeException) {
            // The API call was still made and still cost money.
        }

        $this->assertSame(429, $this->pass($this->request())->getStatusCode());
    }

    #[Test]
    public function the_budget_is_applied_to_the_package_routes()
    {
        // Appended in the ServiceProvider rather than left in the config array,
        // so an app that customises routes.middleware to make the widget public
        // cannot drop the ceiling by accident.
        $middleware = Route::getRoutes()
            ->getByName('jeeves.text')
            ?->gatherMiddleware() ?? [];

        $this->assertContains(EnforceQueryBudget::class, $middleware);
    }
}
