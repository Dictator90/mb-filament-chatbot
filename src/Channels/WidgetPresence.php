<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Widget;

/**
 * The line under the widget's title: whether an answer is coming now.
 *
 * **It reports declared hours, never a person.** Nobody at the desk tells this
 * application when they sit down, and nothing in a poll from a browser could
 * find out; a status computed from whoever last touched a conversation would say
 * "away" through a lunch break and "online" through a night when the operator
 * left the panel open on a spare screen. Declared hours are the one thing an
 * installation has actually stated, so that is what the line says — and the
 * wording it carries («Отвечаем» / "We answer in the morning") is a promise
 * about the hours rather than a claim about a chair.
 *
 * The hours come from the bot behind the placement, and not from a second
 * setting on the widget. A widget is where a bot is put; hours belong to the
 * assistant that keeps them, the scenario branch that says "we will answer in
 * the morning" already reads them from there
 * (`src/Bot/Conditions/Fields/WorkingHoursCondition.php`), and two places to
 * write an opening time is how a widget ends up promising an answer at an hour
 * the scenario apologises for.
 *
 * A placement with no bot, or a bot with no hours, is open. Empty hours mean
 * "always" everywhere else in the package, and the opposite reading would put
 * "we are closed" under the title of every widget that has never been given an
 * opening time — a sign nobody hung, explained nowhere.
 */
final class WidgetPresence
{
    public const ONLINE = 'online';

    public const AWAY = 'away';

    public static function forWidget(?Widget $widget): string
    {
        return self::forBot($widget?->bot);
    }

    public static function forBot(?Bot $bot): string
    {
        return ($bot?->isWorkingHours() ?? true) ? self::ONLINE : self::AWAY;
    }
}
