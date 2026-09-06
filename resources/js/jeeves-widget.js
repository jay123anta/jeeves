/*!
 * Jeeves Widget -  drop-in text + voice query UI for jeeves.
 *
 * Zero dependencies. Injects its own styles. Talks to the package's REST API.
 *
 * Usage:
 *   <div id="nq"></div>
 *   <script src="/jeeves/widget.js"></script>
 *   <script>JeevesWidget.mount('#nq', { baseUrl: '/jeeves' });</script>
 *
 * Or simply use the Blade component: <x-jeeves::widget />
 *
 * Voice strategy -  the whole of it:
 *
 *   Browser SpeechRecognition (Chrome/Edge/Safari) turns speech into English
 *   text on the device, and that text is posted to /text like anything typed.
 *
 * That is deliberately all there is. No audio is ever uploaded, so it needs no
 * configuration, costs nothing, and works with EVERY LLM -  local or hosted - 
 * because by the time the model is involved it is reading a sentence. A
 * browser without speech recognition (Firefox) simply hides the microphone;
 * typing always works.
 *
 * Server-side transcription is out of scope by design. It belongs with
 * multilingual support in a separate package, which will have a speech
 * pipeline of its own. This one is English, browser-heard, text-in.
 */
(function (global) {
    'use strict';

    var DEFAULTS = {
        baseUrl: '/jeeves',
        csrfToken: null,          // auto-read from <meta name="csrf-token"> when null
        title: 'Ask your data',
        placeholder: 'Type a question or use the microphone…',
        // Which ENGLISH accent to listen for and speak back: en-US, en-GB,
        // en-IN, en-AU, en-CA. It changes recognition accuracy noticeably.
        // Null follows the page's <html lang>, then the browser.
        //
        // This package is English-only by design; another locale may seem to
        // work because the browser will try, but the prompts, schema text and
        // answers are all English.
        language: null,
        // How numbers are grouped: 'international' (1,234,567), 'indian'
        // (12,34,567), or any BCP-47 locale. Must match
        // response.number_format in config/jeeves.php -  the server
        // formats the insight figures and the widget formats the rows, and
        // when the two disagreed the same answer showed 20,28,763 in the bars
        // and 15,474,683 in the totals.
        numberFormat: 'international',
        voice: true,              // show mic when supported
        tts: true,                // read answers aloud (toggleable by user)
        autoSpeak: false,         // speak automatically after each answer
        conversation: true,       // use /conversation endpoint (follow-up support)
        examples: [],             // array of example query strings
        // Height of the chat frame. A fixed height is what makes it read as a
        // conversation: the composer stays put and the thread scrolls under it,
        // the way every messaging app people already know behaves. Use 'auto'
        // (or null) to let the widget grow with its content instead -  right
        // for a short embed in a page that scrolls as a whole.
        height: '520px',
        maxBarRows: 12,           // rows rendered as bars before table-only
        themeColor: '#2563eb',
        footerNote: 'AI-generated · please verify important figures',
        dataset: null              // fixed dataset hint (single-dataset apps)
    };

    // ---------------------------------------------------------------- helpers

    function h(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text !== undefined && text !== null) el.textContent = text;
        return el;
    }

    // Set from the widget's numberFormat option so rows are grouped the same
    // way the server groups the totals.
    var numberLocale = 'en-US';

    function numberLocaleFor(format) {
        if (format === 'indian') return 'en-IN';
        if (format === 'international' || !format) return 'en-US';
        return format; // any BCP-47 locale
    }

    function fmtNum(v) {
        if (v === null || v === undefined || v === '') return '- ';
        var n = Number(v);
        if (isNaN(n)) return String(v);
        try { return n.toLocaleString(numberLocale, { maximumFractionDigits: 2 }); }
        catch (e) { return String(n); }
    }

    function speechLocale(configured) {
        if (configured) return configured;
        var docLang = document.documentElement && document.documentElement.lang;
        return docLang || navigator.language || 'en-US';
    }

    /**
     * How a turn was classified, in words a user reads rather than an enum.
     *
     * `carried` is the part that matters: it says the previous turn's dataset,
     * measure and filters are still in force. Everything else is detail.
     */
    function turnKind(classification) {
        switch (classification) {
            case 'new_query':
                return { label: 'New topic', carried: false,
                         hint: 'Read on its own. Nothing was carried over from the last question.' };
            case 'refinement':
                return { label: 'Follow-up', carried: true,
                         hint: 'Narrowed the previous question. Its dataset, measure and filters still apply.' };
            case 'drill_down':
                return { label: 'Drill-down', carried: true,
                         hint: 'Changed the breakdown of the previous question. Its filters still apply.' };
            case 'reference':
                return { label: 'Same query', carried: true,
                         hint: 'Asked about the answer already on screen. The query did not change.' };
            default:
                return null;
        }
    }

    function titleCase(s) {
        return String(s).replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    function isNumericCol(rows, col) {
        for (var i = 0; i < rows.length; i++) {
            var v = rows[i][col];
            if (v === null || v === undefined || v === '') continue;
            return !isNaN(Number(v));
        }
        return false;
    }

    function uuid() {
        return 'nq-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    }

    // ---------------------------------------------------------------- styles

    var STYLE_ID = 'nq-widget-styles';

    function injectStyles(theme) {
        if (document.getElementById(STYLE_ID)) return;
        var css = ''
            + '.nq-widget{--nq:' + theme + ';font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;max-width:860px;margin:0 auto;color:#1f2937}'
            + '.nq-widget *{box-sizing:border-box}'
            // The frame: header, scrolling thread, composer pinned to the
            // bottom. min-height:0 on the scroller is load-bearing -  without it
            // a flex child refuses to shrink and the composer is pushed off the
            // bottom of the frame as the thread grows.
            + '.nq-frame{display:flex;flex-direction:column;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.06);overflow:hidden}'
            + '.nq-header{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;border-bottom:1px solid #eef0f3;flex:none}'
            + '.nq-scroll{flex:1 1 auto;min-height:0;overflow-y:auto;padding:14px;background:#fafbfc}'
            + '.nq-frame.nq-grow .nq-scroll{overflow-y:visible}'
            + '.nq-composer{flex:none;padding:12px 14px;border-top:1px solid #eef0f3;background:#fff}'
            + '.nq-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.06);padding:18px;margin-bottom:14px}'
            + '.nq-bot{border-radius:14px 14px 14px 4px;max-width:94%;margin-bottom:0}'
            + '.nq-empty{padding:22px 8px;text-align:center;color:#6b7280;font-size:.9rem}'
            + '.nq-title{font-size:1.05rem;font-weight:600;margin:0}'
            + '.nq-row{display:flex;gap:8px;align-items:center}'
            + '.nq-input{flex:1;border:1px solid #d1d5db;border-radius:10px;padding:11px 14px;font-size:.95rem;outline:none;min-width:0}'
            + '.nq-input:focus{border-color:var(--nq);box-shadow:0 0 0 3px color-mix(in srgb,var(--nq) 15%,transparent)}'
            + '.nq-btn{border:none;border-radius:10px;padding:11px 16px;font-size:.95rem;cursor:pointer;background:var(--nq);color:#fff;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}'
            + '.nq-btn:disabled{opacity:.55;cursor:not-allowed}'
            + '.nq-btn-ghost{background:#f3f4f6;color:#374151}'
            + '.nq-mic{position:relative;width:44px;height:44px;justify-content:center;padding:0;border-radius:50%}'
            + '.nq-mic.nq-listening{background:#dc2626;animation:nq-pulse 1.2s infinite}'
            + '@keyframes nq-pulse{0%{box-shadow:0 0 0 0 rgba(220,38,38,.5)}70%{box-shadow:0 0 0 12px rgba(220,38,38,0)}100%{box-shadow:0 0 0 0 rgba(220,38,38,0)}}'
            + '.nq-interim{margin-top:10px;font-size:.88rem;color:#6b7280;font-style:italic;min-height:1.2em}'
            + '.nq-examples{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px;justify-content:center}'
            + '.nq-chip{border:1px solid #e5e7eb;background:#f9fafb;border-radius:999px;padding:6px 12px;font-size:.82rem;color:#374151;cursor:pointer}'
            + '.nq-chip:hover{border-color:var(--nq);color:var(--nq)}'
            + '.nq-loading{display:flex;align-items:center;gap:10px;color:#6b7280;font-size:.9rem;padding:6px 2px}'
            + '.nq-spinner{width:18px;height:18px;border:2.5px solid #e5e7eb;border-top-color:var(--nq);border-radius:50%;animation:nq-spin .7s linear infinite}'
            + '@keyframes nq-spin{to{transform:rotate(360deg)}}'
            + '.nq-answer{display:flex;gap:10px;align-items:flex-start}'
            + '.nq-answer-text{flex:1;font-size:.98rem;line-height:1.5;margin:0}'
            + '.nq-speak{background:none;border:1px solid #e5e7eb;border-radius:8px;width:34px;height:34px;cursor:pointer;color:#6b7280;flex:none}'
            + '.nq-speak.nq-speaking{color:var(--nq);border-color:var(--nq)}'
            + '.nq-big{font-size:2.1rem;font-weight:700;color:var(--nq);text-align:center;padding:14px 0 4px}'
            + '.nq-big-label{text-align:center;color:#6b7280;font-size:.85rem;padding-bottom:10px}'
            + '.nq-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:6px}'
            + '.nq-stat{background:#f9fafb;border:1px solid #eef0f3;border-radius:10px;padding:12px;text-align:center}'
            + '.nq-stat-val{font-size:1.25rem;font-weight:700;color:#111827}'
            + '.nq-stat-lbl{font-size:.75rem;color:#6b7280;margin-top:3px}'
            + '.nq-bars{margin-top:8px}'
            + '.nq-bar-row{display:grid;grid-template-columns:minmax(90px,180px) 1fr 90px;gap:8px;align-items:center;padding:4px 0;font-size:.86rem}'
            + '.nq-bar-lbl{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#374151}'
            + '.nq-bar-track{background:#f3f4f6;border-radius:6px;height:20px;overflow:hidden}'
            + '.nq-bar-fill{height:100%;background:var(--nq);border-radius:6px;min-width:2px;transition:width .5s ease}'
            + '.nq-bar-val{text-align:right;font-weight:600;color:#111827}'
            + '.nq-table-wrap{overflow-x:auto;margin-top:10px}'
            + '.nq-table{width:100%;border-collapse:collapse;font-size:.86rem}'
            + '.nq-table th{text-align:left;padding:8px 10px;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;white-space:nowrap}'
            + '.nq-table td{padding:7px 10px;border-bottom:1px solid #f1f3f5}'
            + '.nq-table td.nq-num,.nq-table th.nq-num{text-align:right}'
            + '.nq-insights{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}'
            + '.nq-insight{background:color-mix(in srgb,var(--nq) 8%,white);border:1px solid color-mix(in srgb,var(--nq) 22%,white);border-radius:8px;padding:6px 12px;font-size:.8rem;color:#1f2937}'
            + '.nq-clarify{margin-top:8px}'
            + '.nq-clarify-msg{font-size:.92rem;color:#374151;margin-bottom:10px}'
            + '.nq-options{display:flex;flex-wrap:wrap;gap:8px}'
            + '.nq-state{display:flex;flex-wrap:wrap;align-items:baseline;gap:8px;margin:0 0 12px;padding:7px 11px;background:color-mix(in srgb,var(--nq) 6%,white);border:1px solid color-mix(in srgb,var(--nq) 18%,white);border-radius:8px}'
            + '.nq-state-lbl{font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;flex:none}'
            // A carried turn is tinted; a new topic is grey. The colour is the
            // fast signal, the word is the precise one.
            + '.nq-kind{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:2px 7px;border-radius:5px;background:#e5e7eb;color:#4b5563;flex:none;cursor:help}'
            + '.nq-kind-carried{background:color-mix(in srgb,var(--nq) 16%,white);color:color-mix(in srgb,var(--nq) 80%,black)}'
            + '.nq-state-val{font-size:.85rem;color:#374151;font-weight:500}'
            // Question right, answer left -  the arrangement every messaging app
            // uses, so who said what needs no explaining.
            + '.nq-turn{display:flex;flex-direction:column;gap:8px;margin-bottom:14px}'
            + '.nq-you{align-self:flex-end;background:var(--nq);color:#fff;border-radius:14px 14px 4px 14px;padding:9px 14px;font-size:.92rem;max-width:82%;word-wrap:break-word}'
            + '.nq-slot{align-self:stretch}'
            + '.nq-controls{display:flex;gap:6px;flex:none}'
            + '.nq-new-topic{font-size:.78rem;padding:6px 11px;flex:none}'
            + '.nq-notice{display:flex;flex-wrap:wrap;align-items:baseline;gap:8px;margin:0 0 14px;padding:8px 12px;background:#f3f4f6;border-radius:8px}'
            + '.nq-steps{margin-top:12px;display:flex;flex-direction:column;gap:10px}'
            + '.nq-step{border-left:3px solid color-mix(in srgb,var(--nq) 45%,white);padding:6px 0 6px 12px}'
            + '.nq-step-q{font-size:.82rem;color:#6b7280;margin-bottom:4px}'
            + '.nq-step-q b{color:var(--nq);font-weight:600}'
            + '.nq-step-period{margin-left:8px;padding:1px 7px;border-radius:6px;background:#f3f4f6;color:#6b7280;font-size:.72rem}'
            + '.nq-step-a{font-size:.9rem;color:#1f2937}'
            + '.nq-step-failed{border-left-color:#fecaca}'
            + '.nq-step-failed .nq-step-a{color:#b91c1c}'
            + '.nq-next{margin-top:14px;padding-top:12px;border-top:1px solid #f1f3f5}'
            + '.nq-next-label{font-size:.75rem;color:#9ca3af;margin-bottom:8px}'
            + '.nq-error{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:10px;padding:12px 14px;font-size:.9rem}'
            + '.nq-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;padding-top:10px;border-top:1px solid #f1f3f5;font-size:.75rem;color:#9ca3af;flex-wrap:wrap}'
            + '.nq-hidden{display:none!important}';
        var style = h('style');
        style.id = STYLE_ID;
        style.textContent = css;
        document.head.appendChild(style);
    }

    // ---------------------------------------------------------------- widget

    function Widget(root, opts) {
        this.opts = Object.assign({}, DEFAULTS, opts || {});
        this.opts.language = speechLocale(this.opts.language);
        numberLocale = numberLocaleFor(this.opts.numberFormat);
        this.root = root;
        this.sessionId = this.restoreSessionId();
        this.canRewind = false;
        this.listening = false;
        this.recognition = null;
        this.speaking = false;
        this.csrf = this.opts.csrfToken
            || (document.querySelector('meta[name="csrf-token"]') || {}).content
            || null;
        injectStyles(this.opts.themeColor);
        this.build();
        this.setupVoice();
        this.restoreConversation();
    }

    /**
     * The same session across a page reload.
     *
     * The conversation state lives on the server, keyed by session id. A fresh
     * id on every load orphaned it: the filters were still held server-side but
     * unreachable, so a reload silently started over while the old context sat
     * there until it expired. sessionStorage keeps the two in step, and scopes
     * to the tab -  a second tab is a second conversation, which is what someone
     * opening one expects.
     */
    Widget.prototype.restoreSessionId = function () {
        var key = 'nq-session:' + (this.root.id || 'default');
        this.sessionKey = key;
        try {
            var stored = global.sessionStorage && global.sessionStorage.getItem(key);
            if (stored) return stored;
        } catch (e) { /* storage blocked; a per-load session still works */ }

        var fresh = uuid();
        this.rememberSessionId(fresh);
        return fresh;
    };

    Widget.prototype.rememberSessionId = function (id) {
        try {
            if (global.sessionStorage) global.sessionStorage.setItem(this.sessionKey, id);
        } catch (e) { /* nothing to do; the conversation just will not survive a reload */ }
    };

    /**
     * A chat frame, not a search box.
     *
     * The old layout put the input at the top and grew answers downward, which
     * is the arrangement of a search form. It taught people the wrong thing: a
     * box above a result reads as one question at a time, so nobody tried a
     * follow-up, and the conversation features went unused because nothing on
     * screen suggested a conversation was on offer.
     *
     * Header, scrolling thread, composer pinned at the bottom -  the shape of
     * every messaging app -  makes following up the obvious next move.
     */
    Widget.prototype.build = function () {
        var o = this.opts, self = this;
        this.root.classList.add('nq-widget');

        // 'auto' is the documented way to ask for a growing widget; null and ''
        // mean the same thing for anyone mounting the JS directly.
        this.fixedHeight = o.height && o.height !== 'auto' ? o.height : null;

        var frame = h('div', 'nq-frame');
        if (this.fixedHeight) frame.style.height = this.fixedHeight;
        else frame.classList.add('nq-grow');

        // -- header: title, and the way out of the current context
        var header = h('div', 'nq-header');
        header.appendChild(h('p', 'nq-title', o.title || ''));

        var controls = h('div', 'nq-controls');

        // Going back one step, rather than throwing the whole thread away.
        // Without this the only correction available was New topic, so undoing
        // "only in West" meant retyping everything that came before it -  and
        // the cost of that is that people stop refining and start over instead.
        this.rewindBtn = h('button', 'nq-btn nq-btn-ghost nq-new-topic nq-hidden', 'Undo last step');
        this.rewindBtn.type = 'button';
        this.rewindBtn.title = 'Go back to how things stood before the last question';
        this.rewindBtn.addEventListener('click', function () { self.rewind(); });
        controls.appendChild(this.rewindBtn);

        // Follow-ups inherit the previous question's dataset and filters, so
        // there has to be a way out of that context. Hidden until there is a
        // thread to reset.
        this.newTopicBtn = h('button', 'nq-btn nq-btn-ghost nq-new-topic nq-hidden', 'New topic');
        this.newTopicBtn.type = 'button';
        this.newTopicBtn.title = 'Forget the conversation so far and start again';
        this.newTopicBtn.addEventListener('click', function () { self.newTopic(); });
        controls.appendChild(this.newTopicBtn);

        header.appendChild(controls);
        frame.appendChild(header);

        // -- thread
        this.resultArea = h('div', 'nq-scroll');
        frame.appendChild(this.resultArea);

        // -- composer
        var composer = h('div', 'nq-composer');
        var row = h('div', 'nq-row');

        this.input = h('input', 'nq-input');
        this.input.type = 'text';
        this.input.placeholder = o.placeholder;
        this.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') self.submit();
        });
        row.appendChild(this.input);

        this.micBtn = h('button', 'nq-btn nq-mic', null);
        this.micBtn.type = 'button';
        this.micBtn.title = 'Voice input';
        this.micBtn.innerHTML = micSvg();
        this.micBtn.addEventListener('click', function () { self.toggleVoice(); });
        this.micBtn.classList.add('nq-hidden'); // shown by setupVoice() when supported
        row.appendChild(this.micBtn);

        this.sendBtn = h('button', 'nq-btn', 'Ask');
        this.sendBtn.type = 'button';
        this.sendBtn.addEventListener('click', function () { self.submit(); });
        row.appendChild(this.sendBtn);

        composer.appendChild(row);
        this.interim = h('div', 'nq-interim', '');
        composer.appendChild(this.interim);
        frame.appendChild(composer);

        this.root.appendChild(frame);
        this.renderEmptyState();
    };

    /**
     * What an empty thread says.
     *
     * An empty chat frame with a blinking cursor is the worst prompt there is:
     * the hardest part of asking a database a question is knowing what it can
     * answer. The examples live here rather than under the composer so they
     * take no room once the conversation has actually started.
     */
    Widget.prototype.renderEmptyState = function () {
        var o = this.opts, self = this;

        this.emptyState = h('div', 'nq-empty');
        this.emptyState.appendChild(h('div', null, 'Ask a question about your data. You can follow up on any answer.'));

        if (o.examples && o.examples.length) {
            var ex = h('div', 'nq-examples');
            o.examples.forEach(function (q) {
                var chip = h('button', 'nq-chip', q);
                chip.type = 'button';
                chip.addEventListener('click', function () {
                    self.input.value = q;
                    self.submit();
                });
                ex.appendChild(chip);
            });
            this.emptyState.appendChild(ex);
        }

        this.resultArea.appendChild(this.emptyState);
    };

    /** Keep the newest message in view, the way a chat window does. */
    Widget.prototype.scrollToBottom = function () {
        var el = this.resultArea;
        if (!el) return;
        // A frame scrolls itself; a grown-to-fit widget has no scroller of its
        // own and the page is what needs to move.
        if (this.fixedHeight) el.scrollTop = el.scrollHeight;
        else if (this.currentSlot && this.currentSlot.scrollIntoView) {
            this.currentSlot.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    };

    // -------------------------------------------------------------- voice

    Widget.prototype.setupVoice = function () {
        if (!this.opts.voice) return;
        var SR = global.SpeechRecognition || global.webkitSpeechRecognition;
        var self = this;

        if (SR) {
            this.recognition = new SR();
            this.recognition.lang = this.opts.language;
            this.recognition.interimResults = true;
            this.recognition.continuous = false;
            this.recognition.onresult = function (e) {
                var finalText = '', interimText = '';
                for (var i = 0; i < e.results.length; i++) {
                    if (e.results[i].isFinal) finalText += e.results[i][0].transcript;
                    else interimText += e.results[i][0].transcript;
                }
                self.interim.textContent = interimText || finalText;
                if (finalText) self.input.value = finalText.trim();
            };
            this.recognition.onend = function () {
                self.setListening(false);
                self.interim.textContent = '';
                if (self.input.value.trim()) self.submit();
            };
            this.recognition.onerror = function () { self.setListening(false); };
            this.micBtn.classList.remove('nq-hidden');
        }

        // No else. A browser without SpeechRecognition simply has no
        // microphone button, and typing works as it always did.
        //
        // There used to be a MediaRecorder fallback that uploaded audio to a
        // /voice endpoint for the server to transcribe. That is inbuilt speech
        // processing, which belongs to the multilingual package, not to this
        // one -  this package's design is that the BROWSER hears and the server
        // only ever receives a sentence of English text.
    };

    Widget.prototype.toggleVoice = function () {
        if (this.listening) return this.stopVoice();
        try { this.recognition.start(); this.setListening(true); } catch (e) { /* already started */ }
    };

    Widget.prototype.stopVoice = function () {
        if (this.recognition) {
            try { this.recognition.stop(); } catch (e) {}
        }
        this.setListening(false);
    };

    Widget.prototype.setListening = function (on) {
        this.listening = on;
        this.micBtn.classList.toggle('nq-listening', on);
        if (!on) this.interim.textContent = '';
    };

    // -------------------------------------------------------------- network

    Widget.prototype.headers = function () {
        var hd = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (this.csrf) hd['X-CSRF-TOKEN'] = this.csrf;
        return hd;
    };

    Widget.prototype.submit = function (datasetOverride) {
        var text = this.input.value.trim();
        if (!text) return;
        var self = this, o = this.opts;
        this.stopSpeaking();

        // The question goes into the thread and the box is emptied, so a
        // follow-up can be typed straight away. Leaving the previous text in
        // place meant every follow-up had to be deleted first, which is why a
        // conversation was easier to avoid than to have.
        this.beginTurn(text);
        this.input.value = '';
        this.renderLoading();
        this.sendBtn.disabled = true;

        var useConversation = o.conversation && !datasetOverride;
        var url = o.baseUrl + (useConversation ? '/conversation' : '/text');
        var body = { text: text };
        if (useConversation) body.session_id = this.sessionId;
        var dataset = datasetOverride || o.dataset;
        if (dataset) body.dataset = dataset;

        fetch(url, { method: 'POST', headers: this.headers(), body: JSON.stringify(body), credentials: 'same-origin' })
            .then(function (r) {
                return r.text().then(function (raw) {
                    var json = null;
                    try { json = JSON.parse(raw); } catch (e) { /* an HTML error page */ }
                    return { status: r.status, json: json };
                });
            })
            .then(function (res) { self.handleHttp(res.status, res.json); })
            .catch(function () { self.renderError('Could not reach the query service. Please try again.'); })
            .finally(function () { self.sendBtn.disabled = false; });
    };

    /**
     * Turn an HTTP status into something the user can act on.
     *
     * The status used to be read and thrown away, so every framework-level
     * refusal -  not signed in, expired session, throttled -  reached the user as
     * "Unexpected response status.", which describes nothing and suggests
     * nothing. These are the three most common first-run failures for someone
     * who has just installed the package, and each has a different fix.
     *
     * The package's own errors always carry a `status` field and are passed
     * through untouched; this only interprets the ones Laravel raises before
     * the request ever reaches the engine.
     */
    Widget.prototype.handleHttp = function (status, json) {
        if (json && json.status) return this.renderResponse(json);

        if (status === 401 || status === 403) {
            return this.renderError('You need to be signed in to ask questions. Sign in and try again.');
        }

        // Laravel's CSRF failure. Almost always a page left open until the
        // session expired; occasionally a custom front end not sending the
        // token at all.
        if (status === 419) {
            return this.renderError('Your session expired. Reload the page and try again.');
        }

        if (status === 429) {
            return this.renderError('Too many questions just now. Wait a moment and try again.');
        }

        if (status >= 500) {
            return this.renderError('The server could not answer that. Check the application log, or run: php artisan jeeves:doctor');
        }

        if (json) return this.renderResponse(json);

        this.renderError('Unexpected response from the server (HTTP ' + status + ').');
    };

    // -------------------------------------------------------------- rendering

    /**
     * Start a new turn in the thread.
     *
     * Answers used to replace one another, which made a follow-up impossible to
     * see: "only in West" produced a card that looked exactly like a fresh
     * question, with nothing on screen tying it to the one before. Each turn
     * now keeps the question above its answer, so a conversation reads as one.
     */
    Widget.prototype.beginTurn = function (question) {
        // The examples have served their purpose once a question has been
        // asked, and a frame is short enough that they would push the
        // conversation out of view.
        if (this.emptyState && this.emptyState.parentNode) {
            this.emptyState.parentNode.removeChild(this.emptyState);
        }

        var turn = h('div', 'nq-turn');

        if (question) {
            turn.appendChild(h('div', 'nq-you', question));
        }

        var slot = h('div', 'nq-slot');
        turn.appendChild(slot);
        this.resultArea.appendChild(turn);
        this.currentSlot = slot;

        if (this.newTopicBtn) this.newTopicBtn.classList.remove('nq-hidden');
        this.scrollToBottom();
    };

    /** Clears only the turn in flight, never the thread above it. */
    Widget.prototype.clearResult = function () {
        if (!this.currentSlot) this.beginTurn(null);
        this.currentSlot.innerHTML = '';
    };

    /**
     * Pick up a conversation the server still remembers.
     *
     * The thread itself cannot come back -  the server keeps the state, not the
     * rendered answers -  so this says what is still in force rather than
     * pretending to restore the screen. Claiming more than it can deliver would
     * be worse than saying nothing: the next follow-up resolves against these
     * filters whether or not the user can see them.
     */
    Widget.prototype.restoreConversation = function () {
        if (!this.opts.conversation) return;
        var self = this;

        fetch(this.opts.baseUrl + '/conversation/' + encodeURIComponent(this.sessionId), {
            method: 'GET', headers: this.headers(), credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var conv = (data && data.conversation) || {};
                if (!conv.context_active || !data.state_summary) return;

                self.setCanRewind(!!conv.can_rewind);
                if (self.newTopicBtn) self.newTopicBtn.classList.remove('nq-hidden');

                if (self.emptyState && self.emptyState.parentNode) {
                    self.emptyState.parentNode.removeChild(self.emptyState);
                }
                self.resultArea.appendChild(
                    self.noticeLine('Picking up where you left off', data.state_summary)
                );
                self.scrollToBottom();
            })
            .catch(function () { /* no stored conversation, or offline -  start fresh */ });
    };

    /**
     * Step back one turn.
     *
     * The server restores the exact state it held before, so this is a restore
     * and not another interpretation. The last turn is dropped from the thread
     * to match -  leaving an answer on screen that no longer describes the state
     * behind it is how a user ends up refining against something invisible.
     */
    Widget.prototype.rewind = function () {
        var self = this;
        this.rewindBtn.disabled = true;

        fetch(this.opts.baseUrl + '/conversation/' + encodeURIComponent(this.sessionId) + '/rewind', {
            method: 'POST', headers: this.headers(), body: JSON.stringify({ steps: 1 }), credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    return self.renderError((data && data.error) || 'There is nothing to go back to.');
                }

                var turns = self.resultArea.querySelectorAll('.nq-turn');
                if (turns.length) turns[turns.length - 1].remove();

                self.currentSlot = null;
                self.setCanRewind(!!(data.conversation || {}).can_rewind);
                self.resultArea.appendChild(self.noticeLine('Went back to', data.state_summary || 'the previous step'));
                self.scrollToBottom();
            })
            .catch(function () { self.renderError('Could not go back. Please try again.'); })
            .finally(function () { self.rewindBtn.disabled = false; });
    };

    Widget.prototype.setCanRewind = function (on) {
        this.canRewind = on;
        if (this.rewindBtn) this.rewindBtn.classList.toggle('nq-hidden', !on);
    };

    /** A line from the widget itself, distinct from an answer. */
    Widget.prototype.noticeLine = function (label, detail) {
        var el = h('div', 'nq-notice');
        el.appendChild(h('span', 'nq-state-lbl', label));
        el.appendChild(h('span', 'nq-state-val', detail));
        return el;
    };

    /** Forget the conversation and start again. */
    Widget.prototype.newTopic = function () {
        var self = this;

        // Best effort: the server drops the context, but the thread is cleared
        // either way so the user is never stuck with one that will not reset.
        fetch(this.opts.baseUrl + '/conversation/' + encodeURIComponent(this.sessionId), {
            method: 'DELETE', headers: this.headers(), credentials: 'same-origin'
        }).catch(function () {});

        this.stopSpeaking();
        this.sessionId = uuid();
        this.rememberSessionId(this.sessionId);
        this.resultArea.innerHTML = '';
        this.currentSlot = null;
        this.setCanRewind(false);
        if (this.newTopicBtn) this.newTopicBtn.classList.add('nq-hidden');
        this.renderEmptyState();
        self.input.focus();
    };

    Widget.prototype.renderLoading = function (msg) {
        this.clearResult();
        var card = h('div', 'nq-card nq-bot');
        var l = h('div', 'nq-loading');
        l.appendChild(h('div', 'nq-spinner'));
        l.appendChild(h('span', null, msg || 'Thinking…'));
        card.appendChild(l);
        this.currentSlot.appendChild(card);
        this.scrollToBottom();
    };

    Widget.prototype.renderError = function (msg) {
        this.clearResult();
        var card = h('div', 'nq-card nq-bot');
        card.appendChild(h('div', 'nq-error', msg));
        this.currentSlot.appendChild(card);
        this.scrollToBottom();
    };

    Widget.prototype.renderResponse = function (data) {
        if (!data || typeof data !== 'object') return this.renderError('Unexpected response from server.');

        // Whether going back is possible is the server's answer, not a guess
        // from how many turns are on screen -  a clarification adds a turn to
        // the thread without adding one to the history.
        if (data.conversation) this.setCanRewind(!!data.conversation.can_rewind);

        if (data.status === 'error') return this.renderError(data.error || 'The query could not be processed.');
        if (data.status === 'clarification_needed') return this.renderClarification(data);
        if (data.status !== 'success') return this.renderError('Unexpected response status.');

        this.clearResult();
        var card = h('div', 'nq-card nq-bot');
        var self = this;

        // Answer line + TTS control
        if (data.answer) {
            var ans = h('div', 'nq-answer');
            ans.appendChild(h('p', 'nq-answer-text', data.answer));
            if (this.opts.tts && global.speechSynthesis && (data.speech_text || data.answer)) {
                this.speakBtn = h('button', 'nq-speak');
                this.speakBtn.type = 'button';
                this.speakBtn.title = 'Read aloud';
                this.speakBtn.innerHTML = speakerSvg();
                this.speakBtn.addEventListener('click', function () {
                    self.speaking ? self.stopSpeaking() : self.speak(data.speech_text || data.answer);
                });
                ans.appendChild(this.speakBtn);
            }
            card.appendChild(ans);
        }

        // What the question was understood to mean, above the answer. A user
        // who can read "revenue · by customer · region is West" catches a
        // misread instead of trusting a number that answers something else,
        // and "no, I meant X" becomes a correction of something visible.
        // state_summary on a conversation turn; parsed_summary on an ordinary
        // one. The second was added because this block only ever fired for
        // conversations -  so the line that lets a user catch a misreading was
        // missing from the very first question anyone asks, which is exactly
        // where a misreading is least expected and most damaging.
        var understoodAs = data.state_summary || data.parsed_summary;

        if (understoodAs) {
            var state = h('div', 'nq-state');

            // Whether this turn stands alone or carried the last one forward.
            //
            // The state summary alone cannot answer that. Ask "total amount by
            // city" then "breakdown by client" and both read "Invoices ·
            // amount · by client" whether the second inherited the metric or
            // worked it out afresh -  identical text, two different things
            // happening. And when a filter IS inherited, knowing it was
            // inherited rather than asked for is the difference between
            // trusting a number and checking it.
            var kind = turnKind((data.conversation || {}).classification);
            if (kind) {
                var badge = h('span', 'nq-kind' + (kind.carried ? ' nq-kind-carried' : ''), kind.label);
                badge.title = kind.hint;
                state.appendChild(badge);
            }

            state.appendChild(h('span', 'nq-state-lbl', 'Reading this as'));
            state.appendChild(h('span', 'nq-state-val', understoodAs));
            card.appendChild(state);
        }

        // A decomposed question: show the working, not just the conclusion.
        // A combined number nobody can trace is worth less than two they can.
        if (data.type === 'multi_step' && (data.steps || []).length) {
            this.renderSteps(card, data.steps);
        }

        var rows = data.type === 'multi_step' ? [] : (data.rows || []);
        var viz = data.visualization || 'table';

        if (viz === 'message' || rows.length === 0) {
            // answer text already covers it
        } else if (rows.length === 1) {
            this.renderSingleRow(card, rows[0]);
        } else if (viz === 'bar' && rows.length <= this.opts.maxBarRows) {
            this.renderBars(card, rows);
            if (rows.length > 5) this.renderTable(card, rows);
        } else {
            this.renderTable(card, rows);
        }

        // Insights
        if (data.insights && typeof data.insights === 'object') {
            var ins = h('div', 'nq-insights');
            var count = 0;
            Object.keys(data.insights).forEach(function (k) {
                var v = data.insights[k];
                if (v === null || v === undefined || typeof v === 'object') return;
                ins.appendChild(h('span', 'nq-insight', titleCase(k) + ': ' + v));
                count++;
            });
            if (count) card.appendChild(ins);
        }

        // Somewhere to go next. The hardest part of a chat box is knowing what
        // it can answer, and an empty prompt gives no clue.
        this.renderNextSteps(card, data.next_steps || []);

        // Footer: provenance + timing. (No SQL here -  server-side logs only.)
        var foot = h('div', 'nq-footer');
        foot.appendChild(h('span', null, this.opts.footerNote));
        var meta = data.metadata || {};
        var right = [];
        if (meta.cache_hit) right.push('cached');
        if (meta.processing_time_ms) right.push(Math.round(meta.processing_time_ms) + ' ms');
        if (right.length) foot.appendChild(h('span', null, right.join(' · ')));
        card.appendChild(foot);

        this.currentSlot.appendChild(card);
        this.scrollToBottom();

        if (this.opts.autoSpeak && this.opts.tts && global.speechSynthesis) {
            this.speak(data.speech_text || data.answer || '');
        }
    };

    Widget.prototype.renderSingleRow = function (card, row) {
        var keys = Object.keys(row);
        var numeric = keys.filter(function (k) { return !isNaN(Number(row[k])) && row[k] !== null && row[k] !== ''; });
        if (keys.length <= 2 && numeric.length >= 1) {
            var valKey = numeric[numeric.length - 1];
            card.appendChild(h('div', 'nq-big', fmtNum(row[valKey])));
            card.appendChild(h('div', 'nq-big-label', titleCase(valKey)));
            return;
        }
        var grid = h('div', 'nq-cards');
        keys.forEach(function (k) {
            var stat = h('div', 'nq-stat');
            stat.appendChild(h('div', 'nq-stat-val', fmtNum(row[k])));
            stat.appendChild(h('div', 'nq-stat-lbl', titleCase(k)));
            grid.appendChild(stat);
        });
        card.appendChild(grid);
    };

    Widget.prototype.renderBars = function (card, rows) {
        var cols = Object.keys(rows[0] || {});
        var labelCol = cols.find(function (c) { return !isNumericCol(rows, c); }) || cols[0];
        var valueCol = cols.find(function (c) { return c !== labelCol && isNumericCol(rows, c); });
        if (!valueCol) return this.renderTable(card, rows);

        var max = Math.max.apply(null, rows.map(function (r) { return Math.abs(Number(r[valueCol]) || 0); })) || 1;
        var wrap = h('div', 'nq-bars');
        rows.forEach(function (r) {
            var line = h('div', 'nq-bar-row');
            line.appendChild(h('div', 'nq-bar-lbl', String(r[labelCol] !== null && r[labelCol] !== undefined ? r[labelCol] : '- ')));
            var track = h('div', 'nq-bar-track');
            var fill = h('div', 'nq-bar-fill');
            fill.style.width = Math.max(2, Math.round(Math.abs(Number(r[valueCol]) || 0) / max * 100)) + '%';
            track.appendChild(fill);
            line.appendChild(track);
            line.appendChild(h('div', 'nq-bar-val', fmtNum(r[valueCol])));
            wrap.appendChild(line);
        });
        card.appendChild(wrap);
    };

    Widget.prototype.renderTable = function (card, rows) {
        var cols = Object.keys(rows[0] || {});
        var wrap = h('div', 'nq-table-wrap');
        var table = h('table', 'nq-table');
        var thead = h('thead'), tr = h('tr');
        cols.forEach(function (c) {
            var th = h('th', isNumericCol(rows, c) ? 'nq-num' : null, titleCase(c));
            tr.appendChild(th);
        });
        thead.appendChild(tr);
        table.appendChild(thead);
        var tbody = h('tbody');
        rows.forEach(function (r) {
            var trb = h('tr');
            cols.forEach(function (c) {
                var numeric = isNumericCol(rows, c);
                trb.appendChild(h('td', numeric ? 'nq-num' : null, numeric ? fmtNum(r[c]) : String(r[c] === null || r[c] === undefined ? '- ' : r[c])));
            });
            tbody.appendChild(trb);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        card.appendChild(wrap);
    };

    /** One block per step, so a combined answer can be traced back. */
    Widget.prototype.renderSteps = function (card, steps) {
        var wrap = h('div', 'nq-steps');

        steps.forEach(function (step) {
            var failed = step.status !== 'success';
            var el = h('div', 'nq-step' + (failed ? ' nq-step-failed' : ''));

            var q = h('div', 'nq-step-q');
            var n = h('b', null, 'Step ' + step.n + ': ');
            q.appendChild(n);
            q.appendChild(document.createTextNode(step.question || ''));

            // The period the step resolved to. "Last year" can mean calendar
            // 2025 or a trailing twelve months, and the two differ by millions
            // with nothing in the number to say which was used.
            if (step.period) {
                q.appendChild(h('span', 'nq-step-period', step.period));
            }

            el.appendChild(q);

            el.appendChild(h('div', 'nq-step-a', step.answer || (failed ? 'Could not be answered.' : '')));
            wrap.appendChild(el);
        });

        card.appendChild(wrap);
    };

    /** Follow-up questions, as buttons that ask them. */
    Widget.prototype.renderNextSteps = function (card, nextSteps) {
        if (!nextSteps.length) return;

        var self = this;
        var wrap = h('div', 'nq-next');
        wrap.appendChild(h('div', 'nq-next-label', 'Ask next'));

        var opts = h('div', 'nq-options');

        nextSteps.forEach(function (step) {
            if (!step || !step.query) return;
            var b = h('button', 'nq-btn nq-btn-ghost', step.label || step.query);
            b.type = 'button';
            b.title = step.query;
            b.addEventListener('click', function () {
                self.input.value = step.query;
                self.submit();
            });
            opts.appendChild(b);
        });

        if (!opts.children.length) return;

        wrap.appendChild(opts);
        card.appendChild(wrap);
    };

    Widget.prototype.renderClarification = function (data) {
        this.clearResult();
        var self = this;
        var card = h('div', 'nq-card nq-bot nq-clarify');
        card.appendChild(h('div', 'nq-clarify-msg', data.message || 'Please clarify your question.'));
        var optsWrap = h('div', 'nq-options');

        (data.alternatives || []).forEach(function (alt) {
            var b = h('button', 'nq-btn nq-btn-ghost', alt.dataset_name || alt.dataset_key);
            b.type = 'button';
            b.addEventListener('click', function () { self.submit(alt.dataset_key); });
            optsWrap.appendChild(b);
        });
        (data.available_metrics || []).forEach(function (m) {
            var label = typeof m === 'string' ? m : (m.name || m.key);
            var b = h('button', 'nq-btn nq-btn-ghost', titleCase(label));
            b.type = 'button';
            if (typeof m === 'object' && m.description) b.title = m.description;
            b.addEventListener('click', function () {
                var current = self.input.value.trim();
                // Clicking twice must not build "best? revenue revenue".
                if (current.toLowerCase().indexOf(label.toLowerCase()) === -1) {
                    self.input.value = current + ' ' + label;
                }
                self.submit();
            });
            optsWrap.appendChild(b);
        });

        if (optsWrap.children.length) card.appendChild(optsWrap);
        this.currentSlot.appendChild(card);
        this.scrollToBottom();
    };

    // -------------------------------------------------------------- tts

    Widget.prototype.speak = function (text) {
        if (!text || !global.speechSynthesis) return;
        this.stopSpeaking();
        var self = this;
        var u = new SpeechSynthesisUtterance(text);
        u.lang = this.opts.language;
        u.rate = 0.95;
        u.onstart = function () { self.setSpeaking(true); };
        u.onend = function () { self.setSpeaking(false); };
        u.onerror = function () { self.setSpeaking(false); };
        global.speechSynthesis.speak(u);
    };

    Widget.prototype.stopSpeaking = function () {
        if (global.speechSynthesis) global.speechSynthesis.cancel();
        this.setSpeaking(false);
    };

    Widget.prototype.setSpeaking = function (on) {
        this.speaking = on;
        if (this.speakBtn) this.speakBtn.classList.toggle('nq-speaking', on);
    };

    // -------------------------------------------------------------- svg icons

    function micSvg() {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>';
    }

    function speakerSvg() {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>';
    }

    // -------------------------------------------------------------- public API

    global.JeevesWidget = {
        mount: function (selector, options) {
            var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
            if (!el) throw new Error('JeevesWidget: mount target not found: ' + selector);
            return new Widget(el, options);
        }
    };
})(window);
