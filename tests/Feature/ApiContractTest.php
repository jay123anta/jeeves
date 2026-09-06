<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Conversation\ConversationManager;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The HTTP contract, pinned.
 *
 * The bundled widget is a reference; people build their own front ends, and for
 * them the response shape IS the package. That shape has grown a great deal -
 * state, state_summary, steps, next_steps, filters, period, error_code -  and
 * every field of it is now something an application depends on.
 *
 * Internals can be refactored freely. These cannot change without a deliberate
 * decision and a note in the changelog, which is the difference between an API
 * and an implementation detail somebody happened to observe.
 *
 * Every route is covered, including the ones nobody had exercised over HTTP.
 */
class ApiContractTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
        $app['config']->set('jeeves.routes.middleware', []);
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.feedback.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gb_sales', function ($t) {
            $t->id();
            $t->string('customer_name');
            $t->string('region');
            $t->string('status');
            $t->decimal('revenue', 12, 2);
        });

        DB::table('gb_sales')->insert([
            ['customer_name' => 'Ada', 'region' => 'West', 'status' => 'delivered', 'revenue' => 300],
            ['customer_name' => 'Grace', 'region' => 'East', 'status' => 'pending', 'revenue' => 500],
        ]);

        $provider = new RecordingProvider;
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_by' => 'customer_name',
            'confidence' => 0.9,
            'needs_clarification' => false,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);
        $this->app->forgetInstance(ConversationManager::class);
    }

    // ------------------------------------------------------------- envelope

    #[Test]
    public function a_successful_answer_carries_every_documented_field()
    {
        $response = $this->postJson('/jeeves/text', ['text' => 'revenue by customer']);

        $response->assertStatus(200);

        foreach (['status', 'type', 'rows', 'parsed_query', 'answer', 'visualization', 'metadata'] as $field) {
            $response->assertJsonStructure([$field]);
        }

        // parsed_query is what a client renders as "we read this as".
        foreach (['dataset', 'metric', 'group_by', 'filter_column', 'filters', 'period',
            'group_value', 'limit', 'order', 'query_type'] as $field) {
            $this->assertArrayHasKey($field, $response->json('parsed_query'), "parsed_query.{$field}");
        }
    }

    #[Test]
    public function metadata_identifies_the_request_and_how_it_was_answered()
    {
        $metadata = $this->postJson('/jeeves/text', ['text' => 'revenue by customer'])
            ->json('metadata');

        foreach (['request_id', 'processing_time_ms', 'processing_mode', 'query_mode'] as $field) {
            $this->assertArrayHasKey($field, $metadata, "metadata.{$field}");
        }
    }

    #[Test]
    public function a_conversation_answer_adds_state_and_turn_information()
    {
        $response = $this->postJson('/jeeves/conversation', [
            'session_id' => 'contract',
            'text' => 'revenue by customer',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'state',
            'state_summary',
            'conversation' => ['session_id', 'turn', 'classification', 'context_active', 'refinements', 'can_rewind'],
        ]);
    }

    // ------------------------------------------------------------- endpoints

    #[Test]
    public function the_index_describes_the_package_and_its_endpoints()
    {
        $this->getJson('/jeeves')
            ->assertStatus(200)
            ->assertJsonStructure(['package', 'health', 'endpoints']);
    }

    #[Test]
    public function health_reports_status_and_a_timestamp()
    {
        $response = $this->getJson('/jeeves/health');

        // 200 when reachable, 503 when not -  either is a valid contract, and
        // a monitoring probe needs the distinction.
        $this->assertContains($response->status(), [200, 503]);
        $response->assertJsonStructure(['status', 'timestamp']);
    }

    #[Test]
    public function cache_statistics_are_readable()
    {
        $this->getJson('/jeeves/cache-stats')
            ->assertStatus(200)
            ->assertJsonStructure(['timestamp']);
    }

    #[Test]
    public function the_cache_can_be_cleared()
    {
        $this->postJson('/jeeves/clear-cache', [])
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'deleted_entries', 'timestamp']);
    }

    #[Test]
    public function a_conversation_can_be_wound_back()
    {
        $this->postJson('/jeeves/conversation', ['session_id' => 'rw', 'text' => 'revenue by customer']);
        $this->postJson('/jeeves/conversation', ['session_id' => 'rw', 'text' => 'only in West']);

        $this->postJson('/jeeves/conversation/rw/rewind', ['steps' => 1])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('conversation.rewound', true)
            // The same block state() returns. It reported only the turn number,
            // so a client that had just gone back could not tell whether it
            // could go back again -  and the natural thing to do with a missing
            // answer is leave the control enabled and let the user find out.
            ->assertJsonStructure([
                'state',
                'state_summary',
                'conversation' => ['session_id', 'turn', 'rewound', 'refinements', 'context_active', 'can_rewind', 'max_refinements'],
            ]);
    }

    #[Test]
    public function rewinding_to_the_first_turn_reports_that_there_is_no_more_history()
    {
        $this->postJson('/jeeves/conversation', ['session_id' => 'edge', 'text' => 'revenue by customer']);
        $this->postJson('/jeeves/conversation', ['session_id' => 'edge', 'text' => 'only in West']);

        $this->postJson('/jeeves/conversation/edge/rewind', ['steps' => 1])
            ->assertJsonPath('conversation.can_rewind', false);
    }

    #[Test]
    public function rewinding_with_nothing_behind_it_says_so_rather_than_failing()
    {
        $this->postJson('/jeeves/conversation/never-used/rewind', [])
            ->assertJsonPath('status', 'error');
    }

    #[Test]
    public function feedback_is_accepted()
    {
        $this->postJson('/jeeves/feedback', [
            'query' => 'top customers',
            'dataset' => 'gb_sales',
            'correction' => 'should rank by revenue, not row count',
            'feedback_type' => 'wrong_metric',
        ])->assertStatus(200)->assertJsonStructure(['status']);
    }

    #[Test]
    public function feedback_statistics_are_readable()
    {
        $this->getJson('/jeeves/feedback/stats')->assertStatus(200);
    }

    #[Test]
    public function the_widget_script_is_served_as_javascript()
    {
        $response = $this->get('/jeeves/widget.js');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'javascript',
            (string) $response->headers->get('Content-Type'),
            'widget.js must be served with a JavaScript content type or browsers refuse it'
        );
    }

    // ------------------------------------------------------------ validation

    #[Test]
    public function a_missing_question_is_a_validation_error_not_a_crash()
    {
        $this->postJson('/jeeves/text', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('text');
    }

    #[Test]
    public function a_conversation_turn_requires_a_session()
    {
        $this->postJson('/jeeves/conversation', ['text' => 'revenue by customer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('session_id');
    }

    #[Test]
    public function an_oversized_question_is_refused_before_it_reaches_a_provider()
    {
        $this->postJson('/jeeves/text', ['text' => str_repeat('a', 5000)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('text');
    }

    /**
     * Speech is the browser's job, so there is no audio endpoint to contract.
     * Pinned because it was one, and re-adding it would be a scope change
     * rather than a feature.
     */
    #[Test]
    public function there_is_no_audio_endpoint()
    {
        $this->postJson('/jeeves/voice', ['audio' => 'x'])->assertStatus(404);
    }

    /**
     * Every route answers. A route that 500s on a well-formed request is
     * broken regardless of what its body says, and this is the cheapest way to
     * notice one that was added without being wired up.
     */
    #[Test]
    public function every_registered_route_responds()
    {
        $unreachable = [];

        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'jeeves')) {
                continue;
            }

            if (!in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')) {
                continue; // POST/DELETE and parameterised routes are covered above
            }

            $response = $this->get('/' . $route->uri());

            if ($response->status() >= 500) {
                $unreachable[] = $route->uri() . ' → ' . $response->status();
            }
        }

        $this->assertSame([], $unreachable, 'routes failing with a server error');
    }
}
