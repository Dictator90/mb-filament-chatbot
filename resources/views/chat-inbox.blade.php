{{--
    The operator's desk.

    The styling is a <style> block in the page rather than a stylesheet asset,
    and that is a deliberate trade. A package cannot rely on the host's Tailwind
    build having scanned its vendor directory, so utility classes written here
    may simply not exist in the compiled panel CSS — the screen would arrive
    unstyled with nothing to say why. A published asset would work, but only
    after the host remembers to run `filament:assets` again on every upgrade, and
    a screen that silently loses its layout on upgrade is worse than one carrying
    its own rules. Everything is scoped under `.chatbot-inbox` so none of it can
    reach the rest of the panel.
--}}
<x-filament-panels::page full-height>
    <style>
        /*
            The colours are the panel's own, not the package's.

            Every one of them resolves to a Filament token, and that is what
            keeps this screen from looking like a sticker from another program.
            A palette of its own was indigo: on a panel whose owner had chosen
            amber it was the one page in the application that had not been told,
            and switching the panel's theme left the desk exactly as it was.
            Reading the tokens also buys the dark theme for nothing — the panel
            redefines them itself.

            Each has a literal fallback, for the host that ships a theme of its
            own and defines fewer tokens than Filament does. An undefined custom
            property is not a default, it is nothing: the rule using it collapses
            and the pane arrives transparent, which reads as a broken page rather
            than as a missing colour.
        */
        .chatbot-inbox {
            --ci-line: var(--gray-200, rgb(228 228 231));
            --ci-muted: var(--gray-500, rgb(113 113 122));
            --ci-paper: var(--gray-50, rgb(255 255 255));
            --ci-surface: var(--gray-50, rgb(255 255 255));
            --ci-raised: var(--gray-100, rgb(244 244 245));
            --ci-in: var(--gray-50, rgb(255 255 255));
            --ci-out: var(--primary-600, rgb(79 70 229));
            --ci-out-ink: var(--gray-50, rgb(255 255 255));
            /*
                Mixed from the accent rather than named as a colour of its own,
                so that "the bot said this" stays visible on a dark panel too. A
                fixed pale tint is a pale tint on a dark ground as well —
                indistinguishable from the visitor's bubble, which is the one
                distinction this transcript cannot lose.
            */
            --ci-bot: color-mix(in srgb, var(--primary-500, rgb(99 102 241)) 12%, var(--gray-50, rgb(255 255 255)));
            --ci-accent: var(--primary-600, rgb(79 70 229));
            --ci-mark: var(--danger-600, rgb(220 38 38));

            /*
                A faint speckle behind the transcript, so the thread reads as a
                surface the bubbles are lying on rather than as empty page. Kept
                neutral grey rather than tinted: it sits under every theme a host
                may choose, and a tinted one would fight half of them.
            */
            --ci-grain: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='48' height='48' viewBox='0 0 48 48'%3E%3Ccircle cx='6' cy='10' r='1.25' fill='%23888' fill-opacity='0.10'/%3E%3Ccircle cx='28' cy='22' r='1' fill='%23888' fill-opacity='0.09'/%3E%3Ccircle cx='40' cy='8' r='1.1' fill='%23888' fill-opacity='0.08'/%3E%3Ccircle cx='16' cy='36' r='1' fill='%23888' fill-opacity='0.08'/%3E%3Ccircle cx='38' cy='38' r='1.2' fill='%23888' fill-opacity='0.09'/%3E%3C/svg%3E");

            /*
                One card holding two panes, not two cards side by side.

                The rail and the transcript are one desk: a conversation is
                chosen on the left and read on the right, and a gap between them
                made that one movement look like two screens that happened to be
                open together.
            */
            display: grid;
            grid-template-columns: minmax(0, 21rem) minmax(0, 1fr);
            overflow: hidden;
            border: 1px solid var(--ci-line);
            border-radius: 0.75rem;
            /*
                The desk is given the window rather than its content, because a
                transcript is as tall as the conversation is long. Left to grow,
                it pushes the reply box below the fold — and the operator's
                answer to a long conversation is the one they cannot reach
                without scrolling past everything they have just read.

                `dvh` rather than `vh`: on a phone the browser's own bars are
                part of the viewport under `vh`, and the box would sit behind
                them. The subtraction is the panel's topbar and page padding.

                **The subtraction is a variable, because the panel is not this
                package's.** A host that adds chrome of its own — a fixed bottom
                navigation on phones is the common one — makes the page taller
                than the window: the desk still asks for the whole viewport, the
                page scrolls, the conversation's header slides under the topbar
                and an empty band opens above the bottom menu. Nothing errors,
                and on a desktop it looks perfect. The host knows its chrome;
                it sets `--chatbot-inbox-offset` and the desk fits.

                `8rem` is Filament's own chrome, measured rather than guessed:
                a 4rem topbar, 2rem of page padding above the desk and 2rem
                below it. The old `7rem` left the page a few pixels taller than
                the window on every screen — invisible on a desktop, a scroll
                that hides the header on a phone.
            */
            height: calc(100dvh - var(--chatbot-inbox-offset, 8rem));
            min-height: 20rem;
        }

        .dark .chatbot-inbox {
            --ci-line: var(--gray-800, rgb(39 39 42));
            --ci-muted: var(--gray-400, rgb(161 161 170));
            --ci-paper: var(--gray-900, rgb(24 24 27));
            --ci-surface: var(--gray-900, rgb(24 24 27));
            --ci-raised: var(--gray-950, rgb(9 9 11));
            --ci-in: var(--gray-800, rgb(39 39 42));
            --ci-out: var(--primary-500, rgb(99 102 241));
            --ci-out-ink: var(--gray-950, rgb(9 9 11));
            --ci-bot: color-mix(in srgb, var(--primary-500, rgb(99 102 241)) 22%, var(--gray-900, rgb(24 24 27)));
            --ci-accent: var(--primary-500, rgb(99 102 241));
            --ci-mark: var(--danger-500, rgb(239 68 68));
        }

        /*
            No gap after the desk on its own page.

            Filament renders two empty containers after the page's content —
            for action modals and the like — and the page's flex gap puts 2rem
            in front of each. That is 4rem of nothing under the desk, which made
            every screen scroll: the reply box sat at the bottom of the window
            and the page went on past it. The gap matters between real blocks
            on other pages; on this one there is only the desk.
        */
        .fi-page-content:has(> .chatbot-inbox) {
            row-gap: 0;
        }

        /*
            Кнопка «к списку» живёт только на узком экране — на широком список
            виден слева, и предлагать вернуться к тому, что и так перед
            глазами, значит занимать место в шапке разговора зря.
        */
        .chatbot-inbox__back {
            display: none;
            gap: 0.3rem;
            align-items: center;
            padding: 0.3rem 0.55rem;
            font-size: 0.78rem;
            color: var(--ci-muted);
            background: var(--ci-surface);
            border: 1px solid var(--ci-line);
            border-radius: 0.5rem;
        }

        @media (max-width: 64rem) {
            .chatbot-inbox {
                grid-template-columns: minmax(0, 1fr);
            }

            .chatbot-inbox.is-open .chatbot-inbox__rail {
                display: none;
            }

            .chatbot-inbox__back {
                display: inline-flex;
            }
        }

        /*
            Carried here rather than borrowed from the panel. The host's
            compiled CSS contains only the utilities Filament itself uses, and
            `sr-only` is not among them — without this rule every label meant for
            a screen reader is printed above its field, and the rail reads as a
            column of stray words.
        */
        .chatbot-inbox .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }

        .chatbot-inbox__rail,
        .chatbot-inbox__stage {
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }

        /*
            The rail is paper and the transcript is the surface it lies on. The
            two panes are told apart by their ground rather than by a rule
            between them — a border alone leaves two identical white columns, and
            which one is the list becomes a thing to work out rather than see.
        */
        .chatbot-inbox__rail {
            background: var(--ci-paper);
            border-inline-end: 1px solid var(--ci-line);
        }

        .chatbot-inbox__stage {
            background: var(--ci-raised) var(--ci-grain) repeat;
        }

        .chatbot-inbox__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.5rem;
            padding: 0.9rem 1rem 0.5rem;
        }

        .chatbot-inbox__head h2 {
            margin-top: 0.1rem;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        /*
            The small word above the title, in capitals at two thirds the size.
            It names the room rather than the list: "Conversations" over a column
            of conversations describes what is already visible, and leaves
            nothing saying which part of the panel this column belongs to.
        */
        .chatbot-inbox__kicker {
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--ci-muted);
        }

        .chatbot-inbox__count {
            flex: none;
            padding: 0.15rem 0.5rem;
            font-size: 0.68rem;
            color: var(--ci-muted);
            background: var(--ci-raised);
            border-radius: 9999px;
        }

        .chatbot-inbox__search {
            position: relative;
            margin: 0.5rem 0.9rem 0.35rem;
        }

        .chatbot-inbox__search-icon {
            position: absolute;
            inset-inline-start: 0.7rem;
            top: 50%;
            width: 1rem;
            height: 1rem;
            transform: translateY(-50%);
            color: var(--ci-muted);
            pointer-events: none;
        }

        .chatbot-inbox__search input {
            width: 100%;
            padding: 0.5rem 0.85rem 0.5rem 2.1rem;
            font-size: 0.83rem;
            color: inherit;
            background: var(--ci-raised);
            border: 1px solid var(--ci-line);
            border-radius: 9999px;
        }

        [dir='rtl'] .chatbot-inbox__search input {
            padding: 0.5rem 2.1rem 0.5rem 0.85rem;
        }

        .chatbot-inbox__search input:focus {
            background: var(--ci-paper);
            border-color: var(--ci-accent);
            outline: none;
        }

        .chatbot-inbox__chips {
            display: flex;
            gap: 0.3rem;
            padding: 0.2rem 0.9rem 0.35rem;
        }

        .chatbot-inbox__chip {
            padding: 0.25rem 0.7rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--ci-muted);
            background: transparent;
            border: 1px solid transparent;
            border-radius: 9999px;
        }

        .chatbot-inbox__chip:hover {
            background: var(--ci-raised);
        }

        /*
            The chosen one is tinted rather than filled. Filled, three chips over
            a list read as three buttons of which one has been pressed and is now
            waiting; tinted, they read as one of three states — which is what
            they are.
        */
        .chatbot-inbox__chip.is-on {
            color: var(--ci-accent);
            background: color-mix(in srgb, var(--ci-accent) 10%, transparent);
            border-color: color-mix(in srgb, var(--ci-accent) 35%, var(--ci-line));
        }

        .chatbot-inbox__controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.375rem;
            padding: 0 0.9rem 0.7rem;
        }

        .chatbot-inbox__controls select {
            flex: 1 1 8rem;
            min-width: 0;
            padding: 0.3rem 0.5rem;
            font-size: 0.78rem;
            color: inherit;
            background: var(--ci-raised);
            border: 1px solid var(--ci-line);
            border-radius: 0.5rem;
        }

        .chatbot-inbox__toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.75rem;
            color: var(--ci-muted);
        }

        .chatbot-inbox__list {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            border-top: 1px solid var(--ci-line);
        }

        .chatbot-inbox__row {
            display: flex;
            gap: 0.7rem;
            width: 100%;
            padding: 0.65rem 0.9rem;
            text-align: start;
            /*
                The active row is marked with a rule down its leading edge, and
                every row carries a transparent one of the same width. Added only
                to the chosen row, the mark would push its text sideways as the
                operator moved through the list.
            */
            border-inline-start: 3px solid transparent;
            border-bottom: 1px solid var(--ci-line);
        }

        .chatbot-inbox__row:hover {
            background: var(--ci-raised);
        }

        .chatbot-inbox__row.is-active {
            background: color-mix(in srgb, var(--ci-accent) 7%, transparent);
            border-inline-start-color: var(--ci-accent);
        }

        /*
            The circle of initials, in the panel's own colour.

            It is what makes a rail of twenty threads scannable: a colour and two
            letters are recognised without reading, and the operator finds the
            conversation they left ten minutes ago by shape rather than by
            working down a column of similar names.
        */
        .chatbot-inbox__avatar {
            display: inline-flex;
            flex: none;
            align-items: center;
            justify-content: center;
            width: 2.35rem;
            height: 2.35rem;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            color: var(--ci-out-ink);
            background: var(--ci-accent);
            border-radius: 9999px;
        }

        .chatbot-inbox__row-body {
            flex: 1 1 auto;
            min-width: 0;
        }

        .chatbot-inbox__row-top {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .chatbot-inbox__row-name {
            font-size: 0.85rem;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .chatbot-inbox__row.has-unread .chatbot-inbox__row-name {
            font-weight: 800;
        }

        .chatbot-inbox__row-time,
        .chatbot-inbox__row-preview {
            font-size: 0.72rem;
            color: var(--ci-muted);
        }

        .chatbot-inbox__row-time {
            flex: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .chatbot-inbox__row-preview {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .chatbot-inbox__tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            margin-top: 0.35rem;
        }

        .chatbot-inbox__tag {
            padding: 0.05rem 0.4rem;
            font-size: 0.65rem;
            color: var(--ci-muted);
            background: var(--ci-raised);
            border: 1px solid var(--ci-line);
            border-radius: 9999px;
        }

        /*
            The one tag that is not a label but a state: this thread is being
            answered by the bot, and anything the operator types here lands
            beside a machine that is still talking.
        */
        .chatbot-inbox__tag.is-bot {
            color: var(--ci-accent);
            background: color-mix(in srgb, var(--ci-accent) 10%, transparent);
            border-color: color-mix(in srgb, var(--ci-accent) 30%, var(--ci-line));
        }

        .chatbot-inbox__dot {
            display: inline-block;
            width: 0.5rem;
            height: 0.5rem;
            background: var(--ci-mark);
            border-radius: 9999px;
        }

        .chatbot-inbox__empty {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            gap: 0.25rem;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            font-size: 0.85rem;
            color: var(--ci-muted);
        }

        /*
            The head sits on paper while the transcript below it does not, so
            that the name of the person being answered stays legible over a
            thread that scrolls under it.
        */
        /*
            `flex: none` is what keeps the third line on screen.

            The stage is a flex column with `overflow: hidden`, and a flex item
            may shrink below its content unless it says otherwise — which the
            explicit `min-height` here turned from a floor into a target. A
            thread with a contact card has three lines in this header, the
            header was squeezed back to 3.6rem, and the line underneath was
            clipped away: the channel, the status and the phone number simply
            were not there. Nothing errors, and on a thread with no card
            everything looks perfect.
        */
        .chatbot-inbox__stage-head {
            display: flex;
            flex: none;
            flex-wrap: wrap;
            gap: 0.7rem;
            align-items: center;
            min-height: 3.6rem;
            padding: 0.6rem 1rem;
            background: var(--ci-paper);
            border-bottom: 1px solid var(--ci-line);
        }

        .chatbot-inbox__who {
            flex: 1 1 12rem;
            min-width: 0;
        }

        .chatbot-inbox__who h3 {
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        .chatbot-inbox__who p {
            font-size: 0.72rem;
            color: var(--ci-muted);
        }

        /*
            The one line that says who this is reads darker than the channel and
            the status beneath it. Both are grey and both are second-level, but
            only one of them is what the operator opened the thread to learn.
        */
        .chatbot-inbox__contact {
            display: flex;
            flex-wrap: wrap;
            gap: 0.3rem;
            align-items: center;
            font-size: 0.78rem;
            color: inherit;
        }

        .chatbot-inbox__contact a {
            text-decoration: underline;
        }

        .chatbot-inbox__banner {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            padding: 0.45rem 1rem;
            font-size: 0.75rem;
            color: var(--ci-accent);
            background: color-mix(in srgb, var(--ci-accent) 8%, var(--ci-paper));
            border-bottom: 1px solid var(--ci-line);
        }

        .chatbot-inbox__thread {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            gap: 0.35rem;
            min-height: 12rem;
            padding: 1rem 1.1rem;
            overflow-y: auto;
        }

        /*
            The day, centred over the messages that belong to it, with a rule
            running out to both sides. Left-aligned it reads as a message from
            somebody called "Today".
        */
        /*
            A pill in the middle of the thread rather than a rule with words in
            it. The transcript has a texture behind it now, and a hairline drawn
            across that reads as a seam in the page; a pill sits on top of it as
            a label, which is what the date is.
        */
        .chatbot-inbox__day {
            align-self: center;
            margin: 0.5rem 0 0.3rem;
            padding: 0.15rem 0.7rem;
            font-size: 0.66rem;
            font-weight: 600;
            color: var(--ci-muted);
            background: var(--ci-paper);
            border: 1px solid var(--ci-line);
            border-radius: 9999px;
        }

        /*
            The visitor's words are on paper with a border; ours are painted in
            the panel's colour. The pair is what makes a transcript readable at a
            glance without reading the names: one side of the conversation is the
            page, the other is us.

            The corner nearest the speaker is cut short. It is what every
            messenger does and it costs one line — and it is the only thing in
            the bubble that says which end of the conversation it came from when
            two of them sit at the same width.
        */
        .chatbot-inbox__bubble {
            max-width: min(36rem, 82%);
            padding: 0.5rem 0.75rem 0.4rem;
            font-size: 0.85rem;
            line-height: 1.5;
            color: inherit;
            background: var(--ci-in);
            border: 1px solid var(--ci-line);
            border-radius: 1rem;
            border-end-start-radius: 0.3rem;
            /*
                A shadow of almost nothing, which is what lifts a pale bubble off
                a pale ground. Without it the visitor's own words — white on
                near-white — are a rectangle outlined in a hairline, and a
                transcript of them reads as a form rather than as a conversation.
            */
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
        }

        .chatbot-inbox__bubble.is-outgoing {
            align-self: flex-end;
            color: var(--ci-out-ink);
            background: var(--ci-out);
            border-color: transparent;
            border-end-start-radius: 1rem;
            border-end-end-radius: 0.3rem;
        }

        /*
            What the bot said is ours and is not the operator's. Drawn in the
            same solid colour as a human reply, a transcript of an automated
            conversation reads as a colleague who answered every line — and the
            operator taking it over cannot see where they came in.
        */
        .chatbot-inbox__bubble.is-bot {
            color: inherit;
            background: var(--ci-bot);
            border-color: color-mix(in srgb, var(--ci-accent) 20%, var(--ci-line));
        }

        /*
            A system line is nobody speaking. Dashed and unfilled, it stops
            looking like a promise somebody made to the customer.
        */
        .chatbot-inbox__bubble.is-system {
            align-self: center;
            max-width: min(30rem, 90%);
            color: var(--ci-muted);
            background: transparent;
            border-style: dashed;
            border-radius: 0.75rem;
        }

        .chatbot-inbox__bubble-meta,
        .chatbot-inbox__bubble-foot {
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.65;
        }

        .chatbot-inbox__bubble-foot {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.3rem;
            margin-top: 0.15rem;
            font-weight: 400;
            letter-spacing: normal;
            text-transform: none;
        }

        .chatbot-inbox__bubble-text {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        /*
            The quoted line, above the reply that answers it.

            Clamped to one line and drawn in the bubble's own colour at a lower
            opacity, with a rule down its inner edge — the same shape the widget
            gives it, so that operator and visitor are looking at the same thing.
            Allowed to grow, a quote of a long message would be taller than the
            answer and the answer would read as an afterthought.
        */
        .chatbot-inbox__quote {
            margin-bottom: 0.2rem;
            padding-inline-start: 0.4rem;
            border-inline-start: 2px solid currentColor;
            font-size: 0.72rem;
            opacity: 0.75;
        }

        .chatbot-inbox__quote-author {
            display: block;
            font-weight: 600;
        }

        .chatbot-inbox__quote-text {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /*
            The three stages differ by weight and opacity rather than by hue.
            A red-green pair would carry the whole meaning in the one channel
            about a twelfth of men cannot separate, and the difference between
            "delivered" and "read" is the one an operator checks before deciding
            whether to chase somebody.
        */
        .chatbot-inbox__receipt {
            margin-inline-start: 0.35rem;
            letter-spacing: -0.12em;
            opacity: 0.65;
        }

        /*
            The read tick is drawn in the marking colour and not in the panel's
            own: it lives inside a bubble already painted in the panel's colour,
            where an accent-coloured tick is a tick nobody can see.
        */
        .chatbot-inbox__receipt[data-stage="read"] {
            opacity: 1;
            font-weight: 700;
            color: var(--ci-mark);
        }

        .chatbot-inbox__files {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            margin-top: 0.35rem;
        }

        /*
            A picture is shown small and opens over the desk when clicked. Full
            size in the thread it would push the question underneath it off the
            screen, and the operator scrolls past a photograph of a parcel to
            find the sentence that came with it.
        */
        .chatbot-inbox__files img {
            max-width: min(18rem, 100%);
            max-height: 14rem;
            border-radius: 0.5rem;
            object-fit: cover;
        }

        .chatbot-inbox__shot {
            display: block;
            padding: 0;
            border: 0;
            background: transparent;
            cursor: zoom-in;
        }

        /*
            Over the desk, at the size of the window.

            The thread is a column of a few hundred pixels, and a screenshot
            read inside it is the thumbnail again. It replaced a link that
            opened a new tab: the picture arrived alone, and coming back
            reloaded a Livewire page that had scrolled itself to the newest
            message — the operator lost the place they were reading.
        */
        .chatbot-inbox__viewer {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: rgb(15 23 42 / 0.86);
            cursor: zoom-out;
        }

        .chatbot-inbox__viewer-image {
            max-width: 100%;
            max-height: 100%;
            border-radius: 0.5rem;
            object-fit: contain;
            cursor: default;
        }

        .chatbot-inbox__viewer-close {
            position: absolute;
            top: 0.75rem;
            inset-inline-end: 0.75rem;
            display: inline-flex;
            padding: 0.375rem;
            border-radius: 9999px;
            background: rgb(15 23 42 / 0.6);
            color: #fff;
        }

        .chatbot-inbox__viewer-close svg {
            width: 1.375rem;
            height: 1.375rem;
        }

        .chatbot-inbox__file {
            display: inline-flex;
            gap: 0.35rem;
            align-items: center;
            font-size: 0.78rem;
            text-decoration: underline;
            overflow-wrap: anywhere;
        }

        .chatbot-inbox__file-icon {
            flex: none;
            width: 1rem;
            height: 1rem;
        }

        /*
            Its own class rather than the one the bubbles use. That one names a
            link to a file and carries an underline; this is a button, and it
            wore the underline for as long as the two shared a name.
        */
        .chatbot-inbox__attach {
            flex: none;
            display: inline-flex;
        }

        /*
            The reply box is a card lying on the transcript's own surface rather
            than a strip bolted to the bottom of the page. It is the same shape
            as the bubbles above it, which is the point: what is typed here
            becomes one of them.
        */
        .chatbot-inbox__composer {
            padding: 0.7rem 1.1rem 0.9rem;
        }

        .chatbot-inbox__composer form {
            padding: 0.5rem 0.6rem;
            background: var(--ci-paper);
            border: 1px solid var(--ci-line);
            border-radius: 0.9rem;
        }

        .chatbot-inbox__composer form:focus-within {
            border-color: var(--ci-accent);
        }

        .chatbot-inbox__composer textarea {
            flex: 1 1 auto;
            min-width: 0;
            padding: 0.45rem 0.4rem;
            font-size: 0.85rem;
            color: inherit;
            background: transparent;
            border: 0;
            resize: none;
        }

        .chatbot-inbox__composer textarea:focus {
            outline: none;
        }

        /*
            The whole composer on one line: the two icons, the box, and send.

            It used to be a paragraph-sized textarea with a row of labelled
            buttons underneath, which is a form, not a chat. An operator
            answering twenty threads reads the same four controls twenty times,
            and a reply is a sentence rather than a document.
        */
        .chatbot-inbox__composer-row {
            display: flex;
            gap: 0.4rem;
            align-items: flex-end;
        }

        /*
            The stock phrases sit under the line rather than in it. They are
            reached for occasionally and are the widest control here; on the
            line they pushed the box that everything is typed into down to a
            few characters on a laptop.
        */
        .chatbot-inbox__composer-under {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-top: 0.4rem;
        }

        .chatbot-inbox__counter {
            margin-inline-start: auto;
            font-size: 0.7rem;
            color: var(--ci-muted);
        }

        /*
            On a phone the emoji button goes, and send loses its word.

            Nothing is lost: every phone keyboard has an emoji key of its own,
            so that button is a second door to something already one tap away —
            and it is the only control here whose absence costs nothing. The
            paperclip stays, because a phone is where the photograph is.
        */
        @media (max-width: 40rem) {
            .chatbot-inbox__emoji {
                display: none;
            }

            .chatbot-inbox__send-text {
                display: none;
            }
        }

        .chatbot-inbox__error {
            margin-top: 0.35rem;
            font-size: 0.72rem;
            color: rgb(220 38 38);
        }

        .chatbot-inbox__quick,
        .chatbot-inbox__emoji {
            position: relative;
        }

        /*
            Opens upwards, for the same reason the stock phrases do: the composer
            sits against the bottom of the window and a list dropping down would
            open off screen.
        */
        .chatbot-inbox__emoji-pad {
            position: absolute;
            bottom: 100%;
            left: 0;
            z-index: 10;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            width: 15rem;
            padding: 0.4rem;
            margin-bottom: 0.35rem;
            background: var(--ci-surface);
            border: 1px solid var(--ci-line);
            border-radius: 0.5rem;
            box-shadow: 0 10px 30px rgb(0 0 0 / 0.15);
        }

        .chatbot-inbox__emoji-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.1rem;
        }

        .chatbot-inbox__emoji-pick {
            padding: 0.15rem 0.25rem;
            font-size: 1.1rem;
            line-height: 1.2;
            border-radius: 0.35rem;
        }

        .chatbot-inbox__emoji-pick:hover {
            background: var(--ci-raised);
        }

        .chatbot-inbox__attached {
            display: flex;
            gap: 0.4rem;
            align-items: center;
            margin-top: 0.4rem;
            font-size: 0.75rem;
            color: var(--ci-muted);
        }

        .chatbot-inbox__attached strong {
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .chatbot-inbox__quick-pad {
            position: absolute;
            bottom: 100%;
            left: 0;
            z-index: 10;
            width: 20rem;
            max-height: 16rem;
            margin-bottom: 0.35rem;
            overflow-y: auto;
            background: var(--ci-surface);
            border: 1px solid var(--ci-line);
            border-radius: 0.5rem;
            box-shadow: 0 10px 30px rgb(0 0 0 / 0.15);
        }

        .chatbot-inbox__quick-pick {
            display: block;
            width: 100%;
            padding: 0.45rem 0.6rem;
            font-size: 0.78rem;
            text-align: start;
            border-bottom: 1px solid var(--ci-line);
        }

        .chatbot-inbox__quick-pick:hover {
            background: var(--ci-raised);
        }

        .chatbot-inbox__quick-pick span {
            display: block;
            font-size: 0.7rem;
            color: var(--ci-muted);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .chatbot-inbox__closed {
            font-size: 0.78rem;
            color: var(--ci-muted);
        }
    </style>

    @php
        $record = $this->activeConversation;
        $messages = $record ? $this->thread : collect();
        $channels = $this->channelOptions();
    @endphp

    {{--
        One poll for the pair of panes: a new conversation in the list and a new
        line in the open transcript arrive on the same request. `.visible` is
        what makes a desk left open all day cost nothing — a background tab asks
        for nothing at all.
    --}}
    <div
        @class(['chatbot-inbox', 'is-open' => $record !== null])
        wire:poll.visible.10s="poll"
    >
        <aside class="chatbot-inbox__rail" aria-label="{{ __('chatbot::panel.inbox.conversations') }}">
            <div class="chatbot-inbox__head">
                <div>
                    <p class="chatbot-inbox__kicker">{{ __('chatbot::panel.inbox.kicker') }}</p>
                    <h2>{{ __('chatbot::panel.inbox.conversations') }}</h2>
                </div>

                <span class="chatbot-inbox__count">{{ $this->listSummary() }}</span>
            </div>

            {{--
                The magnifier is inside the field rather than beside it. Beside
                it, it is one more thing in a row of controls; inside, it is what
                tells somebody scanning the rail that the rounded box is a search
                box and not another filter.
            --}}
            <div class="chatbot-inbox__search">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="chatbot-inbox__search-icon" />
                <label class="sr-only" for="chatbot-inbox-search">{{ __('chatbot::panel.inbox.search_label') }}</label>
                <input
                    id="chatbot-inbox-search"
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="{{ __('chatbot::panel.inbox.search_placeholder') }}"
                    autocomplete="off"
                >
            </div>

            {{--
                The three states of a conversation are chips and not a dropdown.

                It is the filter that gets used, and a dropdown hides two thirds
                of it: which of the three is on can only be learned by opening
                the list, and moving between them costs two clicks each way. The
                filters below stay dropdowns because they are the ones that are
                set once and left alone — and because five rows of chips would be
                a wall of pills above the list they filter.
            --}}
            <div class="chatbot-inbox__chips" role="group" aria-label="{{ __('chatbot::panel.inbox.status') }}">
                @foreach (['open' => 'status_open', 'closed' => 'status_closed', 'all' => 'status_all'] as $value => $word)
                    <button
                        type="button"
                        wire:click="$set('statusFilter', '{{ $value }}')"
                        @class(['chatbot-inbox__chip', 'is-on' => $this->statusFilter === $value])
                        aria-pressed="{{ $this->statusFilter === $value ? 'true' : 'false' }}"
                    >{{ __('chatbot::panel.inbox.'.$word) }}</button>
                @endforeach
            </div>

            <div class="chatbot-inbox__controls">
                <label class="sr-only" for="chatbot-inbox-owner">{{ __('chatbot::panel.inbox.assignee') }}</label>
                <select id="chatbot-inbox-owner" wire:model.live="ownerFilter">
                    <option value="all">{{ __('chatbot::panel.inbox.assignee_any') }}</option>
                    <option value="mine">{{ __('chatbot::panel.inbox.assignee_mine') }}</option>
                    <option value="unassigned">{{ __('chatbot::panel.inbox.assignee_unassigned') }}</option>
                </select>

                @if (count($channels) > 1)
                    <label class="sr-only" for="chatbot-inbox-channel">{{ __('chatbot::panel.inbox.channel') }}</label>
                    <select id="chatbot-inbox-channel" wire:model.live="channelFilter">
                        <option value="">{{ __('chatbot::panel.inbox.channel_all') }}</option>
                        @foreach ($channels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif

                <label class="chatbot-inbox__toggle">
                    <input type="checkbox" wire:model.live="onlyUnread">
                    <span>{{ __('chatbot::panel.inbox.only_unread') }}</span>
                </label>
            </div>

            <div class="chatbot-inbox__list" role="list">
                @forelse ($this->conversations as $conversation)
                    <button
                        type="button"
                        role="listitem"
                        wire:key="chatbot-row-{{ $conversation->getKey() }}"
                        wire:click="selectConversation({{ $conversation->getKey() }})"
                        @class([
                            'chatbot-inbox__row',
                            'is-active' => $record?->getKey() === $conversation->getKey(),
                            'has-unread' => $conversation->hasUnread(),
                        ])
                    >
                        {{--
                            The circle of initials is how a rail of twenty
                            conversations is scanned without reading it. Two
                            letters and a colour are recognised at a glance;
                            twenty lines of similar-looking names are not, and
                            the operator ends up reading each one to find the
                            thread they left ten minutes ago.
                        --}}
                        <span class="chatbot-inbox__avatar" aria-hidden="true">{{ $conversation->initials() }}</span>

                        <span class="chatbot-inbox__row-body">
                            <span class="chatbot-inbox__row-top">
                                <span class="chatbot-inbox__row-name">{{ $conversation->title() }}</span>
                                <span class="chatbot-inbox__row-time">
                                    {{ $this->listTimestamp($conversation->last_message_at) }}
                                    @if ($conversation->hasUnread())
                                        <span class="chatbot-inbox__dot" title="{{ __('chatbot::panel.inbox.waiting_dot') }}"></span>
                                    @endif
                                </span>
                            </span>

                            <span class="chatbot-inbox__row-preview">{{ $this->listPreview($conversation) }}</span>

                            <span class="chatbot-inbox__tags">
                                <span class="chatbot-inbox__tag">{{ $conversation->channel }}</span>

                                @if ($conversation->bot_state === \MB\FilamentChatbot\Enums\BotState::Active)
                                    <span class="chatbot-inbox__tag is-bot">{{ $conversation->bot?->name ?? 'bot' }}</span>
                                @endif

                                <span class="chatbot-inbox__tag">{{ $this->ownerLabel($conversation) }}</span>
                            </span>
                        </span>
                    </button>
                @empty
                    <p class="chatbot-inbox__empty">{{ __('chatbot::panel.inbox.nothing_yet') }}</p>
                @endforelse

                @if ($this->listIsCapped())
                    <p class="chatbot-inbox__empty">{{ __('chatbot::panel.inbox.capped') }}</p>
                @endif
            </div>
        </aside>

        <section class="chatbot-inbox__stage" aria-live="polite">
            @if (! $record)
                <div class="chatbot-inbox__empty">
                    <h3>{{ __('chatbot::panel.inbox.pick_heading') }}</h3>
                    <p>{{ __('chatbot::panel.inbox.pick_description') }}</p>
                </div>
            @else
                <header class="chatbot-inbox__stage-head">
                    {{--
                        Дорога обратно к списку, и она существует только там,
                        где списка не видно. На узком экране разговор занимает
                        всё окно, рельс со списком спрятан — и выйти из
                        переписки было нечем: оператор с телефона застревал
                        в одном разговоре до перезагрузки страницы.
                    --}}
                    <button
                        type="button"
                        class="chatbot-inbox__back"
                        wire:click="clearSelection"
                        aria-label="{{ __('chatbot::panel.inbox.back_to_list') }}"
                    >
                        <span aria-hidden="true">←</span>
                        {{ __('chatbot::panel.inbox.back_to_list') }}
                    </button>

                    <span class="chatbot-inbox__avatar" aria-hidden="true">{{ $record->initials() }}</span>

                    <div class="chatbot-inbox__who">
                        <h3>{{ $record->title() }}</h3>

                        {{--
                            Who the application says this is, when it says
                            anything. Without it the desk knows only what the
                            visitor typed, and an operator answering a customer
                            of eight years reads the same three words as for a
                            stranger — then searches the CRM by hand for the
                            record the thread is already linked to.

                            Every word here is the host's: the package never
                            reads the linked record, so what a card shows and
                            what it deliberately leaves out are decisions taken
                            somewhere this file cannot see.
                        --}}
                        {{--
                            Привязка и отвязка стоят здесь, у самой строки
                            контакта, а не в ряду действий разговора: они
                            меняют именно эту строку, и в шапке рядом с
                            «взять себе» и «закрыть» читались как действия
                            над разговором целиком.
                        --}}
                        @php
                            $card = $this->contactCard();
                        @endphp

                        @if ($card || $this->canLinkContact())
                            <div class="chatbot-inbox__contact">
                                @if ($card)
                                    @if ($card->url)
                                        <a href="{{ $card->url }}">{{ $card->name }}</a>
                                    @else
                                        <strong>{{ $card->name }}</strong>
                                    @endif

                                    @if ($card->note)
                                        <span aria-hidden="true">·</span> {{ $card->note }}
                                    @endif
                                @endif

                                @if ($this->canLinkContact())
                                    {{ $this->linkContactAction }}
                                @endif

                                @if ($this->canUnlinkContact())
                                    {{ $this->unlinkContactAction }}
                                @endif
                            </div>
                        @endif

                        <p>
                            {{ $record->channel }}
                            <span aria-hidden="true">·</span>
                            {{ $record->status->getLabel() }}
                            @if (filled($record->contact_phone))
                                <span aria-hidden="true">·</span> {{ $record->contact_phone }}
                            @endif
                            @if (filled($record->entry_page))
                                <span aria-hidden="true">·</span> {{ $record->entry_page }}
                            @endif
                        </p>
                    </div>

                    {{--
                        Every action is rendered unconditionally except the one
                        that is genuinely unavailable. A Filament action embedded
                        in markup draws itself whatever `visible()` says — a
                        hidden one comes out as a greyed-out button rather than
                        as nothing — so "no bot to hand this back to" has to be
                        asked here instead.
                    --}}
                    {{ $this->assignmentAction }}
                    {{ $this->statusAction }}

                    @if ($this->canCreateContact())
                        {{ $this->createContactAction }}
                    @endif

                    @if ($this->canReturnToBot())
                        {{ $this->returnToBotAction }}
                    @endif
                </header>

                @if ($this->botIsAnswering())
                    <div class="chatbot-inbox__banner">
                        <span>{{ __('chatbot::panel.inbox.bot_banner') }}</span>
                    </div>
                @endif

                <div
                    class="chatbot-inbox__thread"
                    wire:key="chatbot-thread-{{ $record->getKey() }}"
                    data-conversation="{{ $record->getKey() }}"
                >
                    @php $day = null; @endphp

                    @forelse ($messages as $message)
                        {{--
                            A heading whenever the day changes.

                            Without it the transcript is a column of clock
                            times, and nothing says whether 14:02 under 09:41 is
                            one afternoon or two months. An operator apologising
                            for a two-minute wait on a message from Tuesday is
                            how that goes wrong, and the bubble they answered
                            looked entirely ordinary.
                        --}}
                        @if ($message->created_at?->toDateString() !== $day)
                            @php $day = $message->created_at?->toDateString(); @endphp

                            <p class="chatbot-inbox__day" wire:key="chatbot-day-{{ $day }}">
                                <span>{{ $this->dayLabel($message->created_at) }}</span>
                            </p>
                        @endif

                        <div
                            wire:key="chatbot-msg-{{ $message->getKey() }}"
                            @class([
                                'chatbot-inbox__bubble',
                                'is-outgoing' => ! $message->isIncoming(),
                                'is-bot' => $message->author === \MB\FilamentChatbot\Enums\MessageAuthor::Bot,
                                /*
                                 * A system line is nobody speaking — a delivery
                                 * failure, a handoff notice — and drawn as a
                                 * reply it reads as something the bot promised
                                 * the customer.
                                 */
                                'is-system' => $message->author === \MB\FilamentChatbot\Enums\MessageAuthor::System,
                            ])
                        >
                            <p class="chatbot-inbox__bubble-meta">{{ $this->authorLabel($message, $record) }}</p>

                            {{--
                                The line this one answers, when the visitor
                                pointed at one.

                                Shown here and not only in the widget, because
                                the quote is the whole content of some replies:
                                "this one" against a message from an hour ago is
                                unreadable at the desk without it, and the
                                operator would answer the wrong question with no
                                sign that a question had been chosen at all.
                            --}}
                            @if ($message->replyTo)
                                <p class="chatbot-inbox__quote">
                                    <span class="chatbot-inbox__quote-author">{{ $this->authorLabel($message->replyTo, $record) }}</span>
                                    <span class="chatbot-inbox__quote-text">{{ \Illuminate\Support\Str::limit((string) $message->replyTo->body, 140) }}</span>
                                </p>
                            @endif

                            <p class="chatbot-inbox__bubble-text">{{ $message->body }}</p>

                            {{--
                                A picture is shown, anything else is a named
                                link. The name is the one that arrived, printed
                                and never used to fetch anything: what the link
                                actually asks for is the file's own random id,
                                and the route decides whether this reader may
                                have it.
                            --}}
                            @if ($message->attachments->isNotEmpty())
                                <div class="chatbot-inbox__files">
                                    @foreach ($message->attachments as $file)
                                        @if ($file->isImage())
                                            {{--
                                                Opened over the desk rather than
                                                in a new tab. A picture in a
                                                thread is looked at, and looking
                                                at it cost the operator the
                                                thread: the tab that opened
                                                showed the photograph alone, and
                                                coming back reloaded a Livewire
                                                page that had scrolled itself
                                                back to the newest message.
                                            --}}
                                            <button
                                                type="button"
                                                class="chatbot-inbox__shot"
                                                title="{{ __('chatbot::panel.inbox.attachment_expand') }}"
                                                x-on:click="$dispatch('chatbot-picture', {
                                                    src: @js($this->attachmentUrl($file)),
                                                    alt: @js($file->name),
                                                })"
                                            >
                                                <img src="{{ $this->attachmentUrl($file) }}" alt="{{ $file->name }}">
                                            </button>
                                        @else
                                            <a
                                                class="chatbot-inbox__file"
                                                href="{{ $this->attachmentUrl($file) }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                title="{{ __('chatbot::panel.inbox.attachment_download', ['name' => $file->name]) }}"
                                            >
                                                <x-filament::icon icon="heroicon-m-paper-clip" class="chatbot-inbox__file-icon" />
                                                {{ $file->name }}
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                            <p class="chatbot-inbox__bubble-foot">
                                <time datetime="{{ $message->created_at?->toIso8601String() }}">
                                    {{ $message->created_at?->format('d M H:i') }}
                                </time>
                                {{--
                                    A failure stays a sentence with the reason in
                                    it; everything else is the tick every
                                    messenger draws. The stage is asked for with
                                    the conversation in hand rather than through
                                    the message's own relation: the same bubble
                                    loop runs fifty times, and a lazy `->conversation`
                                    here would be fifty queries for one timestamp.

                                    `role="img"` with a name on it, because ticks
                                    are a convention for people looking at them
                                    and nothing at all to anybody else — an
                                    unlabelled ✓✓ is read out as punctuation or
                                    skipped entirely.
                                --}}
                                @unless ($message->isIncoming())
                                    @php($stage = $message->deliveryStage($record))
                                    @if ($stage === \MB\FilamentChatbot\Enums\MessageDelivery::Failed)
                                        <span>· {{ __('chatbot::panel.inbox.not_delivered', ['reason' => $message->failure_reason]) }}</span>
                                    @else
                                        <span
                                            class="chatbot-inbox__receipt"
                                            data-stage="{{ $stage->value }}"
                                            role="img"
                                            aria-label="{{ $stage->label() }}"
                                            title="{{ $stage->label() }}"
                                        >{{ $stage === \MB\FilamentChatbot\Enums\MessageDelivery::Sent ? '✓' : '✓✓' }}</span>
                                    @endif
                                @endunless
                            </p>
                        </div>
                    @empty
                        <p class="chatbot-inbox__empty">{{ __('chatbot::panel.inbox.no_messages') }}</p>
                    @endforelse
                </div>

                <div class="chatbot-inbox__composer">
                    @if ($record->status === \MB\FilamentChatbot\Enums\ConversationStatus::Open)
                        {{--
                            The counter reads the draft instead of keeping its own
                            copy of how long it is. `wire:model` without `.live`
                            still updates `$wire` on every keystroke — the value is
                            held in the browser and merely not sent to the server —
                            so this costs no round trip, and there is no second
                            number that can fall out of step with the first.

                            It did fall out of step. A mirrored length has to be
                            resynced from every place the server clears the draft,
                            and there are three: sending, opening another
                            conversation, and closing the one that was open. Each is
                            a separate line somebody has to remember to write, the
                            miss is invisible on screen, and near the limit a stale
                            number reads as "no room left" over an empty box.
                        --}}
                        <form
                            wire:submit="sendReply"
                            x-data="{ get length() { return ($wire.composerBody ?? '').length } }"
                        >
                            {{--
                                Everything typed on one line: the two icons, the
                                box, and send. It was a paragraph-sized textarea
                                over a row of labelled buttons, which is a form
                                rather than a chat — and an operator working
                                twenty threads reads those four controls twenty
                                times to write one sentence.
                            --}}
                            <div class="chatbot-inbox__composer-row">
                                {{--
                                    The emoji are inserted where the cursor is
                                    and the cursor is put back after them, rather
                                    than appended to the draft. Appending sounds
                                    equivalent and is not: an operator who has
                                    gone back to fix a word mid-sentence would
                                    watch their smiley land at the far end of the
                                    paragraph, and the second one would land
                                    after that.
                                --}}
                                <div class="chatbot-inbox__emoji" x-data="{ open: false }">
                                    <x-filament::button
                                        type="button"
                                        color="gray"
                                        size="sm"
                                        icon="heroicon-o-face-smile"
                                        x-on:click="open = ! open"
                                    >
                                        {{-- Named for a screen reader, drawn as an icon. --}}
                                        <span class="sr-only">{{ __('chatbot::panel.inbox.emoji') }}</span>
                                    </x-filament::button>

                                    <div
                                        class="chatbot-inbox__emoji-pad"
                                        x-show="open"
                                        x-on:click.outside="open = false"
                                        x-cloak
                                    >
                                        @foreach ($this->emojiGroups() as $group => $characters)
                                            <div class="chatbot-inbox__emoji-row" wire:key="chatbot-emoji-{{ $group }}">
                                                @foreach ($characters as $character)
                                                    <button
                                                        type="button"
                                                        class="chatbot-inbox__emoji-pick"
                                                        {{--
                                                            The cursor is moved
                                                            by the character's
                                                            length in JavaScript
                                                            and not by its length
                                                            in PHP. `mb_strlen`
                                                            counts code points
                                                            and a string index in
                                                            a textarea counts
                                                            UTF-16 units, so
                                                            every emoji above the
                                                            basic plane — which
                                                            is most of the faces
                                                            — would leave the
                                                            caret one place short
                                                            and the next typed
                                                            letter inside it.
                                                        --}}
                                                        x-on:click="
                                                            const character = @js($character);
                                                            const field = $refs.body;
                                                            const at = field.selectionStart ?? field.value.length;
                                                            field.value = field.value.slice(0, at)
                                                                + character
                                                                + field.value.slice(field.selectionEnd ?? at);
                                                            field.dispatchEvent(new Event('input'));
                                                            field.focus();
                                                            field.selectionStart = field.selectionEnd = at + character.length;
                                                            open = false;
                                                        "
                                                    >{{ $character }}</button>
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                {{--
                                    A label wrapped round a hidden input rather
                                    than a styled `<input type="file">`: the
                                    browser's own control cannot be made to look
                                    like the buttons beside it, and one that
                                    looked different would read as belonging to
                                    something other than this composer.
                                --}}
                                <label class="chatbot-inbox__attach" title="{{ __('chatbot::panel.inbox.attach') }}">
                                    <x-filament::button
                                        tag="span"
                                        color="gray"
                                        size="sm"
                                        icon="heroicon-o-paper-clip"
                                    >
                                        <span class="sr-only">{{ __('chatbot::panel.inbox.attach') }}</span>
                                    </x-filament::button>

                                    <input
                                        type="file"
                                        class="sr-only"
                                        wire:model="attachment"
                                    >
                                </label>

                                <label class="sr-only" for="chatbot-inbox-body">{{ __('chatbot::panel.inbox.your_reply') }}</label>
                                <textarea
                                    id="chatbot-inbox-body"
                                    x-ref="body"
                                    wire:model="composerBody"
                                    rows="1"
                                    maxlength="{{ $this->maxLength() }}"
                                    placeholder="{{ __('chatbot::panel.inbox.reply_placeholder') }}"
                                    x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.sendReply() }"
                                ></textarea>

                                <x-filament::button
                                    type="submit"
                                    icon="heroicon-o-paper-airplane"
                                    size="sm"
                                    wire:loading.attr="disabled"
                                    wire:target="sendReply"
                                >
                                    {{--
                                        The word goes on a phone and the icon
                                        stays. It is kept in the markup either
                                        way rather than swapped for an
                                        `aria-label`, so the button reads the
                                        same to a screen reader at every width.
                                    --}}
                                    <span class="chatbot-inbox__send-text">{{ __('chatbot::panel.inbox.send') }}</span>
                                    <span class="sr-only">{{ __('chatbot::panel.inbox.send') }}</span>
                                </x-filament::button>
                            </div>

                            <div class="chatbot-inbox__composer-under">
                                {{--
                                    The stock phrases open upwards. The composer
                                    sits against the bottom of the window, and a
                                    list dropping down would open off screen.
                                --}}
                                <div class="chatbot-inbox__quick" x-data="{ open: false }">
                                    <x-filament::button
                                        type="button"
                                        color="gray"
                                        size="sm"
                                        icon="heroicon-o-bolt"
                                        x-on:click="open = ! open"
                                    >
                                        {{ __('chatbot::panel.inbox.quick_replies') }}
                                    </x-filament::button>

                                    <div
                                        class="chatbot-inbox__quick-pad"
                                        x-show="open"
                                        x-on:click.outside="open = false"
                                        x-cloak
                                    >
                                        @forelse ($this->quickReplies as $reply)
                                            <button
                                                type="button"
                                                class="chatbot-inbox__quick-pick"
                                                wire:click="applyQuickReply({{ $reply->getKey() }})"
                                                x-on:click="open = false"
                                            >
                                                {{ $reply->title }}
                                                <span>{{ str($reply->body)->limit(90) }}</span>
                                            </button>
                                        @empty
                                            <p class="chatbot-inbox__quick-pick">{{ __('chatbot::panel.inbox.no_quick_replies') }}</p>
                                        @endforelse
                                    </div>
                                </div>

                                <span class="chatbot-inbox__counter">
                                    <span x-text="length">0</span> / {{ $this->maxLength() }}
                                </span>
                            </div>

                            {{--
                                The picked file is named back to the operator
                                before it goes. Without it the only sign that
                                anything is attached is a file dialog they closed
                                a minute ago, and the reply that carries last
                                week's screenshot goes out looking exactly like
                                one that carries nothing.
                            --}}
                            @if ($this->attachmentName() !== '')
                                <p class="chatbot-inbox__attached">
                                    <x-filament::icon icon="heroicon-m-paper-clip" class="chatbot-inbox__file-icon" />
                                    <strong>{{ $this->attachmentName() }}</strong>
                                    <button
                                        type="button"
                                        wire:click="clearAttachment"
                                        title="{{ __('chatbot::panel.inbox.attach_remove') }}"
                                    >
                                        <x-filament::icon icon="heroicon-m-x-mark" class="chatbot-inbox__file-icon" />
                                    </button>
                                </p>
                            @endif

                            @error('composerBody')
                                <p class="chatbot-inbox__error">{{ $message }}</p>
                            @enderror

                            @error('attachment')
                                <p class="chatbot-inbox__error">{{ $message }}</p>
                            @enderror
                        </form>
                    @else
                        <p class="chatbot-inbox__closed">
                            {{ __('chatbot::panel.inbox.closed_notice') }}
                        </p>
                    @endif
                </div>
            @endif
        </section>
    </div>

    {{--
        One viewer for the whole thread, listening for a window event.

        Written once rather than per bubble: a thread is fifty messages long and
        fifty copies of a full-screen overlay is fifty Alpine components holding
        the same markup. It is deliberately not a Livewire modal either — opening
        a picture would be a round trip, and the thing being opened is already on
        the page.
    --}}
    <div
        x-data="{ src: null, alt: '' }"
        x-on:chatbot-picture.window="src = $event.detail.src; alt = $event.detail.alt"
        x-on:keydown.escape.window="src = null"
    >
        <div
            class="chatbot-inbox__viewer"
            x-show="src"
            x-on:click="src = null"
            role="dialog"
            aria-modal="true"
            x-cloak
        >
            {{-- The backdrop closes; the picture does not, or the click that lands on what the operator is looking at shuts it. --}}
            <img class="chatbot-inbox__viewer-image" x-bind:src="src" x-bind:alt="alt" x-on:click.stop>

            <button
                type="button"
                class="chatbot-inbox__viewer-close"
                x-on:click="src = null"
                aria-label="{{ __('chatbot::panel.inbox.attachment_close') }}"
            >
                <x-filament::icon icon="heroicon-m-x-mark" />
            </button>
        </div>
    </div>

    <x-filament-actions::modals />

    {{--
        The transcript opens at the bottom: a conversation is read from how it
        ended, and a reply that lands below the fold looks like it was not sent.
        The script lives here because it needs this page's `$wire`; `@script`
        runs once on mount and survives re-renders.

        **It only follows the bottom while the operator is already there.** The
        first version scrolled down after every morph, and this page polls: an
        operator reading back through a long thread was thrown to the newest
        message every few seconds, mid-sentence, with nothing on screen to
        explain it. Scrolling up is how somebody reads what a customer said
        before deciding what to answer, so it is the one thing the poll must not
        take away.

        Opening a different conversation still lands at the bottom. Without the
        conversation id, a thread opened while the previous one was scrolled up
        would inherit that decision and start halfway through somebody else's
        history.
    --}}
    @script
    <script>
        (() => {
            /*
             * A few pixels of slack. Browsers report fractional scroll heights
             * on zoomed and high-density displays, so an exact comparison says
             * "not at the bottom" for a thread that visibly is, and the desk
             * stops following a live conversation.
             */
            const SLACK = 48;

            const thread = () => document.querySelector('.chatbot-inbox__thread');

            const atBottom = (node) =>
                node.scrollHeight - node.scrollTop - node.clientHeight <= SLACK;

            let follow = true;
            let showing = null;

            const remember = () => {
                const node = thread();

                follow = !node || atBottom(node);
            };

            const settle = () => {
                const node = thread();

                if (!node) {
                    return;
                }

                const opened = node.dataset.conversation ?? null;

                if (opened !== showing) {
                    showing = opened;
                    follow = true;
                }

                if (follow) {
                    node.scrollTop = node.scrollHeight;
                }
            };

            Livewire.hook('morph.updating', remember);
            Livewire.hook('morphed', () => queueMicrotask(settle));
            queueMicrotask(settle);
        })();
    </script>
    @endscript
</x-filament-panels::page>
