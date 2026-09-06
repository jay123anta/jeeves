<?php

namespace Jayanta\Jeeves;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Jayanta\Jeeves\Cache\NullQueryCache;
use Jayanta\Jeeves\Cache\TwoTierQueryCache;
use Jayanta\Jeeves\Console\AuditSchemaCommand;
use Jayanta\Jeeves\Console\BenchmarkCommand;
use Jayanta\Jeeves\Console\CacheCleanupCommand;
use Jayanta\Jeeves\Console\CacheStatsCommand;
use Jayanta\Jeeves\Console\DebugPromptCommand;
use Jayanta\Jeeves\Console\DiscoverSchemaCommand;
use Jayanta\Jeeves\Console\DoctorCommand;
use Jayanta\Jeeves\Console\InstallCommand;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\QueryCacheInterface;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Contracts\SqlValidatorInterface;
use Jayanta\Jeeves\Conversation\ConversationManager;
use Jayanta\Jeeves\Engine\DatasetSeeder;
use Jayanta\Jeeves\Engine\IntentCoverage;
use Jayanta\Jeeves\Engine\NextStepSuggester;
use Jayanta\Jeeves\Engine\PromptBudget;
use Jayanta\Jeeves\Engine\PromptBuilder;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Engine\QueryPlanner;
use Jayanta\Jeeves\Engine\QueryVerifier;
use Jayanta\Jeeves\Engine\ResponseFormatter;
use Jayanta\Jeeves\Engine\SqlBuilder;
use Jayanta\Jeeves\Engine\StepSynthesizer;
use Jayanta\Jeeves\Feedback\FeedbackStore;
use Jayanta\Jeeves\Http\Middleware\Authorize;
use Jayanta\Jeeves\Http\Middleware\EnforceQueryBudget;
use Jayanta\Jeeves\LlmProviders\ClaudeProvider;
use Jayanta\Jeeves\LlmProviders\GeminiProvider;
use Jayanta\Jeeves\LlmProviders\OllamaProvider;
use Jayanta\Jeeves\LlmProviders\OpenAiProvider;
use Jayanta\Jeeves\Schema\IntrospectorRegistry;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Security\InputGuard;
use Jayanta\Jeeves\Security\SqlValidator;
use Jayanta\Jeeves\Support\EnvValue;

/**
 * Deliberately NOT a DeferrableProvider, and it cannot become one.
 *
 * Deferring means Laravel skips boot() until something resolves a binding this
 * provider promises. boot() here does work nothing resolves a binding for:
 * it registers routes, loads views and the Blade component, and loads
 * migrations. A deferred provider would leave `/jeeves/*` returning 404
 * and `<x-jeeves::widget />` failing to render, on an install where
 * everything looks correctly configured.
 *
 * register() is side-effect free and only binds, so the usual reason to defer
 * - keeping a heavy provider off every request - does not apply either.
 */
class JeevesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/jeeves.php', 'jeeves');

        $this->app->singleton(SchemaRegistry::class, function ($app) {
            return new SchemaRegistry(
                config('jeeves.schema.config_path', config_path('jeeves-schemas'))
            );
        });

        $this->app->singleton(LlmProviderInterface::class, function ($app) {
            $driver = config('jeeves.llm.driver', 'gemini');
            $providerConfig = config("jeeves.llm.providers.{$driver}", []);

            return match ($driver) {
                'gemini' => new GeminiProvider($providerConfig),
                'openai' => new OpenAiProvider($providerConfig),
                'claude' => new ClaudeProvider($providerConfig),
                'ollama' => new OllamaProvider($providerConfig),
                // Any other driver name resolves through the OpenAI-compatible
                // provider when its config block declares a base_url. This is
                // how DeepSeek, Groq, Mistral, OpenRouter, vLLM, LM Studio,
                // LocalAI, llama.cpp server, etc. plug in -  define a
                // providers.<name> block with base_url + model and set
                // JEEVES_LLM_DRIVER=<name>. No code changes needed.
                default => !empty($providerConfig['base_url'])
                    ? new OpenAiProvider($providerConfig + ['name' => $driver])
                    : throw new \InvalidArgumentException(
                        "Unknown Jeeves LLM driver: '{$driver}'. Built-in: gemini, openai, claude, ollama. "
                        . 'For any OpenAI-compatible service (DeepSeek, Groq, vLLM, LM Studio, …) add a '
                        . "'llm.providers.{$driver}' config block with 'base_url' and 'model'."
                    ),
            };
        });

        $this->app->singleton(SchemaIntrospectorInterface::class, function ($app) {
            $connection = config('jeeves.sql.database_connection') ?? config('database.default');
            $driver = config("database.connections.{$connection}.driver", 'mysql');

            if ($class = IntrospectorRegistry::classFor($driver)) {
                return $app->make($class);
            }

            // A bare "unsupported driver" inside a 500 page tells a novice
            // nothing, so name the driver and the way out.
            throw new \InvalidArgumentException(
                "Jeeves cannot introspect the '{$driver}' database driver. "
                . 'Supported: ' . implode(', ', IntrospectorRegistry::supportedDrivers())
                . '. You can add your own by mapping the driver to a class implementing '
                . "SchemaIntrospectorInterface under 'sql.introspectors' in config/jeeves.php"
                . ". Run 'php artisan jeeves:doctor' for a full checkup."
            );
        });

        $this->app->singleton(SqlValidatorInterface::class, SqlValidator::class);
        $this->app->singleton(InputGuard::class);
        $this->app->singleton(FeedbackStore::class);

        $this->app->singleton(QueryCacheInterface::class, function ($app) {
            if (!config('jeeves.cache.enabled', true)) {
                return new NullQueryCache;
            }

            return $app->make(TwoTierQueryCache::class);
        });

        $this->app->singleton(SqlBuilder::class);
        $this->app->singleton(PromptBuilder::class);
        $this->app->singleton(ResponseFormatter::class);
        $this->app->singleton(QueryVerifier::class);
        $this->app->singleton(QueryPlanner::class);
        $this->app->singleton(StepSynthesizer::class);
        $this->app->singleton(NextStepSuggester::class);
        $this->app->singleton(IntentCoverage::class);

        // DatasetSeeder needs only SchemaRegistry, already bound above, so the
        // container auto-wires it -  used both for dataset detection and, via
        // QueryOrchestrator::resolveAskingDataset(), to tell a genuine cache
        // hit from a cross-dataset miss (NQ-003-FIX: a mismatch misses, it is
        // never silently reconciled). PromptBudget takes a scalar
        // (prompts.max_chars, null = unbounded, C1) that the container cannot
        // infer, so it gets an explicit factory. Both are STATELESS -
        // singletons are safe only because detect()/check() never memoise
        // anything on $this, which is what lets a step of a multi-step
        // answer call them again cleanly.
        $this->app->singleton(DatasetSeeder::class);
        $this->app->singleton(PromptBudget::class, function ($app) {
            // The env var is consulted directly when the published config has
            // no such key. mergeConfigFrom is ONE LEVEL deep, so an app that
            // ran jeeves:install before 2.1.0 has a published `prompts`
            // array that replaces the package's wholesale -  and `max_chars`,
            // being new, simply does not exist in it. Reading only through
            // config() meant the setting was unreachable on exactly the
            // installs most likely to want it, with no error: you set
            // JEEVES_PROMPT_MAX_CHARS, nothing happened, and nothing
            // said why.
            //
            // array_key_exists, not ??. A published config that sets max_chars
            // to null is saying "unbounded" and must win; one that has no such
            // key has not spoken, and that is the case the fallback is for.
            // `??` cannot tell those apart and would override the first.
            $prompts = config('jeeves.prompts', []);

            $max = array_key_exists('max_chars', $prompts)
                ? $prompts['max_chars']
                : EnvValue::int('JEEVES_PROMPT_MAX_CHARS', null);

            return new PromptBudget($max);
        });

        $this->app->singleton(QueryOrchestrator::class);
        $this->app->singleton(ConversationManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/jeeves.php' => config_path('jeeves.php'),
        ], 'jeeves-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'jeeves-migrations');

        // Frontend widget: publishable copy for apps that bundle their own
        // assets. Publishing is OPTIONAL -  the package also serves the widget
        // directly at {prefix}/widget.js (see routes/api.php).
        $this->publishes([
            __DIR__ . '/../resources/js/jeeves-widget.js' => public_path('vendor/jeeves/jeeves-widget.js'),
        ], 'jeeves-assets');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Views + <x-jeeves::widget /> Blade component
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'jeeves');
        Blade::anonymousComponentPath(
            __DIR__ . '/../resources/views/components',
            'jeeves'
        );

        if (config('jeeves.routes.enabled', true)) {
            $this->registerRoutes();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                DiscoverSchemaCommand::class,
                AuditSchemaCommand::class,
                BenchmarkCommand::class,
                CacheCleanupCommand::class,
                CacheStatsCommand::class,
                DebugPromptCommand::class,
                DoctorCommand::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        $middleware = config('jeeves.routes.middleware', ['web', 'throttle:60,1']);

        // Both appended rather than left to the config array. An app that
        // customises routes.middleware -  the first thing anyone does to make
        // the widget public -  would otherwise drop the authorisation check and
        // the spending ceiling without meaning to, at exactly the moment they
        // start to matter.
        //
        // Authorize first: a refused request should not count against the
        // day's budget.
        $middleware[] = Authorize::class;
        $middleware[] = EnforceQueryBudget::class;

        Route::prefix(config('jeeves.routes.prefix', 'jeeves'))
            ->middleware($middleware)
            ->name(config('jeeves.routes.name_prefix', 'jeeves.'))
            ->group(__DIR__ . '/../routes/api.php');
    }
}
