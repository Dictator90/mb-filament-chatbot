<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use MB\FilamentChatbot\ChatbotPlugin;
use MB\FilamentChatbot\ChatbotServiceProvider;
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

/**
 * The wording is attached to the translator before anybody can ask for it.
 *
 * **A load-order defect, and the worst kind: it appears on one host and not on
 * the next.** A panel plugin registering pages asks for their labels during the
 * *register* phase. Attach the namespace in `boot()` instead, and on a host
 * whose panel is registered before this provider boots the translator loads the
 * group, finds no such namespace, caches an empty array — and never looks
 * again. Every screen then renders `chatbot::panel.readiness.heading` where a
 * heading belongs: the menu, the tabs, the tiles, all of it. Clearing caches
 * does not help, because nothing is cached; the order is simply wrong.
 *
 * Asked in the register phase and nowhere else. The rest of the suite runs
 * against a booted application, and under booting this passes either way —
 * which is exactly why it went unnoticed until a real host hit it.
 */
it('has its wording ready before the panel asks for a label', function (): void {
    $app = new Application(dirname(__DIR__, 2));

    $app->instance('config', new Repository);
    $app->singleton('files', fn (): Filesystem => new Filesystem);
    $app->singleton('translation.loader', fn ($app): FileLoader => new FileLoader($app['files'], []));
    $app->singleton('translator', fn ($app): Translator => new Translator($app['translation.loader'], 'ru'));

    (new ChatbotServiceProvider($app))->register();

    /*
     * Resolved only now — as a host does when a plugin asks for a label while
     * providers are still registering. Nothing here has booted.
     */
    expect($app['translator']->get('chatbot::panel.readiness.heading'))
        ->not->toBe('chatbot::panel.readiness.heading');
});
