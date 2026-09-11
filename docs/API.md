# HTTP API reference

The bundled widget is a reference implementation. Most applications will build
their own front end in React, Vue, Inertia or Blade -  and for those, this
document is the package.

Everything here is covered by `tests/Feature/ApiContractTest.php`. Fields are
not removed or renamed without a changelog entry.

All routes sit under `config('jeeves.routes.prefix')`, `jeeves` by
default.

---

## Authorization and CORS

Routes ship on `['web', 'throttle:60,1']`, plus an authorization check the
package **always** applies whatever you put in that list:

| | Who gets in |
|---|---|
| A `viewJeeves` gate defined by your app | Whatever the gate says -  always, including "no" in local |
| No gate, `local` or `testing` | Everyone. The package works the moment it is installed |
| No gate, anywhere else | Signed-in users only |

```php
// Define this as soon as it is more than you.
Gate::define('viewJeeves', fn ($user) => $user->isAdmin());
```

Refusals are **403 with a message naming the gate** -  never a redirect. There
may be nowhere to redirect to: `auth` used to be the default, and on a fresh
Laravel app with no login route that turned an unauthenticated visit into
`RouteNotFoundException: Route [login] not defined`, a 500 on the demo page.
Add `auth` back yourself if your app has auth scaffolding and you want the
redirect.

`GET /widget.js` is deliberately public -  it is a static file with no data and
no key in it, loaded by every page embedding the widget. The endpoints it calls
are gated.

**These endpoints spend money on every request.** An ungated one in production
is an LLM proxy for the internet, and `limits.queries_per_day` is then counted
per IP.

`web` means session cookies, correct for a Blade app or an SPA served from the
same domain.

**A front end on a different origin** (a Vite dev server, a mobile client) needs
token auth and CORS:

```php
// config/jeeves.php
'routes' => ['middleware' => ['api', 'auth:sanctum', 'throttle:60,1']],

// config/cors.php
'paths' => ['api/*', 'jeeves/*'],
'supports_credentials' => true,   // only if you use cookies
```

Without the CORS entry the browser blocks the response before your code sees it,
and it looks like a network error rather than a policy one.
`php artisan jeeves:doctor` checks for this.

---

## Asking a question

### `POST /text` -  one-shot question

```json
{ "text": "top 5 customers by revenue", "dataset": "orders" }
```

`dataset` is optional; omit it to let the package route the question.

### `POST /conversation` -  a turn in a conversation

```json
{ "session_id": "abc-123", "text": "only in West", "dataset": null }
```

Identical response, plus `state`, `state_summary` and `conversation`. Follow-ups
resolve against the stored state and are never served from the query cache.

### Voice -  there is no audio endpoint

Speech is recognised **in the browser** and posted to `/text` as ordinary text.
That is the whole design, and it is why voice needs no configuration and works
with every LLM, local or hosted: by the time the model is involved it is
reading a sentence.

```js
const sr = new (window.SpeechRecognition || window.webkitSpeechRecognition)();
sr.lang = 'en-IN';                     // an English accent; see below
sr.onresult = (e) => ask(e.results[0][0].transcript);   // POST /text
sr.start();
```

Chrome, Edge and Safari have `SpeechRecognition`; Firefox does not, so hide the
microphone there and let people type. Feature-detect -  do not sniff the agent.

**This package is English-only by design.** `sr.lang` selects an English accent
(`en-US`, `en-GB`, `en-IN`, `en-AU`) and that genuinely improves recognition.
Another language may appear to work because the browser will attempt it, but
nothing downstream is built for it -  the prompts, the schema descriptions and
the answer text are all English. Multilingual is a separate package with its
own speech pipeline.

The package accepts no audio at all: nothing to upload, no recording reaching
your server or any model provider.

---

## The answer

