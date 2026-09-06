<?php

namespace Jayanta\Jeeves\Tests\Conformance;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Conversation\ConversationManager;
use Jayanta\Jeeves\Engine\IntentCoverage;
use Jayanta\Jeeves\Engine\NextStepSuggester;
use Jayanta\Jeeves\Engine\PromptBuilder;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Engine\QueryPlanner;
use Jayanta\Jeeves\Engine\SqlBuilder;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Does this provider actually work, on every documented feature?
 *
 * The unit suite proves the ENGINE is right by feeding it canned intents. That
 * is necessary and it is blind to a whole class of failure, because a stubbed
 * response is perfectly happy to answer a request the real API would reject.
 * Everything below was found only by making real calls:
 *
 *   - Claude's driver returned 400 on every question -  `temperature` deprecated
 *     and assistant prefill refused -  and its shipped default model 404'd.
 *   - Three providers read $parsed['order'] unguarded; only the one never
 *     live-tested sent responses without it.
 *   - "Only in Guwahati" after "total amount by city" returned every city.
 *
 * That last one is why this exists. It was not a provider bug at all -  the
 * engine discarded a filter on the grouping column -  but no single-turn test
 * and no single provider revealed it. It took a two-turn chain run against
 * several models.
 *
 * So this is a CONFORMANCE battery, not an accuracy benchmark. Every expected
 * value below can be checked by hand against six rows of data. A failure here
 * is a defect, not a model having an off day.
 *
 *   JEEVES_CONFORMANCE=1 \
 *   JEEVES_LLM_DRIVER=claude \
 *   JEEVES_CONFORMANCE_KEY=sk-... \
 *   vendor/bin/phpunit --testsuite Conformance
 *
 * JEEVES_CONFORMANCE_DELAY spaces the calls out for a free tier.
 */
class ProviderConformanceTest extends TestCase
{
    private string $driver;

    private array $results = [];

