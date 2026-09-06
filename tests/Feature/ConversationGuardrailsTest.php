<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Conversation\ConversationManager;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two things that keep a long conversation honest.
 *
 * Ambiguity compounds: after enough "only this" and "and that", nobody
 * remembers which filters are live, and the resolver degrades faster than the
 * user notices. And when it does go wrong, the way back has to be a restore
 * rather than another interpretation of the same dialogue.
 */
class ConversationGuardrailsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.feedback.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // State is only carried forward on a successful turn, so the table the
        // stub schema names has to actually exist.
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
    }

    /** Answers every turn with a resolvable intent. */
    private function provider(): void
    {
        $provider = new RecordingProvider;
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_by' => 'region',
            'confidence' => 0.9,
            'needs_clarification' => false,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);
        $this->app->forgetInstance(ConversationManager::class);
    }

    private function manager(): ConversationManager
    {
        return $this->app->make(ConversationManager::class);
    }

    #[Test]
    public function refinements_are_capped_rather_than_resolved_into_nonsense()
    {
        config(['jeeves.conversation.max_refinements' => 3]);
        $this->provider();

        $manager = $this->manager();
        $session = 'cap-' . getmypid();

        $manager->query($session, 'revenue by region');

        // Three refinements are allowed; the fourth is not.
        foreach (['only in West', 'just delivered', 'now for June'] as $q) {
            $result = $manager->query($session, $q);
            $this->assertSame('success', $result['status'], $q);
        }

        $capped = $manager->query($session, 'only the top ones');

        $this->assertSame('clarification_needed', $capped['status']);
        $this->assertSame('refinement_cap', $capped['type']);
        $this->assertTrue($capped['conversation']['cap_reached']);
    }

    #[Test]
    public function a_new_question_is_never_capped()
    {
        // The cap exists for stacked refinements. Refusing to answer a fresh
        // question because an earlier chain ran long would be absurd.
        config(['jeeves.conversation.max_refinements' => 1]);
        $this->provider();

        $manager = $this->manager();
        $session = 'cap2-' . getmypid();

        $manager->query($session, 'revenue by region');
        $manager->query($session, 'only in West');

        $fresh = $manager->query($session, 'revenue by status for the whole year');

        $this->assertSame('success', $fresh['status']);
        $this->assertSame('new_query', $fresh['conversation']['classification']);
    }

    #[Test]
    public function going_back_restores_the_previous_state_exactly()
    {
        $this->provider();

        $manager = $this->manager();
        $session = 'rew-' . getmypid();

        $manager->query($session, 'revenue by region');
        $narrowed = $manager->query($session, 'only in West');

        $this->assertNotEmpty($narrowed['state']);

        $back = $manager->rewind($session);

        $this->assertSame('success', $back['status']);
        $this->assertTrue($back['conversation']['rewound']);
        $this->assertSame('revenue', $back['state']['metric']);
    }

    #[Test]
    public function there_is_nothing_to_go_back_to_on_the_first_turn()
    {
        $this->provider();

        $manager = $this->manager();
        $session = 'rew2-' . getmypid();

        $manager->query($session, 'revenue by region');

        $this->assertSame('error', $manager->rewind($session)['status']);
    }

    #[Test]
    public function every_answer_carries_the_state_it_was_understood_as()
    {
        $this->provider();

        $result = $this->manager()->query('sum-' . getmypid(), 'revenue by region');

        $this->assertNotEmpty($result['state_summary']);
        $this->assertStringContainsString('revenue', $result['state_summary']);
        $this->assertStringContainsString('by region', $result['state_summary']);
    }

    #[Test]
    public function a_follow_up_is_never_answered_from_cache()
    {
        // "Only in West" means one thing after a revenue question and another
        // after an order count. Same words, different question -  so a turn
        // carrying state must not be served, or stored, by a text-keyed cache.
        config(['jeeves.cache.enabled' => true]);
        $this->provider();

        $manager = $this->manager();
        $session = 'cache-' . getmypid();

        $manager->query($session, 'revenue by region');
        $result = $manager->query($session, 'only in West');

        $this->assertNotTrue($result['metadata']['cache_hit'] ?? false);
    }
}
