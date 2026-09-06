<?php

declare(strict_types=1);

namespace MB\FilamentChatbot;

use Filament\Contracts\Plugin;
use Filament\Panel;
use MB\FilamentChatbot\Filament\Pages\ChatInbox;
use MB\FilamentChatbot\Filament\Pages\Sandbox;
use MB\FilamentChatbot\Filament\Resources\Bots\BotResource;
use MB\FilamentChatbot\Filament\Resources\DataResources\DataResourceResource;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\KnowledgeEntryResource;
use MB\FilamentChatbot\Filament\Resources\QuickReplies\QuickReplyResource;
use MB\FilamentChatbot\Filament\Resources\Runs\RunResource;
use MB\FilamentChatbot\Filament\Resources\Scenarios\ScenarioResource;
use MB\FilamentChatbot\Filament\Widgets\ChatStatsWidget;
use MB\FilamentChatbot\Filament\Widgets\ConversationsChartWidget;
use MB\FilamentChatbot\Filament\Widgets\ScenarioOutcomesWidget;

/**
 * Adds the chat screens to one panel.
 *
 * Resources are listed by hand rather than discovered. Filament's
 * discoverResources() scans a directory and derives a namespace from it, which
 * works for an application's own app/Filament tree and not for a package: the
 * host would have to know the vendor path. Listing them also lets a host drop
 * a screen it does not want by extending this class.
 */
class ChatbotPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'chatbot';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources($this->resources())
            ->pages($this->pages());
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * Public so that a suite can walk the same list the panel registers.
     *
     * A test naming the six screens by hand proves the six it names and nothing
     * about the seventh: a resource written and never registered here would
     * leave the suite green and the menu short. Reading the list from the plugin
     * is what makes a forgotten registration fail.
     *
     * @return array<class-string>
     */
    public function resources(): array
    {
        return [
            BotResource::class,
            ScenarioResource::class,
            KnowledgeEntryResource::class,
            QuickReplyResource::class,
            DataResourceResource::class,
            RunResource::class,
        ];
    }

    /**
     * Public for the same reason as `resources()`: a suite that names the page
     * itself proves nothing about a page written and never registered here.
     *
     * @return array<class-string>
     */
    public function pages(): array
    {
        return [
            ChatInbox::class,
            Sandbox::class,
        ];
    }

    /**
     * The dashboard widgets, offered rather than installed.
     *
     * Listed here so a suite and a host can walk the list instead of naming
     * three classes by hand, but deliberately **not** registered on the panel in
     * `register()`. Where these sit — or whether they sit anywhere — is the
     * host's decision about their own front page, and a plugin that put three
     * panels on it uninvited would be a plugin people uninstall.
     *
     * A host writes, in their own panel:
     *
     *     ->widgets(ChatbotPlugin::make()->widgets())
     *
     * `->widgets()` is also what registers them as Livewire components, so a
     * custom dashboard page's `getWidgets()` alone is not enough.
     *
     * @return array<class-string>
     */
    public function widgets(): array
    {
        return [
            ChatStatsWidget::class,
            ConversationsChartWidget::class,
            ScenarioOutcomesWidget::class,
        ];
    }
}
