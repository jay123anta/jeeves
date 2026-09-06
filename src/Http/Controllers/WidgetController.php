<?php

namespace Jayanta\Jeeves\Http\Controllers;

use Illuminate\Routing\Controller;

/**
 * Serves the widget asset and the demo page.
 *
 * Deliberately separate from JeevesController, and deliberately free of
 * constructor dependencies. JeevesController pulls in the whole engine -
 * orchestrator, prompt builder, schema introspector -  which means resolving it
 * fails outright on an unsupported database driver. Serving a static
 * JavaScript file must never depend on any of that: `<x-jeeves::widget />`
 * loads {prefix}/widget.js on every page that embeds it, and a 500 there breaks
 * the page for a reason that has nothing to do with the asset.
 */
class WidgetController extends Controller
{
    /**
     * Serve the widget JavaScript directly from the package (zero-publish).
     * Cached aggressively; cache-bust by version query string if needed.
     */
    public function asset()
    {
        $path = __DIR__ . '/../../../resources/js/jeeves-widget.js';

        if (!is_file($path)) {
            return response('// Jeeves widget asset missing', 404)
                ->header('Content-Type', 'application/javascript');
        }

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Interactive demo page (config-gated).
     */
    public function demo()
    {
        // null = follow environment (enabled in local only); true/false = explicit
        $enabled = config('jeeves.widget.demo_page');
        if ($enabled === null) {
            $enabled = app()->environment('local');
        }
        if (!$enabled) {
            abort(404);
        }

        return view('jeeves::demo');
    }
}
