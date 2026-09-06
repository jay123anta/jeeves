# Changelog

All notable changes to `jayanta/jeeves` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-06

**First release under the name Jeeves.**

This is not new code. It was developed and released as
`jayanta/laravel-natural-query` through nine versions, ending at 3.0.1, and the
full history of those releases is kept below — including the security audit
that produced most of 3.0.0.

The rename came with the chat widget: what began as a query library is now a
drop-in conversational interface to your own database, and the old name
described only half of it. Everything moved with it — the namespace is
`Jayanta\Jeeves`, the facade is `Jeeves`, the config file is
`config/jeeves.php`, the commands are `jeeves:*`, and the tables are
`jeeves_cache` and `jeeves_feedback`.

Nothing else changed in this release. The code is what 3.0.1 was: 810 tests,
a ten-finding security audit closed, and CI green on SQLite, MySQL, MariaDB and
PostgreSQL.

**Coming from `jayanta/laravel-natural-query`?** There is no upgrade path and
there does not need to be — the old package had almost no installs. Install
this one fresh, rename your `config/naturalquery.php` to `config/jeeves.php`,
change `NATURALQUERY_*` environment variables to `JEEVES_*`, and re-run
`php artisan migrate`.

---

# Previously released as `jayanta/laravel-natural-query`

Everything below happened under the old name. Version numbers are that
package's, not this one's.

## [3.0.1] - 2026-09-06

### Fixed

- **`jeeves:install` now names the database connection that 3.0.0 made
  required.** Its closing steps walked an adopter through to "try it" and never
  mentioned `sql.database_connection`, so the last step failed on every fresh
  install. `jeeves:doctor` caught it one step earlier and said exactly
  what to do, which is why this is a patch and not a recall — but the first
  thing somebody reads should not lead them into a wall the package already
  knows about.

  Found by installing the **built package** into a clean Laravel application.
  No test in the suite could have seen it: the suite exercises the code, and
  this was the packaged artifact's onboarding text. There is a test now.

  Nothing else changed. 3.0.0 is functionally identical and remains the
  security release — upgrading is optional.

## [3.0.0] - 2026-09-06

**Security release.** One breaking change, one config line, and the reason is
in docs/CONNECTION.md.

### Security — NQ-001 (critical)

- **Generated SQL will no longer run on your application's database
  connection.** `sql.database_connection` defaulted to `null`, which meant
  model-authored SQL executed with the privileges your app writes with.
  SELECT-only validation in `SqlValidator` was the entire thing between a
  bypass of that validator and write access — and three attack sweeps in one
  week found three bypasses, one of them defeating a fix written hours
  earlier.

  The setting is now required, and it must not name your application's
  default. Until it does, questions fail with a message that says so; the
  application itself boots and runs normally.

  Enforced on the **resolved connection at execution time**
  (`Security\ExecutionConnection`), not at a call site — a guard on one line
  leaves the next execution site free to reintroduce the hole. There is no
  `DB::select()` fallback left anywhere in `src/`. The two console commands
  that execute SQL (`benchmark`, `debug`) are held to the same rule.

  Schema **introspection** deliberately still uses the application connection:
  it reads metadata, never rows, and `discover` has to read your schema in
  order to write the config that names the read connection.

  `jeeves:doctor` now checks all of this, and on MySQL and PostgreSQL
  proves the connection is read-only by trying to create a table on it.

  **Upgrading is one config line plus a database user.** See
  [docs/CONNECTION.md](docs/CONNECTION.md) for the `GRANT` statements and statement
  timeouts.

### Security — the rest of the audit

Eight further findings from the same audit, each with a test that was observed
failing before the fix.

- **NQ-009 (critical): a `UNION` read a table the whitelist never authorised.**

  ```sql
  SELECT CAST(id AS TEXT) FROM orders
  UNION ALL SELECT api_key FROM secrets LIMIT 100
  ```

  validated clean and returned the second table. One statement, a SELECT, no
  forbidden keyword. The `FROM` pattern stopped at `WHERE`, `ORDER`, `GROUP`,
  `LIMIT`, `HAVING`, `ON`, a closing paren or end of string — and `UNION` is
  none of those, so the capture ran past the `LIMIT` and swallowed the second
  `SELECT` whole. `preg_match_all` resumes after consumed text, so the second
  `FROM` was never examined. One table reported, two read.

  Adding `UNION` to that list would have fixed the statement. Splitting on the
  set operators fixes the shape: every branch of a compound SELECT is now
  scanned in full, and `INTERSECT`, `EXCEPT` and `MINUS` come with it. The
  split is top-level only and skips string literals, so a value containing the
  text `UNION SELECT` is not mistaken for one.

  Found by comparing what the validator sees against what the database's own
  planner opens, on the same statement.

- **NQ-003: `jeeves:discover` handed back the password column.**
  `schema.discover_exclude` filters tables, and `users` is not one of them — so
  a stock Laravel app got a users dataset carrying `password`,
  `remember_token` and `two_factor_secret`, selectable and filterable, returned
  in full by "show me all users". Eloquent's `$hidden` does not apply: generated
  SQL is `DB::select()`, and an encrypted cast comes back as ciphertext.

  Withheld in the three introspectors — where columns are produced — so
  `discover`, `audit-schema` and any direct caller inherit it.
  `schema.discover_exclude_columns` adds your application's own names, and
  **adds** rather than replaces, so configuring it cannot silently put
  `password` back.

  A schema file written before 3.0 keeps what it was given.
  `jeeves:audit-schema` now names any credential column still exposed.

- **NQ-008: a comment satisfied the `required_filter` check.**

  ```sql
  SELECT /* status != 'cancelled' */ SUM(revenue) FROM nq_orders LIMIT 50
  ```

  passed, constrained nothing, and returned every row — including the ones the
  schema rule says must never be counted — captioned as a total. Not an error:
  a confident wrong number, which is the worst thing this package can produce.
  Comments are stripped before the comparison now, and the
  match must fall outside any string literal.

- **NQ-006: the failing statement wrote the user's value to the log.** A query
  that errored logged the full SQL and the raw driver message. On the
  `sql_generation` route the model writes every literal inline, and Laravel
  appends the statement *again* to a `QueryException` message with bindings
  interpolated and the database path attached. The message is sanitised now and
  the statement is masked — string **and** numeric literals — because
  `WHERE account_id = 4471` identifies a person as surely as a name does.
  `sql.log_failing_statement` restores the full statement for debugging.

- **NQ-010: the question reached the log nine different ways.** A question
  carries whatever the person typed into it, and it was written to the
  application log at nine sites across four files with four different answers
  to how much: 200 characters in `InputGuard`, 100 in one `QueryOrchestrator`
  site, and the whole thing in three others. Nobody chose that; it accumulated
  one call site at a time.

  `Support\QuestionForLog` decides now, and all nine go through it. Questions
  are still logged by default — when somebody reports a wrong answer it is the
  most useful line in the log — and `logging.log_question` turns it off in one
  place for installs where a question counts as personal data. Off substitutes
  a marker rather than blanking the entry, and the length bound applies either
  way: the setting chooses between bounded and nothing, never everything.

- **NQ-007: a cached recipe can now be scoped to the caller.**
  `cache.key_scope` names a class implementing `Contracts\CacheKeyScopeInterface`
  and its value is folded into the cache key. Off by default, because a row
  holds a recipe rather than result rows and sharing is normally right — set it
  if you vary `required_filter` per tenant. A class rather than a closure so
  `config:cache` keeps working; a value that does not resolve throws rather
  than being ignored.

- **NQ-002: the tenancy advice in `docs/SCHEMA.md` was wrong and is gone.** It
  suggested a global scope or a policy. Generated SQL is `DB::select()`, so
  neither is ever consulted, and anyone who followed it believed tenant
  isolation was in force while nothing enforced it. Replaced with the three
  mechanisms that survive raw SQL — a connection per tenant, PostgreSQL
  row-level security, a database per tenant. `jeeves:doctor` warns when a
  schema declares a tenant-shaped column, and the package still does not inject
  a tenant predicate: placing one on the wrong table is a cross-tenant read.

- **NQ-005: `docs/SECURITY.md` now says which route parameterises.** The intent
  route binds every user-derived value; `sql_generation` cannot, because the
  model writes the whole statement. Neither claim had been made, which for a
  security property reads as a promise nobody made and everybody assumes.

- **NQ-004 stays open, deliberately.** The validator is pattern-based and has no
  parse tree. `phpmyadmin/sql-parser` is maintained but MySQL-focused, and
  asking the database to resolve tables via `EXPLAIN` needs a live connection —
  moving the whitelist check downstream of the boundary NQ-001 draws — and is
  not side-effect free. The planner is used as a **test oracle** instead.

### Testing

- The bypass corpus now runs on **SQLite, MySQL/MariaDB and PostgreSQL**, and
  asserts on the data rather than the parse: a statement the validator allows
  must not return the contents of a table outside the whitelist. Whether a
  string is a bypass is decided by the engine — `` `secrets` `` is an
  identifier on MySQL and a syntax error elsewhere, `#` starts a comment on
  MySQL alone — so a single-engine suite cannot see this class of hole. CI
  fails if the corpus skips an engine.

