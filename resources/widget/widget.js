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
        expand: 'Open the picture',
        status_online: 'Online',
        status_away: 'We answer in the morning',
        author_you: 'You',
        author_bot: 'Assistant',
        author_operator: 'Support',
        teaser: 'Chat with us',
        reply: 'Reply',
        reply_cancel: 'Not this one',
        lead_intro: 'Leave your details and we will pick this up.',
        lead_name: 'Name',
        lead_phone: 'Phone',
        lead_email: 'E-mail',
        lead_submit: 'Start the chat',
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

    /*
     * The chrome's own icons, drawn rather than typed.
     *
     * They were emoji characters — 🙂 and 📎 — and an emoji is not an icon: it
     * is a coloured picture the operating system draws, so the same button was
     * a flat outline on one machine, a glossy yellow face on another and a
     * black-and-white lozenge on a third, none of them matching the widget's
     * own colours and none of them matching each other. It also could not take
     * the theme: an emoji ignores `color`, so a dark widget had two bright
     * stickers sitting in its composer.
     *
     * The paths are Heroicons' outline set, which is what the operator's desk
     * already uses for the same two buttons. Inlined rather than fetched: this
     * file is embedded on somebody else's page and may load no second asset,
     * and two icons of markup are cheaper than a request that could fail.
     */
    var ICONS = {
        emoji: [
            'M15.182 15.182a4.5 4.5 0 0 1-6.364 0M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
            'M9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75Z',
            'M14.625 9.75c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75Z',
        ],
        attach: [
            'm18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13',
        ],
        close: ['M6 18 18 6M6 6l12 12'],
        /*
         * The arrow on the round send button. Drawn rather than written,
         * because in the stacked composer the button is a circle the size of
         * the two icons beside it and the word "Send" does not fit in one —
         * the label stays on the button for a screen reader all the same.
         */
        send: ['M12 19.5v-15m0 0-6.75 6.75M12 4.5l6.75 6.75'],
    };

    var SVG_NS = 'http://www.w3.org/2000/svg';

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
            composer: 'row',
            position: null,
            avatar: null,
            avatar_label: '',
            avatar_show: false,
            /*
             * The three parts of the chrome a placement can switch on, and all
             * three start off — the same direction the server ships them in.
             * Drawn first and withdrawn when the theme answers, each of them
             * would be a claim already read: a status line that said "online"
             * for a moment, a name over a bubble, a sentence over the launcher
             * on a page whose owner switched it off.
             */
            status: false,
            authors: false,
            teaser: false,
        },
        /*
         * Whether an answer is coming now, for the line under the title. Asked
         * for again whenever the panel is opened, because that is the moment it
         * is read — see `refreshTheme`.
         */
        presence: 'online',
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
            /*
             * Every switch the server can send has to be named here, and not
             * only for its default: what arrives is merged key by key over this
             * object, so a switch the widget has never heard of is dropped in
             * silence. The setting is on in the panel, off on the page, and the
             * only sign is a button that is not there.
             */
            replies: false,
        },
        /*
         * The cookie names this placement was told it may read off the page it
         * sits on. Empty until the server says otherwise, and empty is the
         * honest default: reading a cookie nobody asked for is precisely what
         * an embedded script must never do on somebody else's site.
         */
        cookies: [],
        /*
         * Whether this placement asks who is writing before it lets them write.
         * Off until the server says otherwise, so a widget whose theme call has
         * not answered yet shows a chat box rather than a form that might turn
         * out not to exist.
         */
        lead: { enabled: false, fields: [], required: [] },
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

        if (sent && typeof sent.presence === 'string') {
            theme.presence = sent.presence === 'away' ? 'away' : 'online';
        }

        if (sent && sent.features) {
            Object.keys(theme.features).forEach(function (name) {
                if (Object.prototype.hasOwnProperty.call(sent.features, name)) {
                    theme.features[name] = Boolean(sent.features[name]);
                }
            });
        }

        /*
         * Replaced wholesale rather than merged, unlike the flags above. This is
         * a permission and not a preference: a name taken off the list in the
         * panel has to stop being read here on the next load, and a merge would
         * keep collecting it for as long as the page stayed open.
         */
        if (sent && Array.isArray(sent.cookies)) {
            theme.cookies = sent.cookies.filter(function (name) {
                return typeof name === 'string' && /^[A-Za-z0-9_.\-]{1,64}$/.test(name);
            });
        }

        if (sent && sent.lead && Array.isArray(sent.lead.fields)) {
            theme.lead = {
                enabled: Boolean(sent.lead.enabled) && sent.lead.fields.length > 0,
                fields: sent.lead.fields,
                required: Array.isArray(sent.lead.required) ? sent.lead.required : [],
            };
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
        el.root.setAttribute('data-composer', theme.flags.composer);
        el.root.setAttribute('data-authors', theme.flags.authors ? 'yes' : 'no');

        drawStatus();
        drawTeaser();
        repaintRows();

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

        /*
         * The quote buttons are hidden rather than removed, for the same reason
         * the ticks are: a placement that gains the setting has to repaint the
         * transcript already on screen and not only the messages that arrive
         * afterwards.
         */
        el.log.querySelectorAll('.' + settings.prefix + '__reply').forEach(function (button) {
            button.hidden = !theme.features.replies;
        });

        if (!theme.features.replies) {
            cancelQuoting();
        }

        if (!theme.features.emoji) {
            el.emojiPad.hidden = true;
        }

        if (!theme.features.attachments) {
            clearAttachment();
        }

        drawLead();
    }

    /**
     * Draws or removes the opening form, and hides the composer while it stands.
     *
     * **Only ever in front of an empty thread.** A visitor who has already
     * written — today or last week, from a token this browser still holds — has
     * a conversation open, and putting a form in front of it would stop them
     * answering the question the operator has just asked. The form is a way to
     * start a thread, not a gate on one.
     *
     * Rebuilt from scratch on each call rather than toggled, because the fields
     * come from the server and can change under a page that stays open: a
     * placement that stops asking for a phone number has to stop showing the
     * box, not leave a filled-in one that nothing reads.
     */
    function drawLead() {
        if (!el.lead) {
            return;
        }

        var asking = theme.lead.enabled && !state.lead && !state.lastId;

        el.lead.hidden = !asking;
        el.composer.hidden = asking;

        if (!asking) {
            return;
        }

        el.leadIntro.textContent = label('lead_intro');
        el.leadSubmit.textContent = label('lead_submit');

        while (el.leadFields.firstChild) {
            el.leadFields.removeChild(el.leadFields.firstChild);
        }

        theme.lead.fields.forEach(function (field) {
            var box = element('input', 'lead-input');

            box.type = field === 'email' ? 'email' : (field === 'phone' ? 'tel' : 'text');
            box.name = field;
            box.placeholder = label('lead_' + field);
            box.setAttribute('aria-label', label('lead_' + field));
            box.autocomplete = field === 'name' ? 'name' : field;
            box.required = theme.lead.required.indexOf(field) >= 0;

            el.leadFields.appendChild(box);
        });
    }

    /**
     * Takes what the opening form collected and gets out of the way.
     *
     * The browser enforces "required" on its own — the boxes carry the
     * attribute, so a submit with an empty one never reaches here. That is the
     * only enforcement there is on this side, and it is enough: what actually
     * matters about these values is decided on the server, which keeps the
     * fields this placement asked for and ignores everything else.
     *
     * Nothing is sent at this moment. A form submitted and then abandoned is a
     * name and a phone number with no conversation attached to them, which is a
     * record of somebody who never wrote — so the details ride along with the
     * first message instead, and if there is never one there is nothing.
     */
    function acceptLead() {
        var collected = {};

        el.leadFields.querySelectorAll('input').forEach(function (box) {
            if (box.value.trim() !== '') {
                collected[box.name] = box.value.trim();
            }
        });

        state.lead = collected;

        drawLead();

        if (el.input) {
            el.input.focus();
        }
    }

    function label(name) {
        return pinned[name] || labels[name];
    }

    /**
     * The wording of the status line for the state the server last reported.
     *
     * "Away" is a promise about when, never "nobody is here". A visitor who
     * reads "offline" closes the tab; one who reads "we answer in the morning"
     * writes anyway, and that message is the whole reason the line is drawn.
     */
    function statusText() {
        return theme.presence === 'away' ? labels.status_away : labels.status_online;
    }

    function drawStatus() {
        if (!el.status) {
            return;
        }

        el.status.hidden = !theme.flags.status;
        el.status.textContent = statusText();
        el.status.setAttribute('data-state', theme.presence);
    }

    /**
     * The sentence beside the launcher, while the panel is shut.
     *
     * Taken off screen the moment the chat is open, because at that point it is
     * a button offering to do what has already been done — sitting behind the
     * panel, on top of the page, saying "chat with us".
     */
    function drawTeaser() {
        if (!el.teaser) {
            return;
        }

        el.teaser.hidden = !theme.flags.teaser || state.open;
    }

    /**
     * Which of the three names goes over a bubble.
     *
     * The visitor's own line is "you" and not their name: they know who they
     * are, and a name collected by an opening form is a claim the widget should
     * not repeat back as fact. Everything the application says is "assistant"
     * or "support" — never the operator's real name, which is not ours to
     * publish on somebody else's site.
     */
    function authorName(author) {
        if (author === 'visitor') {
            return labels.author_you;
        }

        return author === 'operator' ? labels.author_operator : labels.author_bot;
    }

    /**
     * Remembers which message the next one answers, and says so over the
     * composer.
     *
     * The text is kept here rather than looked up when the message is sent: the
     * bubble it came from can be gone by then — an echo dropped, a transcript
     * trimmed — and a quote that lost its text between the click and the send
     * would arrive as a bare id.
     */
    function quoteMessage(message, author) {
        state.replyTo = {
            id: message.id,
            author: author,
            body: String(message.body || '').slice(0, 140),
        };

        drawQuoting();

        if (el.input) {
            el.input.focus();
        }
    }

    function cancelQuoting() {
        state.replyTo = null;
        drawQuoting();
    }

    function drawQuoting() {
        if (!el.quoting) {
            return;
        }

        var quoting = state.replyTo !== null && theme.features.replies;

        el.quoting.hidden = !quoting;
        el.quotingText.textContent = quoting
            ? authorName(state.replyTo.author) + ': ' + state.replyTo.body
            : '';
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
        el.sendLabel.textContent = labels.send;
        el.send.setAttribute('aria-label', labels.send);
        el.emoji.setAttribute('aria-label', labels.emoji);
        el.emoji.title = labels.emoji;
        el.attach.setAttribute('aria-label', labels.attach);
        el.attach.title = labels.attach;
        el.attachRemove.setAttribute('aria-label', labels.remove);
        el.teaser.textContent = labels.teaser;
        el.quotingCancel.setAttribute('aria-label', labels.reply_cancel);
        el.quotingCancel.title = labels.reply_cancel;

        /*
         * The quote buttons already drawn are renamed too. The transcript is on
         * screen before the first poll answers, so a label written only into the
         * variable would leave every bubble already there saying "Reply" in
         * English under a Russian title.
         */
        el.log.querySelectorAll('.' + settings.prefix + '__reply').forEach(function (button) {
            button.textContent = labels.reply;
        });

        drawStatus();
        drawTeaser();
        drawQuoting();
        repaintRows();
        drawLead();
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
        /*
         * The message the next one answers, or null. Cleared on send rather than
         * kept: a quote is chosen for one reply, and a chip that survived it
         * would attach the same quote to every message after it.
         */
        replyTo: null,
        /*
         * What the visitor typed into the opening form, or null while it is
         * still standing. Held for the session rather than stored: it is sent
         * with every message so that the thread keeps the details even if the
         * first one fails, and a browser that reloads and finds a conversation
         * already open never sees the form again anyway.
         */
        lead: null,
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

    /**
     * The declared cookies, read off the page the widget is embedded in.
     *
     * Only the names the server listed, and the loop is over that list rather
     * than over `document.cookie`: iterating what the page has and keeping what
     * looks interesting is how a support widget turns into something a customer
     * would be right to remove from their site.
     *
     * Nothing here is a secret, and nothing here proves anything. These are
     * values a browser held on a page anybody can set cookies on; the server
     * checks the names again when they arrive, and a scenario branching on one
     * is branching on a claim.
     */
    function pageCookies() {
        if (!theme.cookies.length || typeof document.cookie !== 'string') {
            return null;
        }

        var jar = {};
        var pairs = document.cookie.split(';');

        theme.cookies.forEach(function (name) {
            for (var at = 0; at < pairs.length; at++) {
                var pair = pairs[at];
                var split = pair.indexOf('=');

                if (split < 0) {
                    continue;
                }

                if (pair.slice(0, split).trim() !== name) {
                    continue;
                }

                try {
                    jar[name] = decodeURIComponent(pair.slice(split + 1).trim()).slice(0, 255);
                } catch (error) {
                    jar[name] = pair.slice(split + 1).trim().slice(0, 255);
                }

                return;
            }
        });

        return Object.keys(jar).length ? jar : null;
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

    /**
     * One of the chrome's icons, as an element.
     *
     * Built through the DOM rather than assigned as markup. `innerHTML` on an
     * element of somebody else's page is the habit this file cannot afford to
     * have anywhere — the same line would eventually be written with a value
     * that came from the server — so the shape that is safe everywhere is the
     * one used here too.
     */
    function icon(name) {
        var svg = document.createElementNS(SVG_NS, 'svg');

        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.5');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');

        (ICONS[name] || []).forEach(function (definition) {
            var path = document.createElementNS(SVG_NS, 'path');

            path.setAttribute('d', definition);
            svg.appendChild(path);
        });

        return svg;
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

        /*
         * The teaser is a sibling of the launcher rather than a part of it: it
         * is its own button, so a visitor who reads the sentence can press the
         * sentence. Nested inside the launcher it could not be, and a round
         * launcher with a caption glued to it stops being round.
         */
        el.teaser = element('button', 'teaser', labels.teaser);
        el.teaser.type = 'button';
        el.teaser.hidden = true;
        el.teaser.addEventListener('click', toggle);

        el.panel = element('div', 'panel');
        el.panel.setAttribute('role', 'dialog');
        el.panel.setAttribute('aria-label', label('title'));
        el.panel.hidden = true;

        var header = element('div', 'header');

        /*
         * The title and the status line are stacked in their own element rather
         * than laid straight into the header, which is a row with the close
         * button at its far end. Added as two children of that row, the status
         * line would sit *beside* the name and push the close button off the
         * edge of a narrow panel.
         */
        var heading = element('div', 'heading');

        el.title = element('span', 'title', label('title'));
        el.status = element('span', 'status', statusText());
        el.status.hidden = true;

        heading.appendChild(el.title);
        heading.appendChild(el.status);
        header.appendChild(heading);

        el.close = element('button', 'close');
        el.close.appendChild(icon('close'));
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
        el.attachRemove = element('button', 'attached-remove');
        el.attachRemove.appendChild(icon('close'));
        el.attachRemove.type = 'button';
        el.attachRemove.setAttribute('aria-label', labels.remove);
        el.attachRemove.addEventListener('click', clearAttachment);
        el.attached.appendChild(el.attachedName);
        el.attached.appendChild(el.attachRemove);

        /*
         * What is being quoted, over the composer.
         *
         * Over it rather than inside the box, because the box holds what the
         * visitor is typing and this is not that. It also has to be visible
         * while they type — a quote chosen and then forgotten is how somebody
         * answers the wrong message — and a chip inside the input would be
         * scrolled out of sight by the second line.
         */
        el.quoting = element('div', 'quoting');
        el.quoting.hidden = true;
        el.quotingText = element('span', 'quoting-text');
        el.quotingCancel = element('button', 'quoting-cancel');
        el.quotingCancel.appendChild(icon('close'));
        el.quotingCancel.type = 'button';
        el.quotingCancel.setAttribute('aria-label', labels.reply_cancel);
        el.quotingCancel.title = labels.reply_cancel;
        el.quotingCancel.addEventListener('click', cancelQuoting);
        el.quoting.appendChild(el.quotingText);
        el.quoting.appendChild(el.quotingCancel);

        el.emojiPad = buildEmojiPad();

        /*
         * The opening form, built whether or not this placement asks for one.
         *
         * The alternative is building it when the theme answers, which is one
         * more path through `build()` and the one that would go untested: the
         * widget draws itself before that call returns, so a form created later
         * has to be inserted into a panel that is already on screen and already
         * has a composer in it. Built hidden costs an empty div.
         */
        el.lead = element('form', 'lead');
        el.lead.hidden = true;
        el.leadIntro = element('p', 'lead-intro', label('lead_intro'));
        el.leadFields = element('div', 'lead-fields');
        el.leadSubmit = element('button', 'lead-submit', label('lead_submit'));
        el.leadSubmit.type = 'submit';
        el.lead.appendChild(el.leadIntro);
        el.lead.appendChild(el.leadFields);
        el.lead.appendChild(el.leadSubmit);
        el.lead.addEventListener('submit', function (event) {
            event.preventDefault();
            acceptLead();
        });

        var form = element('form', 'composer');
        el.composer = form;

        el.emoji = element('button', 'emoji');
        el.emoji.appendChild(icon('emoji'));
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
        el.attach = element('label', 'attach');
        el.attach.appendChild(icon('attach'));
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
        el.input.addEventListener('input', markComposer);

        /*
         * The word and the arrow are both on the button, and the stylesheet
         * shows one of them. The stacked composer draws a circle the size of
         * the icons beside it, where the word does not fit; dropped rather than
         * hidden, the button would be an unlabelled arrow to anybody who cannot
         * see that it points anywhere.
         */
        el.send = element('button', 'send');
        el.sendLabel = element('span', 'send-label', labels.send);
        el.send.appendChild(icon('send'));
        el.send.appendChild(el.sendLabel);
        el.send.type = 'submit';
        el.send.setAttribute('aria-label', labels.send);

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
        el.panel.appendChild(el.quoting);
        el.panel.appendChild(el.emojiPad);
        el.panel.appendChild(el.lead);
        el.panel.appendChild(form);

        el.root.appendChild(el.panel);
        el.root.appendChild(el.teaser);
        el.root.appendChild(el.launcher);
        document.body.appendChild(el.root);

        markComposer();
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

    /**
     * Says whether there is anything to send, for the send button to look like.
     *
     * Decoration only — the button stays pressable, and `submit` ignores an
     * empty message as it always has. A disabled button would take the control
     * out of the tab order and swallow the click that a visitor whose keyboard
     * has just inserted an emoji is about to make, and neither is worth a
     * greyed-out arrow.
     *
     * A file with nothing typed counts as something to send: a photograph of a
     * broken parcel says what it needs to.
     */
    function markComposer() {
        if (!el.composer) {
            return;
        }

        var empty = el.input.value.trim() === '' && state.file === null;

        el.composer.setAttribute('data-empty', empty ? 'yes' : 'no');
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
        markComposer();
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
        markComposer();
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

        drawTeaser();

        if (state.open) {
            el.input.focus();
            markRead();

            /*
             * The theme is asked for again on every opening, and the status line
             * is the reason. A marketing page is left open for hours; asked once
             * at load, the line under the title would still say "online" at ten
             * at night to somebody who has just opened the chat. Asking on every
             * poll instead would recompute it every five seconds for a panel
             * nobody is looking at, and the answer only matters at the moment it
             * is read.
             */
            refreshTheme();
        }

        schedule();
    }

    function refreshTheme() {
        api('theme?widget=' + encodeURIComponent(settings.widget) + '&bot=' + encodeURIComponent(settings.bot))
            .then(function (sent) {
                applyTheme(sent);
                applyLabels(sent && sent.labels);
            })
            .catch(function () {});
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

            /*
             * A thread with something in it is a thread that has started, and
             * the opening form has nothing left to ask. This is the branch that
             * matters on a reload: the widget draws itself, the first poll finds
             * yesterday's conversation, and without this the visitor would be
             * asked for their name again above their own transcript.
             */
            drawLead();
        }

        var from = message.incoming ? 'visitor' : 'agent';

        /*
         * The side of the panel and the speaker are two different questions, and
         * the second one is finer: the bot and an operator both write on the
         * agent side, and a handoff is the moment they stop being the same
         * speaker. The runs below are drawn from this, so an operator taking
         * over starts a group of their own instead of continuing the bot's.
         */
        var author = message.author || (message.incoming ? 'visitor' : 'bot');

        var row = element('div', 'message');
        row.setAttribute('data-from', from);
        row.setAttribute('data-author', author);

        /*
         * The name is written for every message and hidden by the stylesheet —
         * on the rows that continue a run, and everywhere when the placement
         * does not show names. Appended only when the switch is on, a theme that
         * arrived after a message did would leave that message nameless among
         * named ones, and the first thing on screen is always the greeting.
         */
        if (author !== 'system') {
            row.appendChild(element('div', 'author', authorName(author)));
        }

        if (from === 'agent' && theme.flags.avatar_show) {
            row.appendChild(avatar());
        }

        var bubble = element('div', 'bubble');

        if (message.reply) {
            bubble.appendChild(quote(message.reply));
        }

        /*
         * The body goes in an element of its own rather than straight into the
         * bubble, so a quote can sit above it. Left as a bare text node it would
         * still work — until an attachment or a tick was appended, at which
         * point the quote, the sentence and the tick would all be siblings with
         * no way to keep the quote on its own line.
         */
        bubble.appendChild(element('span', 'text', message.body));

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

        /*
         * A message can only be quoted once the server has given it an id. The
         * echo of what the visitor has just typed has none — it is drawn locally
         * so the panel feels immediate — and a quote of it would travel as a
         * number the server has never issued.
         */
        if (tracked) {
            row.appendChild(replyButton(message, author));
        }

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
     * Shows one picture at the size of the screen.
     *
     * Built once and reused, because it is drawn over a page this widget does
     * not own: a viewer created per picture would leave a growing stack of
     * fixed-position layers on somebody's marketing site, and each one would
     * hold its blob open.
     *
     * Escape closes it before it closes the chat, which is why the key is
     * listened for here and not once at the top: a visitor pressing Escape at a
     * full-screen photograph means the photograph.
     */
    function viewer() {
        if (el.viewer) {
            return el.viewer;
        }

        el.viewer = element('div', 'viewer');
        el.viewer.hidden = true;
        el.viewer.setAttribute('role', 'dialog');
        el.viewer.setAttribute('aria-modal', 'true');

        el.viewerImage = element('img', 'viewer-image');

        var shut = element('button', 'viewer-close');

        shut.type = 'button';
        shut.appendChild(icon('close'));
        shut.setAttribute('aria-label', labels.close);

        el.viewer.appendChild(el.viewerImage);
        el.viewer.appendChild(shut);

        /*
         * The backdrop closes, the picture does not. Without the check, the
         * click that lands on the photograph the visitor is looking at closes
         * the thing they opened to look at it.
         */
        el.viewer.addEventListener('click', function (event) {
            if (event.target !== el.viewerImage) {
                collapse();
            }
        });

        el.viewer.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                collapse();
            }
        });

        el.root.appendChild(el.viewer);

        return el.viewer;
    }

    function expand(source, name) {
        var box = viewer();

        el.viewerImage.src = source;
        el.viewerImage.alt = name || '';
        box.hidden = false;
        box.setAttribute('tabindex', '-1');
        box.focus();
    }

    function collapse() {
        if (el.viewer) {
            el.viewer.hidden = true;
            el.viewerImage.removeAttribute('src');
        }
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
            /*
             * A button around the thumbnail, not a bare image. A picture in a
             * chat is looked at, and the thumbnail is a hundred pixels of a
             * screenshot somebody sent to be read — the visitor's only way to
             * read it was to know that saving it and opening it elsewhere
             * worked. It is a button rather than a link because there is no
             * address to give: the bytes arrive over an authenticated fetch and
             * live in this page as a blob.
             */
            var opener = element('button', 'shot');

            opener.type = 'button';
            opener.title = labels.expand;
            opener.setAttribute('aria-label', labels.expand);

            var picture = element('img', 'thumb');

            picture.alt = name;
            apiBlob(file.url)
                .then(function (blob) {
                    picture.src = URL.createObjectURL(blob);
                })
                .catch(function () {});

            opener.appendChild(picture);
            opener.addEventListener('click', function () {
                if (picture.src) {
                    expand(picture.src, name);
                }
            });

            return opener;
        }

        var link = element('button', 'file');

        link.appendChild(icon('attach'));
        link.appendChild(document.createTextNode(name));
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
     * The quoted line, inside the bubble that answers it.
     *
     * The text comes from the server with every poll rather than being looked up
     * among the bubbles on screen. A transcript is trimmed to the last fifty
     * messages, so the line being quoted is quite often not on screen at all —
     * looked up locally, a quote of an older message would render empty for the
     * one person who most needs to see what is being answered.
     */
    function quote(quoted) {
        var block = element('div', 'quote');

        block.appendChild(element('span', 'quote-author', authorName(quoted.author)));
        block.appendChild(element('span', 'quote-text', quoted.body));

        return block;
    }

    /**
     * The button that points the next message at this one.
     *
     * Beside the bubble rather than inside it: inside, it would sit in the text
     * of what somebody said, and on a one-word message it would be wider than
     * the message. It is hidden — not absent — when the placement does not offer
     * quoting, so that switching the setting on repaints the transcript already
     * on screen.
     */
    function replyButton(message, author) {
        var button = element('button', 'reply', labels.reply);

        button.type = 'button';
        button.hidden = !theme.features.replies;
        button.addEventListener('click', function () {
            quoteMessage(message, author);
        });

        return button;
    }

    /**
     * The bot's face, or its initials when it has no picture.
     */
    function avatar() {
        return paintAvatar(element('div', 'avatar'));
    }

    function paintAvatar(node) {
        node.textContent = theme.flags.avatar ? '' : (theme.flags.avatar_label || '');
        node.style.backgroundImage = theme.flags.avatar ? 'url("' + theme.flags.avatar + '")' : '';

        return node;
    }

    /**
     * Brings the transcript already on screen into line with the theme and the
     * wording that arrived after it was drawn.
     *
     * **The first bubble is always drawn before either has arrived.** The widget
     * paints itself immediately and asks the server afterwards — deliberately,
     * so that a slow answer costs a moment of default colours rather than a
     * missing chat — and the greeting is appended in that window. Everything
     * decided per message was therefore frozen at the defaults for that one
     * bubble: it was named "Assistant" in English above a Russian panel, and it
     * had no face while every later reply had one. Nothing about that reads as a
     * race; it reads as the first message being a different kind of message.
     */
    function repaintRows() {
        if (!el.log) {
            return;
        }

        el.log.querySelectorAll('.' + settings.prefix + '__message').forEach(function (row) {
            var name = row.querySelector('.' + settings.prefix + '__author');

            if (name) {
                name.textContent = authorName(row.getAttribute('data-author') || 'bot');
            }

            var face = row.querySelector('.' + settings.prefix + '__avatar');
            var wanted = row.getAttribute('data-from') === 'agent' && theme.flags.avatar_show;

            if (wanted && face) {
                paintAvatar(face);
            } else if (wanted) {
                row.insertBefore(avatar(), row.querySelector('.' + settings.prefix + '__bubble'));
            } else if (face) {
                face.remove();
            }
        });

        markRuns();
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
            /*
             * The speaker, not the side. The bot and an operator both write on
             * the agent side, and grouped by side a handoff would put the
             * operator's first sentence into the bot's block — one tail, one
             * face and one name over two different people. The name over a run
             * is what made the difference visible; the grouping was wrong before
             * it, silently.
             */
            var speaker = row.getAttribute('data-author') || row.getAttribute('data-from');
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
        var quoting = state.replyTo;

        if ((!body && !file) || state.sending) {
            return;
        }

        state.sending = true;
        el.input.value = '';
        markComposer();
        el.emojiPad.hidden = true;
        state.choices = [];
        renderChoices();
        hideNotice();

        cancelQuoting();

        append({
            id: null,
            body: body || (file ? file.name : ''),
            incoming: true,
            reply: quoting,
        });

        var request = { method: 'POST' };
        var cookies = pageCookies();

        if (file) {
            var form = new FormData();

            form.append('widget', settings.widget);
            form.append('bot', settings.bot);
            form.append('body', body);
            form.append('page', window.location.href);
            form.append('attachment', file);

            if (quoting) {
                form.append('reply_to', String(quoting.id));
            }

            if (cookies) {
                Object.keys(cookies).forEach(function (name) {
                    form.append('cookies[' + name + ']', cookies[name]);
                });
            }

            if (state.lead) {
                Object.keys(state.lead).forEach(function (name) {
                    form.append('lead[' + name + ']', state.lead[name]);
                });
            }

            request.form = form;
        } else {
            request.body = {
                widget: settings.widget,
                bot: settings.bot,
                body: body,
                reply_to: quoting ? quoting.id : null,
                page: window.location.href,
                cookies: cookies,
                lead: state.lead,
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
        refreshTheme();

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
