# Conversations and multi-step answers

Follow-up questions, how a turn is classified, and questions that need more
than one query to answer.

## Multi-Turn Conversation

A conversation carries a **structured state**, not a transcript. Each turn is
classified, merged into that state in PHP, and validated against your schema
before any SQL is built:

```php
use Jayanta\Jeeves\Conversation\ConversationManager;

$conv = app(ConversationManager::class);

$conv->query('session-123', 'top 5 customers by revenue');
// state: revenue · by customer name            [new_query]

$conv->query('session-123', 'only in West');
// state: revenue · by customer name · region is West       [refinement]

$conv->query('session-123', 'break that down by status');
// state: revenue · by status · region is West              [drill_down]

$conv->query('session-123', 'how many orders by region');
// state: record count · by region                          [new_query]
//   ↑ inherits nothing: it names its own measure
```

**Turn classification comes first**, because the failure people actually
notice is a new question treated as a refinement - they ask something
unrelated and get results still filtered by a region from three turns ago.
Anything that names its own measure or dataset is a `new_query`, whatever it
starts with.

| Type | Example | What happens |
|---|---|---|
| `new_query` | "show me pending orders" | Fresh state, inherits nothing |
| `refinement` | "only in West", "last month" | Merged into the state |
| `drill_down` | "break that down", "in which category" | Adds a breakdown |
| `reference` | "why is that?", "explain" | Same query, different output |

**Every answer reports the state it was understood as.** `state_summary`
("revenue · by customer name · region is West") is rendered above the answer by
the widget, so a misread is caught rather than trusted, and "no, I meant X"
corrects something visible.

**And whether the turn stood alone.** Beside the state the widget shows
**New topic**, **Follow-up**, **Drill-down** or **Same query**, from
`conversation.classification`, tinted when context was carried.

The state summary cannot convey this by itself. Ask "total amount by city" then
"breakdown by client" and both read `amount · by client` whether the second
inherited the measure or worked it out afresh -  identical text, two different
things happening. And when a filter *is* inherited, knowing it was inherited
rather than asked for is the difference between trusting a number and checking
it.

**Going back is a restore, not a re-interpretation.** Every turn's state is
kept:

```php
$conv->rewind('session-123');          // step back one turn
// POST /jeeves/conversation/{session}/rewind
```

The widget offers this as **Undo last step**, appearing when the server reports
there is history behind the current turn. It matters more than it looks: if the
only correction on offer is clearing the conversation, undoing "only in West"
means retyping everything that came before it, so people start over instead of
refining -  the exact behaviour these endpoints exist to make unnecessary.

**A page reload keeps the conversation.** The state lives on the server under a
session id, so the widget persists that id per tab and asks
`GET /conversation/{session}` on mount, reporting what is still in force. The
rendered answers are not stored server-side and the thread does not come back - 
it says what it picked up rather than implying the screen was restored, because
the next follow-up resolves against those filters either way.

**Slots are validated against your schema before any SQL is built.** A metric
that doesn't exist, or a breakdown that isn't a dimension, becomes a clarifying
question - never a query. A confidently wrong number is worse than "I'm not
sure what you mean by that", and by a wide margin when the number will be read
as fact.

**Refinements are capped** at `conversation.max_refinements` (default 6). Past
that, ambiguity compounds faster than anyone notices, so the user is asked to
start fresh rather than served something confident and wrong. Rewind still
works.

Follow-ups are never answered from, or written to, the query cache: "only in
West" means one thing after a revenue question and another after an order
count.

**A period carries between turns, and can be widened.** Since 2.2.1, "revenue
in July 2026" followed by "only in West" answers West *in July*. Before that
the window was silently dropped and the follow-up covered all time -  an answer
six times larger than the right one, reading exactly like a correct answer to
the question asked.

Because it carries, it also needs a way out. A turn that names no dates is
inheriting the window, not clearing it -  a model omitting a field must never
be read as an instruction. Say so explicitly to widen:

> "revenue in July 2026" → "only in West" → **"and across all time"**

