<?php

namespace Jayanta\Jeeves\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Who may spend your API key.
 *
 * The package used to put `auth` in the default middleware, which is the right
 * instinct and the wrong mechanism. A fresh `laravel new` app has no auth
 * scaffolding and therefore no `login` route, so the first thing a new adopter
 * saw was not a login page but a 500:
 *
 *     RouteNotFoundException: Route [login] not defined
 *
 * -  on `/jeeves/demo`, the page the README tells them to visit. The
 * package appeared broken when it was working exactly as configured.
 *
 * So this follows the pattern Telescope and Horizon use for the same problem: a
 * gate, with a default that makes the package usable the moment it is
 * installed and closed everywhere else.
 *
 *   - A `viewJeeves` gate defined by the app decides, always.
 *   - No gate, local or testing   → allowed. A developer evaluating the
 *     package on their own machine should not have to build a login first,
 *     and their first feature test should not fail with 403.
 *   - No gate, anywhere else      → an authenticated user is required, which
 *     is what `auth` gave before, minus the crash.
 *
 * Define the gate as soon as it is more than you: these endpoints spend money
 * on every request, so an open one in production is an LLM proxy for the
 * internet.
 *
 *     Gate::define('viewJeeves', fn ($user) => $user->isAdmin());
 */
class Authorize
{
    public function handle(Request $request, Closure $next)
    {
        if ($this->allowed($request)) {
            return $next($request);
        }

        // 403, not a redirect. There may be nowhere to redirect to -  that was
        // the original bug -  and the widget reads the status and says "you
        // need to be signed in" rather than guessing.
        abort(403, 'Not authorised to use Jeeves. Define a viewJeeves gate, or sign in.');
    }

    protected function allowed(Request $request): bool
    {
        // The app has an opinion, so it is the only one that counts -  including
        // when it says no in local.
        if (Gate::has('viewJeeves')) {
            return Gate::forUser($request->user())->allows('viewJeeves');
        }

        // 'testing' alongside 'local' so an adopter's own feature tests hit the
        // endpoints without first discovering that a gate exists. Neither is a
        // production environment, and a package that fails a newcomer's first
        // test with 403 has taught them nothing except that it is awkward.
        if (app()->environment('local', 'testing')) {
            return true;
        }

        return $request->user() !== null;
    }
}
