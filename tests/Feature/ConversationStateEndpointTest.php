<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Conversation\ConversationManager;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Reading the conversation back.
 *
 * The state lives on the server, so a front end that reloads the page has lost
 * everything it was showing while the filters remain in force. Without a way to
 * read them, the next follow-up resolves against context the user can no longer
 * see -  and the "reading this as" line, which exists precisely so a misreading
 * is visible, disappears at the moment it matters most.
 */
class ConversationStateEndpointTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
        $app['config']->set('jeeves.routes.middleware', []);
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.feedback.enabled', false);
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

    #[Test]
    public function a_fresh_session_reports_an_empty_conversation()
    {
        $this->getJson('/jeeves/conversation/nobody-here')
            ->assertStatus(200)
            ->assertJsonPath('state', [])
            ->assertJsonPath('conversation.context_active', false)
            ->assertJsonPath('conversation.can_rewind', false);
    }

    #[Test]
    public function it_returns_what_a_reloaded_page_needs_to_restore_itself()
    {
        $this->postJson('/jeeves/conversation', [
            'session_id' => 'restore-me',
            'text' => 'revenue by customer',
        ])->assertStatus(200);

        $response = $this->getJson('/jeeves/conversation/restore-me');

        $response->assertStatus(200);
        $response->assertJsonPath('conversation.context_active', true);
        $response->assertJsonPath('conversation.turn', 1);
        $response->assertJsonPath('state.metric', 'revenue');
        $this->assertStringContainsString('revenue', $response->json('state_summary'));
    }

    #[Test]
    public function the_shape_matches_what_a_query_returns()
    {
        // Same keys, so a front end renders a restored conversation with the
        // code it already has rather than a second path that drifts.
        $query = $this->postJson('/jeeves/conversation', [
            'session_id' => 'same-shape',
            'text' => 'revenue by customer',
        ])->json();

        $state = $this->getJson('/jeeves/conversation/same-shape')->json();

        foreach (['state', 'state_summary', 'conversation'] as $key) {
            $this->assertArrayHasKey($key, $state, "restored payload is missing {$key}");
            $this->assertArrayHasKey($key, $query, "query payload is missing {$key}");
        }
    }

    #[Test]
    public function it_reports_whether_going_back_is_possible()
    {
        $this->postJson('/jeeves/conversation', ['session_id' => 'rw', 'text' => 'revenue by customer']);
        $this->getJson('/jeeves/conversation/rw')->assertJsonPath('conversation.can_rewind', false);

        $this->postJson('/jeeves/conversation', ['session_id' => 'rw', 'text' => 'only in West']);
        $this->getJson('/jeeves/conversation/rw')->assertJsonPath('conversation.can_rewind', true);
    }

    #[Test]
    public function clearing_the_conversation_empties_the_state()
    {
        $this->postJson('/jeeves/conversation', ['session_id' => 'gone', 'text' => 'revenue by customer']);

        $this->deleteJson('/jeeves/conversation/gone')->assertStatus(200);

        $this->getJson('/jeeves/conversation/gone')
            ->assertJsonPath('conversation.context_active', false);
    }

    /**
     * Session ids are scoped to the authenticated user inside the manager. Two
     * people guessing the same id must not read each other's conversation -
     * the state carries what they asked about, which is a data leak of a
     * different kind from the row values the privacy wall protects.
     */
    #[Test]
    public function one_users_session_id_does_not_read_anothers_conversation()
    {
        $alice = new GenericUser(['id' => 1]);
        $bob = new GenericUser(['id' => 2]);

        $manager = $this->app->make(ConversationManager::class);

        $this->actingAs($alice);
        $manager->query('shared-id', 'revenue by customer');
        $this->assertTrue($manager->state('shared-id')['conversation']['context_active']);

        $this->actingAs($bob);
        $this->assertFalse(
            $manager->state('shared-id')['conversation']['context_active'],
            'a second user read the first user\'s conversation'
        );
    }

    #[Test]
    public function the_refinement_ceiling_is_reported_so_a_client_can_show_progress()
    {
        $this->getJson('/jeeves/conversation/anything')
            ->assertJsonPath('conversation.max_refinements', 6);
    }
}
