<?php

namespace Jayanta\Jeeves\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A ceiling on how many questions one person can ask in a day.
 *
 * `throttle:60,1` already ships on these routes, but a rate is not a budget:
 * sixty a minute sustained is around eighty-six thousand questions a day, and
 * every one of them is a paid API call. Rate limiting protects the server.
 * This protects the bill.
 *
 * It matters most in exactly the configuration people reach for first -  the
 * widget on a public page with `auth` removed, where the visitor is anonymous
 * and the key is yours.
 *
 * Counted per authenticated user, or per IP when there is no user. The counter
 * lives in the cache and expires at the end of the day, so nothing needs
 * migrating and nothing needs cleaning up.
 */
class EnforceQueryBudget
{
    public function handle(Request $request, Closure $next)
    {
        $limit = config('jeeves.limits.queries_per_day');

        // null means no ceiling -  a deliberate choice, not the default.
        if (!$limit || (int) $limit <= 0) {
            return $next($request);
        }

        $key = $this->budgetKey($request);
        $used = (int) Cache::get($key, 0);

        if ($used >= (int) $limit) {
            Log::warning('[Jeeves] Daily query budget reached', [
                'limit' => (int) $limit,
                'identity' => $this->identity($request),
            ]);

            // 429 so clients already handling rate limits handle this too, and
            // the message says which limit was hit -  "too many requests" with
            // no number is a support ticket.
            return response()->json([
                'status' => 'error',
                'error' => "You have reached the daily limit of {$limit} questions. "
                    . 'It resets at midnight.',
                'metadata' => ['limit_reached' => 'queries_per_day', 'limit' => (int) $limit],
            ], 429);
        }

        // Counted on the way in. Counting on the way out would let a burst of
        // simultaneous requests all pass the check before any of them recorded
        // anything, which is the one moment the limit exists for.
        Cache::put($key, $used + 1, $this->secondsUntilMidnight());

        return $next($request);
    }

    protected function budgetKey(Request $request): string
    {
        return 'jeeves:budget:' . date('Y-m-d') . ':' . sha1($this->identity($request));
    }

    /**
     * Who is being counted. The authenticated user if there is one, otherwise
     * the IP -  which is weak, and is the reason the docs say to keep `auth` on
     * for anything public.
     */
    protected function identity(Request $request): string
    {
        $user = $request->user();

        if ($user && method_exists($user, 'getAuthIdentifier')) {
            return 'user:' . $user->getAuthIdentifier();
        }

        return 'ip:' . (string) $request->ip();
    }

    protected function secondsUntilMidnight(): int
    {
        return max(60, strtotime('tomorrow') - time());
    }
}
