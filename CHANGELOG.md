# Changelog

All notable changes to `jayanta/jeeves` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Misspelled dataset names are placed without a call

`fuzzy_dataset_matching`, off by default. A question whose words are an edit or
two from one dataset's name or alias is routed there locally - no service, no
provider call. Words under five letters are never fuzzed, and a misspelling two
datasets match equally well is left to the model rather than guessed. It runs on
the generation path only, never inside the check that decides whether a cached
answer belongs to the question being asked.

### A renamed or variant-spelled value still finds its rows

`value_aliases` on any column: other names for a stored value - a rename, a
spelling variant, a synonym. The name a user typed is swapped for the stored one
before the query runs, on both the SQL-generation and intent routes, and the
response reports it in `metadata.value_aliases_applied`. Exact matches only,
scoped to the column and table that declare them, and the statement is still
validated afterwards. Nothing in it is specific to any domain: it works the same
for order statuses, country names or a renamed region.

### A misspelled value is corrected instead of answered "no data"

`correct_typos` on a column: when a query filtering on it finds nothing - no
rows, or a total of NULL or 0 - the
value typed is compared with the values the column holds, and one clearly
closest - a different case, or an edit or two away - is swapped in for one more
run. Each correction is reported in `metadata.value_corrections`. The stored
values are read and compared on your server and never reach the model, and
correcting costs no provider call. A stored value is never changed, ties are
not guessed, and a column over `value_correction.max_distinct` is skipped.

### `jeeves:benchmark` can check an answer, not only compare it

A question may carry `expect` - a row count, a range the figure must fall in, a
name that must appear - instead of or as well as a reference query. It grades
what a reference cannot always express, and closes the case where a SUM over no
rows returns NULL, the reference returns the same NULL, and the two compare as
correct. With both, both must hold. An unknown check is refused before anything
runs, so a typo in a check cannot read as the package being wrong.

### Pinned queries

`pinned_queries` ties an exact question - case, punctuation and spacing
ignored, nothing fuzzier - to reviewed SQL that runs with no model call and the
same answer every time. It is still validated like any other statement: SELECT
only, tables from your schema files, the LIMIT rule and `required_filter`. It
runs exactly as written: no value alias, typo correction or shape retry touches
reviewed SQL. A pinned answer is timed, audited and announced like every other.

It buys stability for the questions that must never vary. It says nothing about
accuracy on the questions nobody pinned, and a test suite made of pinned
questions will pass forever for that reason.

### Examples and code no longer carry one project's geography

This package began inside one state government's dashboard, and some of that
project had stayed in what every adopter installs: a state-specific example in
the `system_instructions` comment, place names in examples and code comments,
a lookup-table example built on one kind of administrative area, and a legacy
intent field. Examples now use neutral names, and nothing in the package
assumes a country, a state, its regions or its schemes.

**For custom LLM providers:** return a single-record filter as `group_value`.
The old `district` key, which `LlmProviderInterface` still listed until this
release, is no longer read, and a provider that still returns it will have
that filter ignored. Every bundled provider already returned `group_value`.

## [1.1.0] - 2026-09-10

### Runs on Laravel 11

The constraint is `^11.0|^12.0|^13.0` and CI tests all three. Laravel 11 is
past security support, so every published 11.x carries advisories that will
never be fixed and recent Composer refuses to resolve them by default. If your
`composer require` is blocked, the README says why and what to set — the block
comes from your framework, not from this package, and it applies to every
package you install.

### It asks again when the shape of an answer contradicts the question

*"Which carrier shipped the most orders"* has one answer. Models routinely write
it without the `LIMIT`, so the top row is right and every row under it is wrong.
Nothing about that is visible before execution, which is why the SQL verifier
cannot catch it — the SQL is valid.

The row count is now inspected **on your own server** and, when a question that
asks for one thing came back as a list, the query is regenerated once. The retry
prompt carries the question, the schema and one sentence about the shape. No
value, no row, no count.

### Semantic dataset matching, off by default

An optional stage between keyword routing and the model: an embedding service
you run is asked which dataset a question is closest to, and a confident answer
skips the model call that would otherwise be spent just placing it.

**No model or container ships with this** — the client is about 10 KB and adds
no dependency. Exact routing always wins, and a miss, a timeout or an outage
leaves the question on exactly the route it takes today. It can add a route,
never remove one. See `semantic_matching` in the config.

`php artisan jeeves:semantic-corpus` writes the descriptions such a service
ranks against, generated from your own schema files — orders and tickets in one
application, patients and claims in another. Nothing about the matching is
specific to any domain, and no corpus is bundled, because a shipped one would
describe nobody's data. It writes names, descriptions and the aliases your users
type, and never opens a database connection.

### Fixed

- The security event raised for rejected SQL carried an empty question, so the
  one record of an unsafe generation did not say what had been asked.
- Feedback corrections are screened again where they are replayed into a prompt,
  not only where they are submitted — rows written before that check existed
  were reaching prompts unscreened.

## [1.0.0] - 2026-09-07

First release.

### A chat window for your own database

`<x-jeeves::widget />` puts a conversation thread in any Blade view: users ask
in plain English, and the answer comes back as a number, a table or a chart.
Follow-ups work — ask *"revenue by city"*, then *"just Springfield"*, then
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