```jsonc
{
  "status": "success",
  "type": "ranking",              // ranking | single_result | aggregation | multi_step | no_data
  "answer": "Top 5 customers by revenue: …",   // a sentence, for display. The
                                               // dimension ("customers") is named
                                               // only when the engine built the
                                               // query and knows it; in
                                               // sql_generation mode the sentence
                                               // omits it rather than guess
  "speech_text": "Here are the …",             // phrased for reading aloud
  "rows": [ { "customer_name": "Ada", "revenue": "2028763.00" } ],
  "visualization": "bar",         // bar | table | card | steps | message
  "insights": { "count": 5, "total": "…", "average": "…", "min": "…", "max": "…" },

  "parsed_query": {
    "dataset": "orders",
    "metric": "revenue",
    "group_by": "customer_name",  // what the rows ARE. `null` on an ungrouped
                                  // total, and `null` in sql_generation mode,
                                  // where the engine did not write the query
    "filter_column": "region",    // the column a single filter matched on
    "filters": [ { "column": "region", "value": "West" } ],   // all filters in force
    "period": "2026-07-01 to 2026-07-31",   // the dates actually applied, or
                                  // `null` -  never a range the query did not
                                  // use, and never a period a model claimed
    "date_from": "2026-07-01",    // the same window as two dates rather than a
    "date_to": "2026-07-31",      // label, so a follow-up can inherit it.
                                  // Added in 2.2.1. Both `null` in
                                  // sql_generation mode, where the model wrote
                                  // the WHERE and the engine cannot read it
    "group_value": "West",
    "limit": 5,
    "order": "DESC",
    "query_type": "ranking"
  },

  "next_steps": [                 // schema-derived; no API call was made for
                                  // these, and none carries a value from your
                                  // rows - clicking one sends it to a provider
    { "label": "Revenue by category", "query": "revenue by product_category" }
  ],

  "metadata": {
    "request_id": "…", "processing_time_ms": 812,
    "processing_mode": "gemini", "query_mode": "auto",
    "query_mode_used": "intent",  // who wrote the SQL that ran: intent
                                  // (SqlBuilder) | sql_generation (the model,
                                  // including a retry) | pinned
    "cache_hit": false,

    // What the question cost, when the provider reports it. Summed across
    // every call the question took -  a fallback, a retry, the steps of a
    // decomposed question -  because those are one question to the user.
    // Absent on a cache hit, and absent rather than zero when unreported.
    "usage": { "prompt_tokens": 1200, "completion_tokens": 80,
               "thinking_tokens": 25, "total_tokens": 1305, "calls": 1 }
  }
}
```

