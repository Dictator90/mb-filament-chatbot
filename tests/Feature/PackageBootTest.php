<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use MB\FilamentChatbot\ChatbotPlugin;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * The package installs into a panel that knows nothing about chat.
 *
 * Not decoration: a plugin that fails to register is invisible until someone
 * opens the panel and finds the screens missing, and nothing else in the suite
 * would notice.
 */
it('registers the plugin on the host panel', function (): void {
    expect(Filament::getPanel('workbench')->hasPlugin('chatbot'))->toBeTrue()
        ->and(Filament::getPanel('workbench')->getPlugin('chatbot'))->toBeInstanceOf(ChatbotPlugin::class);
});

it('merges its configuration without the host publishing anything', function (): void {
    expect(config('chatbot.bot.enabled'))->toBeTrue()
        ->and(config('chatbot.data_resources.max_limit'))->toBe(100);
});

/**
 * The registry has to survive across resolutions: hosts register their steps
 * once during boot, and a fresh instance per resolution would drop every one.
 */
it('keeps one registry for the whole application', function (): void {
    expect(app(ChatbotRegistry::class))->toBe(app(ChatbotRegistry::class));
});

/**
 * An empty outbound allow-list and an empty widget origin list are the shipped
 * defaults on purpose: a forgotten setting must fail closed, never open.
 */
it('ships closed defaults for anything that leaves the application', function (): void {
    expect(config('chatbot.http.allowed_domains'))->toBe([])
        ->and(config('chatbot.http.allow_private_networks'))->toBeFalse()
        ->and(config('chatbot.widget.origins'))->toBe([])
        ->and(config('chatbot.ai.default'))->toBe('null');
});
