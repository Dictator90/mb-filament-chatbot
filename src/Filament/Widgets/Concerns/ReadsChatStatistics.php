<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Widgets\Concerns;

use Carbon\CarbonInterface;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use MB\FilamentChatbot\Models\Conversation;

/**
 * What every dashboard widget in the package shares: who may see it, how far
 * back it looks, and how long its numbers are held.
 *
 * A trait rather than a common parent class because the widgets descend from
 * two different Filament bases — a stats overview and a chart — and neither may
 * be given up to share three methods.
 *
 * The word "widget" is overloaded in this package: the site chat widget is the
 * bubble a visitor types into, these are panels on the host's dashboard. Only
 * the namespace tells them apart, so nothing here reads a chat widget's
 * configuration by accident.
 */
trait ReadsChatStatistics
{
    /**
     * How long a widget's numbers are held.
     *
     * A dashboard draws every widget on it at once and is reloaded all day, so
     * the counting has to survive a page refresh. Short enough that an operator
     * watching for work does not start distrusting the figure, long enough that
     * a dashboard opened in three tabs counts once.
     */
    protected const int CACHE_SECONDS = 30;

    /**
     * Whether this panel may show conversation figures at all.
     *
     * The same question `ChatInbox::canAccess()` asks, and asked the same way —
     * through `Filament::auth()` rather than `auth()`. A panel may sign people
     * in on a guard of its own, and hosts commonly do: staff against one table,
     * customers against another. Against such a host `auth()` resolves the
     * *application default* guard, which nobody signed into, and answers null
     * while an operator is plainly looking at the dashboard. Nothing throws —
     * the widget simply is not there, or, with the test written the wrong way
     * round, is there for somebody who may not see a single conversation.
     */
    public static function canView(): bool
    {
        return Filament::auth()->user()?->can('viewAny', Conversation::class) === true;
    }

    /**
     * Midnight, so many days ago.
     *
     * Whole days rather than "thirty times twenty-four hours", because the
     * numbers sit next to a chart whose columns are days: a window that starts
     * mid-afternoon makes the oldest column a half day and the trend against the
     * previous period a comparison of unequal things.
     *
     * Typed as `CarbonInterface`: a host that has called
     * `Date::use(CarbonImmutable::class)` gets an immutable instance here, which
     * is not an `Illuminate\Support\Carbon` at all.
     */
    protected function since(int $days): CarbonInterface
    {
        return now()->subDays($days)->startOfDay();
    }

    /**
     * Holds a widget's numbers for a few seconds under a key that names the
     * viewer.
     *
     * The viewer is in the key even where the figure is the same for everybody,
     * and that is deliberate. One tile already narrows to the person looking at
     * it — the work waiting for them — and a shared key would hand the first
     * person to open the dashboard their own count of it for the life of the
     * entry. Keeping every key viewer-named means the next figure that becomes
     * personal cannot leak by being added to an entry that was safe yesterday.
     *
     * The class name is part of the key because two widgets both caching
     * "counters" would otherwise read each other's array and render whichever
     * arrived first.
     */
    protected function remember(string $key, Closure $callback): mixed
    {
        $viewer = Filament::auth()->id() ?? 'guest';

        return Cache::remember(
            'chatbot.widgets.'.str_replace('\\', '.', static::class).'.'.$key.'.'.$viewer,
            now()->addSeconds(self::CACHE_SECONDS),
            $callback,
        );
    }
}
