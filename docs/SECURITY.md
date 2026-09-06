# Security and privacy

What reaches the AI, what never does, and the layers between a typed question
and your database.

## Security

Four layers of defense. The first one is the only one that is not a judgement
call:

**Layer 0: a database connection that cannot write.** The package refuses to
execute generated SQL at all until `sql.database_connection` names a connection
of its own, and refuses if that connection is your application's default. Point it at a database user holding `SELECT` and nothing else and a
bypass of every layer below becomes a failed query instead of a write. The check
is on the **resolved connection at execution time**, not on a call site, and
there is no fallback path left in the package. `jeeves:doctor` verifies it,
and on MySQL and PostgreSQL will try to create a table on that connection to
prove it cannot. See `docs/CONNECTION.md` for the grants.

This layer exists because the three below are pattern matching, and pattern
matching is only ever as good as the last attack somebody thought of.

**Layer 1: Input Guard** - blocks prompt injection, SQL injection in text, data exfiltration attempts, unicode bypass attacks before they reach the AI.

**Layer 2: AI Prompt** - schema structure only (never data). Forced JSON output. Instructions say "ONLY SELECT queries".

**Layer 3: SQL Validator** - validates AI-generated SQL. A **function allowlist**
(anything not on it is refused, and the refusal names it), a table whitelist
(every FROM/JOIN table checked, including inside CTEs), forbidden keywords
(DROP, DELETE, ...), injection patterns, LIMIT enforcement, no stacked queries,
no PL/pgSQL, no locking reads, no session-state changes.

The allowlist is the load-bearing part and the reason the rest is survivable.
Three rounds of attack sweeps against a pure denylist found three holes, and
each round broke the previous round's fix - a list of what is forbidden is only
ever as good as the last thing someone thought of. Deciding what is *permitted*
means `pg_sleep`, `dblink`, `lo_import`, `LOAD_FILE` and whatever the next
sweep would have found are all refused without being named.

**This is not a SQL parser, and does not pretend to be.** There is no pure-PHP
multi-dialect one: the mature options are MySQL-dialect, and a real PostgreSQL
AST needs a C extension that would stop this package running on a stock
XAMPP install. A parser that mis-parses is worse than a regex, because it
produces confident structure that is wrong. So table extraction, CTE scoping
and clause detection stay pattern-based, and this layer is **defence in depth
over a schema-derived whitelist** - not a proof.

### Two routes, and only one of them is parameterised

A question takes one of two paths, and they do not offer the same guarantee.

**The intent route** is the default. The model returns *slots* - a metric, a
dimension, a filter value - and `SqlBuilder` writes the statement in PHP. Every
user-derived value becomes a `?` with a binding. Nothing a user types is ever
part of the SQL text.

**The `sql_generation` route** handles questions the intent contract cannot
express: superlatives needing a LIMIT, HAVING clauses, anti-joins. Here the
model writes the whole statement, and any value from the question is **inline
in the SQL text**. There are no bindings to pass, because there is no template
to bind into.

This is not classic injection - no string is concatenated into a query by this
package - but it does mean that on that route the validator and Layer 0 are what
stand between a question and the statement, rather than the parameteriser.

**Why this is not simply fixed by extracting the literals.** Rewriting the
model's SQL to replace `'value'` with `?` looks like the obvious answer and is
not safe: some literal positions cannot be parameterised at all. Measured on
PostgreSQL 13.7:

| Position | Bindable |
|---|---|
| `WHERE col = ?` | yes |
| `LIKE ?`, `IN (?, ?)`, `CAST(? AS DATE)`, `date_trunc(?, ...)` | yes |
| `AT TIME ZONE ?`, `ORDER BY ?`, `-> ?` | yes |
| **`interval ?`** | **no** - syntax error |
| **`extract(? FROM ...)`** | **no** - syntax error |

`INTERVAL '30 days'` is one of the most common things a model writes for a
date-range question, so a blanket rewrite would turn working queries into
syntax errors on the exact route that exists to handle the hard ones. A rewrite
with exceptions for the positions that cannot be bound is another denylist, and
this file has already explained what happens to those.

So the honest statement is: **the intent route parameterises; the
`sql_generation` route does not, and cannot without a parser.** Layer 0 is the
answer to it. If that trade is not acceptable for your data, set

```php
'query_mode' => 'intent',   // default is 'auto'
```

and take the reduced question coverage - `'intent'` never routes to
`sql_generation`, not even as a fallback when intent parsing fails, so the
package says it cannot answer rather than reaching for the model. Clear the
query cache when you switch, so no recipe stored while `'auto'` was in force is
replayed.

### Spending

Every question is a paid API call, so the routes carry a ceiling as well as a
rate. `throttle:60,1` stops a burst; it does not stop a slow drain - sixty a
minute sustained is roughly 86,000 questions a day.

```php
// config/jeeves.php
'limits' => [
    'queries_per_day' => 200,   // per user, or per IP when routes are public
],
```

Counted per authenticated user, or per IP when there is none, and reset at
midnight. Reaching it returns HTTP 429 naming the limit, so clients that
already handle rate limiting handle this too. Set it to `null` for no ceiling -
a choice worth making deliberately.

The limit is applied by the package itself rather than through
`routes.middleware`, so customising that array - the first thing anyone does to
make the widget public - cannot drop the ceiling by accident.