### Unreleased before this release

### Security

- **A database error carrying a row value no longer reaches the user.**
  Extracting the driver's cause made the message useful and also made it a path
  for a customer's data to reach a response and a log drain — MySQL's
  *"Truncated incorrect DOUBLE value: 'Rekha Stores'"*, PostgreSQL's *"invalid
  input syntax for type numeric"*, both reachable from a SELECT with an
  implicit cast.

  Naming the leaky shapes was the obvious fix and the wrong one: three drivers,
  an open vocabulary, and the first message nobody anticipated is a leak with
  no test to catch it. The **safe** shapes are listed instead — missing table,
  unknown column, bad function signature, GROUP BY mistake, syntax error — and
  anything else falls back to *"An error occurred while querying the
  database."* The raw text used to be logged server-side; NQ-006 below stopped
  that too, for the same reason one hop later.

  This closes a known issue carried since 2.2.1.

- **`pg_sleep()` and PostgreSQL's other system functions are now refused.**
  `SqlValidator` blocked MySQL's `SLEEP(` and SQL Server's `WAITFOR DELAY` and
  missed PostgreSQL's equivalent — on a package whose first-listed database is
  PostgreSQL. `SELECT pg_sleep(10) FROM orders` is a single SELECT against an
  allowed table, so nothing else in the chain refused it either; the effect is
  a request that holds a database connection for as long as the argument says.

  Being a SELECT is not the same as being safe. A SELECT can hold a connection
  (`pg_sleep`), read a server file (`pg_read_file`, `LOAD_FILE`), write one
  (`INTO OUTFILE`) or open a network connection to somebody else's host
  (`dblink`) — none of which needs a second statement or names a forbidden
  table.

  The cause was a dead entry. `pg_` sat in the forbidden **keyword** list,
  where keywords are matched with `\b` on both sides — and `\b` after an
  underscore requires a non-word character next, which `pg_sleep` does not
  have. It could never have fired. Matching the **call** (`pg_…(`) covers the
  class — `pg_read_file`, `pg_ls_dir` and the rest — without touching a column
  that merely starts with `pg_`. `lo_import` and `lo_export` are refused too.

- **A comment no longer defeats the function rules.** A comment is whitespace
  to the tokeniser, so `SELECT pg_sleep/**/(10)` is a valid call — and `\s*` in
  a pattern does not match `/**/`. Every rule shaped *name followed by a
  parenthesis* was one comment away from useless, including `SLEEP`,
  `BENCHMARK` and `LOAD_FILE`, which predate this release. Patterns are now
  matched against the statement with comments removed as well as the original;
  both, because the stripped copy cannot see the rules that are *about*
  comments and the original cannot see through them.

- **Locking reads are refused.** `FOR UPDATE` was refused only by accident —
  `UPDATE` is a forbidden keyword — and `FOR SHARE` was not refused at all.
  Both take locks that block writers for as long as the transaction lives.

- **`set_config()` is refused.** A bare `SET` never reaches the validator, but
  `set_config()` is a function and rides inside an ordinary `SELECT`. It can
  change `search_path`, which decides which table an unqualified name resolves
  to — a way to answer a question from a table the whitelist never approved.

- **Generated SQL may only call functions on an allowlist.** Everything above
  is a denylist, and three rounds of attack sweeps found three holes in it —
  the third breaking the second's fix. A list of what is *forbidden* can only
  be as good as the last thing someone thought of.

  `sql.allowed_functions` inverts it. `pg_sleep`, `dblink`, `lo_import`,
  `LOAD_FILE`, `REPEAT` and everything nobody has imagined yet are refused
  without being named, and the cost of missing a legitimate function is a
  refusal that says which one and where to add it — not a breach nobody
  notices.

  The default was measured before it was chosen: across 178 real SQL strings —
  every gold answer in both benchmark sets, every example query and every
  computed-metric expression shipped or tested — only `COUNT`, `SUM`, `AVG`,
  `MAX`, `MIN`, `ROUND` and `DATE_TRUNC` appear. The shipped list is far wider,
  covering aggregates, maths, null handling, casting, strings and dates across
  all four supported databases.

  Twenty SELECT-only attack vectors now have regression tests, with
  counterweights so the rules cannot widen onto ordinary questions — a
  `share_price` column, a customer called "Benchmark Ltd", and a customer
  called **"Acme (UK) Ltd"**, which caught a real false positive: function
  calls are found by regex, so a data value containing a parenthesis read as a
  call and the query was refused. Found by running the allowlist against every
  gold answer before shipping it. String literals are blanked before
  extraction now.

### Fixed

- **A question that negates existence is no longer answered as its opposite.**
  "Which orders have not shipped" needs `NOT EXISTS`, `NOT IN`, or a `LEFT
  JOIN` with an `IS NULL` test. The intent contract holds one measure, one
  grouping, one name filter, one period, an order and a limit — there is
  nowhere to put a negation. So it was dropped and the remainder answered:
  every order came back. "All of them" reads like a real answer to that
  question, which is what made it worse than a refusal.

  `IntentCoverage` had an `exclusion` rule, but it matches *excluding*,
  *except*, *apart from* and *without* — none of which is how anyone phrases
  an anti-join. These now escalate to SQL generation, which can express it.
  Escalating swaps one API call for another rather than adding one.

- **A group filter escalates whichever way the number is written.** "More than
  1 order" escalated and "more than one order" did not, so whether the rule
  fired depended on how the user typed the number.

- **"Highest" is now treated as "maximum", and neither is summed.** The intent
  contract names a metric and nothing about what to do with it, and
  `SqlBuilder` wraps every aggregatable column in `SUM()`. "Maximum unit price"
  escalated to SQL generation; "highest unit price" did not. Measured on the
  benchmark, it answered **670** — which is 120 + 250 + 300 — and labelled it
  the highest price. The right answer is 300: a plausible number, twice too
  large, described as something it is not.

  Anchored to the scalar form deliberately. "The car with the largest
  acceleration" asks for a row, which `ORDER BY … LIMIT 1` expresses perfectly
  well; a looser pattern escalates five such questions across the benchmark and
  Spider sets and buys nothing on any of them.

- **A `SUM` metric no longer answers a question about the maximum.**
  `IntentCoverage` skips the non-sum-aggregate rule when the schema already
  defines the aggregate being asked for — so a curated "average order value"
  is not escalated to pay for a second call reaching the answer the schema
  already names. That check matched on the metric's *name* alone, with no
  regard for which aggregate the question wanted.

  `discover --ai` writes SUM totals aliased with the words people say —
  "revenue", "total", "sales". On such a schema, "what is the highest revenue"
  named a metric the schema provides, the bypass fired, and `SqlBuilder`
  answered with `SUM()`. The question named the metric; it did not ask for the
  aggregate the schema computes. The check now compares the aggregate the
  question wants against the metric's own expression.

