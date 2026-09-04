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
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,

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
     * The workbench carries its own schema, Testbench's skeleton is not used.
     *
     * A host application owns its users table, and so does the stand-in for one.
     * Borrowing Testbench's would also hide the boundary this package has to
     * respect: nothing in it may put a foreign key on that table, because a real
     * host may name it differently or keep it on another connection entirely.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
