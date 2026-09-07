<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use MB\FilamentChatbot\Channels\WidgetTheme;
use MB\FilamentChatbot\Http\WidgetDraft;
use MB\FilamentChatbot\Models\Widget;

/**
 * The page inside the preview frame on the widget form.
 *
 * **It draws no widget of its own.** It serves a bare page, puts the real
 * `widget.js` on it, and answers the four calls that script makes with the
 * draft being edited. That division is the whole design: a preview that painted
 * its own bubbles would be a second implementation of the widget, it would
 * disagree with the first one the week after either changed, and the
 * disagreement would show up as "the preview lied" — the one failure a preview
 * cannot survive. Faking the server is safe; faking the widget is not.
 *
 * **Not a public page.** It is a screen of the panel that happens to live at its
 * own address, and it is authorised as one: the panel named in the path decides
 * which guard is asked, and the widget policy decides whether that person may
 * see it. Left open it would be an unauthenticated endpoint that renders
 * arbitrary text from the query string in a chat bubble on this application's
 * own origin.
 */
class WidgetPreviewController
{
    public function __invoke(Request $request, string $panel): Response
    {
        $this->authorise($panel);

        $draft = WidgetDraft::decode((string) $request->query('draft'));

        /*
         * Through the same gate as a saved widget, and for the same reason: the
         * values end up inside a `style` attribute. A preview that rendered
         * whatever the query string said would both accept CSS the real widget
         * refuses — so it would show a design that cannot be saved — and turn
         * an authenticated page into a place to write style from a URL.
         */
        $theme = WidgetTheme::make((array) ($draft['theme'] ?? []));

        return response()->view('chatbot::widget-preview-frame', [
            'scriptUrl' => route('chatbot.widget.script'),
            'greeting' => trim((string) ($draft['greeting'] ?? '')),
            /*
             * One bag rather than five variables, because it goes into the page
             * as one JSON object for the stubbed server to read. Assembled here
             * and not in the view: a `@json` of a literal array is a directive
             * argument spanning six lines, which Blade parses as far as the
             * first bracket it recognises and then fails on the rest.
             */
            'preview' => [
                'properties' => $theme->properties(),
                'flags' => $theme->flags(),
                'labels' => $this->labels((array) ($draft['labels'] ?? [])),
                'transcript' => $this->transcript(),
                'reply' => (string) __('chatbot::panel.site_widgets.preview.sample_reply'),
            ],
        ])->withHeaders([
            /*
             * Framed by this application's own panel and by nothing else. The
             * page is authenticated, so without this a hostile site could frame
             * it and read a staff member's session through their clicks.
             */
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * The panel's own guard, then the widget policy.
     *
     * `Filament::auth()` and not `auth()`: this route is outside the panel's
     * path, so nothing has set the current panel, and the application default
     * guard is not the panel's on any host that signs staff in separately. The
     * panel is named in the URL and set here, which is what makes the guard the
     * right one rather than whichever panel happens to be marked default.
     */
    protected function authorise(string $panel): void
    {
        $found = Filament::getPanel($panel, isStrict: false);

        abort_if($found === null, 404);

        Filament::setCurrentPanel($found);

        $user = Filament::auth()->user();

        abort_if($user === null, 403);
        abort_if(Gate::forUser($user)->denies('viewAny', Widget::class), 403);
    }

    /**
     * The chrome's wording: the draft's own where it has any, this
     * installation's language everywhere else.
     *
     * Falling back to the widget's translations rather than to the script's
     * built-in English, because a form with an empty title field is the ordinary
     * state and the preview of it should look like the widget that ships — not
     * like an English one on a Russian installation.
     *
     * @param  array<string, mixed>  $labels
     * @return array<string, string>
     */
    protected function labels(array $labels): array
    {
        $defaults = [
            'title' => __('chatbot::widget.title'),
            'launcher' => __('chatbot::widget.launcher'),
            'placeholder' => __('chatbot::widget.placeholder'),
            'send' => __('chatbot::widget.send'),
            'close' => __('chatbot::widget.close'),
            'failed' => __('chatbot::widget.failed'),
        ];

        $chosen = [];

        foreach ($defaults as $name => $default) {
            $value = trim((string) ($labels[$name] ?? ''));

            $chosen[$name] = $value !== '' ? $value : (string) $default;
        }

        return $chosen;
    }

    /**
     * A short conversation to look at.
     *
     * An empty log would hide most of what is being chosen here: the bubble
     * shape, the agent colour, the avatar and the way a run of messages is
     * grouped are all invisible until somebody has said something. Two lines
     * cost nothing and make the picture answer the question the form is asking.
     *
     * @return list<array{id: int, body: string, incoming: bool, author: string}>
     */
    protected function transcript(): array
    {
        return [
            [
                'id' => 1,
                'body' => (string) __('chatbot::panel.site_widgets.preview.sample_visitor'),
                'incoming' => true,
                'author' => 'visitor',
            ],
            [
                'id' => 2,
                'body' => (string) __('chatbot::panel.site_widgets.preview.sample_agent'),
                'incoming' => false,
                'author' => 'bot',
            ],
        ];
    }
}
