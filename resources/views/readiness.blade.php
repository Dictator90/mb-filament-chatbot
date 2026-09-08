{{--
    Is the bot ready, and what is in the way.

    The styling is a <style> block in the page rather than a stylesheet asset,
    for the same reason the desk and the sandbox carry theirs: a package cannot
    rely on the host's Tailwind build having scanned its vendor directory, so
    utility classes written here may simply not exist in the compiled panel CSS
    and the screen would arrive unstyled with nothing to say why. Everything is
    scoped under `.chatbot-readiness` so none of it can reach the rest of the
    panel.

    Dark is expressed as `.dark` and never as `prefers-color-scheme`. Filament
    resolves the theme itself — in its "system" setting too — and marks only
    dark; a media query here would overrule that and hand a dark panel to
    somebody who chose light on a dark machine.
--}}
<x-filament-panels::page>
    <style>
        .chatbot-readiness {
            --cr-line: rgb(228 228 231);
            --cr-muted: rgb(113 113 122);
            --cr-surface: rgb(255 255 255);
            --cr-raised: rgb(244 244 245);

            display: grid;
            gap: 1.5rem;
        }

        .dark .chatbot-readiness {
            --cr-line: rgb(63 63 70);
            --cr-muted: rgb(161 161 170);
            --cr-surface: rgb(24 24 27);
            --cr-raised: rgb(39 39 42);
        }

        .chatbot-readiness__tiles {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
            gap: 0.75rem;
        }

        .chatbot-readiness__tile {
            padding: 1rem;
            border: 1px solid var(--cr-line);
            border-radius: 0.75rem;
            background: var(--cr-surface);
        }

        .chatbot-readiness__label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--cr-muted);
        }

        .chatbot-readiness__value {
            margin-top: 0.25rem;
            font-size: 1.5rem;
            font-weight: 600;
            line-height: 1.2;
        }

        .chatbot-readiness__note {
            margin-top: 0.375rem;
            font-size: 0.8125rem;
            color: var(--cr-muted);
        }

        /*
            Tone is a stripe down the side rather than a coloured number. The
            value is the thing being read, and a red numeral is harder to read
            than a black one — the colour belongs beside it, not in it.
        */
        .chatbot-readiness__tile[data-tone='success'] { border-left: 3px solid rgb(22 163 74); }
        .chatbot-readiness__tile[data-tone='warning'] { border-left: 3px solid rgb(217 119 6); }
        .chatbot-readiness__tile[data-tone='danger'] { border-left: 3px solid rgb(220 38 38); }
        .chatbot-readiness__tile[data-tone='gray'] { border-left: 3px solid var(--cr-line); }

        .chatbot-readiness__next {
            padding: 1rem 1.25rem;
            border: 1px solid var(--cr-line);
            border-radius: 0.75rem;
            background: var(--cr-surface);
        }

        .chatbot-readiness__heading {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--cr-muted);
        }

        .chatbot-readiness__todos {
            display: grid;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .chatbot-readiness__todo {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.625rem 0.75rem;
            border-radius: 0.5rem;
            background: var(--cr-raised);
            font-size: 0.875rem;
        }

        .chatbot-readiness__todo[data-tone='danger'] { box-shadow: inset 3px 0 0 rgb(220 38 38); }
        .chatbot-readiness__todo[data-tone='warning'] { box-shadow: inset 3px 0 0 rgb(217 119 6); }
        .chatbot-readiness__todo[data-tone='success'] { box-shadow: inset 3px 0 0 rgb(22 163 74); }

        .chatbot-readiness__open {
            flex: none;
            font-weight: 600;
            text-decoration: underline;
        }
    </style>

    <div class="chatbot-readiness">
        <div class="chatbot-readiness__tiles">
            @foreach ($this->summary['tiles'] as $tile)
                <div class="chatbot-readiness__tile" data-tone="{{ $tile['tone'] }}" data-key="{{ $tile['key'] }}">
                    <div class="chatbot-readiness__label">{{ $tile['label'] }}</div>
                    <div class="chatbot-readiness__value">{{ $tile['value'] }}</div>
                    <div class="chatbot-readiness__note">{{ $tile['note'] }}</div>
                </div>
            @endforeach
        </div>

        <div class="chatbot-readiness__next">
            <div class="chatbot-readiness__heading">{{ __('chatbot::panel.readiness.what_next') }}</div>

            <div class="chatbot-readiness__todos">
                @foreach ($this->summary['todos'] as $todo)
                    @php($link = $this->linkFor($todo['target'], $todo['record']))

                    <div class="chatbot-readiness__todo" data-tone="{{ $todo['tone'] }}">
                        <span>{{ $todo['text'] }}</span>

                        @if ($link !== null)
                            <a class="chatbot-readiness__open" href="{{ $link }}">
                                {{ __('chatbot::panel.readiness.open') }}
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-panels::page>
