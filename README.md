<!-- All five badges on ONE physical line, deliberately. Split across lines
     they render as one row on GitHub and as five stacked rows on Packagist,
     which uses a different Markdown renderer that treats a single newline as
     a break. One line renders identically everywhere. Do not reflow. -->
[![Packagist](https://img.shields.io/packagist/v/jayanta/jeeves.svg)](https://packagist.org/packages/jayanta/jeeves) [![Downloads](https://img.shields.io/packagist/dt/jayanta/jeeves.svg)](https://packagist.org/packages/jayanta/jeeves) [![Tests](https://github.com/jay123anta/jeeves/actions/workflows/tests.yml/badge.svg)](https://github.com/jay123anta/jeeves/actions/workflows/tests.yml) [![PHP](https://img.shields.io/packagist/dependency-v/jayanta/jeeves/php.svg)](https://packagist.org/packages/jayanta/jeeves) [![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

# Laravel Jeeves

**A natural-language query box you can put inside your own Laravel app - for
data you are not allowed to send anywhere.**

Natural language to SQL (text-to-SQL, NL2SQL) as a drop-in chat window: your
users ask in plain English, Jeeves writes the query, and your own server runs
it. The model is sent your schema structure and nothing else.

<p align="center">
  <img src="https://raw.githubusercontent.com/jay123anta/jeeves/main/docs/demo.gif" alt="Asking a database questions in English - revenue by city, narrowed to one city, then broken down by client - with the model receiving only table and column names" width="100%">
</p>

```blade
{{-- One line. A chat thread, follow-up questions, voice input, charts. --}}
<x-jeeves::widget />
```



Almost every "chat with your database" product works by sending rows to a
model. If the data is under GDPR or DPDP, or belongs to a client who has not
agreed to that, the conversation ends there.

This one sends the model your **schema structure only** - table names, column
names, types, and the words your users use for them. It returns SQL. Your
server validates that SQL, runs it locally, and formats the rows. **Not one row
is ever sent upstream**, and that is enforced by tests, not by intent.

Three things follow that a hosted tool cannot offer:

- **You can ship it to your own users.** It is a Blade component inside your
  application, not a separate analytics seat they have to buy and log in to.
- **The data-protection conversation is short.** What leaves is schema
  structure and the question someone typed. Nothing else has a path out - see
  [docs/SECURITY.md](docs/SECURITY.md) for how that is enforced and tested.
- **It can run with nothing leaving your network at all.** Point it at a model
  on your own hardware and even the schema stays in-house.

**Any model, hosted or your own.** Gemini, Claude, OpenAI, DeepSeek, Mistral,
Groq, OpenRouter - or a model you run yourself on Ollama, vLLM, LM Studio or
llama.cpp. One config block, no code changes.

People can ask by typing or by speaking - the browser does the listening, so no
audio reaches your server either.

One line puts a working chat window in any Blade view - thread, follow-up
questions, microphone, charts:

```blade
<x-jeeves::widget />
```

Conversation state is real, and it is resolved in PHP rather than left to the
model: ask *"revenue by city"*, then *"just Guwahati"*, then *"break that down
by client"*, then rewind. Each turn knows what the last one narrowed to.

Or call it yourself and render the result however you like:

```php
$result = Jeeves::query("top 5 customers by revenue");
```

---

## Install

Requires **PHP 8.2+** and **Laravel 12 or 13**. Works on PostgreSQL, MySQL,
MariaDB and SQLite.

```bash
composer require jayanta/jeeves
php artisan jeeves:install
php artisan migrate
```

**Generated SQL needs its own database connection.** It is written by a
language model, so it does not run on the connection your application writes
with — point `sql.database_connection` at a separate connection whose database
user holds `SELECT` only. `php artisan jeeves:doctor` checks it, and on
MySQL and PostgreSQL proves the user cannot write. The `GRANT` statements and
statement timeouts are in [docs/CONNECTION.md](docs/CONNECTION.md).

Choose a model in `.env`. A model you run yourself is a first-class choice, not
a fallback:

```env
# Local, no API key, nothing leaves your machine
JEEVES_LLM_DRIVER=ollama
OLLAMA_MODEL=llama3.3

# Or a hosted API
JEEVES_LLM_DRIVER=gemini
GEMINI_API_KEY=your-key-here
```

Built-in drivers: `ollama`, `gemini`, `openai`, `claude`. Any other
OpenAI-compatible service - DeepSeek, Groq, Mistral, OpenRouter, vLLM,
LM Studio, LocalAI - plugs in with a `base_url` and a `model`; see
[docs/PROVIDERS.md](docs/PROVIDERS.md).

## Teach it your data

```bash
php artisan jeeves:discover --ai
```

This is the whole adaptation step. The package knows nothing about your
application: this reads *your* database and writes one plain PHP file per table
into `config/jeeves-schemas/`. Those files are the only thing that makes
it understand your domain - no code changes, no subclassing.

`--ai` also fills in the human layer that cannot be read from a database:
descriptions, the words your users actually say, business rules, and computed
metrics like averages. **Worth doing** - without it, a question like "average
amount" costs an extra API call to answer.

Then check it:

```bash
php artisan jeeves:doctor
```

It names the real cause of any problem and prints the exact fix. Run it first
whenever something is wrong.

## Ask a question

```php
use Jayanta\Jeeves\Facades\Jeeves;

$result = Jeeves::query('total revenue by region last month');
```

```jsonc
{
  "status": "success",
  "answer": "Revenue by region: West 2,028,763; East 1,878,404",
  "speech_text": "Revenue by region. West, 2 million…",   // phrased to be read aloud
  "rows": [ { "region": "West", "revenue": "2028763.00" } ],
  "parsed_summary": "Orders · revenue · by region · 2026-07-01 to 2026-07-31",
  "parsed_query": {
    "metric": "revenue", "group_by": "region",
    "filters": [], "period": "2026-07-01 to 2026-07-31",
    "date_from": "2026-07-01", "date_to": "2026-07-31"
  }
}
```

**Show users how the question was read.** Every answer also carries
`parsed_summary` - the same information as one line, `"Orders · revenue · by
region · status is pending"` - and the bundled widget puts it under each
answer. It is the difference between someone catching a misreading and
believing a number that answers a different question. Use `parsed_query` when
you want the structure instead.

Or over HTTP, which is what the widget uses:

```bash
POST /jeeves/text          {"text": "top 5 customers by revenue"}
POST /jeeves/conversation  {"session_id": "abc", "text": "only in West"}
```

## Voice

**The browser listens. Your server only ever receives text.**

```blade
<x-jeeves::widget />   {{-- the microphone is already there --}}
```

There is nothing to configure and no audio endpoint. The widget uses the
browser's `SpeechRecognition` to turn speech into English text on the device,
then posts that text exactly as if it had been typed. Three things follow from
that one decision:

- **It works with every model** - Gemini, Claude, Ollama, anything - because by
  the time the model is involved it is reading a sentence, not hearing a
  recording.
- **No audio leaves the device.** Not to your server, not to a provider. There
  is no upload path in the package at all.
- **Nothing extra to set up or pay for** - no transcription service, no second
  API key, no added latency.

Answers carry a `speech_text` field phrased for reading aloud, and the widget
speaks it. Chrome, Edge and Safari support recognition; Firefox does not, so
the microphone is hidden there and people type - which is why text input is
never optional.

`language` picks which English **accent** to listen for - `en-IN` recognises
Indian English far more accurately than `en-US` does:

```blade
<x-jeeves::widget language="en-IN" />
```

English only, on purpose. Multilingual belongs to a separate package with a
speech pipeline of its own; this one stays an English natural-language-to-SQL
assistant. → [docs/WIDGET.md](docs/WIDGET.md)

## Who is allowed to ask

These endpoints spend your API key, so they are not open by default.

| | Who gets in |
|---|---|
| A `viewJeeves` gate you define | Whatever the gate says |
| No gate, `local` or `testing` | Everyone - so it works the moment you install it |
| No gate, anywhere else | Signed-in users only |

```php
// AppServiceProvider::boot()
Gate::define('viewJeeves', fn ($user) => $user->isAdmin());
```

Define the gate as soon as this is more than you: an ungated endpoint in
production is an LLM proxy for the internet.

---

## Which tables it can see

**Only the ones you have written a schema file for.** The whitelist is derived
from those files, not from your database, so a table with no schema file is not
merely discouraged — the validator refuses any query naming it, and the model
was never told it exists.

Three levers, in the order you will reach for them:

**1. Delete or don't create the schema file.** `config/jeeves-schemas/` is the
whole list. Remove `users.php` and `users` becomes unqueryable, immediately.

**2. Stop discovery from generating them at all.**

```php
// config/jeeves.php
'schema' => [
    'discover_exclude' => ['migrations', 'sessions', 'password_resets', 'audit_*'],
    'discover_exclude_columns' => ['ssn', 'salary'],   // columns, not tables
],
```

Framework tables are excluded already. Credential columns — `password`,
`remember_token`, `two_factor_secret` — are withheld by discovery whatever you
configure.

**3. Grant only what it should read.** The first two are the package's rules;
this one is the database's, and it holds even if the package has a bug:

```sql
GRANT SELECT ON myapp.orders    TO 'jeeves'@'%';
GRANT SELECT ON myapp.customers TO 'jeeves'@'%';
```

`GRANT SELECT ON *.*` works and defeats the point. See
[docs/CONNECTION.md](docs/CONNECTION.md).

Already have schema files carrying something you would rather it did not see?
`php artisan jeeves:audit-schema` names them.

---

## What it is good at, and what it is not

**It works well** on datasets you have described. Told that `revenue` is a
measure to total, that users say "client" for `customer_name`, and that
cancelled orders do not count, it is reliable for the questions those datasets
are meant to answer.

That last one is a rule, not a hint. A `required_filter` in a schema file is
**enforced where the SQL is executed** - a generated query that omits it is
refused rather than answered, on every route that reaches your database,
including a cached one replayed later. It is the one setting whose whole
purpose is that the answer is wrong without it, so it is not left to the model
to remember. → [docs/SCHEMA.md](docs/SCHEMA.md)

**It is not magic.** Pointed at an undescribed database and asked something
vague, any text-to-SQL system will sometimes produce a confident, wrong answer.

Two benchmarks, both run against uncurated schemas, both reproducible:

| Benchmark | Score | What it is |
|---|---|---|
| Spider dev sample | **30-31/36 (83-86%)** | Real questions and gold SQL from the Spider set, two unfamiliar databases |
| This package own set | **35/46 (76%)** | 46 questions over a 14-table schema, up to four-table joins |

Three consecutive runs each, against Gemini 2.5 Flash, September 2026. The
second is lower because it is harder: more joins, more periods, and questions
like *which orders have not shipped* where a wrong join silently returns
everything. Both numbers are published because quoting only the friendlier one
is the kind of thing anybody can check in five minutes.

Read them as: **roughly one question in four is wrong on an uncurated schema.**
Describing your schema moves it - measurably, and you can measure it yourself
below - but it is a correction, not a cure.

**The difficulty is not depth, which is the surprise.** By join width, over the
same three runs:

| Join width | Correct |
|---|---|
| 1 table | 16-17/20 |
| 2 tables | 13/16 |
| **3 tables** | **2/6** |
| 4 tables | 3-4/4 |

Three-table questions failed two times in three, identically in all three runs,
while four-table ones nearly all passed. So the honest warning is not "deep
joins are shaky" — it is that a specific shape goes wrong, and the four-table
questions here happen to run along paths the schema describes well. Six
questions is a small sample; the consistency is what makes it worth stating.

Where it was weakest, from the runs that produced those numbers: superlatives,
HAVING clauses, and anti-joins ("customers who have never ordered").

Most of that turned out to be a **routing** fault rather than a limit of the
approach. Intent mode expresses a deliberate subset of SQL, and questions
needing more are supposed to escalate to SQL generation — but the escalation
rules had holes exactly where the benchmark said accuracy was worst:

- An **anti-join** needs `NOT EXISTS`, which the contract cannot express at
  all, and no rule recognised the wording. The negation was dropped and every
  order came back for "which orders have not shipped".
- **"Highest"** was not treated as "maximum". The contract names a metric and
  nothing about what to do with it, so `SqlBuilder` summed: "what is the
  highest unit price" answered **670**, which is 120 + 250 + 300. The right
  answer is 300.
- A **group filter** escalated as "more than 1 order" but not "more than one
  order".
- A schema whose `SUM` total is aliased "revenue" — which is what
  `discover --ai` writes — **disarmed the aggregate guard entirely**, so "the
  highest revenue" was answered with the total.

Fixing those moved the package's own set from **30/46 to 32-33/46**, and it
now measures **35/46**. What closed the last two or three is not established -
later work in the same area, a server-side model change, or both - so it is
reported as an observation rather than credited to a fix.

A range, not a number, because a live model is not deterministic. Four runs at
the 32-33 level scored 33, 33, 33 and 32, and individual questions moved
between them: "revenue by region in July 2026" passed three times and on the
fourth was grouped by *payment method* instead of region. **One run is not a
measurement.** Run it three times and read the spread.

The three runs behind the current figure were tighter - 35, 35, 35 on the
package's own set and 30, 30, 31 on Spider - and ten of the eleven failures
were the same questions every time. A stable failure is a defect with an
address; a wandering one is the model. Both are worth knowing apart, and only
repeated runs tell you which you have.

One genuine non-finding, worth stating because it looks like the opposite:
superlatives that ask for a **row** ("the car with the largest acceleration")
have gold SQL of plain `GROUP BY` / `ORDER BY` / `LIMIT`, which the contract
expresses perfectly well. Measured across all 164 questions in both sets,
escalating those moves five questions and buys nothing on any of them. Only the
**scalar** form ("what is the highest *value*") needs `MAX()`. Whatever makes
the row form fail is not routing, so it is deliberately left alone, and a test
pins that so the measurement has to be repeated before anyone changes it.

What remains genuinely hard is the three-table shape shown above - 2/6 in every
run, while four-table questions nearly all pass. No amount of routing fixes
that, because it is not a routing fault.

The honest framing is **a fast analyst for datasets you have curated**, not an
oracle for arbitrary databases. Every mitigation here follows from that: SQL is
SELECT-only and restricted to your tables, `doctor` catches schema drift, and
every answer states how it read the question.

**You do not have to take that on trust for your own data.** Two commands close
the loop:

```bash
php artisan jeeves:audit-schema   # what the model is still guessing
php artisan jeeves:benchmark      # how often it is right, on your schema
```

The audit names the things introspection cannot recover - a column nobody
described, two columns that could both be "revenue", a table your users call
something else. The benchmark runs your own questions against SQL you wrote by
hand and compares the results. Curate what the audit found, run the benchmark
again, and the difference is a number you produced rather than one this README
asserts.

### Provider conformance

Seventeen cases whose answers are arithmetic on three seeded rows - totals,
filters, averages, periods, a decomposed comparison, and a conversation that
narrows, drills down and rewinds:

| Model | Result |
|---|---|
| Gemini 2.5 Flash | 17/17 |
| Claude Sonnet 5 | 17/17 |
| DeepSeek v4 Flash | 17/17 |
| DeepSeek v4 Flash, via an OpenAI-compatible router | 17/17 |
| `gpt-oss-20b` (open weights) | 17/17 |
| Mistral Large | 17/17 |
| Muse Glimmer 30B (open weights) | 15/17 |
| Nemotron 3 Super 120B (open weights) | 15/17 |

**Capability does not track parameter count, and models fail differently.**
A 20B scored 17/17 including the multi-step decomposition ("compare July with
August") that a 120B failed. Where models do miss, they miss in different
places: one answers a scalar question with an unrequested breakdown - asked for
*the* average it returns an average per client - while another handles single
questions cleanly and cannot decompose a comparison into steps.

So there is no single number to rank models by for this job. The battery takes
about a minute; **run it against the model you actually intend to use** rather
than inferring from size or vendor.

**Small local models are the one case to be careful with.** Below roughly 20B,
expect dropped filters and ignored date periods - asked for July, the whole
table comes back, confidently. If you are running a model on your own hardware
and a wrong number matters, measure it before you trust it.

Conversation state is the exception worth noting: narrowing, drill-down and
rewind hold up even on small models, because they are resolved in PHP rather
than left to the model.

The fourth row is the portability claim, measured: a service this package has
no built-in support for, reached with nothing but a `base_url` and a model
name.

```bash
JEEVES_CONFORMANCE=1 JEEVES_LLM_DRIVER=claude \
JEEVES_CONFORMANCE_KEY=sk-... vendor/bin/phpunit --testsuite Conformance
```

Run any battery more than once before believing it. On a free tier the first
pass often measures the rate limit rather than the model - add
`JEEVES_CONFORMANCE_DELAY=15` to space the calls out.

---

## How this differs from the alternatives

**[beyondcode/laravel-ask-database](https://github.com/beyondcode/laravel-ask-database)**
is the package most people find first, and the one this is most often compared
to. It asked the same question in 2023, collected 302 stars, and was
**archived in February 2024**. It calls OpenAI's GPT-3 specifically, needs an
OpenAI key, and its own README describes it as a learning resource for prompt
engineering rather than something to run.

If you arrived here after finding that one archived, this is the maintained
answer to the same question -  and a different shape of answer: any provider or
a model on your own hardware, SELECT-only validation against a schema-derived
whitelist, conversation state, and a privacy wall that is tested rather than
promised.

**[prism-php/prism](https://packagist.org/packages/prism-php/prism)** and
**[openai-php/laravel](https://packagist.org/packages/openai-php/laravel)** are
the layer *below* this one. They give you a clean, provider-agnostic way to
call a model from Laravel. They do not know what a dataset is, will not stop a
`DROP TABLE`, and have no opinion about whether a row reaches the provider.
Jeeves is a vertical built on that idea: schema introspection, a SQL
validator, a two-tier cache, conversation state, and a privacy wall. If you
want to call an LLM, use Prism. If you want to let people ask your database
questions, use this.

**Hosted text-to-SQL** -  the analytics products with an "ask your data" box - 
send your schema *and usually your rows* to a third party, and price per seat.
This runs in your application, sends schema structure only, and can run
entirely offline against Ollama.

**Writing it yourself.** Entirely reasonable, and most of it is a weekend.
The parts that are not: SELECT-only validation against a schema-derived
whitelist, a cache that cannot answer one question with another question's
result, rate limits reported as rate limits, and a benchmark that tells you how
often you are wrong. Those took this package many adversarial review rounds and
784 tests, and every one of them exists because something went wrong first.

## Documentation

| | |
|---|---|
| [docs/SCHEMA.md](docs/SCHEMA.md) | Schema files in full - metrics, aliases, joins, many tables |
| [docs/API.md](docs/API.md) | Every endpoint, field and error code - plus events and token cost |
| [docs/CONVERSATIONS.md](docs/CONVERSATIONS.md) | Follow-ups, drill-downs, rewind, multi-step answers |
| [docs/PROVIDERS.md](docs/PROVIDERS.md) | Every LLM driver, and adding your own |
| [docs/WIDGET.md](docs/WIDGET.md) | The bundled UI and browser voice input |
| [docs/SECURITY.md](docs/SECURITY.md) | The privacy wall, SQL validation, prompt-injection guard |
| [docs/CACHING.md](docs/CACHING.md) | What is cached, when a row is reused, replacing the cache |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | What each error means and how to fix it |

## Commands

```bash
php artisan jeeves:install        # publish config and migrations
php artisan jeeves:discover       # write schema files from your database
php artisan jeeves:audit-schema   # what the AI still has to guess -  do this next
php artisan jeeves:doctor         # diagnose setup problems, print the fix
php artisan jeeves:benchmark      # how accurate is it on YOUR schema?
php artisan jeeves:debug "…"      # the exact prompt, and which route it takes
php artisan jeeves:cache-stats    # is the cache earning its keep?
php artisan jeeves:cache-cleanup  # prune it
```

`discover` → `audit-schema` → write the descriptions it asks for → `benchmark`
is the loop that moves accuracy. The audit says what the model is guessing; the
benchmark tells you what fixing that was worth, on your own data.

## Contributing

`vendor/bin/phpunit`, `vendor/bin/pint` and `vendor/bin/phpstan analyse` must
pass, and the widget must pass `node --check`. New behaviour gets a test you
have watched fail; every failure a real user hits becomes a regression test.
See [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md) for
anything that should not be a public issue.

## License

MIT. See [LICENSE](LICENSE).