    /**
     * Six rows. Every expectation in this file is arithmetic on these.
     *
     *   Guwahati = 4200 + 6100 = 10300      paid    = 4200 + 6100 = 10300
     *   Jorhat   = 1800                     pending = 1800
     *   all      = 12100                    average = 12100 / 3 = 4033.33
     */
    private function seedInvoices(): void
    {
        Schema::dropIfExists('invoices');
        Schema::create('invoices', function ($t) {
            $t->id();
            $t->string('client');
            $t->string('city');
            $t->string('status');
            $t->decimal('amount', 12, 2);
            $t->date('issued_on');
        });

        DB::table('invoices')->insert([
            ['client' => 'Nabajyoti Ltd', 'city' => 'Guwahati', 'status' => 'paid', 'amount' => 4200, 'issued_on' => '2026-07-02'],
            ['client' => 'Rekha Stores', 'city' => 'Jorhat', 'status' => 'pending', 'amount' => 1800, 'issued_on' => '2026-07-11'],
            ['client' => 'Bora Traders', 'city' => 'Guwahati', 'status' => 'paid', 'amount' => 6100, 'issued_on' => '2026-08-01'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (env('JEEVES_CONFORMANCE') !== '1') {
            $this->markTestSkipped('Set JEEVES_CONFORMANCE=1 to run (real API calls).');
        }

        if (!env('JEEVES_CONFORMANCE_KEY')) {
            $this->markTestSkipped('Set JEEVES_CONFORMANCE_KEY to the provider API key.');
        }

        $this->driver = (string) (env('JEEVES_LLM_DRIVER') ?: 'gemini');

        config([
            'jeeves.llm.driver' => $this->driver,
            "jeeves.llm.providers.{$this->driver}.api_key" => env('JEEVES_CONFORMANCE_KEY'),
            // Every question must reach the provider. A cache hit would report
            // a pass for a call that never happened.
            'jeeves.cache.enabled' => false,
            'jeeves.feedback.enabled' => false,
            // Whatever discovery produces, uncurated -  the state of an install
            // on day one.
            'jeeves.system_instructions' => '',
        ]);

        if ($model = env('JEEVES_CONFORMANCE_MODEL')) {
            config(["jeeves.llm.providers.{$this->driver}.model" => $model]);
        }

        if ($ca = env('JEEVES_SSL_VERIFY')) {
            config(['jeeves.ssl_verify' => $ca]);
        }

        $path = sys_get_temp_dir() . '/nq-conformance-' . getmypid();
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        config(['jeeves.schema.config_path' => $path]);

        $this->seedInvoices();
        $this->forgetSchemaBoundServices();

        Artisan::call('jeeves:discover', [
            '--table' => 'invoices',
            '--output' => $path,
            '--no-verify' => true,
            '--force' => true,
        ]);

        $this->forgetSchemaBoundServices();
    }

    private function forgetSchemaBoundServices(): void
    {
        foreach ([
            SchemaRegistry::class,
            QueryOrchestrator::class,
            ConversationManager::class,
            SqlBuilder::class,
            PromptBuilder::class,
            QueryPlanner::class,
            NextStepSuggester::class,
            IntentCoverage::class,
            SchemaIntrospectorInterface::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    private function pace(): void
    {
        if ($delay = (int) env('JEEVES_CONFORMANCE_DELAY', 0)) {
            sleep($delay);
        }
    }

    /** @return array<int, array<string, mixed>> rows, as plain arrays */
    private function rows(array $result): array
    {
        return array_map(fn ($r) => array_change_key_case((array) $r), $result['rows'] ?? []);
    }

    /**
     * What to print beside a result.
     *
     * An errored answer has no `rows`, so reporting rows alone rendered a rate
     * limit as "[]" -  indistinguishable from a query that ran and matched
     * nothing. Two Mistral failures looked like wrong SQL and were the free
     * tier throttling. A harness whose failures need their own investigation
     * is not doing its job.
     */
    private function detail(array $result): string
    {
        if (($result['status'] ?? null) === 'error') {
            return '[' . ($result['error_code'] ?? 'error') . '] '
                . mb_substr((string) ($result['error'] ?? ''), 0, 90);
        }

        if (($result['status'] ?? null) === 'clarification_needed') {
            return '[clarification] ' . mb_substr((string) ($result['message'] ?? ''), 0, 80);
        }

        return json_encode($this->rows($result));
    }

    /** The single numeric value in a one-row answer, whatever it got called. */
    private function scalar(array $result): ?float
    {
        $rows = $this->rows($result);

        if (count($rows) !== 1) {
            return null;
        }

        foreach ($rows[0] as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function record(string $case, bool $passed, string $detail = ''): void
    {
        $this->results[] = compact('case', 'passed', 'detail');
    }

    private function check(string $case, bool $passed, string $detail = ''): void
    {
        $this->record($case, $passed, $detail);
    }

    protected function tearDown(): void
    {
        if ($this->results) {
            $failed = array_filter($this->results, fn ($r) => !$r['passed']);
            $lines = ['', '  CONFORMANCE -  ' . strtoupper($this->driver ?? '?'), ''];

            foreach ($this->results as $r) {
                $lines[] = sprintf('  %-5s %-46s %s', $r['passed'] ? 'ok' : 'FAIL', $r['case'], $r['detail']);
            }

            $lines[] = '';
            $lines[] = sprintf('  %d/%d passed', count($this->results) - count($failed), count($this->results));
            $lines[] = '';

            fwrite(STDERR, implode("\n", $lines) . "\n");
        }

        parent::tearDown();
    }

    // =====================================================================

    #[Test]
    public function it_conforms_on_every_documented_feature()
    {
        $o = $this->app->make(QueryOrchestrator::class);

        // ---------------------------------------------------- one-shot answers
        $r = $o->query('total amount');
        $this->check('aggregation: total amount = 12100', $this->scalar($r) == 12100.0, $this->detail($r));
        $this->pace();

        $r = $o->query('total amount by city');
        $rows = $this->rows($r);
        $this->check('breakdown: by city = 2 rows', count($rows) === 2, $this->detail($r));
        $this->pace();

        $r = $o->query('how many invoices are there');
        $this->check('count: how many invoices = 3', $this->scalar($r) == 3.0, $this->detail($r));
        $this->pace();

        $r = $o->query('how many invoices are pending');
        $this->check('count + filter: pending = 1', $this->scalar($r) == 1.0, $this->detail($r));
        $this->pace();

        $r = $o->query('average amount');
        $avg = $this->scalar($r);
        $this->check('average: = 4033.33 (not the sum)', $avg !== null && abs($avg - 4033.33) < 1, $this->detail($r));
        $this->pace();

        $r = $o->query('total amount in Guwahati');
        $this->check('total + filter: Guwahati = 10300', $this->scalar($r) == 10300.0, $this->detail($r));
        $this->pace();

        $r = $o->query('top clients by amount');
        $rows = $this->rows($r);
        $this->check('ranking: 3 clients, Bora first',
            count($rows) === 3 && str_contains(strtolower(json_encode($rows[0])), 'bora'), $this->detail($r));
        $this->pace();

        // ------------------------------------------------------- conversation
        $c = $this->app->make(ConversationManager::class);
        $session = 'conformance-' . getmypid();

        $c->query($session, 'total amount by city');
        $this->pace();

        // The chain that exposed the dropped filter.
        $r = $c->query($session, 'only in Guwahati');
        $rows = $this->rows($r);
        $this->check('follow-up narrowing: 1 row, 10300',
            count($rows) === 1 && str_contains(strtolower($this->detail($r)), 'guwahati')
                && str_contains($this->detail($r), '10300'),
            $this->detail($r));
        $this->check('narrowing is shown in the state summary',
            stripos((string) ($r['state_summary'] ?? ''), 'guwahati') !== false,
            (string) ($r['state_summary'] ?? ''));
        $this->pace();

        // The filter must SURVIVE a change of breakdown: Guwahati has two
        // clients, so three rows means it was silently dropped.
        $r = $c->query($session, 'breakdown by client');
        $rows = $this->rows($r);
        $this->check('drill-down keeps the filter: 2 clients, not 3',
            count($rows) === 2, $this->detail($r));
        $this->pace();

        // A question naming its own measure inherits nothing.
        $r = $c->query($session, 'how many invoices are pending');
        $this->check('new topic drops the inherited filter: = 1',
            $this->scalar($r) == 1.0, $this->detail($r));
        $this->pace();

        // ------------------------------------------------------------ rewind
        $before = $c->state($session)['state_summary'] ?? '';
        $rewound = $c->rewind($session);
        $this->check('rewind returns a restored state',
            ($rewound['status'] ?? '') === 'success' && ($rewound['state_summary'] ?? '') !== $before,
            (string) ($rewound['state_summary'] ?? ''));

        // ------------------------------------------------------------ periods
        //
        // The seeded invoices straddle two months on purpose: two in July
        // (4200 + 1800 = 6000) and one in August (6100). A period that is
        // silently ignored returns 12100 and looks like a perfectly good
        // answer, so the wrong number here is the whole point.
        $this->pace();
        $r = $o->query('total amount in July 2026');
        $this->check('period: July = 6000 (not 12100)', $this->scalar($r) == 6000.0, $this->detail($r));

        $this->pace();
        $r = $o->query('total amount in August 2026');
        $this->check('period: August = 6100', $this->scalar($r) == 6100.0, $this->detail($r));

        // --------------------------------------------------------- multi-step
        //
        // The most complex path in the package and the one never checked
        // against a real provider: the question is decomposed, each step is
        // answered separately, and the parts are combined. The steps must carry
        // the periods they actually used -  "last month" resolving differently
        // from what the user meant is invisible in a combined figure.
        $this->pace();
        $r = $o->query('compare total amount in July 2026 and August 2026');
        $steps = $r['steps'] ?? [];
        $values = array_map(
            fn ($s) => (float) (array_values((array) (($s['rows'][0] ?? [])))[0] ?? 0),
            $steps
        );
        sort($values);

        $this->check('multi-step: two steps, 6000 and 6100',
            count($steps) === 2 && $values === [6000.0, 6100.0],
            count($steps) . ' step(s): ' . json_encode($values) ?: $this->detail($r));

        $this->check('multi-step: each step states its period',
            count($steps) === 2
                && !empty($steps[0]['period'] ?? null)
                && !empty($steps[1]['period'] ?? null),
            json_encode(array_column($steps, 'period')));

        // --------------------------------------------------------- next steps
        //
        // Schema-derived, so no API call is made for them and they can only
        // propose breakdowns the validator would accept. A front end renders
        // them as buttons; empty means the widget shows a dead end.
        $this->pace();
        $r = $o->query('total amount by city');
        $next = $r['next_steps'] ?? [];
        $this->check('next steps: suggestions offered, each with a query',
            count($next) > 0 && !empty($next[0]['query'] ?? null),
            json_encode(array_column($next, 'label')));

        // ---------------------------------------------------------- reporting
        $failed = array_filter($this->results, fn ($r) => !$r['passed']);

        $this->assertSame(
            [],
            array_map(fn ($r) => $r['case'] . ' → ' . $r['detail'], $failed),
            strtoupper($this->driver) . ' failed conformance'
        );
    }
}