**Removing `auth` deserves a moment's thought.** Without it the widget is an
LLM proxy anyone who finds the URL can use, and the daily ceiling falls back to
counting by IP, which is easy to get around. `jeeves:doctor` says so if
you do it.

**Optional Layer 4: AI Guard** - install [jayanta/laravel-ai-guard](https://github.com/jay123anta/laravel-ai-guard) for additional protection. Jeeves auto-detects it - no configuration needed.

```bash
composer require jayanta/laravel-ai-guard
```

When installed, ai-guard adds:
- 30 prompt injection patterns (instruction overrides, jailbreaks, DAN attacks, token manipulation) with confidence scoring
- 354 bot signatures to block AI scrapers and malicious bots at the middleware level
- Honeypot trap routes, PII leak detection on responses, and optional ML-based detection

**ai-guard's own settings decide what it blocks here.** Every question is run
through its detector, but a detection is only refused when ai-guard is in
`block` mode and scores at or above its `confidence_threshold` (default 70).
Its shipped default mode is `log_only`, so installing the package gives you
visibility first - it will not start rejecting questions your users could ask
yesterday. Detections that don't block are logged at info level, so nothing is
silently dropped. Layers 1–3 apply either way.

To act on detections, switch ai-guard itself to blocking:

```php
// config/ai-guard.php
'mode' => 'block',
'confidence_threshold' => 70,
```

Or override the decision from Jeeves's side:

```env
JEEVES_AI_GUARD_ENFORCE=always   # block above threshold in any ai-guard mode
JEEVES_AI_GUARD=false            # ignore ai-guard even though it's installed
```

Neither package requires the other. They work independently and integrate automatically when both are present.

## Privacy

- AI receives ONLY table names, column names, and types
- Your actual data NEVER leaves your server
- AI generates SQL which runs locally on YOUR database
- Error messages are sanitized (no API keys, no internal paths); logged
  exceptions have API keys and tokens redacted

This is enforced by the test suite, not just by intent. `PrivacyWallTest`
seeds a database with sentinel values, runs real queries end to end through a
recording provider, and asserts those values appear nowhere in anything sent
upstream - across intent mode, SQL-generation mode, self-verification, the
retry path, multi-turn follow-ups and clarifications. Every case also asserts
the query genuinely returned sentinel-bearing rows, so a query that quietly
failed cannot pass by transmitting nothing. One case asserts the package has no
way to receive audio at all, since speech never reaches the server.

Run it yourself:

```bash
vendor/bin/phpunit --filter PrivacyWallTest
```

### What reaches your log

Two things used to reach it that should not have.

**The failing statement and the driver's message.** A query that errored logged
the full SQL and the raw driver text. On the `sql_generation` route the model
writes every literal inline, and Laravel appends the statement *again* to a
`QueryException` message with the bindings already interpolated. The message is
sanitised now, and the statement is logged with string and numeric literals
replaced by `?` — `WHERE account_id = 4471` identifies a person as surely as a
name does. Set `sql.log_failing_statement` to `true` to get the whole statement
back while debugging.

**The question itself**, at nine sites, with four different truncation lengths
and no setting anywhere. One policy now:

```php
'logging' => [
    'log_question'       => true,   // JEEVES_LOG_QUESTION
    'question_max_chars' => 200,
],
```

It stays **on** by default. When somebody reports a wrong answer the question is
the most useful line in the log, and defaulting to silence would cost every
adopter that for a benefit only some need. Turn it off if a question counts as
personal data where you operate — *"show me John Smith's salary"* is a question,
and also a name and a subject. Off substitutes a marker rather than blanking the
entry, so the log still says what happened.

The bound applies either way: that setting chooses between *bounded* and
*nothing*, never *everything*.

None of this is the privacy wall — nothing here reaches a provider. It is the
same principle one hop later. The log is a different audience with a different
retention policy: shipped to aggregators, read by people who were never granted
access to the data, kept long after the request.

### Credential columns are withheld from discovery

`schema.discover_exclude` filters **tables** — `migrations`, `sessions`,
`personal_access_tokens`. `users` is not one of them, so before 3.0 a stock
Laravel app that ran `jeeves:discover` got a users dataset whose columns
included `password`, `remember_token` and `two_factor_secret` — selectable,
groupable, filterable, and returned in full by "show me all users".

Eloquent does not save you here: generated SQL is `DB::select()`, so `$hidden`
is never consulted and an encrypted cast comes back as **ciphertext** rather
than as a decrypted value.

The three introspectors now withhold them — that is where columns are produced,
so `discover`, `audit-schema` and any direct caller all inherit it. The
built-in list covers the names the framework itself creates: `password*`,
`*_password`, `remember_token`, `two_factor_secret`, `*_recovery_codes`,
`*_token`, `*_secret`, `api_key`, `private_key`.

It cannot know what *your* application calls things:

```php
// config/jeeves.php
'schema' => [
    'discover_exclude_columns' => ['ssn', 'aadhaar_no', 'salary'],
],
```

**Additive, not replacing.** `forbidden_keywords` and `allowed_functions` both
replace their defaults, which is right for lists you curate deliberately. This
one guards against names nobody thought about, so adding `ssn` must not quietly
put `password` back on the table.

**Schema files written before 3.0 keep what they were given.** Filtering
discovery cannot reach back and edit a file already on disk. Run

```bash
php artisan jeeves:audit-schema
```

and it names any credential column still exposed. It reports rather than
repairs — rewriting your schema file unasked is not a read-only audit
command's job.
