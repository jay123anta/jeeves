<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Jayanta\Jeeves\Http\Middleware\Authorize;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Who may spend the API key.
 *
 * The package used to put `auth` in the default middleware -  right instinct,
 * wrong mechanism. A fresh `laravel new` app has no auth scaffolding and so no
 * `login` route, and the first thing a new adopter met was not a login page but
 *
 *     RouteNotFoundException: Route [login] not defined
 *
 * a 500 on `/jeeves/demo`, the page the README tells them to visit.
 * Confirmed on a virgin Laravel 13 install before this was changed.
 *
 * A gate replaces it, the way Telescope and Horizon solve the same problem.
 * These cases hold both halves: usable the moment it is installed, and shut
 * everywhere it should be. The second half matters more -  these endpoints spend
 * money on every request, so an open one in production is an LLM proxy for the
 * internet.
 */
class AuthorizationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // NOT overriding routes.middleware here: the point is what an adopter
        // gets by default, so the shipped list is what must be exercised -
        // including 'web', which the rest of the suite skips.
        //
        // 'web' brings sessions and encrypted cookies, hence a real key. Every
        // Laravel app has one; only this stripped-down test harness does not.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('jeeves.cache.enabled', false);
    }

    private function ask()
    {
        return $this->postJson('/jeeves/text', ['text' => 'total revenue']);
    }

    /**
     * Laravel exempts requests from CSRF while running tests, and it decides
     * that by looking at the environment -  which these cases deliberately
     * change. Without this, every one of them fails with 419 before reaching
     * the check under test.
     */
    private function inEnvironment(string $env): void
    {
        $this->app->detectEnvironment(fn () => $env);

        // A real session and a real token, rather than switching the CSRF
        // middleware off. These cases run the shipped middleware list, 'web'
        // included, so the request should look like one a browser makes -
        // and a widget in a browser does send this header.
        $this->startSession();
        $this->withHeader('X-CSRF-TOKEN', csrf_token());
    }

    // ------------------------------------------------ usable once installed

    #[Test]
    public function a_developer_on_their_own_machine_is_let_straight_in()
    {
        $this->inEnvironment('local');

        $this->assertNotSame(403, $this->ask()->status(), 'a fresh local install was refused');
    }

    #[Test]
    public function the_demo_page_the_readme_sends_people_to_actually_opens()
    {
        $this->inEnvironment('local');

        // It 500'd with "Route [login] not defined" before the gate existed.
        $this->get('/jeeves/demo')->assertStatus(200);
    }

    /**
     * The widget script is a static file loaded by every page that embeds the
     * widget, including pages shown to signed-out visitors. Gating it protects
     * nothing -  no data, no key -  and only stops the widget rendering, which
     * looks like a broken package rather than a policy.
     */
    #[Test]
    public function the_widget_script_stays_public_even_in_production()
    {
        $this->inEnvironment('production');

        $this->get('/jeeves/widget.js')->assertStatus(200);
    }

    // ---------------------------------------------------- shut where it counts

    #[Test]
    public function production_refuses_a_guest()
    {
        $this->inEnvironment('production');

        $this->ask()->assertStatus(403);
    }

    #[Test]
    public function production_admits_a_signed_in_user()
    {
        $this->inEnvironment('production');

        $status = $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Ada']))
            ->postJson('/jeeves/text', ['text' => 'total revenue'])
            ->status();

        $this->assertNotSame(403, $status, 'a signed-in user was refused in production');
    }

    // ------------------------------------------------------------ the gate

    #[Test]
    public function a_gate_defined_by_the_app_decides()
    {
        $this->inEnvironment('production');
        Gate::define('viewJeeves', fn ($user) => ($user->name ?? null) === 'Ada');

        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Bob']))
            ->postJson('/jeeves/text', ['text' => 'total revenue'])
            ->assertStatus(403);

        $allowed = $this->actingAs(new GenericUser(['id' => 2, 'name' => 'Ada']))
            ->postJson('/jeeves/text', ['text' => 'total revenue'])
            ->status();

        $this->assertNotSame(403, $allowed, 'the gate said yes and the request was still refused');
    }

    /**
     * Including when it says no in local. An app that has taken a view is the
     * only authority; a convenience default that overrode it would be a
     * back door.
     */
    #[Test]
    public function a_gate_that_refuses_is_obeyed_in_local_too()
    {
        $this->inEnvironment('local');
        Gate::define('viewJeeves', fn ($user = null) => false);

        $this->ask()->assertStatus(403);
    }

    #[Test]
    public function the_refusal_says_what_to_do_about_it()
    {
        $this->inEnvironment('production');

        $body = (string) $this->ask()->getContent();

        $this->assertStringContainsString('viewJeeves', $body, 'the gate is not named');
    }

    // -------------------------------------------------------- not bypassable

    /**
     * Emptying routes.middleware is the first thing anyone does to make the
     * widget public. It must not also silently remove the authorisation check,
     * so the package appends it after reading the config rather than relying
     * on the config to contain it.
     *
     * Asserted on the registered route rather than by rebooting the app with a
     * different config, because that is the invariant that actually protects
     * people: whatever the list says, this is on the route.
     */
    #[Test]
    public function the_check_is_appended_by_the_package_not_supplied_by_config()
    {
        $middleware = Route::getRoutes()
            ->getByName('jeeves.text')
            ->gatherMiddleware();

        $this->assertContains(Authorize::class, $middleware);
        $this->assertNotContains(
            Authorize::class,
            config('jeeves.routes.middleware'),
            'if it came from the config, emptying the config would remove it'
        );
    }
}
