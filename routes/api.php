<?php

use Illuminate\Support\Facades\Route;
use Jayanta\Jeeves\Http\Controllers\JeevesController;
use Jayanta\Jeeves\Http\Controllers\WidgetController;
use Jayanta\Jeeves\Http\Middleware\Authorize;

/*
|--------------------------------------------------------------------------
| Jeeves Package Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the JeevesServiceProvider.
| Prefix and middleware are configured in config/jeeves.php
|
| Default: /jeeves/*
|
*/

// Dashboard / demo page
Route::get('/', [JeevesController::class, 'index'])->name('index');

// Widget asset -  served directly by the package so no publishing is needed.
// (A publishable copy also exists: php artisan vendor:publish --tag=jeeves-assets)
//
// Handled by WidgetController, which has no constructor dependencies. Routing
// this through JeevesController would construct the whole engine -  and
// therefore the schema introspector -  just to return a static file, so every
// page embedding <x-jeeves::widget /> would break on an unsupported
// database driver.
// Public, and it has to be: this is a static JavaScript file loaded by every
// page that embeds the widget, including pages shown to signed-out visitors.
// Gating it does not protect anything -  the file contains no data and no key -
// it just stops the widget rendering at all, and the failure looks like a
// broken package rather than a policy. The endpoints it CALLS are gated.
Route::get('/widget.js', [WidgetController::class, 'asset'])
    ->withoutMiddleware(['auth', Authorize::class])
    ->name('widget.asset');

// Interactive demo page using the widget (config-gated; defaults to local env only)
Route::get('/demo', [WidgetController::class, 'demo'])->name('demo');

// Main query endpoint (text input)
Route::post('/text', [JeevesController::class, 'textQuery'])->name('text');

// Health check
Route::get('/health', [JeevesController::class, 'health'])->name('health');

// Available datasets
Route::get('/datasets', [JeevesController::class, 'datasets'])->name('datasets');

// Cache management
Route::get('/cache-stats', [JeevesController::class, 'cacheStats'])->name('cache.stats');
Route::post('/clear-cache', [JeevesController::class, 'clearCache'])->name('cache.clear');

// Conversation (multi-turn)
Route::post('/conversation', [JeevesController::class, 'conversationQuery'])->name('conversation');
Route::get('/conversation/{sessionId}', [JeevesController::class, 'conversationState'])->name('conversation.state');
Route::post('/conversation/{sessionId}/rewind', [JeevesController::class, 'rewindConversation'])->name('conversation.rewind');
Route::delete('/conversation/{sessionId}', [JeevesController::class, 'clearConversation'])->name('conversation.clear');

// Feedback
Route::post('/feedback', [JeevesController::class, 'submitFeedback'])->name('feedback');
Route::get('/feedback/stats', [JeevesController::class, 'feedbackStats'])->name('feedback.stats');
