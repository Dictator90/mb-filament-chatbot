<?php

declare(strict_types=1);

namespace MB\FilamentChatbot;

use Illuminate\Support\ServiceProvider;
use MB\FilamentChatbot\Bot\Slots\BuiltInSlots;
use MB\FilamentChatbot\Bot\Steps\Types\AskStep;
use MB\FilamentChatbot\Bot\Steps\Types\CloseStep;
use MB\FilamentChatbot\Bot\Steps\Types\DelayStep;
use MB\FilamentChatbot\Bot\Steps\Types\HandoffStep;
use MB\FilamentChatbot\Bot\Steps\Types\JumpStep;
use MB\FilamentChatbot\Bot\Steps\Types\KeywordStep;
use MB\FilamentChatbot\Bot\Steps\Types\SayStep;
use MB\FilamentChatbot\Bot\Steps\Types\SetStep;
use MB\FilamentChatbot\Bot\Steps\Types\StartStep;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Wires the package into the host application.
 *
 * Everything the package adds to a panel lives in {@see ChatbotPlugin} instead,
 * and that split is deliberate. A host may run several panels — a staff desk and
 * a partner portal — and only one of them should carry the chat screens. Register
 * panel pieces here and they would appear in every panel the application has,
 * with no way to opt out short of forking the provider.
 */
class ChatbotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chatbot.php', 'chatbot');

        /*
         * The registry is a singleton because host packages register their steps,
         * capabilities and channels into it during their own boot. A fresh
         * instance per resolution would quietly drop every one of them.
         */
        $this->app->singleton(ChatbotRegistry::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'chatbot');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'chatbot');

        $this->registerBuiltInSteps();
        $this->registerBuiltInSlots();

        if ($this->app->runningInConsole()) {
            $this->publishesTo();
        }
    }

    /**
     * Adds the package's own node kinds without ever displacing the host's.
     *
     * A key already claimed is left alone, for the same reason the slots below
     * are. Replacing a built-in node is the documented way to change one detail
     * of it — the wording it sends, the bound it enforces — and a host that does
     * so writes one line in a provider that may boot before this one or after
     * it, depending only on where the package sits in the manifest. Registering
     * unconditionally would honour that line on some installations and revert it
     * on others, which is the worst of both: it works on the developer's machine
     * and quietly does not in production.
     *
     * The list is written out rather than discovered by scanning the directory.
     * Scanning would pick up whatever happens to be lying in it — a half-written
     * node, a class kept for a migration — and put it on the palette of every
     * installation, and a node reaching the palette is a node a customer can be
     * routed through.
     *
     * Nodes the other subsystems own — collect, form, condition — register
     * themselves alongside their own machinery, and belong in this method as
     * they arrive.
     */
    protected function registerBuiltInSteps(): void
    {
        $registry = $this->app->make(ChatbotRegistry::class);

        $steps = [
            SayStep::class,
            AskStep::class,
            KeywordStep::class,
            SetStep::class,
            DelayStep::class,
            JumpStep::class,
            HandoffStep::class,
            CloseStep::class,
        ];

        foreach ($steps as $step) {
            if ($registry->hasStep($step::key())) {
                continue;
            }

            $registry->registerStep($step);
        }
    }

    /**
     * Adds the package's own slots without ever displacing the host's.
     *
     * Registration is skipped for a key that is already taken, and the skip is
     * the point. Providers boot in load order — this one before the host's own,
     * but after any package the host loaded earlier — so a plain
     * registerSlots() would win against half the application and lose against
     * the other half depending on nothing more than the order of a manifest. A
     * host that redefines "phone" with its own label and question would see it
     * silently reverted, and only on some installs.
     *
     * Skipping taken keys makes both directions agree: whoever registers a key
     * other than this package owns it, whether they got there first or arrive
     * later and overwrite.
     */
    protected function registerBuiltInSlots(): void
    {
        $registry = $this->app->make(ChatbotRegistry::class);

        foreach (BuiltInSlots::all() as $slot) {
            if ($registry->slot($slot->key()) !== null) {
                continue;
            }

            $registry->registerSlot($slot);
        }
    }

    /**
     * Publishable groups, one tag per kind.
     *
     * Migrations go through publishesMigrations() rather than a plain publishes()
     * so that Laravel stamps a fresh timestamp on the copy. Published with their
     * package filenames, they would sort before migrations the host already has
     * and try to add foreign keys to tables that do not exist yet.
     */
    protected function publishesTo(): void
    {
        $this->publishes([
            __DIR__.'/../config/chatbot.php' => config_path('chatbot.php'),
        ], 'chatbot-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'chatbot-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/chatbot'),
        ], 'chatbot-views');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/chatbot'),
        ], 'chatbot-lang');
    }
}
