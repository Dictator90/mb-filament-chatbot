<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use Illuminate\Http\Request;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Widget;

/**
 * Works out which widget a request is for, and whether it may answer here.
 *
 * One place rather than one per endpoint. Every widget route asks the same two
 * questions — which widget, and is this site allowed it — and four copies of
 * that would eventually disagree about the second, which is the half that
 * decides who may open a conversation.
 */
class WidgetResolver
{
    /**
     * The widget a request names, or null.
     *
     * Two ways in, and the second is a compatibility path rather than a choice.
     *
     * `widget` is the key written into the embed today: opaque, and pointed at a
     * bot from this side, so re-pointing it never means editing somebody else's
     * page.
     *
     * `bot` is what embeds written before widgets existed carry, and they are on
     * pages this application does not control and cannot fix. A bot slug
     * resolves to that bot's first active widget. Dropping it would silently
     * take the chat off every site already running one, and the site owner would
     * have no way of knowing why.
     */
    public function forRequest(Request $request): ?Widget
    {
        $key = trim((string) ($request->input('widget') ?? $request->query('widget') ?? ''));

        if ($key !== '') {
            return Widget::query()->active()->where('key', $key)->first();
        }

        $slug = trim((string) ($request->input('bot') ?? $request->query('bot') ?? ''));

        return $slug === '' ? null : $this->byBotSlug($slug);
    }

    /**
     * Whether this widget will answer the site the request came from.
     *
     * The installation-wide allow-list has already been applied by the origin
     * middleware; this is the widget's own narrowing on top of it.
     */
    public function reachable(?Widget $widget, Request $request): bool
    {
        return $widget !== null && $widget->reaches($request->headers->get('Origin'));
    }

    /**
     * A bot's widget, for the embeds that still name a bot.
     *
     * The oldest active one rather than any of them, so an installation that
     * later adds a second widget to the same bot does not change which one those
     * pages get from one deploy to the next.
     */
    protected function byBotSlug(string $slug): ?Widget
    {
        $bot = Bot::query()->where('slug', $slug)->first();

        if ($bot === null) {
            return null;
        }

        return Widget::query()
            ->active()
            ->where('bot_id', $bot->getKey())
            ->orderBy('id')
            ->first();
    }
}
