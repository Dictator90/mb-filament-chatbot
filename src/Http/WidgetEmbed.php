<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http;

/**
 * The script tag a site owner pastes onto their pages.
 *
 * Written here rather than in the panel screen that shows it, and built from
 * the route rather than from a string, because the snippet is a promise: what
 * it says is where the widget answers. A hand-typed example in a form — or in
 * the documentation somebody copies from — drifts the moment the route prefix
 * or the application URL changes, and the failure it produces is a widget that
 * loads perfectly on a customer's site and talks to nothing. Deriving the URL
 * from `route('chatbot.widget.script')` means the snippet cannot disagree with
 * the installation it was copied from.
 *
 * Two things decide whether the pasted tag will actually open a conversation,
 * and neither is on the bot: the widget must be switched on, and the embedding
 * site must be on the allow-list — which is `CHATBOT_WIDGET_ORIGINS`, empty by
 * default and blocking every site when it is. Showing the snippet without
 * saying so hands somebody a tag that installs cleanly and answers 403 on the
 * first message, from a page they do not own and cannot debug.
 */
final class WidgetEmbed
{
    /**
     * The address the tag points at, from the route table.
     */
    public static function scriptUrl(): string
    {
        return route('chatbot.widget.script');
    }

    /**
     * The tag itself, naming the bot by slug.
     *
     * By slug and never by id: the tag lives on a public page, where an id is
     * both meaningless to whoever maintains that page and a number anybody can
     * change to point at a different bot. The widget reads `data-bot` and the
     * endpoint looks the slug up, so the two ends already agree on this.
     */
    public static function snippet(string $slug): string
    {
        return '<script src="'.self::scriptUrl().'" async'."\n"
            .'        data-bot="'.$slug.'"></script>';
    }

    /**
     * The origins on which a given bot actually answers.
     *
     * Two lists decide that, and the panel has to report their combination
     * rather than whichever is nearer to hand. `chatbot.widget.origins` is the
     * guard: it says which sites may reach these endpoints at all, and an empty
     * one admits nobody. A bot's own `allowed_origins` narrows that further —
     * which of those sites carry *this* bot — and an empty one narrows nothing.
     *
     * Reporting only the configuration would answer a different question from
     * the one the request is judged by, and be believed: somebody restricts a
     * bot to one site, reads a list of five here, and pastes the tag onto a page
     * where that bot will not answer.
     *
     * @param  iterable<mixed>|null  $botOrigins  the bot's own list, if it names any
     * @return list<string>
     */
    public static function allowedOrigins(?iterable $botOrigins = null): array
    {
        $clean = static function (mixed $list): array {
            $origins = [];

            foreach ((array) $list as $origin) {
                $origin = trim((string) $origin);

                if ($origin !== '') {
                    $origins[] = $origin;
                }
            }

            return $origins;
        };

        $allowed = $clean(config('chatbot.widget.origins', []));
        $bot = $clean($botOrigins);

        return $bot === [] ? $allowed : array_values(array_intersect($allowed, $bot));
    }

    /**
     * Whether a tag copied right now would open a conversation.
     *
     * The widget being switched off and the allow-list being empty fail the
     * same way — a 403 from the endpoint, on somebody else's page — so both are
     * asked here rather than only the one that happens to be nearer to hand.
     */
    public static function isServable(): bool
    {
        return (bool) config('chatbot.widget.enabled', true)
            && self::allowedOrigins() !== [];
    }
}
