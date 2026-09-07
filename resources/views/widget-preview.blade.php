{{--
    The frame that carries the preview, and nothing else.

    Deliberately dumb: an iframe and a caption. Everything that decides what is
    inside it is worked out before the view is reached, so there is no second
    place where the preview could disagree with the widget.
--}}
<div class="chatbot-widget-preview">
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
    .chatbot-widget-preview iframe {
        display: block;
        width: 100%;
        height: 420px;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background: #ffffff;
    }

    .dark .chatbot-widget-preview iframe {
        border-color: rgb(51 65 85);
        background: #0f172a;
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
