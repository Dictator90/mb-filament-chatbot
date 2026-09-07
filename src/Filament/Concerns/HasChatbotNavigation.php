<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Concerns;

use Illuminate\Support\Str;
use UnitEnum;

/**
 * Reads the navigation placement from configuration instead of a literal.
 *
 * Filament resolves `$navigationGroup` from a static property, and a package
 * that filled that property with its own string would nail six screens into a
 * group the host never asked for. A host renames the group in one setting; six
 * hardcoded strings would mean six overridden classes to move the same menu,
 * and whoever missed one would end up with a menu split across two groups
 * named almost the same.
 *
 * The sort offset stays a property because it is about the order of these
 * screens among themselves, which is the package's business — the host only
 * decides where the whole block sits relative to its own screens.
 *
 * **The address is not one of them: every screen here declares a `$slug` under
 * `chatbot/`.** Filament derives an address from the class name, and installed
 * into an application that already had a page class called `ChatInbox` both
 * asked for `/chat-inbox` — the later registration won, the host's own inbox
 * became unreachable, and its menu item opened ours instead. Nothing was
 * logged, nothing failed, and the route list was the only place that said so.
 * `bots`, `widgets`, `runs` and `scenarios` are ordinary enough words that the
 * next application would have collided somewhere else.
 *
 * That one is a fixed prefix rather than a setting, unlike the group above it.
 * Which addresses a package occupies is not a preference — it is the promise
 * that installing it takes nothing away — and a setting would make the promise
 * optional. `tests/Feature/PanelScreensTest.php` holds the rule for the screens
 * written later, because the collision cannot show itself in this package's own
 * suite, where there is nothing to collide with.
 */
trait HasChatbotNavigation
{
    /**
     * Position within the chatbot block, added to the configured base.
     *
     * A method rather than a property because PHP refuses a trait and the class
     * using it declaring the same static property with different defaults, and
     * every screen here needs a different one.
     */
    protected static function chatbotNavigationOffset(): int
    {
        return 0;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = config('chatbot.panel.navigation_group');

        return is_string($group) && $group !== '' ? $group : null;
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('chatbot.panel.navigation_sort', 0) + static::chatbotNavigationOffset();
    }

    /**
     * A heading made from the model label by raising its first letter only.
     *
     * Filament title-cases these — Every Word In Capitals — which is an English
     * rule and wrong nearly everywhere else. Russian capitalises the first word
     * of a heading and nothing after it, so «база знаний» arrived in the menu as
     * «База Знаний»: nothing crashed, nothing was missing, the package simply
     * read as though translated by somebody who does not speak the language.
     *
     * Sentence case rather than deferring to the translation file, because the
     * labels are deliberately stored lowercase — Filament also drops them into
     * the middle of its own sentences ("Create bot", "No bots found"), and a
     * file holding "Bots" would put a capital there too. One rule that is right
     * in both languages beats two spellings of every label.
     */
    public static function getTitleCaseModelLabel(): string
    {
        return Str::ucfirst(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
