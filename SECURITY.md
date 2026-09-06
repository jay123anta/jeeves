# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 2.1.x | ✅ |
| 2.0.x | Security fixes only |
| 1.x | ❌ |

## Reporting a vulnerability

**Do not open a public issue.**

Email **jay123anta@gmail.com** with `jeeves security` in the
subject. Include the version, a description, and the smallest reproduction you
have. You will get an acknowledgement within 72 hours and an assessment within
seven days.

If a fix is warranted it ships as a patch release, and the advisory credits you
unless you ask otherwise.

## What counts as a vulnerability here

This package sends **schema structure and the user's question** to an AI
provider, generates SQL from the reply, validates it, and runs it locally.
Reports that matter most:

- **Data leaving the server.** Any path where row values, result sets, or
  data-derived strings reach a provider. The privacy wall is the product; a
  breach of it is the highest-severity report this project can receive.
- **SQL that escapes the validator.** Anything non-`SELECT` reaching the
  database, or a query touching a table outside the schema-derived whitelist.
- **Prompt injection with consequence.** Input that makes the engine run SQL
  the schema forbids -  not merely a strange answer.
- **Authorisation bypass** on the HTTP routes, which spend your API key.
- **Credential exposure** in logs, events, exceptions or cached rows.

## What does not

- **A wrong answer.** The package is documented as roughly one question in
  five wrong on an uncurated schema. Report it as a bug -  please do -  but it
  is not a security issue.
- **Prompt injection that only produces nonsense** and is refused or returns
  no rows.
- **Cost from an ungated endpoint.** The routes are gated by default; an open
  one is a configuration choice. See "Who is allowed to ask" in the README.
- **Issues in an AI provider's own service.** Report those to the provider.

## Hardening worth doing

- Define the `viewJeeves` gate. Undefined outside `local`, the routes
  fall back to signed-in users only, which may still be broader than you want.
- Point `sql.database_connection` at a **read-only database user**. The
  package only generates `SELECT`, and defence in depth costs nothing.
- Run a local model (Ollama, vLLM, LM Studio) if even schema names are
  sensitive. Nothing leaves your network in that configuration.
- Read [docs/SECURITY.md](docs/SECURITY.md) for how the privacy wall, the SQL
  validator and the input guard actually work.
