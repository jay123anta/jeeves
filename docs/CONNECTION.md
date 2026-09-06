# The read-only database connection

**Jeeves will not execute generated SQL until you give it a database connection
of its own.** Until you do, every question fails with a message saying exactly
that. Nothing else is affected: your app boots, your routes work, your schema
files are read normally.

This is required, not advisory, and it is the one setting you cannot skip.

### The setup

```php
// config/jeeves.php
'sql' => [
    'database_connection' => 'jeeves',   // was null
],
```

```php
// config/database.php
'jeeves' => [
    ...config('database.connections.mysql'),   // or pgsql
    'username' => env('JEEVES_DB_USERNAME'),
    'password' => env('JEEVES_DB_PASSWORD'),
],
```

Then create that user with SELECT and nothing else — the statements are below.

```bash
php artisan jeeves:doctor
```

Doctor checks this, names the problem, and on MySQL and PostgreSQL will
actually try to create a table on that connection to prove it cannot.

### Why it is required rather than recommended

The SQL is written by a language model. Validation in `Security\SqlValidator`
— SELECT-only, a function allowlist, a schema-derived table whitelist — is
pattern matching, and pattern matching is only ever as good as the last attack
somebody thought of.

That is not a hypothetical. Three attack sweeps in one week found three
bypasses of that validator, and one of them defeated a fix written hours
earlier. A later audit found a `UNION` that read a table the whitelist had
never authorised. There is no pure-PHP multi-dialect SQL parser to replace the
patterns with, so the validator will not become perfect.

A separate database user makes a bypass a **failed query instead of a write**.
That is worth one line of config.

The guarantee is enforced on the **resolved connection at execution time**, not
at one call site: `Security\ExecutionConnection` refuses when nothing is
configured, and refuses when what is configured is your application's default.
There is no `DB::select()` fallback left anywhere in the package.

Schema **introspection** still uses your application connection. It reads table
and column metadata, never rows, and `discover` has to read your schema in
order to write the config that names the read connection.

---

## The grants

Scope them to the tables you actually expose. `GRANT SELECT ON *.*` works and
defeats the point.

### MySQL / MariaDB

```sql
CREATE USER 'jeeves'@'%' IDENTIFIED BY 'a-strong-password';

GRANT SELECT ON myapp.orders     TO 'jeeves'@'%';
GRANT SELECT ON myapp.customers  TO 'jeeves'@'%';
GRANT SELECT ON myapp.products   TO 'jeeves'@'%';

FLUSH PRIVILEGES;
```

Verify:

```sql
SHOW GRANTS FOR 'jeeves'@'%';
```

### PostgreSQL

```sql
CREATE ROLE jeeves LOGIN PASSWORD 'a-strong-password';

GRANT CONNECT ON DATABASE myapp TO jeeves;
GRANT USAGE   ON SCHEMA public  TO jeeves;

GRANT SELECT ON public.orders    TO jeeves;
GRANT SELECT ON public.customers TO jeeves;
GRANT SELECT ON public.products  TO jeeves;

-- Do NOT grant on future tables. A new table should be a deliberate decision,
-- not something the query engine picks up because it appeared.
REVOKE CREATE ON SCHEMA public FROM jeeves;
```

Verify:

```sql
SELECT table_name, privilege_type
FROM information_schema.role_table_grants
WHERE grantee = 'jeeves';
```

### SQLite

SQLite has no users or grants. There is no read-only connection to create, so
the identity check still applies — point `sql.database_connection` at a second
connection entry — but it buys you isolation of configuration, not of
privilege. Doctor says so rather than reporting a pass it cannot justify.

---

## Statement timeouts

A `SELECT` can still be expensive. A three-way join over large tables, or a
question phrased to produce one, will hold a connection until it finishes.
Cap it on the read connection so the ceiling is the database's, not your
web server's.

### MySQL / MariaDB

```php
// config/database.php, in the 'jeeves' connection
'options' => [
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION max_execution_time = 10000',  // ms
],
```

### PostgreSQL

```php
// config/database.php, in the 'jeeves' connection
'options' => [
    PDO::ATTR_TIMEOUT => 15,
],
```

and, more reliably, on the role itself:

```sql
ALTER ROLE jeeves SET statement_timeout = '10s';
ALTER ROLE jeeves SET idle_in_transaction_session_timeout = '10s';
```

The role-level setting is the one to trust — it survives connection pooling
and applies to every session, including ones opened by something other than
Laravel.

---

## What else protects you

This connection is the only layer that is not a judgement call. The others —
the input guard, the schema-only prompt, and the SQL validator with its
function allowlist and table whitelist — are described in
[SECURITY.md](SECURITY.md), along with what they deliberately do not cover.
