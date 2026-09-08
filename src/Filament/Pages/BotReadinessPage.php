<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Pages;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Computed;
use MB\FilamentChatbot\Bot\Readiness;
use MB\FilamentChatbot\Filament\Concerns\HasChatbotNavigation;
use MB\FilamentChatbot\Filament\Resources\Bots\BotResource;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\KnowledgeEntryResource;
use MB\FilamentChatbot\Filament\Resources\Scenarios\ScenarioResource;
use MB\FilamentChatbot\Filament\Resources\Widgets\WidgetResource;
use MB\FilamentChatbot\Models\Scenario;
use Throwable;

/**
 * "Why is the bot silent", answered in one place.
 *
 * The state of a bot used to be assembled from four screens: the scenarios, the
 * canvas, the run journal and the saved checks. The commonest answer — nothing
 * is switched on — was learned last, because the switch is on a fifth.
 *
 * **The addresses live here and the reasoning lives in the domain.** The summary
 * ({@see Readiness}) names a kind of screen and a record; this page turns that
 * into a link. A domain class that knew panel URLs would be a package deciding
 * which panel its screens are registered in, which is exactly the decision the
 * plugin exists to leave to the host.
 *
 * Nothing here is cached. The page is opened when somebody is fixing something,
 * and a cached summary would answer with the state before their fix — sending
 * them to look for a fault they have just repaired.
 */
class BotReadinessPage extends Page
{
    use HasChatbotNavigation;

    /** @var view-string */
    protected string $view = 'chatbot::readiness';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    /** Under `chatbot/`, for the reason `HasChatbotNavigation` sets out. */
    protected static ?string $slug = 'chatbot/readiness';

    public static function getNavigationLabel(): string
    {
        return __('chatbot::panel.readiness.title');
    }

    /**
     * Last in the chatbot group. It is a summary of everything above it, and a
     * summary listed first is a screen people open before there is anything to
     * summarise.
     */
    protected static function chatbotNavigationOffset(): int
    {
        return 8;
    }

    /**
     * Whoever may look at scenarios may look at this.
     *
     * Asked of the panel's guard rather than the application default —
     * `Filament::auth()`, never `auth()`. Against a host whose panel signs
     * people in on a guard of its own, `auth()` answers null while an operator
     * is plainly looking at the page, and the screen vanishes from the menu with
     * nothing to say why.
     */
    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->can('viewAny', Scenario::class) === true;
    }

    public function getTitle(): string|Htmlable
    {
        return __('chatbot::panel.readiness.heading');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('chatbot::panel.readiness.subheading');
    }

    /**
     * @return array{tiles: list<array<string, mixed>>, todos: list<array<string, mixed>>}
     */
    #[Computed]
    public function summary(): array
    {
        return app(Readiness::class)->summary();
    }

    /**
     * Where an item leads, or nothing when this installation does not have that
     * screen registered.
     *
     * Every lookup is guarded, and the guard is not defensive habit. A host
     * registers the plugin's resources by hand, and one that left the quality
     * checks out has a perfectly valid installation — asking Filament for that
     * resource's URL there throws, and the summary would take the whole page
     * down over a link it could simply not draw.
     */
    public function linkFor(string $target, ?int $record): ?string
    {
        try {
            return match ($target) {
                Readiness::TARGET_BOTS => BotResource::getUrl(),
                Readiness::TARGET_SCENARIOS => ScenarioResource::getUrl(),
                Readiness::TARGET_SCENARIO => $record === null
                    ? ScenarioResource::getUrl()
                    : ScenarioResource::getUrl('graph', ['record' => $record]),
                /*
                 * Saved checks have no screen in this package: they are
                 * recorded and replayed from the console
                 * (`php artisan chatbot:quality`). The item still appears and
                 * still names the check — knowing which one drifted is the
                 * useful half — it simply has nowhere to lead. Returning a
                 * plausible-looking link to a resource nobody registered would
                 * be worse than none.
                 */
                Readiness::TARGET_QUALITY => null,
                Readiness::TARGET_KNOWLEDGE => KnowledgeEntryResource::getUrl(),
                Readiness::TARGET_WIDGETS => WidgetResource::getUrl(),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
