{{--
    Hosts the scenario canvas.

    The whole page is `wire:ignore`, and that is the single most important line
    here. Livewire re-renders by patching the DOM it believes it owns; React
    owns this subtree and moves nodes around inside it. Without the guard the two
    fight over the same elements, and the visible result is a canvas that
    silently resets while somebody is dragging on it.

    Everything the canvas starts with travels in data attributes rather than in a
    script tag, so the payload survives a Livewire navigation without a second
    parse and without a global.
--}}
<x-filament-panels::page>
    {{--
        The canvas bundle is registered with loadedOnRequest(), so it is not in
        the panel's global assets and has to be asked for here. That is the point
        of the flag: most of a megabyte of React and a graph library, downloaded
        by the one screen that draws a graph rather than by everyone who opens
        the panel.
    --}}
    @push('styles')
        <link rel="stylesheet" href="{{ \Filament\Support\Facades\FilamentAsset::getStyleHref('chatbot-graph', 'mb4it/filament-chatbot') }}" />
    @endpush

    @push('scripts')
        <script src="{{ \Filament\Support\Facades\FilamentAsset::getScriptSrc('chatbot-graph', 'mb4it/filament-chatbot') }}" defer></script>
    @endpush

    <div
        wire:ignore
        class="chatbot-graph"
    >
        <div
            id="chatbot-graph-root"
            class="chatbot-graph__canvas"
            data-config="{{ json_encode($this->graphConfig(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
            data-graph="{{ json_encode($this->graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
            {{--
                A sandbox conversation begun before a reload comes back with the
                page. Without it the transcript is thrown away every time
                somebody presses F5, and nothing that takes more than one message
                — which is every scenario worth drawing — can be tried at all.
            --}}
            data-sandbox="{{ json_encode($this->sandboxState(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
        ></div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