*all time*, *all dates*, *every year*, *without the date filter*, *drop the
date range* and *the whole period* are all recognised. **New topic** and
**rewind** clear it too. The current window is always visible in
`state.date_from` / `state.date_to` and in the state summary line, so a user
can see what is still in force rather than having to remember it.

On the `sql_generation` route the period does **not** carry. The model wrote
the `WHERE` itself and the engine cannot read the dates back out of arbitrary
SQL, so it says nothing rather than guessing -  name the period again in the
follow-up. Intent mode, and `auto` mode on questions the intent contract
covers, carry it normally.

## Chatbots: multi-step answers and follow-ups

Two features exist for conversational front-ends. Both are on by default and
both appear in the ordinary `/text` and `/conversation` responses - there is no
separate endpoint to call.

### Questions that need more than one query

"Compare revenue this year with last year" is two queries and a subtraction.
Answered as one it returns whichever half the model wrote SQL for, and drops
the other silently. So a question like that is split, each part is answered
independently, and the results are combined:

```json
{
  "status": "success",
  "type": "multi_step",
  "answer": "Line revenue is 21,011,088 (revenue in 2026) versus 18,244,900 (revenue in 2025) -  up 15.2%.",
  "steps": [
    { "n": 1, "question": "revenue in 2026", "status": "success",
      "answer": "Total revenue: 21,011,088", "rows": [ ... ] },
    { "n": 2, "question": "revenue in 2025", "status": "success",
      "answer": "Total revenue: 18,244,900", "rows": [ ... ] }
  ],
  "comparison": { "change_pct": 15.2, "direction": "up",
                  "first_value": 21011088, "second_value": 18244900 }
}
```

Three things worth knowing:

**Every step is a real query with every guard applied.** Each one is intent
parsed, validated against the schema-derived table whitelist, and executed like
a question typed on its own. Decomposition adds a planning call; it does not
add a second route into your database.

**Ordinary questions cost exactly what they cost today.** Planning only runs
when the wording suggests a comparison - "vs", "compare", "difference between",
"year on year". "Top 5 customers by revenue" never triggers it. A missed
decomposition simply behaves as it did before; that is the safer direction to
fail in.

**The arithmetic is done in PHP, on values already fetched.** A percentage is
never produced by asking a model to subtract two numbers, and never at all
unless the two steps measure the same metric and each returned a single value.
A ranking compared against a total yields `"comparison": null` rather than an
invented figure.

### Suggested follow-ups

The hard part of asking your data questions in words is not phrasing one - it
is knowing what can be asked at all. Every answer carries a few concrete next
questions:

```json
"next_steps": [
  { "label": "Revenue by product category", "query": "revenue by product_category" },
  { "label": "Show order counts by region", "query": "how many by region" },
  { "label": "Bottom 5 instead",            "query": "bottom 5 regions by revenue" }
]
```

These are derived from your schema, not from the model: no API call, no added
latency, and they can only propose breakdowns the validator would accept. The
bundled widget renders them as buttons; a custom chat UI can do the same.

**No suggestion ever contains a value from your results.** Until 2.1.1 one kind
did - "Break West down by category", built from the top row - and it was
governed by a `suggest_drilldown_values` setting. That was wrong twice over. A
suggestion is sent to the provider the instant it is clicked, so the value was
leaving the building; and the privacy wall is not something a setting should be
able to open. On a schema produced by `jeeves:discover` the top row's
value can be a `remember_token`, because introspection marks every string
column groupable and nothing in the suggester knows which columns hold secrets.

The setting is still accepted so an existing published config keeps working,
and it is no longer read.

You can still ask for a drill-down - "revenue by category in West" answers
exactly as before. The package will not compose that sentence out of your data
for you.

```php
// config/jeeves.php
'chat' => [
    'multi_step' => true,               // decompose comparison questions
    'max_steps' => 4,                   // ceiling on queries per question
    'suggest_next_steps' => true,
    'max_next_steps' => 4,
],
```
