# Troubleshooting

The errors people actually hit, and what each one means.

## Troubleshooting

**`cURL error 60: SSL certificate problem` on Windows / XAMPP / WAMP** - your
PHP has no CA certificate store, so HTTPS calls to the LLM provider can't be
verified. Download [cacert.pem](https://curl.se/ca/cacert.pem) and point the
package at it:

```env
JEEVES_SSL_VERIFY=C:\path\to\cacert.pem
```

(Or fix it globally by setting `curl.cainfo` in `php.ini` to the same file.)
`ssl_verify` accepts `true` (default - system CA bundle), a CA bundle file
path, or `false` (disables verification - never do this in production).

XAMPP already ships a bundle at `C:\xampp\apache\bin\curl-ca-bundle.crt`, so
usually no download is needed. Run `php artisan jeeves:doctor` first -  it
checks whether PHP has a CA store at all and prints the path of one it finds on
your machine. Worth doing before anything else, because the symptom does not
look like a certificate problem: every question simply fails, which reads like
a broken package rather than a missing file.

**`Driver '<name>' is connected but Jeeves cannot introspect it`** -
Jeeves reads your schema through database introspection, and ships with
SQLite, PostgreSQL and MySQL/MariaDB. If your app runs on something else, point
Jeeves at a connection it can read:

```php
// config/jeeves.php
'sql' => [
    'database_connection' => 'pgsql',   // a connection from config/database.php
],
```

…or teach it yours. That is a config change, not a fork - implement
`Contracts\SchemaIntrospectorInterface` and register the class:

```php
'sql' => [
    'introspectors' => [
        'sqlsrv' => \App\Jeeves\SqlServerIntrospector::class,
        'sqlite' => null,   // null disables a built-in driver
    ],
],
```

**404 from the LLM provider** - the configured model may have been retired.
Check the provider's live model list and update e.g. `GEMINI_MODEL`, then run
`php artisan config:clear`.

**"The AI service is receiving too many requests right now (rate limit)"** -
the provider returned HTTP 429. Free-tier API keys (especially Gemini) have
low per-minute and per-day quotas; wait a minute and retry, enable the query
cache (`JEEVES_CACHE_ENABLED=true`) so a repeat of a question already
asked is answered without a call, or upgrade the key's quota. The cache helps
with repeats only: a question asked for the first time, a follow-up inside a
conversation, and the same wording asked under a different dataset scope all
still call the provider. The package fails fast on 429 - it
deliberately does not fall back or retry the whole query, since more calls
would extend the rate-limit window.

**Slow responses when the provider is struggling** - network-level retries
are bounded by the `retry` config block, which exists because waiting is
blocking and PHP-FPM workers are a finite pool:

```php
'retry' => [
    'base_delay_ms' => 250,       // first wait; doubles each attempt
    'max_delay_ms' => 2000,       // ceiling for any single wait
    'total_budget_ms' => 4000,    // ceiling for ALL waits in one API call
    'respect_retry_after' => true,
    'jitter' => true,             // de-synchronise concurrent workers
],
```

With the defaults, one API call waits well under a second in total. When the
next wait would blow the budget - including an oversized `Retry-After: 60`
hint - the call fails immediately rather than half-waiting. Transient 5xx
responses get the same bounded retry; other 4xx (bad key, malformed request)
are never retried, since they fail identically every time. On queue workers
or CLI, where blocking is cheap, raising `total_budget_ms` is reasonable; on
FPM a large budget risks tying up every worker during a provider outage.

### "This question needs about N tokens of context but num_ctx is M" (Ollama)

Your schema no longer fits in the context window the model is allowed to read.

The package refuses to send the prompt rather than let Ollama truncate it,
because Ollama does not reject an oversized prompt -  it silently discards the
start and answers from what is left. The start is your schema, so you would get
a normal-looking answer written by a model that never saw some of your tables.
Refusing is the loud version of a failure that is otherwise invisible.

If you are on 2.0.0 and have *never* seen this message on a large schema, that
is worth knowing about: in 2.0.0 the refusal was retried automatically with a
single-dataset prompt, which fits, so you received an answer computed from one
table instead. Upgrade -  the refusal now reaches you.

Three ways out, cheapest first:

```env
OLLAMA_NUM_CTX=16384
```

Raising it costs memory for the KV cache, and the model must support the size - 
a 4k model will not honour 16k however you configure it.

Or use a model with a larger context. Or describe fewer tables: every file in
`config/jeeves-schemas` goes into the prompt, so deleting the ones nobody
queries makes it smaller and the answers better, since the model has fewer
wrong columns to pick from.

An empty `OLLAMA_NUM_CTX=` is treated as unset rather than zero, so it will not
refuse everything. The same is true of every numeric setting: a variable
present in `.env` with nothing after it falls back to its documented default
instead of crashing.

**"This question needs N characters of schema context, but
jeeves.prompts.max_chars allows only M"** - you set a prompt ceiling and
this question exceeds it. Raise it, describe fewer tables, or use
`query_mode: intent` for the affected questions:

```env
JEEVES_PROMPT_MAX_CHARS=48000
```

It is a size bound only - the package will not quietly send a narrower prompt
built from fewer tables, because guessing which of your tables a question does
not need is how a correctly-grouped answer silently loses its grouping. It
applies to SQL generation; intent mode builds its prompt inside the provider
and is not measured by it.

If you set `JEEVES_PROMPT_MAX_CHARS` and nothing changes, check whether
you published `config/jeeves.php` before 2.1.0. Laravel merges package
config only one level deep, so a published `prompts` block from an earlier
version replaces the package's wholesale and the new key is not in it. The env
var is honoured as a fallback in that case, but re-publishing the config
(`php artisan vendor:publish --tag=jeeves-config --force`, after diffing
your changes) is the durable fix.

## Check your setup before anything else

```bash
php artisan jeeves:doctor
```

New to this? Run that first, and run it again any time something misbehaves.
It checks your driver and API key, pings the provider to confirm the model is
actually live, verifies the database connection and migrations, and - the one
that catches most silent failures - confirms every table and column named in
your schema files really exists in your database. Each problem is printed with
the exact command or setting that fixes it. It never prints your API key, and
`--skip-api` runs the whole checkup without spending any quota.

```
  Schemas
    ✓ 2 schema(s) loaded: orders, customers
    ✗ Schema 'orders': column(s) not in 'demo_orders': revenu
      → Fix: Correct these names in the schema file - the AI is told they
        exist, so queries using them will fail at execution.
```
