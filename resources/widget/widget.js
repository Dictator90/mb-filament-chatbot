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

        /*
         * The script's own query string is carried across to the stylesheet.
         *
         * Whatever a site puts after `widget.js` — a release number, a build
         * hash — is there to make the browser fetch a new copy, and the two
         * files are one release. Left off, the script arrives new and the
         * stylesheet stays cached for as long as its max-age says, which is the
         * one combination that breaks the widget rather than merely dating it:
         * new markup styled by old rules.
         */
        var version = script.src.indexOf('?') === -1 ? '' : script.src.slice(script.src.indexOf('?'));
        var link = document.createElement('link');

        link.rel = 'stylesheet';
        link.href = href + version;
        link.setAttribute('data-chatbot-styles', '');

        (document.head || document.documentElement).appendChild(link);
    })();

    var settings = {
        /*
         * `data-widget` is the key an embed carries today; `data-bot` is what
         * embeds written before widgets existed carry, and those live on pages
         * this application does not control. Both are sent, and the server
         * prefers the first — dropping the second would take the chat off every
         * site already running one, with nothing on that site to explain it.
         */
        widget: data.widget || '',
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
        emoji: 'Emoji',
        attach: 'Attach a file',
        remove: 'Remove',
        receipt_sent: 'Sent',
        receipt_delivered: 'Delivered',
        receipt_read: 'Read',
    };

    /*
     * The emoji on offer, grouped by what they are for.
     *
     * Written out here rather than fetched, and short rather than complete. This
     * file carries no dependencies by design — it lands next to whatever
     * framework the site already loads — so an emoji library is not on the
     * table, and a full palette without one is a searchable, paginated,
     * skin-toned thing that is a product of its own. Thirty characters cover
     * what somebody reaches for mid-sentence, and everything else is already on
     * the keyboard of every phone that will open this widget.
     *
     * The operator's desk keeps its own copy for the same reason in reverse: it
     * is PHP and cannot read this file, and an endpoint fetched on every page
     * load to deliver thirty characters that have never changed would be a round
     * trip spent on nothing.
     */
    var EMOJI = [
        ['🙂', '😀', '😅', '😉', '😍', '🤔', '😐', '😢', '😮', '😴'],
        ['👍', '👎', '👌', '🙏', '👏', '🤝', '💪', '❤️', '🔥', '✨'],
        ['✅', '❌', '⚠️', '📎', '📅', '⏰', '💬', '📞', '🎉', '🎁'],
    ];

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
        /*
         * What this placement lets the visitor do, and the defaults are the ones
         * the server ships. Attachments start off, so the paper clip is absent
         * until a widget that accepts files says so — a button drawn first and
         * withdrawn a moment later is a button somebody will have clicked.
         */
        features: {
            emoji: true,
            attachments: false,
            /*
             * Ticks start on, unlike the paper clip. They say nothing except how
             * far the visitor's own message has got, which is the one thing in
             * this application they are entitled to know all of — and a widget
             * that drew them and then had them withdrawn a moment later would
             * have shown a state it then took away.
             */
            receipts: true,
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

        if (sent && sent.features) {
            Object.keys(theme.features).forEach(function (name) {
                if (Object.prototype.hasOwnProperty.call(sent.features, name)) {
                    theme.features[name] = Boolean(sent.features[name]);
                }
            });
        }

        if (!el.root) {
            return;
        }

        applyFeatures();

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

    /**
     * Shows or hides the two composer buttons the widget's owner controls.
     *
     * Hiding a button is decoration and nothing more: the endpoint refuses a
     * file from a widget that does not take them regardless of what is on
     * screen, because this script runs on a page the visitor can edit and
     * anything decided here is decided by them. What this saves is a visitor
     * choosing a file and being told no.
     */
    function applyFeatures() {
        if (!el.root) {
            return;
        }

        el.emoji.hidden = !theme.features.emoji;
        el.attach.hidden = !theme.features.attachments;

        el.log.querySelectorAll('.' + settings.prefix + '__receipt').forEach(function (mark) {
            mark.hidden = !theme.features.receipts;
        });

        if (!theme.features.emoji) {
            el.emojiPad.hidden = true;
        }

        if (!theme.features.attachments) {
            clearAttachment();
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
        el.emoji.setAttribute('aria-label', labels.emoji);
        el.emoji.title = labels.emoji;
        el.attach.setAttribute('aria-label', labels.attach);
        el.attach.title = labels.attach;
        el.attachRemove.setAttribute('aria-label', labels.remove);
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
        file: null,
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

    /**
     * Every call to the API, with the token on it and nothing else.
     *
     * `form` rather than `body` when a file is going: the browser has to set the
     * multipart Content-Type itself, boundary and all, so this leaves the header
     * off entirely for that case. Setting it by hand produces a request the
     * server cannot parse and an error that names neither this line nor the file.
     *
     * A refusal is rejected with the status *and* the body, because the one
     * thing the server can say that the widget cannot work out for itself is why
     * a file was not accepted. Rejecting with a bare number, as this used to,
     * left every failure looking like "that did not send".
     */
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
            body: request.form || (request.body ? JSON.stringify(request.body) : undefined),
            credentials: 'omit',
            mode: 'cors',
        }).then(function (response) {
            if (!response.ok) {
                return response.json()
                    .catch(function () {
                        return {};
                    })
                    .then(function (payload) {
                        return Promise.reject({ status: response.status, payload: payload });
                    });
            }

            return response.json();
        });
    }

    /**
     * Fetches an attached file as a blob.
     *
     * A blob and not an `<img src>` pointing at the endpoint, and that is forced
     * rather than preferred. The visitor's token travels in a header; a browser
     * sends no custom header on an `<img>`, a `<video>` or a plain link, so the
     * request would arrive with nothing identifying the conversation and the
     * server would rightly refuse it. Fetched, the header goes with it, and what
     * comes back is turned into a local address the page can point at.
     */
    function apiBlob(url) {
        var headers = {};

        if (state.token) {
            headers['X-Chatbot-Token'] = state.token;
        }

        return fetch(url, { headers: headers, credentials: 'omit', mode: 'cors' })
            .then(function (response) {
                if (!response.ok) {
                    return Promise.reject(response.status);
                }

                return response.blob();
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

        el.notice = element('div', 'notice');
        el.notice.setAttribute('role', 'status');
        el.notice.hidden = true;

        /*
         * The chip that names the file waiting to go. Without it the only sign
         * that anything is attached is a file dialog the visitor closed a moment
         * ago, and the wrong file leaves looking exactly like no file at all.
         */
        el.attached = element('div', 'attached');
        el.attached.hidden = true;
        el.attachedName = element('span', 'attached-name');
        el.attachRemove = element('button', 'attached-remove', '×');
        el.attachRemove.type = 'button';
        el.attachRemove.setAttribute('aria-label', labels.remove);
        el.attachRemove.addEventListener('click', clearAttachment);
        el.attached.appendChild(el.attachedName);
        el.attached.appendChild(el.attachRemove);

        el.emojiPad = buildEmojiPad();

        var form = element('form', 'composer');

        el.emoji = element('button', 'emoji', '🙂');
        el.emoji.type = 'button';
        el.emoji.setAttribute('aria-label', labels.emoji);
        el.emoji.title = labels.emoji;
        el.emoji.addEventListener('click', function () {
            el.emojiPad.hidden = !el.emojiPad.hidden;
        });

        /*
         * A label wrapping a hidden input rather than a styled file control. The
         * browser's own cannot be made to match the buttons beside it on every
         * engine this runs on, and one that looked foreign would read as part of
         * the host page rather than part of the chat.
         */
        el.attach = element('label', 'attach', '📎');
        el.attach.title = labels.attach;
        el.attach.setAttribute('aria-label', labels.attach);
        el.file = document.createElement('input');
        el.file.type = 'file';
        el.file.addEventListener('change', function () {
            showAttachment(el.file.files && el.file.files[0]);
        });
        el.attach.appendChild(el.file);

        el.input = element('input', 'input');
        el.input.type = 'text';
        el.input.placeholder = label('placeholder');
        el.input.autocomplete = 'off';

        el.send = element('button', 'send', labels.send);
        el.send.type = 'submit';

        form.appendChild(el.emoji);
        form.appendChild(el.attach);
        form.appendChild(el.input);
        form.appendChild(el.send);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submit(el.input.value);
        });

        el.panel.appendChild(header);
        el.panel.appendChild(el.log);
        el.panel.appendChild(el.choices);
        el.panel.appendChild(el.notice);
        el.panel.appendChild(el.attached);
        el.panel.appendChild(el.emojiPad);
        el.panel.appendChild(form);

        el.root.appendChild(el.panel);
        el.root.appendChild(el.launcher);
        document.body.appendChild(el.root);
    }

    /**
     * The emoji picker: three rows, no state of its own.
     *
     * Each character is inserted where the cursor is and the cursor is put back
     * after it, rather than appended. Appending sounds equivalent and is not:
     * somebody who has gone back to fix a word mid-sentence would watch the
     * smiley land at the far end of the line, and the next one land after that.
     */
    function buildEmojiPad() {
        var pad = element('div', 'emoji-pad');

        pad.hidden = true;

        EMOJI.forEach(function (group) {
            var row = element('div', 'emoji-row');

            group.forEach(function (character) {
                var pick = element('button', 'emoji-pick', character);

                pick.type = 'button';
                pick.addEventListener('click', function () {
                    insertEmoji(character);
                });

                row.appendChild(pick);
            });

            pad.appendChild(row);
        });

        return pad;
    }

    function insertEmoji(character) {
        var at = el.input.selectionStart;
        var to = el.input.selectionEnd;
        var value = el.input.value;

        if (typeof at !== 'number') {
            at = value.length;
            to = value.length;
        }

        el.input.value = value.slice(0, at) + character + value.slice(to);
        el.emojiPad.hidden = true;
        el.input.focus();
        el.input.selectionStart = at + character.length;
        el.input.selectionEnd = at + character.length;
    }

    /**
     * Remembers the picked file and names it on screen.
     *
     * Nothing is uploaded here. The file goes with the message, so a visitor who
     * picks one and then closes the tab has left nothing behind on the server —
     * which is the whole reason there is no upload endpoint of its own.
     */
    function showAttachment(file) {
        state.file = file || null;
        el.attached.hidden = state.file === null;
        el.attachedName.textContent = state.file ? state.file.name : '';
        hideNotice();
    }

    function clearAttachment() {
        el.file.value = '';
        showAttachment(null);
    }

    /**
     * A line under the transcript saying why something was refused.
     *
     * The server's own sentence, in the application's language, and not the
     * widget's generic "that did not send". A file rejected for being too large
     * and one rejected for being the wrong kind need different answers, and
     * neither of them is "try again".
     */
    function notice(text) {
        el.notice.textContent = text;
        el.notice.hidden = !text;
    }

    function hideNotice() {
        notice('');
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

                applyReceipts(payload.read_through);

                if (payload.messages && payload.messages.length && !state.open) {
                    el.launcher.setAttribute('data-unread', 'yes');
                }

                /*
                 * Something arrived while the panel was open, which means the
                 * visitor is looking at it. Reported here as well as on opening,
                 * or the desk would keep showing a thread as unanswered-and-
                 * unseen through a whole conversation somebody read live.
                 */
                if (payload.messages && payload.messages.length && state.open) {
                    markRead();
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

        var bubble = element('div', 'bubble', message.body);

        (message.attachments || []).forEach(function (file) {
            bubble.appendChild(attachment(file));
        });

        /*
         * The mark goes on the visitor's own messages and on nothing else. What
         * the bot or an operator said has no stage worth showing the person
         * reading it — they are reading it, which is the end of the ladder.
         *
         * The moment it was written is kept on the row rather than only used
         * here, because the stage is recomputed on every poll from one watermark
         * and the comparison needs the message's own time back.
         */
        if (from === 'visitor') {
            row.setAttribute('data-at', message.at || '');
            bubble.appendChild(receipt(tracked ? 'delivered' : 'sent'));
        }

        row.appendChild(bubble);

        if (!tracked) {
            row.setAttribute('data-local', 'yes');
        }

        el.log.appendChild(row);
        markRuns();
        el.log.scrollTop = el.log.scrollHeight;
    }

    /**
     * The tick beside one of the visitor's own messages.
     *
     * `role="img"` with a name on it. A tick is a convention for people looking
     * at the screen and silence for everybody else: left as a bare character it
     * is read out as punctuation, or skipped, and the whole state disappears for
     * the reader who cannot see it.
     *
     * Hidden rather than absent when the placement has receipts switched off, so
     * that turning them on repaints what is already on screen instead of only
     * the messages sent afterwards.
     */
    function receipt(stage) {
        var mark = element('span', 'receipt', stage === 'sent' ? '✓' : '✓✓');

        mark.setAttribute('data-stage', stage);
        mark.setAttribute('role', 'img');
        mark.setAttribute('aria-label', labels['receipt_' + stage]);
        mark.hidden = !theme.features.receipts;

        return mark;
    }

    /**
     * Raises the visitor's own messages to "read" as far as the desk has got.
     *
     * Driven by one watermark rather than by a stage per message, because the
     * poll only ever asks for what is newer than the last id the widget holds:
     * a message already on screen is never sent again, so a per-message stage
     * would be frozen at whatever it was when it arrived.
     *
     * **Nothing here ever lowers a stage.** A message that has been read stays
     * read: a later poll that answered with no watermark — a truncated payload,
     * a response from before the operator opened the thread — would otherwise
     * pull the ticks back in front of the visitor, and a state that goes
     * backwards reads as the message having been un-received.
     */
    function applyReceipts(readThrough) {
        var boundary = readThrough ? Date.parse(readThrough) : NaN;

        el.log.querySelectorAll('[data-from="visitor"][data-at]').forEach(function (row) {
            var mark = row.querySelector('.' + settings.prefix + '__receipt');

            if (!mark || mark.getAttribute('data-stage') === 'read') {
                return;
            }

            var written = Date.parse(row.getAttribute('data-at'));

            if (isNaN(boundary) || isNaN(written) || written > boundary) {
                return;
            }

            mark.setAttribute('data-stage', 'read');
            mark.setAttribute('aria-label', labels.receipt_read);
        });
    }

    /**
     * One attached file inside a bubble: a picture, or its name.
     *
     * Both are fetched rather than pointed at. The endpoint decides who may read
     * a file by the token in a header, and a browser sends no custom header on
     * an `<img src>` or on a plain link — so an address written straight into
     * the markup would arrive at the server anonymous and be refused, showing a
     * broken image to the very person the file belongs to.
     *
     * The name is written with `textContent`, so a file called `<script>` is a
     * file called `<script>` and not a script. It is the one part of an
     * attachment the uploader chose.
     */
    function attachment(file) {
        var name = String(file.name || '');

        if (file.image) {
            var picture = element('img', 'thumb');

            picture.alt = name;
            apiBlob(file.url)
                .then(function (blob) {
                    picture.src = URL.createObjectURL(blob);
                })
                .catch(function () {});

            return picture;
        }

        var link = element('button', 'file', '📎 ' + name);

        link.type = 'button';
        link.addEventListener('click', function () {
            apiBlob(file.url)
                .then(function (blob) {
                    /*
                     * Saved through a link the widget makes and clicks itself.
                     * The bytes are already in the page by then, so this is the
                     * only way to hand them to the visitor without a second
                     * request that would arrive without the token.
                     */
                    var url = URL.createObjectURL(blob);
                    var anchor = document.createElement('a');

                    anchor.href = url;
                    anchor.download = name;
                    anchor.style.display = 'none';
                    document.body.appendChild(anchor);
                    anchor.click();
                    anchor.remove();
                    window.setTimeout(function () {
                        URL.revokeObjectURL(url);
                    }, 10000);
                })
                .catch(function () {
                    notice(labels.failed);
                });
        });

        return link;
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

    /**
     * Sends what is in the box, and the file beside it if there is one.
     *
     * A file with nothing typed is a message: a photograph of a broken parcel
     * says what it needs to. The server files it under the name it arrived
     * with, which is why the echo below shows that name rather than an empty
     * bubble.
     *
     * Multipart only when there is a file. JSON is what every other call here
     * sends and what the endpoint has always taken; switching the whole widget
     * to form data for the sake of the rare message that carries one would
     * change the shape of every request a host has ever logged.
     */
    function submit(text) {
        var body = (text || '').trim();
        var file = state.file;

        if ((!body && !file) || state.sending) {
            return;
        }

        state.sending = true;
        el.input.value = '';
        el.emojiPad.hidden = true;
        state.choices = [];
        renderChoices();
        hideNotice();

        append({ id: null, body: body || (file ? file.name : ''), incoming: true });

        var request = { method: 'POST' };

        if (file) {
            var form = new FormData();

            form.append('widget', settings.widget);
            form.append('bot', settings.bot);
            form.append('body', body);
            form.append('page', window.location.href);
            form.append('attachment', file);

            request.form = form;
        } else {
            request.body = {
                widget: settings.widget,
                bot: settings.bot,
                body: body,
                page: window.location.href,
                identity: settings.identity.signature ? settings.identity : null,
            };
        }

        clearAttachment();

        api('messages', request)
            .then(function (payload) {
                if (payload.token) {
                    state.token = payload.token;
                    writeToken(payload.token);
                }
            })
            .catch(function (failure) {
                dropEchoes();
                notice(refusal(failure) || labels.failed);
            })
            .then(function () {
                state.sending = false;
                window.clearTimeout(state.timer);
                poll();
            });
    }

    /**
     * The server's own reason, when it gave one.
     *
     * Only for a 422: that is the status the endpoint uses to say "this
     * particular thing about your message is wrong", and it is the only one
     * whose body is worth reading out. A 403, a 500 or a dropped connection all
     * mean the same thing to a visitor — it did not go — and quoting a framework
     * error at somebody on a marketing page helps nobody.
     */
    function refusal(failure) {
        if (!failure || failure.status !== 422 || !failure.payload) {
            return '';
        }

        var errors = failure.payload.errors || {};
        var first = errors.attachment || errors.body;

        if (first && first.length) {
            return first[0];
        }

        return failure.payload.message || '';
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
        api('theme?widget=' + encodeURIComponent(settings.widget) + '&bot=' + encodeURIComponent(settings.bot))
            .then(function (sent) {
                applyTheme(sent);
                applyLabels(sent && sent.labels);
            })
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