- **The same question escalates however it is spelled.** `max` and `min`
  alongside the words they abbreviate; `what's` and `show me the` alongside
  `what is`; and the word-number list for group filters extended past ten
  ("more than twenty orders", "more than a dozen tickets"). Which of these
  escalated used to depend on how the user happened to type it, and the ones
  that did not were summed. Row lookups ("the car with the largest
  acceleration") still stay in intent mode, pinned by a counterweight test.

- **The prompt says what to project, and that "the most" means one row.** The
  largest cluster of benchmark failures was the *right rows with an extra
  column* — `list all product names` answering `[1|chair]`, `which products
  cost more than 200` answering `[250|desk]`. Four questions, 8.7 points.

  Two of them had already escalated to SQL generation and still did it, so it
  was never a routing fault. The multi-dataset prompt carries rule 8d, *"SELECT
  the measure alongside the label"* — right for a ranking, and being applied to
  questions with no measure in them at all. Both prompts now separate the two
  cases, and separately distinguish "which carrier shipped the most" (one row)
  from "top 5 carriers" (a list). A test pins 8d so a later attempt at this
  cluster cannot fix it from the wrong side.

  **Unverified.** A prompt change can only be judged by a live run; this is a
  hypothesis with a measurement attached, not a proven gain.

- **`feedback.max_per_prompt` now does something.** The config documented it as
  "max corrections to include per prompt (too many = slow/expensive)" and set
  it to 5, but `PromptBuilder` called `FeedbackStore` without the limit, so the
  real ceiling was the method's own default and the setting was inert. Lowering
  it to control prompt size produced no effect and no error.

### Removed

- **Three config keys that were documented and never read.** Each was removed
  rather than wired up, because wiring any of them would weaken a guarantee the
  package makes unconditionally:

  | Key | Why removed rather than implemented |
  |---|---|
  | `privacy.sanitize_errors` | Read as a privacy switch. Sanitising is unconditional and stays that way — Rule 2 rejects a privacy breach by configuration, not just by default. |
  | `sql.allowed_operations` | Read as a security allowlist. SELECT-only is enforced in `SqlValidator` for every path with no exceptions; a config key implying you could add `UPDATE` is worse than no key. |
  | `errors.verbose` | Promised SQL and prompt length in responses. The statement is deliberately never echoed back to a user. |

  An adopter's published config carrying these keys is unaffected — nothing read
  them before either.

- `Exceptions\UnsupportedFeatureException` — dead code. Nothing threw it, so
  nothing could catch it, and its hardcoded driver list
  (`pgsql, mysql, sqlsrv, sqlite`) could contradict
  `IntrospectorRegistry::supportedDrivers()`, which is the single source of
  truth the real error path uses. `src/Exceptions/` held nothing else.

### Measured

Re-running `--testsuite Benchmark` against Gemini, twice:

| | before | after |
|---|---|---|
| This package's own set | 30/46 (65%) | **32–33/46 (70–72%)** |
| Spider dev sample | 29/36 (81%) | 29–30/36 (81–83%) |

A range, not a number. Four consecutive runs scored 33, 33, 33 and 32, and
individual questions move between them — "revenue by region in July 2026"
passed three times and on the fourth was grouped by payment method instead of
region. One run is not a measurement.

Join depth is where the remaining failures sit: 1-table 16/20, 2-table 13/16,
**3-table 2/6, 4-table 1–2/4**.

### Measured, and deliberately not changed

- **Superlatives are not a routing fault.** The README lists them among the
  weakest cases, and they do not escalate — but their gold SQL is plain `GROUP
  BY` / `ORDER BY` / `LIMIT`, which the intent contract expresses perfectly
  well. Across all 164 questions in the benchmark and Spider sets, adding a
  singular-superlative rule newly escalates seven questions and every one of
  their gold answers is expressible. That is determinism spent for nothing, so
  no rule was added. A test pins this so the measurement has to be repeated
  before anyone adds one.

- The two rules above were chosen the same way: measured against the gold SQL
  of all 164 questions first. They newly escalate five, and all five have gold
  containing `NOT EXISTS`, `NOT IN`, `HAVING` or a subquery. Zero
  over-escalation.

**These are routing fixes, not an accuracy claim.** The published benchmark
scores stand until a live run replaces them.

## [2.3.0] - 2026-08-28

### Fixed

- **A stale cache row no longer blames the question.** A recipe cached before a
  table was renamed names the old table; the validator refuses it against the
  schema-derived whitelist, correctly and with a message saying so — and that
  message was then replaced with *"Could not understand the query. Try
  mentioning a dataset name. Available: …"*. The question had been read
  perfectly, the dataset was named, and following the advice could never work.
  Rows carry no expiry, so it said this for ever at zero provider calls.

  `unsafe_sql` now survives that path. The replacement only ever happened after
  the regeneration strategy had already declined, so a question that names its
  dataset is still regenerated and still recovers on the second call.

- **The prompt asks for `required_filter` verbatim.** The guard that enforces it
  is a literal string match and has to stay one — it decides whether rows the
  schema says must never be counted were excluded, so a looser match would be
  guessing about the one thing nobody may guess about. Failing closed has a
  cost: a model writing `status NOT IN ('cancelled')` against a rule written
  `status != 'cancelled'` is refused, correct SQL and all, leaving the dataset
  unanswerable until someone notices. So the prompt gives rather than the guard
  — it now asks for the predicate character for character and names the rewrite
  models actually produce. Loosening the guard to meet the model would have
  traded a refusal for a wrong number.

### Removed

- **The fuzzy cache tier is gone.** It matched questions by wording similarity,
  was off by default, and could return a different question's answer. Removed
  rather than defaulted off again, so an app that enabled it years ago gets the
  safe behaviour on upgrade instead of the one its config file asks for.

  It scored `0.6 × Jaccard + 0.4 × Levenshtein` against a threshold, and that
  score turned out to be anti-correlated with safety. Measured at the `0.85`
  the package shipped:

  | Question pair | Score | Behaviour |
  |---|---|---|
  | `revenue for grade a` / `grade b` | 0.673 | missed |
  | `…for pending orders in grade a` / `grade b` | **0.858** | **reused — wrong answer** |
  | `…and category…in 2025 for grade a` / `grade b` | **0.875** | **reused — wrong answer** |
  | `revenue in 2025` / `in 2026` | 0.567 | missed |
  | `top 10 customers` / `bottom 10 customers` | 0.648 | missed |
  | `revenue summry…` / `revenue summary…` (a typo) | 0.747 | missed |
  | a genuine paraphrase | 0.592 | missed |

  The pairs that must never match scored *higher* than every pair that should,
  so no threshold separated them — at its own default the tier reused the one
  thing it had to refuse and refused both things it existed for.

  That is structural rather than calibration. `normalizeQuery()` already folds
  away everything two questions can innocently differ by — case, filler words,
  synonyms, duplication, word order — so whatever is still different afterwards
  differs in **meaning**, and an overlap score is dominated by the tokens two
  questions *share*. It therefore grew more confident the longer and more
  specific the question became. `grade a` against `grade b` is the same shape of
  difference as `summary` against `overview`.

  **The cost:** a typo or a paraphrase now misses and pays for a provider call.
  At the shipped `0.85` both already missed, so nobody running the defaults
  loses anything — only an install that had lowered `similarity_threshold` to
  around `0.55`, which was also being served `grade a` for `grade b`.

  **Paraphrases belong in the synonym map**, where they fold during
  normalisation into *exact* hits that nothing has to guess at.

- `cache.fuzzy_matching` and `cache.similarity_threshold` are **no longer
  read**. Both keys remain in the shipped config, with comments explaining why,
  so an app carrying them keeps booting. `jeeves:cache-stats` prints a
  line when `fuzzy_matching` is still set.

- `TwoTierQueryCache::findFuzzyMatch()` and `calculateSimilarity()` are
  removed. They were documented extension points; a subclass that overrode them
  will no longer have them called. Nothing else in the class changed shape —
  `findForDataset()` still takes the dataset hint, which it folds into the row
  hash so two pages scoped to different datasets never share a row.

## [2.2.1] - 2026-08-28

Correctness only. No configuration changed, no API was removed, and nothing
needs to be republished. Every item is a wrong answer that could be served
confidently, which is the failure this package treats as the worst one.

### Fixed

- **A question about a period is no longer answered from the cache.** The cache
  key is the question's *words*, and those do not change when the correct
  answer does. "Revenue last month" asked in July resolved to June and was
  stored; asked again in August it replayed June -  the wrong number, with the
  provider never consulted, no expiry to age the row out, and the fuzzy tier
  handing the same dead row to every rewording. Nothing about the answer looked
  wrong and there was no way for the user to escape it.

  Both routes are covered and both are judged on **the SQL that ran**: an
  intent carrying `date_from`/`date_to` is not stored, and a generated recipe
  whose SQL pins a moment is not stored. `NOW()` and `CURRENT_DATE`
  re-evaluate on replay, so recipes built on them stay cacheable. Refusals are
  logged at debug level. See `docs/CACHING.md`.

- **Rows cached by earlier versions are retired on upgrade.** Gating only the
  write left every row 2.2.0 had already written eligible on read, so the
  installs that actually had the bug above kept serving it for ever. The intent
  contract version now retires them; `jeeves:cache-cleanup` reclaims the
  space but is not required.

- **A period established in a conversation survives the next turn.** "Revenue
  in July 2026" followed by "only in West" answered West *all-time* -  an
  answer six times larger than the right one, reading exactly like a correct
  answer to the question asked. `parsed_query` carried a rendered label but
  never the two dates, so no conversation had ever held a date range.

- **...and can be widened again.** A carried window with no way to remove it is
  its own wrong answer: "and across all time" was classified as a refinement,
  the model correctly returned no dates, and the answer stayed pinned to July.
  A turn that names no dates is inheriting the window; saying *all time*, *all
  dates*, *every year*, *without the date filter* or *the whole period*
  clears it. On `sql_generation` the period does not carry at all -  the model
  wrote the `WHERE` and the engine will not guess at arbitrary SQL rather than
  report something it cannot verify.

- **Database failures say what went wrong on MySQL and SQLite**, not only
  PostgreSQL. Every non-PostgreSQL fault used to collapse to "An error
  occurred while querying the database" -  on the stack this package is most
  often installed on. The statement and its bindings are stripped *before* the
  cause is extracted, so a filter value containing `error:` can no longer be
  served back to the user in place of the cause, and a cause containing a
  parenthesis (`function sum(character varying) does not exist`) is no longer
  truncated to something that reads like a column name.

- **A cached recipe is re-checked against the schema rules that now apply.**
  One cached before an adopter added a `required_filter` was replayed into a
  refusal for ever, and the advice in that refusal -  ask again -  could not
  work. It now regenerates once and the row heals. A recipe naming a table the
  validator no longer whitelists still reports `not_understood`; that is
  tracked and not fixed here.

### Added

- `parsed_query.date_from` and `parsed_query.date_to` -  the window an answer
  actually applied, as dates rather than the existing rendered `period` label,
  so a client can carry it. Both `null` in `sql_generation` mode. Additive;
  nothing was renamed or removed.

### Known issues

- On the `sql_generation` route a period is not inherited by a follow-up. Name
  it again in the follow-up question.
- `required_filter` is matched literally, so SQL that satisfies the rule by
  other means (`status NOT IN ('cancelled')` against a rule written
  `status != 'cancelled'`) is still refused, and the regeneration above pays
  for a provider call each time it happens.
- `database_error` text is the driver's own, and a few MySQL messages embed the
  offending value. It reaches the user and the `QuestionFailed` event; it is
  never sent to a provider. **Resolved in the next release** — the message is
  now matched against a list of schema-complaint shapes and anything else falls
  back to the generic sentence.

## [2.2.0] - 2026-08-26

### Added
- **Every answer says how the question was understood.** `parsed_summary` is a
  one-line rendering of `parsed_query` -  *"Orders · revenue · by region ·
  status is pending"* -  and the bundled widget shows it under each answer.
  Previously only conversation follow-ups got such a line, so the very first
  question anyone asks displayed nothing. Roughly one question in five is
  misread on an uncurated schema, and a misreading that is shown is one the
  user can correct.

  **The line never says more than is known.** The breakdown and the period
  come from `SqlBuilder`, which wrote the SQL and knows which branch it took,
  so a plain total claims no breakdown even though the schema always has a
  default dimension, and a period is named only when a period was resolved and
  written into the `WHERE`. `parsed_query.group_by` and `parsed_query.period`
  report the same two values, where `group_by` previously echoed the schema
  default on every answer including ungrouped ones.

  On the `sql_generation` route the statement is the model's, so the line names
  the dataset, the measure and any structured filters — and stays silent about
  the breakdown and the period, because nothing in the package can honestly
  say what that SQL did. Recovering them by pattern-matching the finished
  query was tried and abandoned: a `GROUP BY` inside a CTE was read as the
  answer's dimension and printed "by region" over a single total, and any
  mention of the date column after `WHERE` — an `ORDER BY` included — was read
  as a date filter, captioning an all-time total with a month the model had
  merely claimed. A caption that might be false is worse than none, because
  this line exists to be trusted. `intent` mode, the default for simple
  questions under `auto`, names all of it.

- **`jeeves:benchmark`** -  measure accuracy on **your** schema. The
  package quotes a figure about itself; nobody should take that on trust for
  their own data. Supply a file of questions with the SQL you would have
  written by hand, and both queries run against your database with the result
  sets compared -  aliases and column order ignored, row order ignored unless
  the question asked for a ranking.

  The point is the before-and-after: run it, run `audit-schema`, write the
  descriptions, run it again. The difference is what curation bought you, in a
  number you produced yourself. A wrong answer reports what the question was
  *read as*, which is usually where the misreading is.

  `--min=` for a CI gate, `--dataset=` to narrow, `--json` to chart it. Costs
  real provider calls and confirms before spending them. Reference SQL must be
  `SELECT`/`WITH` -  a benchmark file gets copied between projects and a stray
  `UPDATE` would otherwise run unremarked. Example at
  `stubs/benchmark-example.php`.

  Grading uses the same `Benchmark\ResultComparator` as the package's own
  benchmark, so the two numbers mean the same thing.

- **`jeeves:audit-schema`** -  the curation coach. It also asks for a
  `required_filter` when a column declares values like `cancelled`, `void` or
  `deleted`, or is NAMED like an exclusion marker (`deleted_at`,
  `is_cancelled`), and the table has no rule -  the one gap whose absence
  produces a wrong number rather than a vague answer. Roughly one question in
  five is misread on an uncurated schema, and the same questions land
  near-perfectly once a handful of sentences are written; until now nobody
  could see *which* sentences were missing. It reports only what introspection
  genuinely cannot recover:

  - a term two datasets both claim (`"amount" could mean nq_orders.amount or
    nq_invoices.amount`) -  the ambiguity that returns a plausible wrong number
    rather than an error
  - columns with no description, where the model has only the name to go on
  - datasets with no aliases, which users will never address by key
  - aggregatable columns with no unit, so `1500` renders bare
  - infrastructure tables (`sessions`, `jobs`, `audit_log`) still registered

  `--dataset=` to narrow, `--json` for CI. Exits 0 even with findings -  an
  unaudited schema is not a broken install.

- **`jeeves:cache-stats`** -  what the cache is actually doing: distinct
  questions cached, answers reused, provider calls avoided, most-reused
  questions. `--json` for monitoring. This matters more now that fuzzy matching
  is off by default and rows are scoped, both of which lower hit rates on
  purpose; without numbers an operator cannot tell "correctly conservative"
  from "silently broken".

- **`jeeves:doctor` reports published-config drift.** Laravel merges
  package config one level deep, so an app that published
  `config/jeeves.php` under an earlier version silently loses every key
  added inside a block since. Doctor now names the missing settings. Two
  settings hit this in 2.1.0 alone, and one of them fails towards a wrong
  answer rather than an error.

- **`jeeves:doctor` flags small models.** Measured on this package's own
  conformance battery, models below roughly 20B drop filters and ignore date
  periods - asked for July, the whole table comes back. Someone running a small
  model locally and seeing wrong answers would otherwise blame the package. The
  check matches explicit parameter counts only -  not words like "mini", which
  would catch capable hosted models this package has never measured.

### Fixed
- **A capital letter could change the question.** `query_type` decides whether
  an answer is one number or a list, and every consumer compared it with
  `===` while nothing normalised it. A model replying `"Aggregation"` fell
  through to the ranking default, so "what is the overall figure" came back as
  a league table and `parsed_summary` announced a breakdown nobody asked for.
  Case is now folded at both points every question passes: where an intent
  enters the engine, which decides the SQL shape, and where a result is
  executed, which decides the wording. Not per provider - implementing
  `LlmProviderInterface` directly is the documented way to add one and would
  have stayed on the broken path.

  Only case and whitespace. Mapping words like `total` and `sum` onto
  `aggregation` was tried and removed: those name a MEASURE as often as a
  shape, and "top 3 customers by revenue" answered by a model that says
  `"total"` collapsed a correct ranking into a single number.

  The SQL-generation prompts also asked for a `query_type` of `overview`,
  which nothing in the package supports; they no longer do.

- **An aggregate over zero rows was reported as `0`.** `SUM`/`AVG` over no
  matching rows is SQL `NULL`, and `??` treats NULL as absent, so both
  coalesces fell through to a literal zero: "average amount for cancelled
  orders" with no cancelled orders answered `0`, and said it aloud, with
  `status: success`. It now says nothing matched. `COUNT`, which is genuinely
  `0` over no rows, is unaffected.

- **The answer sentence named a dimension the engine could not know.** The
  interpretation line was made honest about this first, which left the worse
  half in place: on the `sql_generation` route the sentence still asserted the
  schema's default group column, so rows that were regions were announced as
  "Top 2 customers" - and `speech_text` read it aloud. The sentence now names
  the dimension only when `SqlBuilder` wrote the query and knows it, and names
  none otherwise.

- **A missing table was blamed on the wording.** A schema naming a table that
  does not exist returned "Could not understand the query. Try mentioning a
  dataset name", while the log recorded `no such table`. The dataset *is*
  named, and it is the broken thing, so the advice was a loop with no exit.
  It is now reported as `database_error`, which is what
  `jeeves:doctor` has always called it.

- **`required_filter` was enforced on one route and merely suggested on the
  other.** It is how you state a rule the schema cannot imply -  cancelled
  orders never count towards a total. `intent` mode appended it to the SQL so
  the model could not omit it; `sql_generation` put a line in the prompt and
  hoped. A model that skipped that line returned every cancelled row in the
  total, reported success, and nothing downstream re-checked -  on the one
  setting whose whole purpose is that the answer is wrong without it.

  Generated SQL is now checked for the filter and **refused** if it is missing.
  Refused rather than patched: splicing a filter into arbitrary model SQL means
  a regex on the first `WHERE`, which on a query with a subquery in the `FROM`
  clause narrows the wrong thing, silently. The check is literal, so an
  equivalent filter written differently (`status NOT IN ('cancelled')`) is
  refused too -  a false refusal costs an error message, a loose check costs a
  wrong number.

  The check runs at the point SQL is **executed**, not where it is generated.
  Checking the generation sites left three ways past it: the self-verifier
  rewrites the SQL afterwards and its fix is trusted unchecked; that rewrite is
  stored as the cached recipe, and the replay branch executes a cached recipe
  verbatim; and a step of a decomposed question arrives by a third route.

  It is armed by the dataset being answered. Arming it instead from the table
  names found in the SQL was tried and reverted, because matching table names
  against arbitrary model SQL failed in both directions at once: `FROM a, b`
  hid the guarded table and served the forbidden total; a bare `FROM orders`
  armed every `schema.orders` dataset on a multi-scheme database and refused
  correct queries with a message naming a different dataset's filter; and a
  table pulled in by `required_join` armed a rule `SqlBuilder` had no way to
  satisfy, making that dataset permanently unanswerable.

  The known limitation, stated rather than papered over: a model that reports
  the WRONG dataset while querying a guarded table is not caught. The SQL is
  still validated against the schema-derived whitelist, and closing this
  properly means consuming `SqlValidator`'s existing table extractor rather
  than adding a second one.

  It tests that the filter is *present*, not that it *covers*: a filter inside
  a subquery while the outer aggregate runs unfiltered reads as present.
  Detecting that needs a SQL parser. `intent` mode remains the route where the
  filter is applied rather than checked.

  `required_filter` is also now documented, in `docs/SCHEMA.md`. It had existed
  since 1.0.0 as a commented-out line in a stub file and appeared in no
  documentation at all -  the most correctness-critical setting in the package
  was the hardest one to find.

- **`QueryState::fromIntent()` dropped the `filters` slot** although `summary()`
  reads it, so every state built from an intent described a filtered question
  exactly like an unfiltered one, and a new-query turn in a conversation lost
  the filter it had just been given.

- **`parsed_summary` could never name a breakdown or a period.** It was built by
  handing a result array to a builder that reads `group_by`, `date_from` and
  `date_to`, while a result array carries `group_column` and `time_filter` -  so
  those slots were not occasionally missing, they were unreachable. A total for
  one month and a total for all time printed the byte-identical line, above
  different numbers, at the one place in the UI meant to make a misreading
  visible. Every example string in `README.md` and `docs/API.md` was
  unproducible; they are now asserted by tests rather than described in a
  comment.

- **A conversation carried the previous answer's period into the next turn.**
  `parsed_summary` needed a period label, and the slot that held it was
  declared as describing one answer only -  but `merge()` copies every slot it
  holds and merely *iterates* the carrying list, so the label survived, was
  exported into the next turn's prompt as an instruction, and captioned the
  next answer. Asking "total revenue in July" and then "only in West" returned
  West's all-time total under the heading "July", when the July figure was six
  times smaller.

- **`jeeves:doctor` reported stale findings** when invoked twice in one
  process -  Octane, a queue worker, or two `Artisan::call()`s -  because it
  accumulated into instance state that was never reset, so a second run showed
  a setup getting worse while nothing had changed.

## [2.1.1] - 2026-08-21

A hotfix. Both of these affect every release from 1.0.0 onwards.

### Fixed
- **`composer require` failed on a current Laravel application.** The package
  required `guzzlehttp/guzzle: ^7.15.1`, and the Laravel 13 skeleton ships
  guzzle 8, so installing stopped at a dependency conflict on the very first
  command in the README. The requirement was never needed: nothing in `src/`
  references Guzzle. HTTP goes through `Illuminate\Support\Facades\Http`, whose
  own dependency resolves the client. The line is gone; nothing else changed.

- **A value from your data could be sent to the AI provider.** `next_steps`
  offered "Break West down by category", composed from the top row of the
  result and carrying that value in the query it would send. Suggestions are
  built locally, which is why this looked safe -  but the widget sends one to
  the provider the moment it is clicked, so the value left the building.

  On a schema produced by `jeeves:discover` the value need not be a
  region. Introspection marks every string column groupable, so on a stock
  Laravel `users` table the top row's value can be a `remember_token`, and
  `jeeves:audit-schema` reports nothing wrong.

  It was governed by `chat.suggest_drilldown_values` and defended on the
  grounds that clicking makes the question the user's own text. Rule 2 rejects
  both halves of that: the string was composed by the package out of data, and
  a privacy wall a setting can open is not a wall. **No suggestion now contains
  anything read from your rows.** The setting is still accepted, so a published
  config keeps working, and is no longer read.

  Turning it off would not have protected you either. The code fell back to
  `config(..., true)`, and because Laravel merges package config one level
  deep, a published `chat` block shadowed the package default entirely -  so the
  fix had to be in code, not in a default.

  Drill-down questions still work: "revenue by category in West" answers
  exactly as before. The package will not compose that sentence out of your
  data for you. Restoring the button means running a drill-down without a
  provider at all, since the dataset, measure, dimension and value are already
  known -  a feature, not a patch.

## [2.1.0] - 2026-08-16

### Added
- **`Contracts\ScopesCacheByDataset`** -  a new optional capability interface. A
  cache that implements it is asked `findForDataset($query, $datasetHint)` and
  can filter its own fuzzy tier; one that does not is called through
  `find()` exactly as before. Nothing existing has to change: it is a
  capability check, not a widening of `QueryCacheInterface`, because adding
  even an optional parameter to an interface method is a fatal error at class
  load for every implementation already in the wild.

- **`prompts.max_chars`** bounds the SQL-generation prompt, in characters - 
  the single-dataset and multi-dataset forms alike, whichever the question
  builds. `null` (default) = unbounded, today's behaviour byte for byte
  -  upgrading never changes anything unless you set this yourself. When a
  question's prompt would exceed it, the call is refused with an actionable
  message (bytes needed, bytes allowed, the config key to raise) BEFORE any
  request reaches the AI provider, rather than silently sending a truncated
  prompt.

  **Intent mode is not bounded by this.** `query_mode: intent`, and the intent
  half of `auto`, build their prompt in the provider and never consult
  `PromptBudget`. Ollama has its own pre-flight guard (`num_ctx`), so a
  self-hosted install is still protected there; a hosted provider will accept
  an oversized intent prompt and truncate or bill for it. Setting `max_chars`
  does not change that, and this is a known gap rather than an oversight in
  the wording.

  This is a **size bound only** -  it does not narrow which datasets are
  sent. An earlier iteration of this feature attempted to also shortlist the
  dataset(s) a question's prompt is built from; that half was withdrawn
  before release. Deciding a dimension table one join away is or is not
  relevant to a question is a guess about someone's domain, which this
  package will not make, and the guess was demonstrably capable of changing
  a correctly-grouped answer's grouping while still reporting `success`. On
  a schema too large for `max_chars` to hold, the fix is a smaller
  schema/`system_instructions` footprint, `query_mode: intent` for the
  affected questions, or raising the limit -  not scoping.

### Changed
- The dataset-index line format used by every provider's intent prompt now
  lives in one class, `Schema\DatasetCatalog`, instead of being open-coded in
  `AbstractProvider`. Output is byte-identical -  verified across 29 hand-built
  shapes and 20,000 generated ones against the previous implementation, not
  merely asserted by a test.

### Fixed

- **A cached answer could come from the wrong table.** The query cache is keyed
  on the question's TEXT, and nothing else was checked before replaying a row.
  So the same wording asked in two places -  a dataset-scoped page and a general
  one, two tenants, two schemas -  was answered from whichever row was written
  first: right shape, wrong table, `success`, no API call, and nothing in the
  log to suggest anything had happened. Tier 2 rows have no TTL, so it did not
  age out.

  A row now records the SCOPE the question was asked under, and a row asked
  under a different scope is a MISS, never a correction. An earlier version of
  this fix retargeted the mismatch instead -  overwriting the cached dataset
  with the current one -  which turned a wrong table into a wrong number,
  because a re-pointed recipe silently loses whatever made the original
  question need it.

  Four routes had to be closed rather than one: the exact-hash tier, the fuzzy
  tier, the conversation path, and the case where the asking scope cannot be
  determined at all. Each earlier attempt closed the route it was written for
  and left the next one open.

- **The cache was off for most questions on a multi-dataset install.** A
  question naming no dataset resolves to no scope, while its cached row
  recorded the dataset the model had CHOSEN, which is never null -  so the row
  could never match the question that created it and every repeat paid for
  another API call. The two values are now stored separately: `dataset` is what
  the answer is about, and is what `jeeves:cache-cleanup --dataset` and
  the stats command read; the asker's scope is what decides eligibility.

- **`jeeves:cache-cleanup --dataset=X` deleted nothing.** Two independent
  causes, both fixed.

  `store()` rewrote the intent blob and the contract version and left the
  derived columns alone, so `dataset` kept its first value once the same
  wording was asked about a second dataset. All derived columns now follow the
  intent.

  And the command ANDed its `--days=30` and `--min-hits=2` defaults in behind
  `--dataset`, so the flag could only ever remove rows that were simultaneously
  stale and barely used -  while the reason to reach for it, a schema file
  changed and its cached answers are now wrong, describes rows being hit daily.
  Naming a dataset now purges that dataset; pass `--days` or `--min-hits`
  explicitly to narrow it. A bare run keeps both conservative defaults.

- **A conversation turn reported itself as cached.** `metadata.cache_hit` was
  set the moment the cache returned a row, before either reader had decided
  whether to use one -  and both readers refuse a row mid-conversation. So a
  turn the provider had just generated and billed for came back `cache_hit:
  true`, and `verification.skip_on_cache_hit` (default true) read that flag and
  skipped `QueryVerifier` on brand-new SQL. The audit log and the
  `QuestionAnswered` event carried the same wrong value. The flag is now set
  only where a row is actually replayed.

- **A cached SQL recipe read back in intent mode lost its WHERE clause.**
  SQL-generation rows carry the finished statement; intent mode rebuilt them
  through `SqlBuilder`, whose contract has no slot for a predicate. A cached
  "revenue for pending orders" came back as the revenue for every row. Those
  rows exist precisely because the question needed something the intent
  contract cannot express, so rebuilding one through that contract was always
  going to drop exactly that. Intent mode now treats them as a miss.

- **A replacement cache was never read.** `findInCache()` bypassed any
  implementation that did not declare `ScopesCacheByDataset` whenever an asking
  dataset was known -  and on a single-dataset install that is every question.
  `store()` kept working, so the adopter's cache filled up, returned nothing,
  and said nothing.

- **A tenant gate added by overriding `find()` never ran.** Subclassing the
  bundled cache and overriding `find()` is the obvious way to add a tenant or
  permission check; the subclass inherits `ScopesCacheByDataset`, so the engine
  called `findForDataset()` and went straight past it -  a cross-tenant read
  that looks like a cache hit. Where `find()` is overridden and the scoped
  method is not, `find()` now wins.

- **Intent contract version raised to 4.** Rows written by 2.0.0 carry no
  scope, and reading that absence as "unscoped" would reopen the cross-dataset
  hit above. They are ignored rather than served. Expect one round of cache
  misses after upgrading; no action is required and nothing needs clearing.

- **A rate limit was reported as a rate limit on some paths and not others.**
  `error_code: rate_limited` maps to HTTP 429, `retryable: true` and a
  `Retry-After` header. Four paths never set it. A 429 while identifying the
  dataset was read as "could not place the question" and followed immediately
  by another call; a 429 inside a decomposed question let the remaining steps
  run, then returned an envelope with no `error_code` at all -  so the
  controller answered **HTTP 500 with `retryable: false`**, telling every SDK
  and queue worker not to back off, on the one fault where backing off is the
  entire remedy. The bundled widget renders `data.error`, absent from that
  envelope too, so the user saw "The query could not be processed" with no
  mention of a rate limit. A 429 during planning did the same and then made a
  second call. All four now report it, and a limited run stops rather than
  continuing to ask.

- **Follow-up steps escaped the conversation.** A decomposed question re-entered
  the engine without its conversation context, so the steps could not see the
  metric, period or filters established earlier -  and, because the cache guard
  keys on conversation state, each step wrote itself to the shared,
  session-less query cache. Another session asking those words read them back.

- **Fuzzy cache matching is now OFF by default** (`cache.fuzzy_matching`).
  Queries are normalised before comparison -  sorted, de-duplicated, synonyms
  folded, filler words dropped -  so any pair this tier judges already differs
  in meaning-bearing tokens, and the score is dominated by the tokens they
  share. Swapping one value in a long question scored 0.858 and 0.875 against
  a 0.85 threshold: the more precisely a question was stated, the likelier it
  was to be answered with a different one. No threshold setting fixes that.
  Re-enable with `JEEVES_CACHE_FUZZY=true` if you accept the trade.

- **Generated SQL was cached before it was validated.** A statement rejected by
  `SqlValidator`, or refused by the database, was written to a cache with no
  expiry and replayed on every later ask of that wording -  the provider never
  consulted again, so one bad generation made that question permanently
  unanswerable. Only SQL that validated and executed is cached now.

- **A question narrowed by a single character was answered from the
  un-narrowed one.** The cache key dropped every one-character token, so
  "total revenue for A" and "total revenue" shared a row: asking about one
  region returned the grand total, and asking for the total after that
  returned the region -  `cache_hit: true`, no provider call, nothing in the
  answer to show it. Single-character values are ordinary -  grade A, block B,
  zone 1 -  and a token that changes the answer belongs in the key. `a` and `i`
  have also been removed from the filler-word list for the same reason; two
  phrasings differing only by an article now cost one extra API call, which is
  the correct trade against a wrong number.

- **A blank value in `.env` crashed every query.** `JEEVES_PROMPT_MAX_CHARS=`
  with nothing after it is the empty string, not an absent value: `env()`'s
  default never fires and PHP 8 refuses a non-numeric string for a typed
  parameter, so `PromptBudget` threw a `TypeError` while the container was
  resolving `QueryOrchestrator` and the whole application 500'd.
  `JEEVES_TIMEOUT=` did the same to every provider request. All twenty
  numeric settings now fall back to their documented default when the variable
  is absent, empty, or not a number.

  If you published `config/jeeves.php` before this release, your copy
  still reads these through bare `env()`. Re-publish it, or leave the variables
  out of `.env` entirely rather than present-and-empty.

- **The Ollama context guard was being undone by the retry path.** 2.0.0 added
  a refusal so an oversized prompt is never sent, because Ollama does not
  reject one -  it discards the beginning, which is the schema, and answers from
  what is left. The refusal worked. What happened next did not.

  A refusal became `provider_error`, and `retry_on_failure` (default true) then
  retried with a *single-dataset* prompt, which is far smaller, clears the same
  guard, and gets answered for real. So a question that should have produced
  "raise num_ctx" produced a confident number computed from one table, under a
  prompt telling the model those were the only tables in the database.
  `metadata.retry` was the only trace.

  Reproduced end to end: four linked datasets, `num_ctx` 1700, multi-dataset
  prompt needing ~2,026 tokens (refused), single-dataset retry needing ~1,382
  (sent). The caller received `revenue: 500` -  arithmetic on one table -  instead
  of the refusal.

  A provider that declines locally now says so with `refused_before_sending`,
  and the orchestrator does not retry it. The distinction matters and is the
  whole fix: when the *model* returns something unusable, a simpler prompt
  genuinely is more likely to succeed and that retry is kept. When the request
  never left the machine, a smaller prompt is not a second attempt at the
  question -  it is a first attempt at a narrower one.

  The field is documented on `LlmProviderInterface`, so any provider adding a
  pre-flight guard gets the same protection without touching the orchestrator.

  Affects anyone on Ollama whose schema outgrew `num_ctx` -  the case 2.0.0's
  guard was written for.

## [2.0.0] - 2026-08-12

One rename, and a fix for a silent Ollama failure.

### Changed -  BREAKING

- **`scheme` is now `dataset`, everywhere.**

  The word came in with the application this package was extracted from, where
  the things being queried really were government schemes. Here it means "one
  of the datasets you have described", and it sat one letter away from
  `schema` -  which the package also uses, for the files that define those
  datasets, and which PostgreSQL uses for something else again. The codebase
  had 400 of one and 464 of the other, so a reader meeting
  `SchemaRegistry::getAvailableSchemes()` had no way to tell whether `scheme`
  was a typo, a Postgres schema, or a third thing.

  `dataset` was already the word in the prompts (`AVAILABLE DATASETS:`) and in
  the documentation prose. This finishes a rename that had only ever been
  half done.

  **`schema` keeps its meaning** -  the schema files, `SchemaRegistry`, and
  database introspection are unchanged. Only `scheme` moved.

  | Was | Now |
  |---|---|
  | `GET /jeeves/schemes` | `GET /jeeves/datasets` |
  | `?scheme=` query parameter | `?dataset=` |
  | `scheme` in every response and in `parsed_query` | `dataset` |
  | `scheme_name` in responses | `dataset_name` |
  | `default_scheme` config key | `default_dataset` |
  | `JEEVES_DEFAULT_SCHEME` | `JEEVES_DEFAULT_DATASET` |
  | `Jeeves::query($q, $schemeHint)` | `$datasetHint` |
  | `Jeeves::getSchemeMetrics($key)` | `getDatasetMetrics($key)` |
  | `<x-jeeves::widget scheme="orders" />` | `dataset="orders"` |
  | `scheme` column on the cache and feedback tables | `dataset` |
  | `"scheme"` in the intent JSON contract | `"dataset"` |
  | route name `jeeves.schemes` | `jeeves.datasets` |
  | `schemes` key in the `/datasets` response body | `datasets` |
  | `unique_schemes`, `top_schemes` in `/cache-stats` | `unique_datasets`, `top_datasets` |
  | `schemes_with_feedback` in `/feedback/stats` | `datasets_with_feedback` |
  | `POST /feedback` field `scheme` | `dataset` (still required) |
  | `LlmProviderInterface::parseIntent($text, $schemeList)` | `$datasetList` |
  | `QueryCacheInterface::clear(?string $scheme)` | `?string $dataset` |
  | `SchemaRegistry::getAvailableSchemes()` / `getSchemeListForLlm()` | `getAvailableDatasets()` / `getDatasetListForLlm()` |
  | `PromptBuilder::buildMultiSchemePrompt()` | `buildMultiDatasetPrompt()` |
  | `Events\QuestionAsked::$scheme` | `$dataset` |
  | `--scheme=` on `cache-cleanup` and `debug` | `--dataset=` |
  | widget JS option `scheme:` | `dataset:` |
  | conversation `state.scheme` | `state.dataset` |

  **Upgrading from 1.0.0:** run `php artisan migrate`. A guarded migration
  renames the columns, renames their indexes to match what a fresh install
  gets, and keeps your cached queries and submitted feedback. It is a no-op on
  a fresh install, and it reads your table names from config, so a renamed
  table is migrated too.

  Then rename the config key, the env variable and any widget prop, and update
  anything reading `scheme` or `scheme_name` out of a response.
  `jeeves:doctor` reports a published config that still sets
  `default_scheme`, because Laravel merges package config only one level deep
  and the old key would otherwise sit there being silently ignored.

  There is no compatibility shim. With a rename this thorough, one that
  accepted both spellings would reintroduce exactly the ambiguity being
  removed.

- **Cached intents now carry the contract version they were written against.**

  Bumping `INTENT_CONTRACT_VERSION` was supposed to make an upgrade miss stale
  rows rather than serve them. It never did on the fuzzy tier: fuzzy matching
  finds rows by their normalized *text* and never touches the hash the version
  was folded into. So the exact lookup would miss an old row and the fuzzy
  lookup would hand back the same row immediately afterwards -  which had
  quietly defeated the earlier `group_by` bump too.

  The cache table gains a `contract_version` column, written on store and
  required by both lookups. Rows from 1.0.0 have it null and are unreachable,
  which is the intended behaviour: the question is asked once more and
  re-cached under the current shape. This matters here because the column
  rename does not reach inside the stored intent, which is JSON and still says
  `scheme` -  served to 2.0.0 it would read as a null dataset, and Tier 2 rows
  have no expiry, so those questions would have stayed broken indefinitely.

### Verified

- **The renamed intent contract was re-tested against live models, not canned
  responses.** This is the check the unit suite structurally cannot perform: the
  fixtures and the parser were renamed by the same command, so they agree with
  each other and would keep agreeing even if every real model returned the old
  field name. The failure being looked for was a model answering with `scheme`,
  which turns every question into a clarification.

  Seventeen cases, arithmetic on three seeded rows: Gemini 2.5 Flash, Claude
  Sonnet 5, DeepSeek v4 Flash, Mistral Large and Llama 3.3 70B all **17/17**.
  Llama 3.1 8B **12/17**, up from 11/17 -  within run-to-run variance rather
  than an improvement to claim.

  All five 8B failures are the documented weakness: one dropped filter and four
  date periods, where it returns the whole table instead of the month asked
  for. Conversation state -  narrowing, drill-down, rewind, new-topic -  passes
  even there, because it is resolved in PHP rather than left to the model.

### Fixed
- **Ollama was reading a truncated schema and not saying so.** The driver set
  `num_predict` -  how many tokens to write -  and never `num_ctx`, how many it
  may read. So every install ran on Ollama's server default, 4096 in current
  builds and 2048 in older ones, and neither is a number this package chose.

  Ollama does not reject a prompt that exceeds it. It discards the beginning
  and answers from the rest. The beginning is the schema block, so the model
  answered from table definitions it had only partly seen, and nothing in the
  response showed it had happened. On a multi-table app this is reachable with
  an ordinary question.

  `num_ctx` is now sent explicitly and configurable as
  `llm.providers.ollama.num_ctx` or `OLLAMA_NUM_CTX`, defaulting to 8192. A
  prompt that will not fit is **refused rather than sent**, with an error
  naming the setting, the size needed and the size available.

  The fit estimate uses three bytes per token rather than the usual four. A
  schema prompt is mostly identifiers and punctuation, which tokenize worse
  than prose, and the count is in bytes -  so a multibyte description costs
  about one token per three-byte character. Both push the same way, and
  under-estimating is what sends a prompt to be truncated.

  Values below 1024 are treated as unset. `OLLAMA_NUM_CTX=` with nothing after
  it arrives as `''`, casts to `0`, and would otherwise refuse every question
  with an error naming the setting the user had just edited.

## [1.0.0] - 2026-08-10

First release. Nothing to upgrade from.

Ask your database a question in English and get an answer, without a single row
of your data leaving your server. The model is sent your schema structure and
the question; it returns SQL; your server validates it, runs it locally and
formats the result.

### Added

**The engine**

- Two strategies -  local intent parsing (`intent`) and LLM SQL generation
  (`sql_generation`) -  plus an `auto` mode that falls back from the first to the
  second. Both cost one API call.
- **Privacy wall.** Only schema structure and the user's question are ever sent
  to a provider. Generated SQL runs locally and result rows never leave the
  server. Enforced by tests, not by intent.
- **Escalation beyond the intent contract.** A question whose wording needs SQL
  the slot contract cannot express -  `HAVING`, a numeric filter, a comparison
  against an aggregate, an exclusion, `DISTINCT`, a ratio, several aggregates at
  once, a per-group top-N -  goes straight to SQL generation rather than being
  answered as a narrower question.
- **Counting.** Every dataset has an implicit `record_count` metric, so "how
  many orders by status" is answered by counting rather than by whichever
  measure happens to be default.
- **Breakdowns and cross-column filters.** `group_by` decides what the rows are;
  `filter_column` says which column a filter value belongs to, so "quantity by
  customer for Grocery" filters on category while grouping by customer. A filter
  on the column being grouped by narrows it rather than being dropped. An
  unusable breakdown or filter is refused with the list of what is available.
- **Non-sum aggregates.** AVG, MIN and MAX escalate to SQL generation -  unless
  the schema defines one as a `computed_metric`, which is where "average order
  value means `ROUND(AVG(amount), 2)`" belongs. Those stay in intent mode:
  no extra call, and deterministic.
- **Time periods.** `date_from` / `date_to`, with the column chosen by the
  schema's `date_column` -  a table often has several dates and they answer
  different questions. Providers are told today's date, since a model cannot
  otherwise resolve "last month". Dates are pattern-checked and bound; a period
  that cannot be parsed is refused rather than ignored. Every answer reports the
  range it actually covered, including in SQL-generation mode.
- **Multi-step answers and suggested follow-ups.** A question needing several
  queries is split, answered per step and combined, with the arithmetic done in
  PHP on values already fetched. Follow-up suggestions are derived from the
  schema -  no API call, and incapable of proposing a breakdown the validator
  would refuse.
- Two-tier query cache (exact + similarity) and a feedback store.

**Conversations**

- **Conversation as structured state.** Each turn is classified
  (`new_query` / `refinement` / `drill_down` / `reference`), merged into a slot
  object in PHP rather than by rewriting the question, and validated against the
  schema before any SQL is built.
- A follow-up that names **no** measure and **no** breakdown keeps the ones
  already established; one that names either still changes it. Frontier models
  carry that context unprompted -  the guard is what makes conversations work on
  small open-weight models, the audience that most needs them.
- A bare narrowing inside a conversation ("only in Guwahati" after "total amount
  by city") is read as a filter on the column already grouped by. Asked cold,
  the same words still return the detail rows for one named record.
- `GET /conversation/{session}` reads the state back -  it lives on the server,
  so without it a page reload leaves the next follow-up resolving against
  context the user can no longer see. `POST /conversation/{session}/rewind`
  restores an earlier turn. Refinements are capped and follow-ups never touch
  the query cache.
- **Each turn says whether it stood alone.** The widget shows *New topic*,
  *Follow-up*, *Drill-down* or *Same query*, tinted when context was carried.
  The state summary alone cannot convey it: "total amount by city" then
  "breakdown by client" both read `amount · by client` whether the measure was
  inherited or worked out afresh.

**The HTTP API**

- **[docs/API.md](docs/API.md)** -  every endpoint and field, the error table
  with HTTP statuses and which codes are worth retrying, the conversation state
  shape and how a turn is classified, and the CORS/token setup a front end on
  another origin needs. Pinned by contract tests.
- **`error_code` on every failure**, with a matching HTTP status: 429 for a rate
  limit (with `Retry-After`), 502 for a provider fault, 422 for a question that
  cannot be answered, 400 for one that was refused. Plus `retryable`, so a
  client need not know which codes are transient. A provider that is unreachable
  is never reported as a question nobody understood.
- A failed provider call includes the provider's own explanation -  truncated,
  whitespace-collapsed and run through the secret redactor. "HTTP 403" is a
  number; the same 403 carrying "your team doesn't have any credits yet" is a
  five-minute fix.
- **`/schemes` returns everything a "what can I ask?" panel needs** -  metrics,
  dimensions, default dimension, date column and examples per dataset, in one
  call. `?scheme=` returns one, or 404 naming the ones that exist.
- **CORS and token auth are a supported setup**, with an `api` middleware
  preset and a `doctor` check. Without the CORS entry the browser blocks the
  response before your code runs, and it looks like a network fault rather than
  a policy one.
- **Events** -  `QuestionAsked`, `QuestionAnswered`, `QuestionFailed`,
  `UnsafeSqlRejected` -  so an application can attribute cost, alert on a
  provider outage and audit what is being answered badly. `QuestionAnswered`
  carries the SQL that ran (server-side only) and a **row count, never the
  rows**: events walk into log drivers, queue payloads and error trackers, which
  is the one direction this package exists to keep data out of. A clarification
  fires neither the answered nor the failed event -  being asked which measure
  you meant is the system working.
- **Token counts.** `metadata.usage` reports what a question cost when the
  provider says, reading both dialects (Gemini `usageMetadata`, OpenAI `usage`),
  accumulated across every call one question took. Absent on a cache hit; absent
  rather than zero when unreported, because a zero understates a bill. Custom
  providers opt in via `Contracts\ReportsUsage` -  deliberately separate from
  `LlmProviderInterface`, which would break every custom provider already
  written.

**Providers**

- Four built-in drivers -  Gemini, OpenAI, Claude, Ollama -  plus a universal
  OpenAI-compatible driver: DeepSeek, Groq, Mistral, OpenRouter, vLLM,
  LM Studio, LocalAI and llama.cpp work by adding an `llm.providers.<name>`
  block with a `base_url`. A model you run yourself is a first-class choice.
- Provider defaults are verified live, per Rule 5.

**Getting it working**

- `jeeves:discover [--ai]` reads your database and writes one plain-PHP
  schema file per table. Every generated file is verified to parse, and every
  AI-suggested example query is validated and planned with `EXPLAIN` against the
  real database, so hallucinated columns are dropped before they ship.
- `jeeves:discover --merge` refreshes the structural layer after a
  migration while keeping everything written by hand -  descriptions, aliases,
  `llm_instructions`, `computed_metrics`, `example_queries`, per-column flags,
  `group_column`, `required_join`, `required_filter`. New columns appear,
  dropped columns are removed, and the change is reported.
- `jeeves:doctor` -  self-diagnosis for driver/key, provider model
  liveness, database connection, migrations, CA certificate store, and whether
  every table and column named in your schema files still exists. Prints the
  exact fix per problem, never prints the API key, exits non-zero. `--skip-api`
  uses no quota.
- `jeeves:install`, `jeeves:debug` and `jeeves:cache-cleanup`.
- The install template is **not** a selectable dataset: the registry ignores an
  unedited `example.php`, so a fresh install cannot answer "total amount" with
  "no such table: schema". `doctor` still reports it from the directory, so the
  file does not vanish from view.

**The widget**

- `<x-jeeves::widget />` -  a complete assistant in one line, served at
  `{prefix}/widget.js` with no publish step. Laid out as a conversation:
  header, scrolling thread, composer pinned at the bottom, question right and
  answer left. A search box above a result reads as one question at a time, and
  people did not try follow-ups because nothing suggested they could.
- **Voice is the browser's.** English speech is recognised on the device and
  posted to `/text` as if typed. Nothing to configure, no audio leaves the
  machine, and it works with every LLM because the model only ever reads a
  sentence. Firefox has no speech API, so the microphone is hidden there and
  people type -  which is why text input is never optional.
- `widget.language` selects an **English accent** (`en-US`, `en-GB`, `en-IN`,
  `en-AU`), which measurably changes recognition accuracy. Other locales are not
  supported: the browser will attempt them, but the prompts, schema text and
  answers are all English. Multilingual is a separate package with a speech
  pipeline of its own.
- Bar/table/card rendering, text-to-speech, clarification prompts, undo, a
  session that survives a page reload, and `height="auto"` to grow with content.
- Framework-level refusals say what happened: a 401, an expired session (419), a
  throttle (429) and a 500 each get their own message rather than one
  "Unexpected response status", and an HTML error page no longer crashes the
  parse. These are precisely the first-run failures.
- The widget is a reference implementation. Everything it does goes through the
  same public REST endpoints your own front end would use.

**Access and limits**

- **Authorization is a gate, not `auth` middleware**, following the pattern
  Telescope and Horizon use: a `viewJeeves` gate you define decides,
  always; with no gate, `local` and `testing` are open so the package works the
  moment you install it; anywhere else needs a signed-in user. Refusals are
  **403 naming the gate**, never a redirect -  a fresh Laravel app has no auth
  scaffolding, so `auth` had nowhere to redirect and the demo page returned 500
  while working exactly as configured. The check is appended whatever
  `routes.middleware` contains, since emptying that list is the first thing
  people do to make the widget public. `GET /widget.js` stays public.
- `limits.queries_per_day` (default 200 per person) alongside the rate limit,
  applied by the package so that customising `routes.middleware` cannot drop it.
  It counts questions, which is a rough proxy: a two-table schema and a
  fourteen-table schema differ by an order of magnitude in prompt tokens.

**Security**

- `InputGuard` before the provider -  prompt injection, SQL-in-text,
  exfiltration, unicode bypass, resource abuse -  and a SELECT-only
  `SqlValidator` with a schema-derived table whitelist after it. Every SQL path
  goes through the validator, including feedback-submitted corrections.
- The optional companion package `jayanta/laravel-ai-guard` is auto-detected and
  layered on top of `InputGuard`. Enforcement follows ai-guard's own `mode` and
  `confidence_threshold`, so installing it never silently changes what is
  refused; override with `privacy.ai_guard.enforce`.
- `guzzlehttp/guzzle` is floored at `^7.15.1`. The package makes authenticated
  outbound HTTP on every query, and earlier 7.x releases carry open advisories.

**Databases and platform**

- **PostgreSQL, MySQL, MariaDB and SQLite.** SQLite matters because
  `laravel new` creates a SQLite app, so it is the database most people trying
  the package already have. Structure comes from `sqlite_master` and the PRAGMA
  functions, with foreign keys whose target column is omitted resolved against
  the referenced primary key, composite keys paired by position, and declared
  types normalised by SQLite's affinity rules -  date and boolean checked first,
  since both have NUMERIC affinity and a DATE column is meant as a date.
- Pluggable introspection: any other database works by implementing
  `Contracts\SchemaIntrospectorInterface` and registering it under
  `sql.introspectors`. The built-in map lives in code, not in the publishable
  config, so apps that published their config keep working across upgrades - 
  Laravel merges package config only one level deep.
- **Laravel 12 and 13 on PHP 8.2–8.5.** Laravel 10 and 11 are intentionally not
  supported: both are past security support, so every published version carries
  advisories and Composer refuses to install them under its default
  `policy.advisories.block`. A version the package manager will not install is
  not a version worth claiming.

**How it is verified**

- CI across PHP 8.2–8.5 × Laravel 12/13, prefer-lowest jobs at both ends, a lint
  job, and integration jobs that execute the real generated SQL against
  PostgreSQL 16 and 18, MySQL 8.4 and 9, and MariaDB 11. Those jobs fail if
  their tests skip rather than run, because a skipped test is not a passing one.
- **A provider conformance battery** (`tests/Conformance`) -  seventeen cases
  whose answers are arithmetic on three seeded rows: totals, counts, filters,
  averages, rankings, calendar periods, a decomposed comparison, the follow-up
  suggestions, and a conversation that narrows, drills down and rewinds. It
  exists because unit tests structurally cannot find what it finds -  a canned
  response happily answers a request the real API would reject. CI runs it per
  provider from repository secrets and skips the ones not set, so a fork is
  never failed by a secret it cannot have.
- **Accuracy is measured, not asserted.** An execution-accuracy harness grades
  generated SQL against hand-written gold SQL by comparing result sets, the way
  Spider and BIRD do. Run with `JEEVES_BENCHMARK=1`.
- 528 PHP tests and 57 widget tests.
- Installed from the **distributed zip** -  not a source checkout -  into a clean
  Laravel 13 app. That is a genuinely different path: the zip is what
  `.gitattributes` `export-ignore` leaves behind. Auto-discovery, all five
  commands, `install`, both migrations, `discover` against the app's own tables,
  `doctor` and all fourteen routes work from `vendor/`.

### Known limits

- **Roughly one question in five is wrong on an *uncurated* schema.** Spider dev
  benchmark: 29/36 (81%). On a described schema it is far better, which is why
  the schema files matter more than anything else you will do. Show users
  `parsed_query` so a misreading is visible.
- **Model size matters more than vendor.** Gemini 2.5 Flash, Claude Sonnet 5,
  DeepSeek v4 Flash, Mistral Large and Llama 3.3 70B all score 17/17 on the
  conformance battery. Llama 3.1 8B scores 11/17 -  it misses filters, miscounts,
  and ignores date periods entirely. Use a 70B-class model or better wherever a
  wrong number matters.
- **No external adopter has used this yet**, which is what the release candidate
  label is for. Suitable for an internal tool where figures get sanity-checked;
  not yet for unattended reporting.

[Unreleased]: https://github.com/jay123anta/jeeves/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/jay123anta/jeeves/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/jay123anta/jeeves/releases/tag/v1.0.0
