{{--
    The page inside the preview frame.

    It is a stand-in for a customer's website and nothing more: a heading, a
    paragraph of grey lines, and the real `widget.js` on top of it. Every pixel
    of the chat itself is drawn by that script from the real stylesheet, which
    is the point — anything drawn here instead would be a second widget, and the
    two would disagree the week after either changed.

    The script's four calls are answered in the page rather than by the server.
    They cannot reach the real endpoints: those are guarded by the origin
    allow-list, they would open a genuine conversation on the desk for every
    person who opened this form, and — worst of the three — they would answer
    with the *saved* widget, so the preview would ignore the change being made.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('chatbot::panel.site_widgets.preview.title') }}</title>
    <style>
        :root { color-scheme: light; }

        body {
            margin: 0;
            padding: 28px 24px;
            font: 14px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: #334155;
            background: #ffffff;
        }

        .page-title {
            margin: 0 0 6px;
            font-size: 17px;
            font-weight: 600;
            color: #0f172a;
        }

        .page-lead {
            margin: 0 0 22px;
            color: #64748b;
        }

        .page-lines span {
            display: block;
            height: 10px;
            margin-bottom: 12px;
            border-radius: 5px;
            background: #f1f5f9;
        }

        .page-lines span:nth-child(2) { width: 86%; }
        .page-lines span:nth-child(3) { width: 64%; }
        .page-lines span:nth-child(4) { width: 78%; }

        {{--
            Filament marks dark with a class on <html> and marks light with
            nothing at all, and that class is on the panel document rather than
            on this one — so the frame copies it across (below) and answers to
            the same selector. Never `prefers-color-scheme`: it would overrule
            the theme somebody chose by hand and hand a dark frame to whoever
            picked light on a dark machine.
        --}}
        .dark body { color: #cbd5e1; background: #0f172a; }
        .dark .page-title { color: #f1f5f9; }
        .dark .page-lead { color: #94a3b8; }
        .dark .page-lines span { background: #1e293b; }
    </style>
</head>
<body>
    <h1 class="page-title">{{ __('chatbot::panel.site_widgets.preview.page_title') }}</h1>
    <p class="page-lead">{{ __('chatbot::panel.site_widgets.preview.page_lead') }}</p>
    <div class="page-lines"><span></span><span></span><span></span><span></span></div>

    <script>
        /*
         * Takes the panel's own light-or-dark decision rather than making one.
         * Filament has already resolved "system" by the time it stamps the
         * class, so reading the class is the only way to agree with it — and
         * the read is wrapped because a frame is not always in a document it is
         * allowed to look at.
         */
        try {
            if (window.parent !== window && window.parent.document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.add('dark');
            }
        } catch (error) {
            /* A frame on another origin cannot ask, and light is the safe answer. */
        }

        window.__chatbotPreview = @json($preview);

        /*
         * A stubbed server, installed before the widget loads.
         *
         * `fetch` is wrapped rather than the widget being told about a preview
         * mode, because a preview mode inside `widget.js` would be a branch that
         * only ever runs here — untested on the path that matters and one more
         * thing to keep in step. From the script's side nothing is different: it
         * asks for a theme, polls for messages, and posts what was typed.
         */
        (function () {
            var preview = window.__chatbotPreview;
            var real = window.fetch ? window.fetch.bind(window) : null;
            var log = preview.transcript.slice();
            var nextId = log.length;

            function answer(body) {
                return Promise.resolve(new Response(JSON.stringify(body), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }));
            }

            /*
             * `quoted` is the id the widget said it was answering, and the stub
             * looks it up in its own log rather than echoing back what it was
             * sent. That is the same shape the real endpoint has — the quoted
             * text always comes from the record, never from the request — so the
             * preview shows a quote of a message that exists and nothing else.
             */
            function say(body, incoming, quoted) {
                nextId += 1;

                var answered = log.filter(function (message) {
                    return message.id === quoted;
                })[0];

                log.push({
                    id: nextId,
                    body: String(body || ''),
                    incoming: incoming,
                    author: incoming ? 'visitor' : 'bot',
                    at: new Date().toISOString(),
                    reply: answered ? {
                        id: answered.id,
                        author: answered.author,
                        body: answered.body.slice(0, 140),
                    } : null,
                });
            }

            window.fetch = function (input, init) {
                var url = typeof input === 'string' ? input : ((input && input.url) || '');
                var request = init || {};

                if (url.indexOf('/chatbot/site/') === -1) {
                    return real ? real(input, init) : Promise.reject(new Error('no fetch'));
                }

                if (url.indexOf('/chatbot/site/theme') !== -1) {
                    return answer({
                        properties: preview.properties,
                        flags: preview.flags,
                        features: preview.features,
                        presence: preview.presence,
                        labels: preview.labels,
                    });
                }

                if (url.indexOf('/chatbot/site/messages') === -1) {
                    return answer({ ok: true });
                }

                if ((request.method || 'GET').toUpperCase() === 'POST') {
                    var sent = {};

                    try {
                        sent = JSON.parse(request.body || '{}');
                    } catch (error) {
                        sent = {};
                    }

                    say(sent.body, true, sent.reply_to);
                    say(preview.reply, false);

                    return answer({});
                }

                var after = parseInt((url.split('after=')[1] || '0'), 10) || 0;

                return answer({
                    conversation: 1,
                    status: 'open',
                    messages: log.filter(function (message) {
                        return message.id > after;
                    }),
                    choices: [],
                    labels: preview.labels,
                });
            };
        })();
    </script>

    <script src="{{ $scriptUrl }}" async
            data-widget="preview"
            @if ($greeting !== '') data-greeting="{{ $greeting }}" @endif></script>
</body>
</html>
