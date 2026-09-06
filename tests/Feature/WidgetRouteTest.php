<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The widget is advertised as drop-in and zero-publish: add
 * `<x-jeeves::widget />` to a Blade view and the browser fetches
 * {prefix}/widget.js straight from the package.
 *
 * Regression: that route was handled by JeevesController, whose
 * constructor pulls in the whole engine -  orchestrator, prompt builder, schema
 * introspector. On any database driver the package cannot introspect, simply
 * resolving the controller threw, so a static JavaScript file returned 500 and
 * every page embedding the widget broke. Laravel 11 and 12 default to SQLite,
 * so this was the out-of-the-box experience for a lot of new apps.
 *
 * Serving a file must not depend on the database at all.
 */
class WidgetRouteTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // A driver the package explicitly cannot introspect.
        $app['config']->set('database.default', 'unsupported');
        $app['config']->set('database.connections.unsupported', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Public so the test exercises the asset, not the auth stack.
        $app['config']->set('jeeves.routes.middleware', []);
    }

    #[Test]
    public function the_widget_asset_is_served_without_a_supported_database_driver()
    {
        $response = $this->get('/jeeves/widget.js');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('Jeeves', $response->getContent());
    }

    #[Test]
    public function the_widget_asset_is_cacheable()
    {
        $this->get('/jeeves/widget.js')->assertHeader('cache-control', 'max-age=3600, public');
    }

    #[Test]
    public function the_demo_page_does_not_need_a_supported_database_driver_either()
    {
        config(['jeeves.widget.demo_page' => true]);

        // Rendering may still fail on a missing view, but it must not fail
        // while resolving the controller's dependencies.
        $response = $this->get('/jeeves/demo');

        $this->assertNotSame(500, $response->getStatusCode(), $response->getContent());
    }

    /**
     * The server formats the totals and the widget formats the rows, so they
     * have to agree about how digits are grouped. They did not: the widget had
     * 'en-IN' hardcoded while the package default is 'international', and one
     * answer showed 20,28,763 in the bars and 15,474,683 in the totals -
     * under a footer asking the reader to verify important figures.
     */
    #[Test]
    public function the_widget_is_told_how_the_server_groups_numbers()
    {
        config(['jeeves.response.number_format' => 'indian']);

        $rendered = $this->blade('<x-jeeves::widget />');

        $rendered->assertSee('numberFormat', false);
        $rendered->assertSee('indian', false);
    }

    #[Test]
    public function the_widget_does_not_impose_a_speech_locale_of_its_own()
    {
        // null lets it follow <html lang> and then the browser. A hardcoded
        // locale was a leftover from the project this package came out of.
        config(['jeeves.widget.language' => null]);

        $this->blade('<x-jeeves::widget />')->assertDontSee('"language"', false);
    }

    #[Test]
    public function the_engine_still_refuses_an_unsupported_driver_with_an_actionable_message()
    {
        // The fix must not silently make an unsupported database look workable.
        // SQLite is supported now, so disable it to reach the refusal path.
        config(['jeeves.sql.introspectors' => ['sqlite' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("cannot introspect the 'sqlite' database driver");

        $this->app->make(QueryOrchestrator::class);
    }
}
