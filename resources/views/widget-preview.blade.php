{{--
    The frame that carries the preview, and nothing else.

    Deliberately dumb: an iframe, a switch and a caption. Everything that
    decides what is inside it is worked out before the view is reached, so there
    is no second place where the preview could disagree with the widget.

    **One iframe, moved by a class — never a second one for the big view.** Two
    frames would be two loads of the same page and two conversations against the
    stub, and the moment their addresses were built a fraction apart the small
    preview and the large one would show different widgets. Re-parenting the
    node with `x-teleport` is out for the reason it looks tempting: moving an
    iframe in the DOM reloads it, so a chat somebody had open would empty itself
    the instant they pressed the button.
--}}
<div
    class="chatbot-widget-preview"
    x-data="{ full: false }"
    x-bind:class="full ? 'chatbot-widget-preview--full' : ''"
    x-on:keydown.escape.window="full = false"
>
    <div class="chatbot-widget-preview__bar">
        <button
            type="button"
            class="chatbot-widget-preview__toggle"
            x-on:click="full = ! full"
            x-text="full
                ? @js(__('chatbot::panel.site_widgets.preview.collapse'))
                : @js(__('chatbot::panel.site_widgets.preview.expand'))"
        ></button>
    </div>

    <iframe
        src="{{ $previewUrl }}"
        title="{{ __('chatbot::panel.site_widgets.preview.title') }}"
        loading="lazy"
        referrerpolicy="no-referrer"
    ></iframe>

    <p class="chatbot-widget-preview__note">
        {{ __('chatbot::panel.site_widgets.preview.note') }}
    </p>
</div>

<style>
    /*
        Docked, the frame is as tall as the screen allows. It used to be a fixed
        420 pixels inside a third of a column: a launcher, a sliver of panel,
        and no way to judge the thing being configured without saving it and
        opening the real site.
    */
    .chatbot-widget-preview iframe {
        display: block;
        width: 100%;
        height: min(68vh, 760px);
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: #ffffff;
    }

    .dark .chatbot-widget-preview iframe {
        border-color: rgb(51 65 85);
        background: #0f172a;
    }

    .chatbot-widget-preview__bar {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 0.375rem;
    }

    .chatbot-widget-preview__toggle {
        padding: 0.2rem 0.5rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.5rem;
        background: #ffffff;
        color: rgb(71 85 105);
        font-size: 0.75rem;
        cursor: pointer;
    }

    .dark .chatbot-widget-preview__toggle {
        border-color: rgb(51 65 85);
        background: rgb(15 23 42);
        color: rgb(203 213 225);
    }

    /*
        The whole window, over the panel.

        A widget is designed against a page, and a column three hundred pixels
        wide is not one: the bubbles wrap differently, the launcher sits
        somewhere it never will, and every judgement made in that column has to
        be made again on the real site.
    */
    .chatbot-widget-preview--full {
        position: fixed;
        inset: 0;
        z-index: 40;
        display: flex;
        flex-direction: column;
        padding: 0.75rem;
        background: rgb(241 245 249);
    }

    .dark .chatbot-widget-preview--full {
        background: rgb(2 6 23);
    }

    .chatbot-widget-preview--full iframe {
        flex: 1 1 auto;
        height: auto;
    }

    .chatbot-widget-preview__note {
        margin-top: 0.5rem;
        font-size: 0.75rem;
        line-height: 1.4;
        color: rgb(100 116 139);
    }

    .dark .chatbot-widget-preview__note {
        color: rgb(148 163 184);
    }
</style>
