{{--
    Jeeves drop-in widget.

    Usage:
        <x-jeeves::widget />
        <x-jeeves::widget title="Ask about sales" dataset="orders" :examples="['Top 10 customers by revenue']" />

    All attributes are optional -  defaults come from config('jeeves.widget').
    The widget JS is served by the package route (no publishing needed); to serve a
    published copy instead, run: php artisan vendor:publish --tag=jeeves-assets
--}}
@php
    $widgetConfig = array_filter([
        'baseUrl'     => rtrim(url(config('jeeves.routes.prefix', 'jeeves')), '/'),
        'title'       => $title ?? config('jeeves.widget.title', 'Ask your data'),
        'placeholder' => $placeholder ?? config('jeeves.widget.placeholder'),
        // null lets the widget follow <html lang> and then the browser, rather
        // than imposing one project's locale on everyone's users.
        'language'    => $language ?? config('jeeves.widget.language'),
        // Taken from the SERVER's setting so the rows the widget formats and
        // the totals the server formats group their digits the same way.
        'numberFormat' => config('jeeves.response.number_format', 'international'),
        'voice'       => $voice ?? config('jeeves.widget.voice', true),
        'tts'         => $tts ?? config('jeeves.widget.tts', true),
        'autoSpeak'   => config('jeeves.widget.auto_speak', false),
        'conversation'=> $conversation ?? config('jeeves.widget.conversation', true),
        'examples'    => $examples ?? config('jeeves.widget.examples', []),
        'themeColor'  => $themeColor ?? config('jeeves.widget.theme_color', '#2563eb'),
        'footerNote'  => config('jeeves.widget.footer_note', 'AI-generated · please verify important figures'),
        'dataset'      => $dataset ?? config('jeeves.default_dataset'),
        // Chat frame height. "auto" means grow with the content -  a string
        // rather than null on purpose, because null cannot survive the trip:
        // both `??` here and Blade's own @props treat an explicitly-passed
        // null as "not given" and quietly reinstate the default, so
        // :height="null" would look like it worked and do nothing.
        'height'      => $height ?? config('jeeves.widget.height', '520px'),
    ], fn ($v) => $v !== null);

    $widgetId = 'nq-widget-' . \Illuminate\Support\Str::random(6);
@endphp

<div id="{{ $widgetId }}"></div>

<script src="{{ rtrim(url(config('jeeves.routes.prefix', 'jeeves')), '/') }}/widget.js" defer></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        JeevesWidget.mount('#{{ $widgetId }}', Object.assign(
            @json($widgetConfig),
            { csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || @json(csrf_token()) }
        ));
    });
</script>