The generated SQL is **not** in the response. A browser has no use for it and
it describes the shape of your database. Server-side, the `QuestionAnswered`
event carries it -  see [Events and cost](#events-and-cost) below.

**Read `parsed_query` before trusting a number.** It states what the question was
understood to mean -  which measure, which breakdown, which filters, which dates.
Showing it is how a user catches a misreading instead of believing a total that
answers a different question.

`parsed_summary` is the same thing as one readable line, for when you want to
put it on screen rather than branch on it:

```json
"parsed_summary": "Orders · revenue · by region · status is pending"
```

The bundled widget renders it under every answer. If you have built your own
front end, this is the single most useful string in the response -  roughly one
question in five is misread on an uncurated schema, and a misreading that is
displayed is a misreading the user can correct.

On a conversation turn the equivalent field is `state_summary`, which also
accounts for anything carried over from earlier turns. Prefer it when present.

One limitation worth knowing: in `sql_generation` mode a filter lives inside
the generated SQL rather than in a structured field, so the line names the
dataset and measure but may not name the filter. `intent` mode, and the intent
half of `auto`, carry filters explicitly and always name them.

### Multi-step answers

A question needing several queries returns `type: "multi_step"`:

```jsonc
{
  "type": "multi_step",
  "answer": "Revenue is 11,503,983 (this year) versus 9,507,105 (last year) -  up 21%.",
  "steps": [
    { "n": 1, "question": "total revenue this year", "status": "success",
      "period": "2026-01-01 to 2026-12-31", "rows": [ … ], "answer": "…" }
  ],
  "comparison": { "change_pct": 21.0, "direction": "up",
                  "first_value": 11503983, "second_value": 9507105 }
}
```

Render the steps, not just the conclusion. Each carries the `period` it used - 
"last year" can mean a calendar year or a trailing twelve months, and the
difference is invisible in the total alone. `comparison` is `null` when the
steps are not comparable; a percentage is never invented.

### Clarifications

```jsonc
{
  "status": "clarification_needed",
  "type": "metric_clarification",   // dataset_clarification | metric_clarification
                                    // | slot_clarification | refinement_cap
  "message": "What metric would you like to see for this dataset?",

  // On metric_clarification. Render each as a button that re-sends the
  // question with that metric named.
  "available_metrics": [
    { "key": "revenue", "description": "Total order value", "type": "currency", "computed": false }
  ],

  // On dataset_clarification ONLY -  empty on every other type. A dataset button
  // offered next to metric buttons re-sends the dataset the request already had
  // and redraws the same card, which reads as a broken button.
  "alternatives": [
    { "dataset_name": "Orders", "dataset_key": "orders", "confidence": 0.5 }
  ],

  "parsed_query": { "dataset": "orders", "metric": null, "group_value": null },
  "metadata": { "confidence": 0.4 }
}
```

**HTTP 200** -  the user is being asked a question, not told about a failure.

---

## Errors

```jsonc
{
  "status": "error",
  "error_code": "rate_limited",
  "error": "The AI service is receiving too many requests right now…",
  "retryable": true
}
```

Branch on `error_code`, never on `error` -  the message is written for a person
and gets reworded.

| `error_code` | HTTP | Retryable | Meaning |
|---|---|---|---|
| `blocked` | 400 | no | Input guard refused it before any provider saw it |
| `not_understood` | 422 | no | Could not be read as a question about your data |
| `cannot_answer` | 422 | no | Understood, but the schema has no such measure or breakdown |
| `unsafe_sql` | 422 | no | Generated SQL failed validation. Never executed |
| `rate_limited` | 429 | **yes** | Provider is throttling. `Retry-After` header is set |
| `provider_error` | 502 | **yes** | Provider failed or returned something unusable |
| `database_error` | 500 | no | The database rejected or failed the query |
| `internal_error` | 500 | no | Anything unclassified |

Validation failures return **422** with Laravel's standard `errors` object.
Exceeding `limits.queries_per_day` returns **429** with
`metadata.limit_reached`.

**`database_error` carries the driver's own cause**, on MySQL and SQLite as
well as PostgreSQL -  *"Unknown column 'revenoo' in 'field list'"* rather than
a generic sentence.

Two things are stripped before you see it. The statement and its bindings, so
the SQL is never echoed back. And anything that is not a **schema** complaint:
the message is matched against a list of shapes that talk about structure - a
missing table, an unknown column, a bad function signature, a GROUP BY mistake,
a syntax error - and anything else falls back to *"An error occurred while
querying the database."*

That second filter exists because a few driver messages embed a **row value**:
MySQL's *"Truncated incorrect DOUBLE value: 'Rekha Stores'"*, PostgreSQL's
*"invalid input syntax for type numeric"*. Both are reachable from a SELECT
with an implicit cast, and both would otherwise land in the response and in the
`QuestionFailed` event, which most apps forward to a log drain. The cost is a
vaguer message for a fault nobody anticipated; the raw text is still logged
server-side. Nothing from it is ever sent to an LLM provider -  a retry is
rebuilt from the schema and the original question.

---

## Conversation

### `GET /conversation/{session}` -  read the state back

```jsonc
{
  "status": "success",
  "state": { "dataset": "orders", "metric": "revenue", "group_by": "customer_name",
             "filters": [ { "column": "region", "value": "West" } ] },
  "state_summary": "Orders · revenue · by customer name · region is West",
  "conversation": { "turn": 2, "refinements": 1, "context_active": true,
                    "can_rewind": true, "max_refinements": 6 }
}
```

Call this after a page reload. The state lives on the server, so without it the
next follow-up resolves against context the user can no longer see.

### `POST /conversation/{session}/rewind`

```json
{ "steps": 1 }
```

Restores an earlier turn exactly, rather than re-interpreting the conversation.
Returns the same body as `GET /conversation/{session}`, with
`conversation.rewound: true` and a fresh `can_rewind` -  so one call tells you
both where you landed and whether going back again is possible.

`{"status": "error"}` when there is nothing behind the current turn.

**Offer this, not just a reset.** If the only correction available is clearing
the conversation, undoing "only in West" means retyping everything before it,
and people start over instead of refining -  which is the behaviour the
conversation endpoints exist to make unnecessary.

### Surviving a page reload

The state lives on the server, keyed by session id. A front end that mints a
fresh id on every load orphans it: the filters are still held, but unreachable,
so the reload silently starts over. Persist the session id (`sessionStorage`
scopes it to the tab, which is usually what you want), then call
`GET /conversation/{session}` on mount and show `state_summary` if
`context_active` is true.

The rendered answers are not stored server-side, so the thread itself cannot be
restored. Say what is still in force rather than implying the screen was
recovered -  the next follow-up resolves against those filters either way.

### `DELETE /conversation/{session}`

Forgets everything. Offer this: after several refinements nobody remembers which
filters are live.

### Turn classification

Each turn is reported as `conversation.classification`:

| | Example | Effect |
|---|---|---|
| `new_query` | "show me pending orders" | Inherits nothing |
| `refinement` | "only in West" | Merged into the state; filters accumulate |
| `drill_down` | "break that down by status" | Changes the breakdown, keeps filters |
| `reference` | "why is that?" | Same query, different output |

A question naming its own measure is always `new_query`, whatever it starts
with -  so "only revenue by region" does not inherit last turn's filters.

---

## Describing the schema

### `GET /datasets` -  what can be asked

```jsonc
{
  "datasets": [ {
    "key": "orders", "name": "Orders", "description": "…", "aliases": ["sales"],
    "metrics": [ { "key": "revenue", "description": "…", "computed": false } ],
    "dimensions": ["customer_name", "region", "product_category"],
    "default_dimension": "customer_name",
    "date_column": "order_date",
    "examples": ["Top 10 by revenue", "Revenue by region"]
  } ],
  "total": 1
}
```

Everything needed for a "what can I ask?" panel. `dimensions` excludes measures,
so a client cannot offer a breakdown the engine would refuse.

`GET /datasets?dataset=orders` returns one dataset, or **404** naming the ones
that exist.

---

## Operations

| Route | Purpose |
|---|---|
| `GET /health` | Provider and database reachability. **503** when unhealthy -  probe this |
| `GET /cache-stats` | Cache size and hit counts |
| `POST /clear-cache` | `{ dataset?, older_than_days?, min_hits? }` |
| `POST /feedback` | `{ query, dataset, correction?, corrected_sql?, feedback_type? }` -  fed into later prompts |
| `GET /feedback/stats` | Correction counts |
| `GET /widget.js` | The reference widget, no publish step |
| `GET /demo` | A working page to check a new install against before writing any front end |
| `GET /` | Package version and the endpoint list |

---

## Notes for front-end authors

**Show `state_summary`.** It is the difference between a user catching a
misreading and trusting a wrong number.

**Render `next_steps` as buttons.** Knowing what can be asked is harder than
phrasing it, and these cost no API call.

**Respect `retryable`.** Retrying a `cannot_answer` will never succeed; retrying
a `rate_limited` after `Retry-After` usually will.

**Never render `rows` for a `multi_step` answer alone** -  the steps carry the
working, and a combined figure nobody can trace is worth less than two they can.

---

## Events and cost

An AI feature spends money on every request and can be confidently wrong, so an
application needs to see what its own feature is doing. Four events give it
somewhere to stand -  listen to none and nothing changes.

```php
use Jayanta\Jeeves\Events\QuestionAnswered;

Event::listen(QuestionAnswered::class, function ($e) {
    AiUsage::create([
        'user_id'  => auth()->id(),
        'question' => $e->question,
        'sql'      => $e->sql,          // server-side only; never in the HTTP response
        'rows'     => $e->rowCount,
        'ms'       => $e->durationMs,
        'tokens'   => $e->usage['total_tokens'] ?? null,
        'cached'   => $e->cacheHit,
    ]);
});
```

| Event | When | Use it for |
|---|---|---|
| `QuestionAsked` | After the input guard, before any spending | Cost attribution, your own quotas, audit |
| `QuestionAnswered` | A successful answer | Usage dashboards, slow-query review, "answered badly" queues |
| `QuestionFailed` | An error -  **not** a clarification | Alerting. `errorCode` separates a provider outage from an unanswerable question |
| `UnsafeSqlRejected` | The validator refused generated SQL | Security. Should be near-silent; a burst from one user is not |

`QuestionAnswered` carries a **row count, never the rows**. A listener that
wants the data can re-run the SQL -  putting result rows on an event walks them
into log drivers, queue payloads and error trackers, which is the one direction
this package exists to keep data out of.

### What a question cost

`metadata.usage` reports tokens whenever the provider says, in either dialect
(Gemini's `usageMetadata`, OpenAI's `usage`):

```jsonc
"usage": { "prompt_tokens": 1200, "completion_tokens": 80,
           "thinking_tokens": 25, "total_tokens": 1305, "calls": 1 }
```

Counts **accumulate across every call one question took** -  a fallback, a
retry, the steps of a decomposed question -  because those are one question to
the user. A cache hit reports nothing, which is the point of the cache, and a
provider that returns no usage block reports nothing rather than zero: an
omitted figure is honest, a zero understates a bill.

This matters because `limits.queries_per_day` counts **questions**, which is a
rough proxy -  a question against a two-table schema and one against a
fourteen-table schema differ by an order of magnitude in prompt tokens. Use the
event to meter what you are actually spending.

Custom providers opt in by implementing `Contracts\ReportsUsage`; those that
do not are unaffected.

## Feedback / Training

Users can submit corrections. These are fed into future prompts so the AI learns:

```bash
curl -X POST /jeeves/feedback \
  -d '{"query":"show top customers","dataset":"orders","correction":"Should rank by SUM(quantity * unit_price), not the amount column","feedback_type":"wrong_metric"}'
```
