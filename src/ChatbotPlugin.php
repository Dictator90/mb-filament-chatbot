<?php

declare(strict_types=1);

namespace MB\FilamentChatbot;

use Filament\Contracts\Plugin;
use Filament\Panel;
use MB\FilamentChatbot\Filament\Resources\Bots\BotResource;
use MB\FilamentChatbot\Filament\Resources\DataResources\DataResourceResource;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\KnowledgeEntryResource;
use MB\FilamentChatbot\Filament\Resources\QuickReplies\QuickReplyResource;
use MB\FilamentChatbot\Filament\Resources\Runs\RunResource;
use MB\FilamentChatbot\Filament\Resources\Scenarios\ScenarioResource;

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
     * @return array<class-string>
     */
    protected function pages(): array
    {
        return [];
    }
}
