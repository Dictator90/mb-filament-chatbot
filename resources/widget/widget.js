/*
 * The chat widget an external site embeds.
 *
 *   <script src="https://app.example.com/chatbot/widget.js" async data-bot="support"></script>
 *
 * Deliberately one file, vanilla, and unbundled. It runs on somebody else's
 * site, next to whatever framework they already load, and every dependency it
 * carried would be a version conflict waiting to happen. It also means the file
 * can be read by whoever is asked to approve putting it on their site — which
 * is a real conversation, and it goes better when the answer is "open it".
 *
 * Nothing here trusts the page it runs on. Identity comes from a signature the
 * host application issued; everything else is decoration.
 */
(function () {
    'use strict';

    var script = document.currentScript || (function () {
        var all = document.getElementsByTagName('script');

        return all[all.length - 1];
    })();

    if (!script || window.__chatbotWidgetLoaded) {
        return;
    }

    window.__chatbotWidgetLoaded = true;

    /*
     * The API lives wherever this script came from. Deriving it beats asking the
     * site to configure a URL: the two can disagree, and when they do the widget
     * loads perfectly and talks to nothing.
     */
    var base = script.src.replace(/\/chatbot\/widget\.js.*$/, '');
    var data = script.dataset || {};

    /*
     * The stylesheet is fetched by the widget, not by the embedding page.
     *
     * The embed is one script tag — that is the promise the README, the docs and
     * the panel's copy-paste box all make — so anything else the widget needs it
     * has to go and get. Left to the site, this is the tag somebody forgets, and
     * forgetting it does not look like a missing stylesheet: the launcher has no
     * position of its own, so the whole widget lands in the page flow at the very
     * bottom of the document, below the footer, as a few lines of stray text. It
     * reads as "the widget did not load" and sends whoever installed it looking
     * at origins and bot slugs instead.
     *
     * Carried unnoticed for the life of the package because the one page that
     * embedded the widget — the workbench demo — wrote the <link> by hand.
     *
     * The URL comes from this script's own src, for the same reason the API
     * address does: two settings that can disagree eventually do, and when they
     * do the widget loads perfectly and looks broken.
     */
    (function () {
        var href = base + '/chatbot/widget.css';

        if (document.querySelector('link[data-chatbot-styles], link[href^="' + href + '"]')) {
            return;
        }

        var link = document.createElement('link');

        link.rel = 'stylesheet';
        link.href = href;
        link.setAttribute('data-chatbot-styles', '');

        (document.head || document.documentElement).appendChild(link);
    })();

    var settings = {
        bot: data.bot || '',
        title: data.title || 'Chat',
        greeting: data.greeting || '',
        placeholder: data.placeholder || 'Write a message',
        launcher: data.launcher || 'Chat with us',
        color: data.color || '#4f46e5',
        position: data.position === 'left' ? 'left' : 'right',
        prefix: data.prefix || 'chatbot',
        identity: {
            id: data.authId || '',
            signature: data.authSig || '',
            name: data.authName || '',
            phone: data.authPhone || '',
            email: data.authEmail || '',
        },
    };

    var POLL_OPEN = 5000;
    var POLL_IDLE = 45000;

    var state = {
        token: null,
        open: false,
        sending: false,
        lastId: 0,
        timer: null,
        messages: [],
        choices: [],
    };

    var el = {};

    /*
     * The visitor token identifies a conversation across reloads. It lives in
     * localStorage rather than a cookie because the widget is on a third-party
     * page: a cookie set from this application would be a third-party cookie,
     * and browsers have been quietly dropping those for years.
     */
    function storageKey(name) {
        return settings.prefix + ':' + name;
    }

    function readToken() {
        try {
            return window.localStorage.getItem(storageKey('token'));
        } catch (error) {
            return null;
        }
    }

    function writeToken(token) {
        try {
            window.localStorage.setItem(storageKey('token'), token);
        } catch (error) {
            /* Private windows and blocked storage are ordinary, not exceptional. */
        }
    }

    function api(path, options) {
        var request = options || {};
        var headers = { Accept: 'application/json' };

        if (request.body) {
            headers['Content-Type'] = 'application/json';
        }

        if (state.token) {
            headers['X-Chatbot-Token'] = state.token;
        }

        return fetch(base + '/chatbot/site/' + path, {
            method: request.method || 'GET',
            headers: headers,
            body: request.body ? JSON.stringify(request.body) : undefined,
            credentials: 'omit',
            mode: 'cors',
        }).then(function (response) {
            if (!response.ok) {
                return Promise.reject(response.status);
            }

            return response.json();
        });
    }

    function element(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = settings.prefix + '__' + className;
        }

        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    }

    function build() {
        el.root = element('div', 'root');
        el.root.setAttribute('data-position', settings.position);
        el.root.style.setProperty('--' + settings.prefix + '-accent', settings.color);

        el.launcher = element('button', 'launcher', settings.launcher);
        el.launcher.type = 'button';
        el.launcher.setAttribute('aria-expanded', 'false');
        el.launcher.addEventListener('click', toggle);

        el.panel = element('div', 'panel');
        el.panel.setAttribute('role', 'dialog');
        el.panel.setAttribute('aria-label', settings.title);
        el.panel.hidden = true;

        var header = element('div', 'header');
        header.appendChild(element('span', 'title', settings.title));

        var close = element('button', 'close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', 'Close');
        close.addEventListener('click', toggle);
        header.appendChild(close);

        el.log = element('div', 'log');
        el.log.setAttribute('role', 'log');
        el.log.setAttribute('aria-live', 'polite');

        el.choices = element('div', 'choices');

        var form = element('form', 'composer');
        el.input = element('input', 'input');
        el.input.type = 'text';
        el.input.placeholder = settings.placeholder;
        el.input.autocomplete = 'off';

        var send = element('button', 'send', 'Send');
        send.type = 'submit';

        form.appendChild(el.input);
        form.appendChild(send);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submit(el.input.value);
        });

        el.panel.appendChild(header);
        el.panel.appendChild(el.log);
        el.panel.appendChild(el.choices);
        el.panel.appendChild(form);

        el.root.appendChild(el.panel);
        el.root.appendChild(el.launcher);
        document.body.appendChild(el.root);
    }

    function toggle() {
        state.open = !state.open;
        el.panel.hidden = !state.open;
        el.launcher.setAttribute('aria-expanded', state.open ? 'true' : 'false');

        if (state.open) {
            el.input.focus();
            markRead();
        }

        schedule();
    }

    /*
     * Polling, not a socket. A socket per visitor on somebody else's marketing
     * page is a connection held open for the ninety per cent who never type
     * anything, and the widget has no way to know which visitor is which until
     * they do.
     */
    function schedule() {
        window.clearTimeout(state.timer);
        state.timer = window.setTimeout(poll, state.open ? POLL_OPEN : POLL_IDLE);
    }

    function poll() {
        api('messages?after=' + state.lastId)
            .then(function (payload) {
                if (payload.token) {
                    state.token = payload.token;
                    writeToken(payload.token);
                }

                if ((payload.messages || []).length) {
                    dropEchoes();
                }

                (payload.messages || []).forEach(append);

                state.choices = payload.choices || [];
                renderChoices();

                if (payload.messages && payload.messages.length && !state.open) {
                    el.launcher.setAttribute('data-unread', 'yes');
                }
            })
            .catch(function () {
                /*
                 * A failed poll is not worth telling the visitor about. The
                 * network on a phone drops constantly, the next poll usually
                 * succeeds, and an error banner on somebody's marketing page is
                 * worse than a message arriving five seconds late.
                 */
            })
            .then(schedule);
    }

    /**
     * Adds a message to the log.
     *
     * Only a message that came from the server moves the high-water mark. The
     * greeting and the echo of what the visitor just typed are drawn locally so
     * the widget feels immediate, and giving them ids would put a made-up number
     * in the next poll's `after=` — which then asks the server for everything
     * newer than a message it has never heard of.
     */
    function append(message) {
        var tracked = typeof message.id === 'number' && message.id > 0;

        if (tracked) {
            if (message.id <= state.lastId) {
                return;
            }

            state.lastId = message.id;
        }

        var row = element('div', 'message');
        row.setAttribute('data-from', message.incoming ? 'visitor' : 'agent');
        row.appendChild(element('div', 'bubble', message.body));

        if (!tracked) {
            row.setAttribute('data-local', 'yes');
        }

        el.log.appendChild(row);
        el.log.scrollTop = el.log.scrollHeight;
    }

    /**
     * Removes the bubbles the widget drew before the server had them.
     *
     * The echo of what the visitor typed exists so the message appears the
     * instant they press send; the server then returns the same message in the
     * transcript, and without this the visitor sees themselves say it twice.
     *
     * Dropping the echo rather than trying to match it to the returned message
     * is deliberate: matching by text is wrong the moment somebody sends the
     * same word twice in a row, which people do constantly ("hello", "hello?").
     */
    function dropEchoes() {
        el.log.querySelectorAll('[data-local="yes"][data-from="visitor"]')
            .forEach(function (node) {
                node.remove();
            });
    }

    /*
     * Options are buttons here and a numbered list in channels that have none.
     * Either way the answer the visitor sends is the option's own label, so the
     * engine reads both the same way and neither channel needs its own branch.
     */
    function renderChoices() {
        el.choices.textContent = '';

        state.choices.forEach(function (choice) {
            var button = element('button', 'choice', choice.label);
            button.type = 'button';
            button.addEventListener('click', function () {
                submit(choice.label);
            });
            el.choices.appendChild(button);
        });
    }

    function submit(text) {
        var body = (text || '').trim();

        if (!body || state.sending) {
            return;
        }

        state.sending = true;
        el.input.value = '';
        state.choices = [];
        renderChoices();

        append({ id: null, body: body, incoming: true });

        api('messages', {
            method: 'POST',
            body: {
                bot: settings.bot,
                body: body,
                page: window.location.href,
                identity: settings.identity.signature ? settings.identity : null,
            },
        })
            .then(function (payload) {
                if (payload.token) {
                    state.token = payload.token;
                    writeToken(payload.token);
                }
            })
            .catch(function () {
                var failed = element('div', 'message');
                failed.setAttribute('data-from', 'system');
                failed.appendChild(element('div', 'bubble', 'That did not send. Try again in a moment.'));
                el.log.appendChild(failed);
            })
            .then(function () {
                state.sending = false;
                window.clearTimeout(state.timer);
                poll();
            });
    }

    function markRead() {
        if (state.token) {
            api('read', { method: 'POST', body: {} }).catch(function () {});
        }
    }

    function start() {
        state.token = readToken();
        build();

        if (settings.greeting) {
            append({ id: null, body: settings.greeting, incoming: false });
        }

        poll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
