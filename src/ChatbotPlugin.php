<?php

declare(strict_types=1);

namespace MB\FilamentChatbot;

use Filament\Contracts\Plugin;
use Filament\Panel;

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
     * @return array<class-string>
     */
    protected function resources(): array
    {
        return [];
    }

    /**
     * @return array<class-string>
     */
    protected function pages(): array
    {
        return [];
    }
}
