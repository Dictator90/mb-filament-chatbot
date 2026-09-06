{{--
    Trying a whole bot out.

    The styling is a <style> block in the page rather than a stylesheet asset,
    for the same reason the desk carries its own: a package cannot rely on the
    host's Tailwind build having scanned its vendor directory, so utility classes
    written here may simply not exist in the compiled panel CSS and the screen
    would arrive unstyled with nothing to say why. Everything is scoped under
    `.chatbot-sandbox` so none of it can reach the rest of the panel.

    Dark is expressed as `.dark` and never as `prefers-color-scheme`. Filament
    resolves the theme itself — in its "system" setting too — and marks only
    dark; a media query here would overrule that and hand a dark panel to
    somebody who chose light on a dark machine.
--}}
<x-filament-panels::page>
    <style>
        .chatbot-sandbox {
            --cs-line: rgb(228 228 231);
            --cs-muted: rgb(113 113 122);
            --cs-surface: rgb(255 255 255);
            --cs-raised: rgb(244 244 245);
            --cs-bot: rgb(237 233 254);

            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 22rem);
            gap: 1rem;
            align-items: start;
        }

        .dark .chatbot-sandbox {
            --cs-line: rgb(63 63 70);
            --cs-muted: rgb(161 161 170);
            --cs-surface: rgb(24 24 27);
            --cs-raised: rgb(39 39 42);
            --cs-bot: rgb(59 45 95);
        }

        @media (max-width: 64rem) {
            .chatbot-sandbox {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        .chatbot-sandbox__panel {
            border: 1px solid var(--cs-line);
            border-radius: 0.75rem;
            background: var(--cs-surface);
            padding: 0.75rem;
        }

        .chatbot-sandbox__head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .chatbot-sandbox__spacer {
            flex: 1;
        }

        .chatbot-sandbox__muted {
            color: var(--cs-muted);
            font-size: 0.8125rem;
        }

        /*
            The transcript scrolls inside its own box. Left to grow it would push
            the composer below the fold, and the box somebody types into is the
            one thing here that has to stay reachable.
        */
        .chatbot-sandbox__transcript {
            max-height: 26rem;
            min-height: 10rem;
            overflow-y: auto;
            padding: 0.5rem;
            border: 1px solid var(--cs-line);
            border-radius: 0.5rem;
            background: var(--cs-raised);
        }

        .chatbot-sandbox__line {
            margin: 0 0 0.375rem;
            padding: 0.35rem 0.5rem;
            border-radius: 0.5rem;
            background: var(--cs-surface);
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .chatbot-sandbox__line.is-bot {
            background: var(--cs-bot);
        }

        .chatbot-sandbox__composer {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .chatbot-sandbox__composer input,
        .chatbot-sandbox select {
            padding: 0.4rem 0.5rem;
            border: 1px solid var(--cs-line);
            border-radius: 0.5rem;
            background: var(--cs-raised);
            color: inherit;
            font: inherit;
        }

        .chatbot-sandbox__composer input {
            flex: 1;
            min-width: 0;
        }

        .chatbot-sandbox__trace {
            margin: 0;
            padding: 0;
            list-style: none;
            font-size: 0.8125rem;
        }

        .chatbot-sandbox__trace li {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.5rem;
            padding: 0.25rem 0;
            border-bottom: 1px solid var(--cs-line);
        }

        .chatbot-sandbox__trace code {
            overflow-wrap: anywhere;
        }
    </style>

    <div class="chatbot-sandbox">
        <div class="chatbot-sandbox__panel">
            <div class="chatbot-sandbox__head">
                <label for="chatbot-sandbox-bot" class="chatbot-sandbox__muted">Bot</label>

                <select id="chatbot-sandbox-bot" wire:model.live="botId">
                    @foreach ($this->bots as $bot)
                        <option value="{{ $bot->getKey() }}">
                            {{ $bot->name }}@unless ($bot->is_active) (switched off)@endunless
                        </option>
                    @endforeach
                </select>

                <span class="chatbot-sandbox__spacer"></span>

                @if ($this->conversationState)
                    <span class="chatbot-sandbox__muted">The conversation is {{ $this->conversationState }}.</span>
                @endif

                <x-filament::button size="sm" color="gray" wire:click="restart">
                    Restart
                </x-filament::button>
            </div>

            <div class="chatbot-sandbox__transcript">
                @forelse ($this->transcript as $line)
                    <p class="chatbot-sandbox__line is-{{ $line['from'] }}">{{ $line['body'] }}</p>
                @empty
                    {{--
                        Says which question this screen answers. The canvas has a
                        sandbox of its own that enters one scenario on purpose;
                        here the routing decides, and somebody who does not know
                        that reads a scenario answering out of turn as a bug in
                        the sandbox.
                    --}}
                    <p class="chatbot-sandbox__muted">
                        Say something. The bot routes it exactly as it would a visitor's message, so this is
                        where a scenario claiming words that belong to another one shows up.
                    </p>
                @endforelse
            </div>

            <form class="chatbot-sandbox__composer" wire:submit="send">
                <input
                    type="text"
                    wire:model="said"
                    placeholder="Say something to the bot"
                    autocomplete="off"
                />

                <x-filament::button type="submit">
                    Send
                </x-filament::button>
            </form>
        </div>

        <div class="chatbot-sandbox__panel">
            <p class="chatbot-sandbox__muted">Nodes it walked</p>

            @if (count($this->trace) === 0)
                <p class="chatbot-sandbox__muted">Nothing has run yet.</p>
            @else
                <ol class="chatbot-sandbox__trace">
                    @foreach ($this->trace as $step)
                        <li>
                            <code>{{ $step['scenario'] }}/{{ $step['key'] }}</code>
                            <span class="chatbot-sandbox__muted">{{ $step['outcome'] }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
</x-filament-panels::page>
