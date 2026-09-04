<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Application wiring for the workbench that is not the panel.
 *
 * Separate from the panel provider so that the workbench keeps demonstrating the
 * shape a host would actually use: a panel provider that adds the plugin, and
 * ordinary application wiring somewhere else.
 *
 * Empty for now, and that is the point — everything the workbench can do it gets
 * from the package. The moment something has to be added here to make a screen
 * work, that is a thing a host would have had to write too, and it belongs in
 * the package or in its documentation.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
