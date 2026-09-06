# The bundled widget

A reference implementation, not the product. It uses the same public REST
API your own front end would -  see [API.md](API.md).

## Drop-in Widget (text + voice UI)

Don't want to build a frontend? The package ships one. Add this to any Blade view:

```blade
<x-jeeves::widget />
```

That single line renders a complete assistant: a chat frame with the thread
above and the composer below, microphone and spoken answers, result cards /
bar charts / tables, clarification buttons, and follow-up questions ("what
about the South region?") via the conversation API.

- **It is laid out as a conversation, deliberately.** Questions on the right,
  answers on the left, composer pinned at the bottom -  the shape of every
  messaging app. A search box above a result reads as one question at a time,
  and people did not try follow-ups because nothing suggested they could.
- **Voice works with every LLM provider** - speech-to-text happens in the
  browser (Chrome/Edge/Safari), so no audio leaves the user's machine, nothing
  needs configuring, and the model only ever reads English text. Firefox has no
  speech API, so the microphone is hidden there. Text input always works. See
  [Voice](#voice-the-browser-listens-the-server-reads-text).
- **No build step, no publishing** - the widget JS is served by the package at
  `/jeeves/widget.js`. To bundle it yourself instead:
  `php artisan vendor:publish --tag=jeeves-assets`
- **Customizable per instance:**

```blade
<x-jeeves::widget
    title="Ask about sales"
    dataset="orders"
    language="en-IN"
    height="640px"
    :examples="['Top 10 customers by revenue', 'Revenue by region']"
/>
```

`height` sets the chat frame; the thread scrolls inside it and the composer
stays put. Pass `height="auto"` to let the widget grow with its content
instead, which suits a short embed in a page that scrolls as a whole.

Example queries appear while the thread is empty and clear once it starts - 
knowing what can be asked is the hard part, and an empty prompt gives no clue.

Site-wide defaults live in `config/jeeves.php` under `widget`
(title, language, theme color, frame height, example chips, TTS on/off,
conversation mode).

A ready-made demo page is available at `/jeeves/demo` (enabled in the
`local` environment by default; control with `JEEVES_DEMO_PAGE`).

## Voice: the browser listens, the server reads text

**There is nothing to configure, and no audio endpoint.**

The widget uses the browser's `SpeechRecognition` to turn speech into English
text on the device, then posts that text to `/text` exactly as if it had been
typed. That is the entire voice design, and it buys three things at once:

- **It works with every LLM** -  Gemini, Claude, OpenAI, Ollama, anything
  self-hosted -  because by the time the model is involved it is reading a
  sentence, not hearing a recording.
- **No audio ever leaves the user's machine.** Not to your server, not to a
  model provider. There is no upload path in the package at all.
- **Nothing to set up and nothing extra to pay for** -  no transcription
  service, no second API key, no added latency.

Chrome, Edge and Safari support it. Firefox does not, so the widget hides the
microphone there and people type -  which is why text input is never optional.

### English only, on purpose

`language` chooses which **English accent** the browser listens for and speaks
back -  `en-US`, `en-GB`, `en-IN`, `en-AU`. It matters: `en-IN` recognises
Indian English far more accurately than `en-US` does.

```blade
<x-jeeves::widget language="en-IN" />
```

Another locale may appear to work, because the browser will attempt the
recognition, but nothing downstream is built for it -  the prompts, the schema
descriptions and the generated answers are all English. **Multilingual is a
separate package** with a speech pipeline of its own. This one stays an
English natural-language-to-SQL assistant.

## Building your own front end

The widget is a reference implementation, not the product. Most applications
will build their own in React, Vue, Inertia or Blade, and everything the widget
does goes through the same public REST endpoints.

**→ [API.md](API.md) -  the full HTTP reference.** Every endpoint,
every response field, the error codes and their HTTP statuses, the conversation
state shape, and CORS/token setup for a front end on a different origin.

The response shape is pinned by `tests/Feature/ApiContractTest.php`, so fields
do not disappear between releases without a changelog entry.

Three things worth knowing before you start:

- **`parsed_query` and `state_summary` are not debug output.** They state which
  measure, breakdown, filters and dates were actually used. Showing them is how
  a user catches a misreading instead of believing a wrong number.
- **Branch on `error_code`, never on the message.** `retryable` tells you
  whether trying again can possibly help.
- **A different origin needs CORS.** Without it the browser blocks the response
  before your code runs, and it looks like a network fault rather than a policy
  one. `php artisan jeeves:doctor` checks for this.
