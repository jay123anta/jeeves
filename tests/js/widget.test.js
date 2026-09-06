/**
 * The widget, driven in a real DOM.
 *
 * Every user-facing defect this package has had was found by someone clicking,
 * not by the PHP suite -  the thread that replaced itself, the dataset button
 * that did nothing, the two number formats in one card. None of those could
 * fail a test that never renders anything.
 *
 * So the widget is mounted in jsdom, given scripted responses, and clicked.
 * Speech synthesis is stubbed and asserted on: what can be checked is that the
 * button exists, is wired, and speaks the right text. Whether audio actually
 * comes out of a speaker is the one thing no test can tell you.
 *
 *   node tests/js/widget.test.js
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const WIDGET = path.join(__dirname, '..', '..', 'resources', 'js', 'jeeves-widget.js');

let passed = 0;
let failed = 0;

/**
 * Awaited, always.
 *
 * The first version called fn() without awaiting, so an async case that threw
 * printed "ok" and then killed the process with an unhandled rejection -  a
 * green line for a test that had failed. A harness that can report a pass for
 * a failure is worse than no harness.
 */
async function check(name, fn) {
    try {
        await fn();
        passed++;
        console.log('  ok   ' + name);
    } catch (e) {
        failed++;
        console.log('  FAIL ' + name + '\n         ' + e.message);
    }
}

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

function assertContains(haystack, needle, message) {
    assert(String(haystack).includes(needle), (message || '') + '\n         expected to contain: ' + needle + '\n         actual: ' + String(haystack).slice(0, 300));
}

/** A widget mounted in a fresh DOM, with fetch and speech stubbed. */
function mount(options, responder) {
    const dom = new JSDOM('<!doctype html><html lang="en-GB"><body><div id="nq"></div></body></html>', {
        url: 'http://localhost',
        runScripts: 'outside-only',
    });

    const { window } = dom;
    const spoken = [];
    const requests = [];

    window.SpeechSynthesisUtterance = function (text) { this.text = text; };
    window.speechSynthesis = {
        speak(u) { spoken.push(u.text); if (u.onstart) u.onstart(); },
        cancel() {},
        speaking: false,
    };

    // Faithful to a real Response: status, and BOTH json() and text().
    // The first version only had json(), so it could not represent a 401 or a
    // 419 -  exactly the framework-level refusals a new adopter hits first, and
    // exactly the ones the widget was mishandling.
    window.fetch = function (url, init) {
        requests.push({ url, init, body: init && init.body ? JSON.parse(init.body) : null });
        const payload = responder(url, requests[requests.length - 1].body);
        const status = (payload && payload.__status) || 200;
        const body = (payload && payload.__body !== undefined)
            ? payload.__body
            : JSON.stringify(payload);

        return Promise.resolve({
            ok: status >= 200 && status < 300,
            status,
            text: () => Promise.resolve(body),
            json: () => Promise.resolve(JSON.parse(body)),
        });
    };

    window.eval(fs.readFileSync(WIDGET, 'utf8'));

    const widget = window.JeevesWidget.mount('#nq', Object.assign({ baseUrl: '/nq' }, options || {}));

    return { window, widget, spoken, requests, root: window.document.getElementById('nq') };
}

/** Let the stubbed promise chain settle. */
const settle = () => new Promise((r) => setTimeout(r, 0));

function answer(overrides) {
    return Object.assign({
        status: 'success',
        type: 'ranking',
        answer: 'Top 2 customers by revenue: Patgiri Traders, Kalita Stores',
        speech_text: 'Here are the top customers.',
        rows: [
            { customer_name: 'Patgiri Traders', revenue: 2028763 },
            { customer_name: 'Kalita Stores', revenue: 1878404 },
        ],
        metadata: { processing_time_ms: 120 },
    }, overrides || {});
}

