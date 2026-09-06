# Changelog

All notable changes to `jayanta/jeeves` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-07

First release.

### A chat window for your own database

`<x-jeeves::widget />` puts a conversation thread in any Blade view: users ask
in plain English, and the answer comes back as a number, a table or a chart.
Follow-ups work — ask *"revenue by city"*, then *"just Guwahati"*, then
*"break that down by client"*, and rewind. That state is resolved in PHP rather
than asked of the model, so it holds even on small local models.

Voice input is browser-side. No audio reaches your server.

There is a programmatic API too — `Jeeves::query("top 5 customers by revenue")`
— and an HTTP contract for front ends that are not Blade.

### The model never sees a row

Only schema structure is sent: table names, column names, types, and the words
your users use for them. The model returns SQL; your server validates it, runs
it locally, and formats the result. **Not one row of data goes upstream**, and
that is enforced by tests rather than by intention — `PrivacyWallTest` seeds
sentinel values, runs real queries end to end, and asserts those values appear
nowhere in anything sent to a provider.

### Generated SQL runs on a connection that cannot write

`sql.database_connection` is **required**, and it must not be your
application's default. Point it at a database user holding `SELECT` and nothing
else. The check is on the resolved connection at execution time, not at a call
site, and there is no fallback path anywhere in the package.

Above it sit three more layers: an input guard, a schema-only prompt, and a
validator with a function allowlist and a schema-derived table whitelist.
`docs/SECURITY.md` states what each one does and — just as importantly — what
it does not cover. `php artisan jeeves:doctor` checks the lot, and on MySQL and
PostgreSQL proves the connection is read-only by trying to create a table on it.

See [docs/CONNECTION.md](docs/CONNECTION.md) for the `GRANT` statements and
statement timeouts.

### Any model, hosted or your own

Gemini, OpenAI, Claude, DeepSeek, Groq, OpenRouter — or a model you run
yourself on Ollama, vLLM, LM Studio or llama.cpp. One config block, no code
changes. PostgreSQL, MySQL, MariaDB and SQLite.

### Measured, including where it is weak

812 tests and CI across five database engines. Accuracy is published with the
failures included, because a text-to-SQL tool that only quotes its good number
is not one you can plan around:

| Benchmark | Score |
|---|---|
| This package's own 14-table set | 35/46 (76%) |
| Spider dev sample | 30-31/36 (83-86%) |

Three consecutive runs each, uncurated, against Gemini 2.5 Flash. The weak
spot is not join depth as such — three-table questions scored 2/6 in every
run while four-table ones scored 3-4/4. Superlatives and projection are the
other two known classes. `README.md` names them and shows how to measure your
own schema.

[1.0.0]: https://github.com/jay123anta/jeeves/releases/tag/v1.0.0
