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
            height: 100%;
            min-height: 32rem;
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

        .chatbot-inbox__quick {
            position: relative;
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
        <aside class="chatbot-inbox__rail" aria-label="Conversations">
            <div class="chatbot-inbox__head">
                <h2>Conversations</h2>
                <span class="chatbot-inbox__kicker">{{ $this->listSummary() }}</span>
            </div>

            <div class="chatbot-inbox__controls">
                <label class="sr-only" for="chatbot-inbox-search">Search by name, username, phone or e-mail</label>
                <input
                    id="chatbot-inbox-search"
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Name, username, phone"
                    autocomplete="off"
                >

                <label class="sr-only" for="chatbot-inbox-status">Status</label>
                <select id="chatbot-inbox-status" wire:model.live="statusFilter">
                    <option value="open">Open</option>
                    <option value="closed">Closed</option>
                    <option value="all">All</option>
                </select>

                <label class="sr-only" for="chatbot-inbox-owner">Assignee</label>
                <select id="chatbot-inbox-owner" wire:model.live="ownerFilter">
                    <option value="all">Anyone</option>
                    <option value="mine">Mine</option>
                    <option value="unassigned">Unassigned</option>
                </select>

                @if (count($channels) > 1)
                    <label class="sr-only" for="chatbot-inbox-channel">Channel</label>
                    <select id="chatbot-inbox-channel" wire:model.live="channelFilter">
                        <option value="">All channels</option>
                        @foreach ($channels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif

                <label class="chatbot-inbox__toggle">
                    <input type="checkbox" wire:model.live="onlyUnread">
                    <span>Unanswered only</span>
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
                                    <span class="chatbot-inbox__dot" title="Waiting for an answer"></span>
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
                    <p class="chatbot-inbox__empty">Nothing here yet.</p>
                @endforelse

                @if ($this->listIsCapped())
                    <p class="chatbot-inbox__empty">Showing the most recent conversations — narrow the search.</p>
                @endif
            </div>
        </aside>

        <section class="chatbot-inbox__stage" aria-live="polite">
            @if (! $record)
                <div class="chatbot-inbox__empty">
                    <h3>Pick a conversation</h3>
                    <p>Everything people have written, on every channel, is on the left.</p>
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
                        <span>The bot is answering this conversation. Your first reply takes it over.</span>
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
                            <p class="chatbot-inbox__bubble-foot">
                                <time datetime="{{ $message->created_at?->toIso8601String() }}">
                                    {{ $message->created_at?->format('d M H:i') }}
                                </time>
                                @unless ($message->isIncoming())
                                    @if ($message->failed_at !== null)
                                        <span>· not delivered: {{ $message->failure_reason }}</span>
                                    @elseif (! $message->isDelivered())
                                        <span>· sending…</span>
                                    @endif
                                @endunless
                            </p>
                        </div>
                    @empty
                        <p class="chatbot-inbox__empty">No messages yet.</p>
                    @endforelse
                </div>

                <div class="chatbot-inbox__composer">
                    @if ($record->status === \MB\FilamentChatbot\Enums\ConversationStatus::Open)
                        {{--
                            The counter is kept in the browser and only resynced
                            when the server changes the draft — inserting a stock
                            phrase does that. Bound live to the server instead, it
                            would cost a round trip and a re-render of the whole
                            desk on every pause in typing, to move one number.
                        --}}
                        <form
                            wire:submit="sendReply"
                            x-data="{ length: 0 }"
                            x-on:chatbot-draft-changed.window="$nextTick(() => length = ($refs.body?.value ?? '').length)"
                        >
                            <label class="sr-only" for="chatbot-inbox-body">Your reply</label>
                            <textarea
                                id="chatbot-inbox-body"
                                x-ref="body"
                                wire:model="composerBody"
                                rows="3"
                                maxlength="{{ $this->maxLength() }}"
                                placeholder="Write a reply…"
                                x-init="length = ($el.value ?? '').length"
                                x-on:input="length = $event.target.value.length"
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
                                        Quick replies
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
                                            <p class="chatbot-inbox__quick-pick">No stock phrases yet.</p>
                                        @endforelse
                                    </div>
                                </div>

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
                                    Send
                                </x-filament::button>
                            </div>

                            @error('composerBody')
                                <p class="chatbot-inbox__error">{{ $message }}</p>
                            @enderror
                        </form>
                    @else
                        <p class="chatbot-inbox__closed">
                            This conversation is closed. Reopen it to reply, or wait for the next message.
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
