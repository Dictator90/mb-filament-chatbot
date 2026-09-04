<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Console\Commands\DemoConversationCommand;

/**
 * Everything the workbench application registers that is not the panel.
 *
 * Separate from the panel provider so that the workbench keeps demonstrating
 * the shape a host would actually use: a panel provider that adds the plugin,
 * and ordinary application wiring somewhere else.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DemoConversationCommand::class,
            ]);
        }
    }
}
