<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Livewire\LivewireServiceProvider;
use MB\FilamentChatbot\ChatbotServiceProvider;

use function Orchestra\Testbench\default_migration_path;

use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Workbench\App\Providers\WorkbenchPanelProvider;

/**
 * Boots the package inside a bare Laravel application.
 *
 * Filament's own providers are listed by hand because Testbench does not run
 * package discovery for a dependency's dependencies. Miss one and the failure
 * arrives far from its cause — a missing Blade component, an unregistered
 * Livewire alias — so the whole set stays here in one place.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        /*
         * Livewire is registered after Filament, and that order is load-bearing.
         *
         * Filament's support provider does `bind(DataStore::class,
         * DataStoreOverride::class)` — a plain, non-shared binding. Laravel's
         * bind() drops any existing instance of the same abstract, and Livewire
         * registers its DataStore with instance(). Whoever runs last therefore
         * decides whether Livewire's per-component store is a singleton at all.
         *
         * With Livewire first, Filament's bind wins and every resolution hands
         * back a brand new store: everything a component writes to it is lost on
         * the next read. The first page that renders then dies with a type error
         * inside Livewire's validation support, blaming a null error bag — which
         * names neither the binding, nor Filament, nor this file.
         *
         * A real application never sees this because composer's discovery order
         * puts filament/* ahead of livewire/livewire. The suite has to say so.
         */
        return [
            BladeCaptureDirectiveServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,

            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,

            LivewireServiceProvider::class,

            ChatbotServiceProvider::class,
            WorkbenchPanelProvider::class,
        ];
    }

    public function defineEnvironment($app): void
    {
        tap($app['config'], function (Repository $config): void {
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);

            $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
            $config->set('cache.default', 'array');
            $config->set('queue.default', 'sync');
            $config->set('session.driver', 'array');
            $config->set('mail.default', 'array');
        });
    }

    /**
     * The users table comes from Testbench's skeleton, by path rather than
     * through loadLaravelMigrations().
     *
     * The helper resolves the same directory but did not create the table under
     * this version, and a missing users table shows up far from its cause —
     * as one unrelated test failing on an insert. Naming the path says what is
     * loaded and fails loudly if it ever moves.
     *
     * The workbench does not define a users table of its own. It would sort
     * ahead of the skeleton's, create the table first, and leave the skeleton's
     * migration to fail on every `testbench serve` — while the suite, which
     * loads only these paths, stayed green.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(default_migration_path());
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
