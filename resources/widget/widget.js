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
        greeting: data.greeting || '',
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

    /*
     * The widget's own wording, in three layers, and the order matters.
     *
     * `data-title`, `data-placeholder` and `data-launcher` on the script tag
     * win over everything else. They are documented, sites already rely on
     * them, and a server label that overrode them would silently rename a
     * widget somebody had deliberately named.
     *
     * Under them come the labels the API sends with every transcript, in the
     * application's own language. They cannot be compiled into this file: it is
     * served as one static, byte-identical asset behind a shared cache, so a
     * language baked in here would be handed to whoever asked for it second.
     *
     * The English below is only what is drawn between the page loading and the
     * first poll answering, and only where the site named nothing. Dropping it
     * would mean a launcher with no text on it in that moment, which reads as a
     * broken widget rather than as one still loading.
     */
    var labels = {
        title: 'Chat',
        launcher: 'Chat with us',
        placeholder: 'Write a message',
        send: 'Send',
        close: 'Close',
        failed: 'That did not send. Try again in a moment.',
    };

    var pinned = {
        title: data.title || null,
        launcher: data.launcher || null,
        placeholder: data.placeholder || null,
    };

    /**
     * How the widget looks, until the server says otherwise.
     *
     * The defaults are the same values `widget.css` already declares. Kept here
     * as well so the widget can draw itself before the theme request answers —
     * and so that a request which never answers leaves a working widget in the
     * package's own colours rather than an unstyled one.
     */
    var theme = {
        properties: {},
        flags: {
            bubbles: 'rounded',
            launcher: 'pill',
            position: null,
            avatar: null,
            avatar_label: '',
            avatar_show: false,
        },
    };

    /**
     * Applies a theme to the element the widget is drawn into.
     *
     * `data-*` on the script tag still wins over anything stored, and that
     * direction is deliberate: the tag is written by whoever maintains the page
     * the widget sits on, and a colour chosen in the panel must not silently
     * repaint a widget that a site owner has already matched to their own
     * design. It also means every embed written before themes existed keeps
     * looking exactly as it does today.
     */
    function applyTheme(sent) {
        if (sent && sent.properties) {
            theme.properties = sent.properties;
        }

        if (sent && sent.flags) {
            Object.keys(theme.flags).forEach(function (name) {
                if (Object.prototype.hasOwnProperty.call(sent.flags, name)) {
                    theme.flags[name] = sent.flags[name];
                }
            });

            theme.flags.avatar_show = Boolean(theme.flags.avatar || theme.flags.avatar_label);
        }

        if (!el.root) {
            return;
        }

        Object.keys(theme.properties).forEach(function (name) {
            el.root.style.setProperty(name, theme.properties[name]);
        });

        /*
         * Re-applied after the stored properties so an accent given on the
         * script tag stays the one on screen.
         */
        if (data.color) {
            el.root.style.setProperty('--' + settings.prefix + '-accent', data.color);
        }

        el.root.setAttribute('data-bubbles', theme.flags.bubbles);
        el.root.setAttribute('data-launcher', theme.flags.launcher);

        if (!data.position && theme.flags.position) {
            el.root.setAttribute('data-position', theme.flags.position);
        }
    }

    function label(name) {
        return pinned[name] || labels[name];
    }

    /**
     * Takes the labels the server sent and redraws whatever they changed.
     *
     * Written back to the elements rather than only into `labels`, because the
     * chrome is already on the page by the time the first poll answers: a value
     * kept in memory and never applied would be right in the variable and
     * English on screen.
     */
    function applyLabels(sent) {
        if (!sent) {
            return;
        }

        Object.keys(labels).forEach(function (name) {
            if (typeof sent[name] === 'string' && sent[name] !== '') {
                labels[name] = sent[name];
            }
        });

        if (!el.root) {
            return;
        }

        el.launcherLabel.textContent = label('launcher');
        el.panel.setAttribute('aria-label', label('title'));
        el.title.textContent = label('title');
        el.close.setAttribute('aria-label', labels.close);
        el.input.placeholder = label('placeholder');
        el.send.textContent = labels.send;
    }

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

        /*
         * The label sits in its own element so the round launcher can hide it
         * without removing it: the button still announces itself to a screen
         * reader, which sees no difference between a circle and a pill.
         */
        el.launcher = element('button', 'launcher');
        el.launcherLabel = element('span', 'launcher-label', label('launcher'));
        el.launcher.appendChild(el.launcherLabel);
        el.launcher.type = 'button';
        el.launcher.setAttribute('aria-expanded', 'false');
        el.launcher.addEventListener('click', toggle);

        el.panel = element('div', 'panel');
        el.panel.setAttribute('role', 'dialog');
        el.panel.setAttribute('aria-label', label('title'));
        el.panel.hidden = true;

        var header = element('div', 'header');
        el.title = element('span', 'title', label('title'));
        header.appendChild(el.title);

        el.close = element('button', 'close', '×');
        el.close.type = 'button';
        el.close.setAttribute('aria-label', labels.close);
        el.close.addEventListener('click', toggle);
        header.appendChild(el.close);

        el.log = element('div', 'log');
        el.log.setAttribute('role', 'log');
        el.log.setAttribute('aria-live', 'polite');

        el.choices = element('div', 'choices');

        var form = element('form', 'composer');
        el.input = element('input', 'input');
        el.input.type = 'text';
        el.input.placeholder = label('placeholder');
        el.input.autocomplete = 'off';

        el.send = element('button', 'send', labels.send);
        el.send.type = 'submit';

        form.appendChild(el.input);
        form.appendChild(el.send);
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

                applyLabels(payload.labels);

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

        var from = message.incoming ? 'visitor' : 'agent';
        var row = element('div', 'message');
        row.setAttribute('data-from', from);

        if (from === 'agent' && theme.flags.avatar_show) {
            row.appendChild(avatar());
        }

        row.appendChild(element('div', 'bubble', message.body));

        if (!tracked) {
            row.setAttribute('data-local', 'yes');
        }

        el.log.appendChild(row);
        markRuns();
        el.log.scrollTop = el.log.scrollHeight;
    }

    /**
     * The bot's face, or its initials when it has no picture.
     */
    function avatar() {
        var node = element('div', 'avatar', theme.flags.avatar ? '' : (theme.flags.avatar_label || ''));

        if (theme.flags.avatar) {
            node.style.backgroundImage = 'url("' + theme.flags.avatar + '")';
        }

        return node;
    }

    /**
     * Marks where each message sits in a run of consecutive ones from the same
     * speaker: `first`, `middle`, `last` or `only`.
     *
     * Written onto the element rather than left to CSS sibling selectors,
     * because messages arrive one at a time from a poll: a rule that styled a
     * bubble by what follows it would restyle the bubble already on screen the
     * moment the next one lands, and the visitor would watch a corner change
     * shape under a message they had finished reading.
     *
     * Recomputed for the whole log rather than patched at the end, since an echo
     * removed from the middle changes the run of everything around it.
     */
    function markRuns() {
        var rows = Array.prototype.slice.call(el.log.querySelectorAll('.' + settings.prefix + '__message'));

        rows.forEach(function (row, index) {
            var speaker = row.getAttribute('data-from');
            var previous = rows[index - 1];
            var next = rows[index + 1];

            /*
             * A system line never joins a run. It is nobody speaking, and
             * grouped with the bot's replies it would take a tail and read as
             * something the bot said.
             */
            if (speaker === 'system') {
                row.setAttribute('data-run', 'only');

                return;
            }

            var startsRun = !previous || previous.getAttribute('data-from') !== speaker;
            var endsRun = !next || next.getAttribute('data-from') !== speaker;

            row.setAttribute('data-run', startsRun && endsRun ? 'only' : (startsRun ? 'first' : (endsRun ? 'last' : 'middle')));

            /*
             * The face goes beside the last message of a run, level with it —
             * where iMessage puts it, and where it reads as "this group came
             * from them" rather than as a label on the first line only. The
             * earlier bubbles keep an invisible one so their text stays on the
             * same left edge as the one below it.
             */
            var face = row.querySelector('.' + settings.prefix + '__avatar');

            if (face) {
                face.setAttribute('data-blank', endsRun ? 'no' : 'yes');
            }
        });
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
                failed.appendChild(element('div', 'bubble', labels.failed));
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
        applyTheme(null);

        /*
         * The theme is asked for and then applied, rather than awaited before
         * anything is drawn. A widget that waited would be absent for as long as
         * the request takes — on a slow connection, long enough for a visitor to
         * decide the site has no chat — and if the request never answers it
         * would never appear at all. Drawn first, the worst case is a launcher
         * that settles into its colours a moment later.
         */
        api('theme?bot=' + encodeURIComponent(settings.bot))
            .then(applyTheme)
            .catch(function () {});

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