async function run() {
    console.log('\n  WIDGET -  rendered and clicked in jsdom\n');

    // ---------------------------------------------------------------- basics
    await check('it renders an input and an Ask button', () => {
        const { root } = mount({}, () => answer());
        assert(root.querySelector('.nq-input'), 'no input rendered');
        assert(root.textContent.includes('Ask'), 'no Ask button');
    });

    // ----------------------------------------------------------- the thread
    {
        const ctx = mount({}, () => answer());
        ctx.widget.input.value = 'top 5 customers by revenue';
        ctx.widget.submit();
        await settle();

        await check('the question appears in the thread', () => {
            assertContains(ctx.root.textContent, 'top 5 customers by revenue');
        });

        await check('the input is cleared so a follow-up can be typed', () => {
            assert(ctx.widget.input.value === '', 'input still holds: ' + ctx.widget.input.value);
        });

        await check('the answer renders', () => {
            assertContains(ctx.root.textContent, 'Patgiri Traders');
        });

        ctx.widget.input.value = 'only in West';
        ctx.widget.submit();
        await settle();

        await check('a second turn APPENDS rather than replacing the first', () => {
            assertContains(ctx.root.textContent, 'top 5 customers by revenue');
            assertContains(ctx.root.textContent, 'only in West');
            assert(ctx.root.querySelectorAll('.nq-turn').length === 2,
                'expected 2 turns, got ' + ctx.root.querySelectorAll('.nq-turn').length);
        });
    }

    // ------------------------------------------------------------ the state
    {
        const ctx = mount({}, () => answer({
            state_summary: 'Orders · revenue · by customer name · region is West',
        }));
        ctx.widget.input.value = 'only in West';
        ctx.widget.submit();
        await settle();

        await check('the resolved state is shown above the answer', () => {
            const el = ctx.root.querySelector('.nq-state');
            assert(el, 'no state line rendered');
            assertContains(el.textContent, 'region is West');
            assertContains(el.textContent, 'Reading this as');
        });
    }

    // -------------------------------------------------------------- speech
    {
        const ctx = mount({ tts: true }, () => answer());
        ctx.widget.input.value = 'top customers';
        ctx.widget.submit();
        await settle();

        await check('a speak control is offered on the answer', () => {
            assert(ctx.root.querySelector('.nq-speak'), 'no speaker button rendered');
        });

        await check('clicking it speaks the answer', () => {
            ctx.root.querySelector('.nq-speak').dispatchEvent(new ctx.window.Event('click'));
            assert(ctx.spoken.length === 1, 'speechSynthesis.speak was not called');
            assertContains(ctx.spoken[0], 'Here are the top customers');
        });
    }

    await check('speech is not offered when tts is turned off', async () => {
        const ctx = mount({ tts: false }, () => answer());
        ctx.widget.input.value = 'x';
        ctx.widget.submit();
        return settle().then(() => {
            assert(!ctx.root.querySelector('.nq-speak'), 'speaker button rendered with tts disabled');
        });
    });

    // --------------------------------------------------------- next steps
    {
        const ctx = mount({}, () => answer({
            next_steps: [{ label: 'Revenue by region', query: 'revenue by region' }],
        }));
        ctx.widget.input.value = 'top customers';
        ctx.widget.submit();
        await settle();

        await check('follow-up suggestions render as buttons', () => {
            assertContains(ctx.root.textContent, 'Revenue by region');
        });

        await check('clicking a suggestion asks that question', () => {
            const before = ctx.requests.length;
            const btns = Array.from(ctx.root.querySelectorAll('.nq-next .nq-btn'));
            const target = btns.find((b) => b.textContent === 'Revenue by region');
            assert(target, 'suggestion button not found');
            target.dispatchEvent(new ctx.window.Event('click'));
            assert(ctx.requests.length === before + 1, 'clicking the suggestion sent no request');
            assert(ctx.requests[before].body.text === 'revenue by region',
                'sent: ' + ctx.requests[before].body.text);
        });
    }

    // ------------------------------------------------------- clarification
    {
        const ctx = mount({}, () => ({
            status: 'clarification_needed',
            type: 'metric_clarification',
            message: 'What metric would you like to see?',
            alternatives: [],
            available_metrics: [{ key: 'revenue', description: 'Order line revenue' }],
        }));
        ctx.widget.input.value = 'who is the best';
        ctx.widget.submit();
        await settle();

        await check('a metric question offers metric buttons', () => {
            assertContains(ctx.root.textContent, 'Revenue');
        });

        await check('no dead dataset button appears beside them', () => {
            const buttons = Array.from(ctx.root.querySelectorAll('.nq-options .nq-btn'));
            assert(buttons.length === 1, 'expected exactly one option, got ' + buttons.length);
        });

        await check('clicking a metric asks again with it', () => {
            const before = ctx.requests.length;
            ctx.root.querySelector('.nq-options .nq-btn').dispatchEvent(new ctx.window.Event('click'));
            assert(ctx.requests.length === before + 1, 'clicking the metric sent no request');
            assertContains(ctx.requests[before].body.text, 'revenue');
        });
    }

    // ---------------------------------------------------------- new topic
    {
        const ctx = mount({}, () => answer());
        ctx.widget.input.value = 'top customers';
        ctx.widget.submit();
        await settle();

        await check('New topic appears once there is a thread', () => {
            assert(!ctx.widget.newTopicBtn.classList.contains('nq-hidden'), 'New topic still hidden');
        });

        await check('New topic clears the thread and forgets the session', () => {
            const before = ctx.widget.sessionId;
            ctx.widget.newTopicBtn.dispatchEvent(new ctx.window.Event('click'));
            assert(ctx.root.querySelectorAll('.nq-turn').length === 0, 'thread not cleared');
            assert(ctx.widget.sessionId !== before, 'session id was not renewed');
            const deletes = ctx.requests.filter((r) => r.init && r.init.method === 'DELETE');
            assert(deletes.length === 1, 'no DELETE sent to drop server-side context');
        });
    }

    // ------------------------------------------------------------ numbers
    {
        const intl = mount({ numberFormat: 'international' }, () => answer());
        intl.widget.input.value = 'x';
        intl.widget.submit();
        await settle();

        await check('numbers group the way the server was told to group them', () => {
            assertContains(intl.root.textContent, '2,028,763');
        });

        const indian = mount({ numberFormat: 'indian' }, () => answer());
        indian.widget.input.value = 'x';
        indian.widget.submit();
        await settle();

        await check('and follow the indian format when that is configured', () => {
            assertContains(indian.root.textContent, '20,28,763');
        });
    }

    // -------------------------------------------------------- multi-step
    {
        const ctx = mount({}, () => answer({
            type: 'multi_step',
            answer: 'Revenue is up 15.2%.',
            steps: [
                { n: 1, question: 'revenue in 2026', status: 'success', answer: 'Total: 21,011,088', rows: [] },
                { n: 2, question: 'revenue in 2025', status: 'success', answer: 'Total: 18,244,900', rows: [] },
            ],
        }));
        ctx.widget.input.value = 'compare this year with last year';
        ctx.widget.submit();
        await settle();

        await check('a decomposed answer shows its working', () => {
            assertContains(ctx.root.textContent, 'revenue in 2026');
            assertContains(ctx.root.textContent, 'revenue in 2025');
            assert(ctx.root.querySelectorAll('.nq-step').length === 2, 'expected 2 steps rendered');
        });

        await check('the steps stay inside ONE turn, not one thread each', () => {
            assert(ctx.root.querySelectorAll('.nq-turn').length === 1,
                'expected 1 turn, got ' + ctx.root.querySelectorAll('.nq-turn').length);
        });
    }

    // A step that reports a number without saying which period produced it is
    // how "last year" silently became a trailing twelve months.
    {
        const ctx = mount({}, () => answer({
            type: 'multi_step',
            answer: 'Revenue is down 44.6%.',
            steps: [
                { n: 1, question: 'total revenue this year', status: 'success',
                  period: '2026-01-01 to 2026-12-31', answer: 'Total: 11,503,983', rows: [] },
                { n: 2, question: 'total revenue last year', status: 'success',
                  period: '2025-01-01 to 2025-12-31', answer: 'Total: 9,507,105', rows: [] },
            ],
        }));
        ctx.widget.input.value = 'revenue this year versus last year';
        ctx.widget.submit();
        await settle();

        await check('each step states the period it actually used', () => {
            const periods = Array.from(ctx.root.querySelectorAll('.nq-step-period'))
                .map((el) => el.textContent);
            assert(periods.length === 2, 'expected a period on each step, got ' + periods.length);
            assertContains(periods[0], '2026-01-01');
            assertContains(periods[1], '2025-01-01');
        });
    }

    // --------------------------------------------------------------- errors
    await check('a failed request says so instead of hanging', async () => {
        const dom = new JSDOM('<!doctype html><html><body><div id="nq"></div></body></html>',
            { url: 'http://localhost', runScripts: 'outside-only' });
        dom.window.fetch = () => Promise.reject(new Error('offline'));
        dom.window.eval(fs.readFileSync(WIDGET, 'utf8'));
        const w = dom.window.JeevesWidget.mount('#nq', { baseUrl: '/nq' });
        w.input.value = 'anything';
        w.submit();
        return settle().then(() => {
            assertContains(dom.window.document.getElementById('nq').textContent, 'Could not reach');
        });
    });

    // ----------------------------------------------------------- chat layout
    //
    // The old layout was a search form: box on top, answers growing below it.
    // It taught people the wrong thing -  one question at a time -  and the
    // conversation features went unused because nothing on screen suggested a
    // conversation was on offer. These pin the chat shape in place.
    {
        const { root, widget } = mount({ examples: ['Revenue by region'] }, () => answer());

        await check('the composer sits BELOW the thread, not above it', () => {
            const kids = Array.from(root.querySelector('.nq-frame').children).map((el) => el.className);
            const thread = kids.findIndex((c) => c.includes('nq-scroll'));
            const composer = kids.findIndex((c) => c.includes('nq-composer'));
            assert(thread > -1 && composer > -1, 'frame is missing a thread or a composer: ' + kids.join(', '));
            assert(composer > thread, 'composer renders above the thread: ' + kids.join(', '));
        });

        await check('the input and the mic are both in the composer', () => {
            const composer = root.querySelector('.nq-composer');
            assert(composer.querySelector('.nq-input'), 'input is not in the composer');
            assert(composer.querySelector('.nq-mic'), 'mic is not in the composer');
        });

        await check('an empty thread suggests what to ask', () => {
            const empty = root.querySelector('.nq-empty');
            assert(empty, 'no empty state rendered');
            assertContains(empty.textContent, 'Revenue by region', 'examples missing from the empty state');
        });

        await check('the frame has a height, so the composer stays put', () => {
            assert(root.querySelector('.nq-frame').style.height === '520px',
                'expected a fixed frame height, got: ' + root.querySelector('.nq-frame').style.height);
        });

        // jsdom lays nothing out, so scrollHeight is 0 and any assertion about
        // scrolling passes trivially. Giving the element a real scrollHeight is
        // what makes the next check able to fail.
        Object.defineProperty(root.querySelector('.nq-scroll'), 'scrollHeight', { value: 4000 });

        widget.input.value = 'top 5 customers by revenue';
        widget.submit();
        await settle();

        await check('the suggestions clear once the conversation starts', () => {
            assert(!root.querySelector('.nq-empty'), 'empty state survived the first question');
        });

        await check('the question is a right bubble and the answer a left one', () => {
            const turn = root.querySelector('.nq-turn');
            assert(turn.querySelector('.nq-you'), 'no question bubble');
            assert(turn.querySelector('.nq-bot'), 'answer is not rendered as a bot bubble');
        });

        await check('the newest message is scrolled into view', () => {
            const scroll = root.querySelector('.nq-scroll');
            assert(scroll.scrollTop === 4000,
                'thread was left parked at the top of a frame it has outgrown (scrollTop ' + scroll.scrollTop + ')');
        });

        await check('New topic brings the suggestions back', () => {
            widget.newTopic();
            assert(root.querySelector('.nq-empty'), 'empty state did not return after New topic');
            assert(!root.querySelector('.nq-turn'), 'thread was not cleared');
        });
    }

    // "auto" rather than null is the documented value on purpose: an explicitly
    // passed null cannot survive Blade -  both `??` and @props read it as "not
    // given" and reinstate the default, so :height="null" looked like it worked
    // and did nothing. A string cannot be mistaken for absence.
    for (const value of ['auto', null, '']) {
        await check('height:' + JSON.stringify(value) + ' lets the widget grow with its content', () => {
            const { root } = mount({ height: value }, () => answer());
            const frame = root.querySelector('.nq-frame');
            assert(frame.classList.contains('nq-grow'), 'grow mode not applied');
            assert(!frame.style.height, 'a height was set anyway: ' + frame.style.height);
        });
    }

    // -------------------------------------------------------- going back
    //
    // Before this, the only correction on offer was New topic. Undoing "only
    // in West" meant retyping everything that came before it, so the cheap
    // move was to start over -  which is exactly the behaviour the conversation
    // features were built to make unnecessary.
    {
        const ctx = mount({}, (url) => {
            if (String(url).includes('/rewind')) {
                return {
                    status: 'success',
                    state: { metric: 'revenue' },
                    state_summary: 'Orders · revenue · by customer name',
                    conversation: { turn: 1, rewound: true, can_rewind: false },
                };
            }
            return answer({ conversation: { turn: 2, can_rewind: true } });
        });

        await check('undo is not offered before there is anything to undo', () => {
            assert(ctx.widget.rewindBtn.classList.contains('nq-hidden'), 'undo offered on an empty thread');
        });

        ctx.widget.input.value = 'top 5 customers by revenue';
        ctx.widget.submit();
        await settle();

        await check('undo appears when the SERVER says there is history', () => {
            assert(!ctx.widget.rewindBtn.classList.contains('nq-hidden'), 'undo still hidden after a turn');
        });

        ctx.widget.rewindBtn.dispatchEvent(new ctx.window.Event('click'));
        await settle();

        await check('it asks the server to rewind rather than guessing locally', () => {
            const req = ctx.requests.find((r) => String(r.url).includes('/rewind'));
            assert(req, 'no rewind request was made');
            assert(req.body.steps === 1, 'expected one step, got ' + JSON.stringify(req.body));
        });

        await check('the undone turn leaves the thread', () => {
            assert(ctx.root.querySelectorAll('.nq-turn').length === 0,
                'the answer that no longer describes the state is still on screen');
        });

        await check('and the state it went back to is stated', () => {
            const notice = ctx.root.querySelector('.nq-notice');
            assert(notice, 'no notice rendered');
            assertContains(notice.textContent, 'revenue', 'restored state not shown');
        });

        await check('undo hides itself once there is nothing left to undo', () => {
            assert(ctx.widget.rewindBtn.classList.contains('nq-hidden'),
                'undo still offered after the server said can_rewind:false');
        });
    }

    // ------------------------------------------------- surviving a reload
    //
    // The state lives on the server, keyed by session id. A fresh id per load
    // orphaned it: the filters were still held server-side but unreachable, so
    // a reload silently started over while the old context sat there.
    {
        const dom = new JSDOM('<!doctype html><html><body><div id="nq"></div></body></html>',
            { url: 'http://localhost', runScripts: 'outside-only' });
        const seen = [];
        dom.window.fetch = function (url) {
            seen.push(String(url));
            const body = JSON.stringify({
                status: 'success',
                state_summary: 'Orders · revenue · region is West',
                conversation: { context_active: true, can_rewind: true, turn: 2 },
            });
            return Promise.resolve({
                ok: true,
                status: 200,
                text: () => Promise.resolve(body),
                json: () => Promise.resolve(JSON.parse(body)),
            });
        };
        dom.window.eval(fs.readFileSync(WIDGET, 'utf8'));

        const first = dom.window.JeevesWidget.mount('#nq', { baseUrl: '/nq' });
        const firstSession = first.sessionId;
        await settle();

        // A reload is a second mount against the same tab's storage.
        dom.window.document.getElementById('nq').innerHTML = '';
        const second = dom.window.JeevesWidget.mount('#nq', { baseUrl: '/nq' });
        await settle();

        await check('a reload keeps the same conversation on the server', () => {
            assert(second.sessionId === firstSession,
                'session changed across a reload: ' + firstSession + ' → ' + second.sessionId);
        });

        await check('it asks the server what is still in force', () => {
            assert(seen.some((u) => u.includes('/conversation/' + firstSession)),
                'no state request made on mount: ' + seen.join(', '));
        });

        await check('and says what it picked up, rather than pretending to restore the thread', () => {
            const notice = dom.window.document.querySelector('.nq-notice');
            assert(notice, 'no resume notice rendered');
            assertContains(notice.textContent, 'region is West', 'resumed state not shown');
        });

        await check('New topic starts a genuinely new session', () => {
            second.newTopic();
            assert(second.sessionId !== firstSession, 'New topic reused the old session id');
            assert(dom.window.sessionStorage.getItem('nq-session:nq') === second.sessionId,
                'the new session was not persisted');
        });
    }

    // ------------------------------------------------------ voice is browser-only
    //
    // The package's design is that the BROWSER hears and the server only ever
    // receives English text. There used to be a MediaRecorder fallback that
    // uploaded audio for the server to transcribe; that is inbuilt speech
    // processing and belongs to the separate multilingual package. These stop
    // it creeping back.
    {
        await check('no microphone is offered when the browser cannot listen', () => {
            const { root, widget } = mount({}, () => answer());
            // jsdom has no SpeechRecognition, which is the Firefox situation.
            assert(widget.micBtn.classList.contains('nq-hidden'),
                'a microphone was offered in a browser with no speech recognition');
            assert(root.querySelector('.nq-input'), 'typing must still work');
        });

        await check('the widget has no way to record or upload audio', () => {
            const { widget } = mount({}, () => answer());
            assert(typeof widget.submitVoice === 'undefined', 'an audio upload path came back');
            assert(typeof widget.startRecording === 'undefined', 'a recorder came back');
        });

        await check('a question is only ever sent as text', async () => {
            const ctx = mount({ conversation: false }, () => answer());
            ctx.widget.input.value = 'revenue by region';
            ctx.widget.submit();
            await settle();

            const urls = ctx.requests.map((r) => String(r.url));
            assert(urls.every((u) => !u.includes('/voice')), 'something was posted to an audio endpoint');
            assert(urls.some((u) => u.endsWith('/text')), 'the question did not go to /text');
            assert(ctx.requests.every((r) => !r.body || !('audio' in r.body)), 'audio was included in a request body');
        });
    }

    // ------------------------------------------- what a new install fails with
    //
    // Verified against a virgin `laravel new` app: these are the refusals
    // Laravel raises before the request ever reaches the engine, and every one
    // of them used to reach the user as "Unexpected response status." -  which
    // describes nothing and suggests nothing. Each has a different fix.
    {
        const cases = [
            { status: 401, body: { message: 'Unauthenticated.' }, expect: 'signed in' },
            { status: 419, body: { message: 'CSRF token mismatch.' }, expect: 'session expired' },
            { status: 429, body: { message: 'Too Many Attempts.' }, expect: 'Too many questions' },
            { status: 500, body: { message: 'Server Error' }, expect: 'jeeves:doctor' },
        ];

        for (const c of cases) {
            await check('HTTP ' + c.status + ' is explained, not shrugged at', async () => {
                const ctx = mount({}, () => ({ __status: c.status, __body: JSON.stringify(c.body) }));
                ctx.widget.input.value = 'revenue by region';
                ctx.widget.submit();
                await settle();

                const text = ctx.root.textContent;
                assertContains(text, c.expect, 'HTTP ' + c.status + ' gave no actionable message');
                assert(!text.includes('Unexpected response status'),
                    'HTTP ' + c.status + ' still falls through to the useless message');
            });
        }

        // A 500 from Laravel is an HTML debug page, so json() throws. Reading
        // the body as text first is what makes that survivable.
        await check('an HTML error page does not crash the widget', async () => {
            const ctx = mount({}, () => ({ __status: 500, __body: '<!DOCTYPE html><h1>Whoops</h1>' }));
            ctx.widget.input.value = 'revenue by region';
            ctx.widget.submit();
            await settle();

            assertContains(ctx.root.textContent, 'jeeves:doctor', 'an HTML error page was not handled');
        });

        // The package's own errors already carry a code and a written message;
        // the HTTP layer must not talk over them.
        await check('the package\'s own error message is passed through untouched', async () => {
            const ctx = mount({}, () => ({
                __status: 502,
                __body: JSON.stringify({
                    status: 'error',
                    error_code: 'provider_error',
                    error: 'The AI service could not be reached.',
                    retryable: true,
                }),
            }));
            ctx.widget.input.value = 'revenue by region';
            ctx.widget.submit();
            await settle();

            assertContains(ctx.root.textContent, 'The AI service could not be reached.',
                'a considered error message was replaced by a generic one');
        });
    }

    // --------------------------------------- did this turn stand alone or not
    //
    // Asked after real use: two turns on screen, and no way to tell whether the
    // second inherited the first. The state summary cannot answer it -  "total
    // amount by city" then "breakdown by client" both read "amount · by client"
    // whether the metric was inherited or worked out afresh. Identical text,
    // two different things happening.
    {
        const kinds = [
            ['new_query', 'New topic', false],
            ['refinement', 'Follow-up', true],
            ['drill_down', 'Drill-down', true],
            ['reference', 'Same query', true],
        ];

        for (const [classification, label, carried] of kinds) {
            await check(classification + ' is shown as "' + label + '"', async () => {
                const ctx = mount({}, () => answer({
                    state_summary: 'Invoices · amount · by client',
                    conversation: { turn: 2, classification: classification },
                }));
                ctx.widget.input.value = 'breakdown by client';
                ctx.widget.submit();
                await settle();

                const badge = ctx.root.querySelector('.nq-kind');
                assert(badge, 'no turn-kind badge rendered for ' + classification);
                assert(badge.textContent === label, 'got "' + badge.textContent + '", wanted "' + label + '"');
                assert(badge.classList.contains('nq-kind-carried') === carried,
                    classification + ' marked as ' + (carried ? 'standalone' : 'carried') + ' -  the wrong way round');
                assert((badge.title || '').length > 0, 'no explanation on hover');
            });
        }

        await check('a one-shot answer carries no badge, since there is no thread', async () => {
            const ctx = mount({ conversation: false }, () => answer());
            ctx.widget.input.value = 'total amount by city';
            ctx.widget.submit();
            await settle();

            assert(!ctx.root.querySelector('.nq-kind'), 'a badge appeared with no conversation');
        });
    }

    await settle();

    console.log('\n  ' + passed + ' passed, ' + failed + ' failed\n');
    process.exit(failed === 0 ? 0 : 1);
}

run();
