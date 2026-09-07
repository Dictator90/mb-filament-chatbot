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
        .chatbot-inbox {
            --ci-line: rgb(228 228 231);
            --ci-muted: rgb(113 113 122);
            --ci-surface: rgb(255 255 255);
            --ci-raised: rgb(244 244 245);
            --ci-in: rgb(244 244 245);
            --ci-out: rgb(224 231 255);
            --ci-bot: rgb(237 233 254);

            display: grid;
            grid-template-columns: minmax(0, 20rem) minmax(0, 1fr);
            gap: 1rem;
            /*
                The desk is given the window rather than its content, because a
                transcript is as tall as the conversation is long. Left to grow,
                it pushes the reply box below the fold — and the operator's
                answer to a long conversation is the one they cannot reach
                without scrolling past everything they have just read.

                `dvh` rather than `vh`: on a phone the browser's own bars are
                part of the viewport under `vh`, and the box would sit behind
                them. The subtraction is the panel's topbar and page padding.
            */
            height: calc(100dvh - 7rem);
            min-height: 26rem;
        }

        .dark .chatbot-inbox {
            --ci-line: rgb(63 63 70);
            --ci-muted: rgb(161 161 170);
            --ci-surface: rgb(24 24 27);
            --ci-raised: rgb(39 39 42);
            --ci-in: rgb(39 39 42);
            --ci-out: rgb(49 46 129);
            --ci-bot: rgb(59 45 95);
        }

        @media (max-width: 64rem) {
            .chatbot-inbox {
                grid-template-columns: minmax(0, 1fr);
            }

            .chatbot-inbox.is-open .chatbot-inbox__rail {
                display: none;
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
            border: 1px solid var(--ci-line);
            border-radius: 0.75rem;
            background: var(--ci-surface);
        }

        .chatbot-inbox__head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.5rem;
            padding: 0.75rem 0.875rem 0.5rem;
        }

        .chatbot-inbox__head h2 {
            font-size: 0.95rem;
            font-weight: 600;
        }

        .chatbot-inbox__kicker {
            font-size: 0.72rem;
            color: var(--ci-muted);
        }

        .chatbot-inbox__controls {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
            padding: 0 0.875rem 0.625rem;
        }

        .chatbot-inbox__controls input[type='search'],
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
            display: block;
            width: 100%;
            padding: 0.6rem 0.875rem;
            text-align: start;
            border-bottom: 1px solid var(--ci-line);
        }

        .chatbot-inbox__row:hover {
            background: var(--ci-raised);
        }

        .chatbot-inbox__row.is-active {
            background: var(--ci-raised);
            box-shadow: inset 3px 0 0 currentColor;
        }

        .chatbot-inbox__row-top {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .chatbot-inbox__row-name {
            font-size: 0.83rem;
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .chatbot-inbox__row.has-unread .chatbot-inbox__row-name {
            font-weight: 700;
        }

        .chatbot-inbox__row-time,
        .chatbot-inbox__row-preview {
            font-size: 0.72rem;
            color: var(--ci-muted);
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
            margin-top: 0.3rem;
        }

        .chatbot-inbox__tag {
            padding: 0.05rem 0.35rem;
            font-size: 0.65rem;
            color: var(--ci-muted);
            background: var(--ci-raised);
            border: 1px solid var(--ci-line);
            border-radius: 0.375rem;
        }

        .chatbot-inbox__dot {
            display: inline-block;
            width: 0.5rem;
            height: 0.5rem;
            background: rgb(245 158 11);
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

        .chatbot-inbox__stage-head {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            padding: 0.625rem 0.875rem;
            border-bottom: 1px solid var(--ci-line);
        }

        .chatbot-inbox__who {
            flex: 1 1 12rem;
            min-width: 0;
        }

        .chatbot-inbox__who h3 {
            font-size: 0.95rem;
            font-weight: 600;
        }

        .chatbot-inbox__who p {
            font-size: 0.72rem;
            color: var(--ci-muted);
        }

        .chatbot-inbox__banner {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            padding: 0.5rem 0.875rem;
            font-size: 0.78rem;
            background: var(--ci-bot);
        }

        .chatbot-inbox__thread {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            gap: 0.5rem;
            min-height: 12rem;
            padding: 0.875rem;
            overflow-y: auto;
        }

        .chatbot-inbox__bubble {
            max-width: min(44rem, 80%);
            padding: 0.45rem 0.65rem;
            font-size: 0.83rem;
            background: var(--ci-in);
            border-radius: 0.75rem;
        }

        .chatbot-inbox__bubble.is-outgoing {
            align-self: flex-end;
            background: var(--ci-out);
        }

        .chatbot-inbox__bubble.is-bot {
            background: var(--ci-bot);
        }

        .chatbot-inbox__bubble-meta,
        .chatbot-inbox__bubble-foot {
            font-size: 0.66rem;
            color: var(--ci-muted);
        }

        .chatbot-inbox__bubble-text {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .chatbot-inbox__files {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            margin-top: 0.35rem;
        }

        /*
            A picture is shown small and links to itself. Full size it would push
            the question underneath it off the screen, and the operator scrolls
            past a photograph of a parcel to find the sentence that came with it.
        */
        .chatbot-inbox__files img {
            max-width: min(18rem, 100%);
            max-height: 14rem;
            border-radius: 0.5rem;
            object-fit: cover;
        }

        .chatbot-inbox__file {
            display: inline-flex;
            gap: 0.35rem;
            align-items: center;
            font-size: 0.78rem;
            text-decoration: underline;
            overflow-wrap: anywhere;
        }

        .chatbot-inbox__composer {
            padding: 0.625rem 0.875rem;
            border-top: 1px solid var(--ci-line);
        }

        .chatbot-inbox__composer textarea {
            width: 100%;
            padding: 0.45rem 0.6rem;
            font-size: 0.83rem;
            color: inherit;
            background: var(--ci-raised);
            border: 1px solid var(--ci-line);
            border-radius: 0.5rem;
            resize: vertical;
        }

        .chatbot-inbox__composer-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            margin-top: 0.5rem;
        }

        .chatbot-inbox__counter {
            font-size: 0.7rem;
            color: var(--ci-muted);
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
                <h2>{{ __('chatbot::panel.inbox.conversations') }}</h2>
                <span class="chatbot-inbox__kicker">{{ $this->listSummary() }}</span>
            </div>

            <div class="chatbot-inbox__controls">
                <label class="sr-only" for="chatbot-inbox-search">{{ __('chatbot::panel.inbox.search_label') }}</label>
                <input
                    id="chatbot-inbox-search"
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="{{ __('chatbot::panel.inbox.search_placeholder') }}"
                    autocomplete="off"
                >

                <label class="sr-only" for="chatbot-inbox-status">{{ __('chatbot::panel.inbox.status') }}</label>
                <select id="chatbot-inbox-status" wire:model.live="statusFilter">
                    <option value="open">{{ __('chatbot::panel.inbox.status_open') }}</option>
                    <option value="closed">{{ __('chatbot::panel.inbox.status_closed') }}</option>
                    <option value="all">{{ __('chatbot::panel.inbox.status_all') }}</option>
                </select>

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
                                <span class="chatbot-inbox__tag">{{ $conversation->bot?->name ?? 'bot' }}</span>
                            @endif

                            <span class="chatbot-inbox__tag">{{ $this->ownerLabel($conversation) }}</span>
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
                    <div class="chatbot-inbox__who">
                        <h3>{{ $record->title() }}</h3>
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

                    @if ($this->canReturnToBot())
                        {{ $this->returnToBotAction }}
                    @endif
                </header>

                @if ($this->botIsAnswering())
                    <div class="chatbot-inbox__banner">
                        <span>{{ __('chatbot::panel.inbox.bot_banner') }}</span>
                    </div>
                @endif

                <div class="chatbot-inbox__thread" wire:key="chatbot-thread-{{ $record->getKey() }}">
                    @forelse ($messages as $message)
                        <div
                            wire:key="chatbot-msg-{{ $message->getKey() }}"
                            @class([
                                'chatbot-inbox__bubble',
                                'is-outgoing' => ! $message->isIncoming(),
                                'is-bot' => $message->author === \MB\FilamentChatbot\Enums\MessageAuthor::Bot,
                            ])
                        >
                            <p class="chatbot-inbox__bubble-meta">{{ $this->authorLabel($message, $record) }}</p>
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
                                            <a
                                                href="{{ $this->attachmentUrl($file) }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <img src="{{ $this->attachmentUrl($file) }}" alt="{{ $file->name }}">
                                            </a>
                                        @else
                                            <a
                                                class="chatbot-inbox__file"
                                                href="{{ $this->attachmentUrl($file) }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                title="{{ __('chatbot::panel.inbox.attachment_download', ['name' => $file->name]) }}"
                                            >
                                                📎 {{ $file->name }}
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                            <p class="chatbot-inbox__bubble-foot">
                                <time datetime="{{ $message->created_at?->toIso8601String() }}">
                                    {{ $message->created_at?->format('d M H:i') }}
                                </time>
                                @unless ($message->isIncoming())
                                    @if ($message->failed_at !== null)
                                        <span>· {{ __('chatbot::panel.inbox.not_delivered', ['reason' => $message->failure_reason]) }}</span>
                                    @elseif (! $message->isDelivered())
                                        <span>· {{ __('chatbot::panel.inbox.sending') }}</span>
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
                            <label class="sr-only" for="chatbot-inbox-body">{{ __('chatbot::panel.inbox.your_reply') }}</label>
                            <textarea
                                id="chatbot-inbox-body"
                                x-ref="body"
                                wire:model="composerBody"
                                rows="3"
                                maxlength="{{ $this->maxLength() }}"
                                placeholder="{{ __('chatbot::panel.inbox.reply_placeholder') }}"
                                x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.sendReply() }"
                            ></textarea>

                            <div class="chatbot-inbox__composer-bar">
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
                                        {{ __('chatbot::panel.inbox.emoji') }}
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
                                <label class="chatbot-inbox__file" title="{{ __('chatbot::panel.inbox.attach') }}">
                                    <x-filament::button
                                        tag="span"
                                        color="gray"
                                        size="sm"
                                        icon="heroicon-o-paper-clip"
                                    >
                                        {{ __('chatbot::panel.inbox.attach') }}
                                    </x-filament::button>

                                    <input
                                        type="file"
                                        class="sr-only"
                                        wire:model="attachment"
                                    >
                                </label>

                                <span class="chatbot-inbox__counter">
                                    <span x-text="length">0</span> / {{ $this->maxLength() }}
                                </span>

                                <x-filament::button
                                    type="submit"
                                    icon="heroicon-o-paper-airplane"
                                    size="sm"
                                    wire:loading.attr="disabled"
                                    wire:target="sendReply"
                                >
                                    {{ __('chatbot::panel.inbox.send') }}
                                </x-filament::button>
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
                                    <span>📎</span>
                                    <strong>{{ $this->attachmentName() }}</strong>
                                    <button
                                        type="button"
                                        wire:click="clearAttachment"
                                        title="{{ __('chatbot::panel.inbox.attach_remove') }}"
                                    >×</button>
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

    <x-filament-actions::modals />

    {{--
        The transcript opens at the bottom: a conversation is read from how it
        ended, and a reply that lands below the fold looks like it was not sent.
        The script lives here because it needs this page's `$wire`; `@script`
        runs once on mount and survives re-renders.
    --}}
    @script
    <script>
        (() => {
            const toBottom = () => {
                const thread = document.querySelector('.chatbot-inbox__thread');

                if (thread) {
                    thread.scrollTop = thread.scrollHeight;
                }
            };

            Livewire.hook('morphed', () => queueMicrotask(toBottom));
            queueMicrotask(toBottom);
        })();
    </script>
    @endscript
</x-filament-panels::page>
