# Contributing

Thanks for considering it. This package answers questions about people's real
data, so the bar is correctness first and cleverness never.

## The one rule

**A wrong number rendered confidently is the worst thing this package can do.**
Every design argument resolves against that. A refusal with a clear message
beats a plausible answer, every time. If a change makes the engine guess where
it used to refuse, it will not be merged, however much it improves a benchmark.

## Getting set up

```bash
git clone https://github.com/jay123anta/jeeves
cd jeeves
composer install
vendor/bin/phpunit
```

No API key is needed. The suite uses a recording provider and never contacts a
model. Tests requiring MySQL or PostgreSQL skip themselves when the server is
not running, and CI covers both.

## Before you open a pull request

```bash
vendor/bin/phpunit          # must pass
vendor/bin/pint             # code style
vendor/bin/phpstan analyse  # static analysis
node --check resources/js/jeeves-widget.js
```

## What a good change looks like

**New behaviour gets a test.** Not a test that passes because the code exists - 
a test you have watched fail. Revert your fix, run it, confirm it goes red, put
the fix back. A test that has only ever been green is a comment.

**Every failure a real user hits becomes a regression test.** That is how this
package has improved: the test suite is a record of things that went wrong once.

**Say why in the code.** Comments here explain the decision, not the syntax.
"Refuses rather than injects, because splicing into arbitrary model SQL means a
regex on the first WHERE" is useful. "Loop over the rows" is not.

**Guard the thing, not the call site.** The commonest defect in this codebase
has been a guard attached to one path while an adjacent path stayed open. If
you add a check, enumerate every route that reaches the thing being checked.

## Things that need extra care

- **`src/Security/`, `src/LlmProviders/`, `PromptBuilder`** -  anything that can
  put data on the wire. Only schema structure and the user's question may ever
  reach a provider.
- **`SqlValidator`** -  every generated statement passes through it. `SELECT`
  only, schema-derived table whitelist, no exceptions.
- **`src/Contracts/`** -  adding a parameter to an interface method, even an
  optional one, is a fatal error at class load for every existing
  implementation. New capabilities go in a new optional interface. See
  `ScopesCacheByDataset` for the pattern, and `PublicContractCanaryTest` for
  the test that catches it.
- **Provider defaults** -  model IDs get retired. If you touch a provider,
  confirm its default model is still served.

## Documentation

If a change makes a document inaccurate, fix the document in the same pull
request: `README.md`, `docs/*.md`, `config/jeeves.php` comments (people
read those as docs), `stubs/`, and PHPDoc.

## Reporting bugs

Use the issue templates. A wrong answer is much easier to act on with the
question, the `parsed_summary` line from the response, the relevant schema
file, and which model you were running.

Security issues go to [SECURITY.md](SECURITY.md), not the tracker.
