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
 * Everything else the workbench can do it gets from the package. The moment
 * something has to be added here to make a screen work, that is a thing a host
 * would have had to write too, and it belongs in the package or its README.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Points the workbench at its own SQLite file.
     *
     * Set here rather than in `testbench.yaml` because the two are not read in
     * the same places. Artisan honours the yaml, `testbench serve` boots the
     * skeleton's own `.env` and falls back to the in-memory testing connection —
     * so `workbench:build` migrated the file and the browser then asked an empty
     * database, and the error blamed a missing users table rather than the
     * connection it was looking in.
     *
     * The test suite overrides this from its own base test case, so a test still
     * runs against `:memory:` and never sees this file.
     */
    public function register(): void
    {
        $this->app['config']->set('database.default', 'workbench');
        $this->app['config']->set('database.connections.workbench', [
            'driver' => 'sqlite',
            'database' => dirname(__DIR__, 2).'/database/database.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
