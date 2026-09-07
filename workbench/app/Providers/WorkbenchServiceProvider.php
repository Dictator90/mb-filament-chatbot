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

    /**
     * Lets the demo page embed the widget.
     *
     * In `boot` rather than `register`, because the package merges its own
     * defaults during `register` and whichever ran second would win — a race
     * decided by the order of a manifest.
     *
     * A host writes this same line, and gets it wrong the same way: the origin
     * is scheme and host, not a bare domain, and it is the site the widget sits
     * on rather than the application serving it. Here they happen to be the same
     * application, which is what makes the demo page a fair test of the setting.
     *
     * **The port is part of the origin, and `APP_URL` here has none.** Testbench
     * ships `http://localhost`, `testbench serve` listens on a port, and the
     * browser then announces `http://localhost:8000` — which does not match, so
     * the demo page answers 403 from an allow-list that looks correct in the
     * config. Taking the address from the request that arrived is what makes the
     * stand work on whatever port it was started with.
     *
     * Safe here and nowhere else: this reads the *served* host and hands it to
     * an allow-list that only this stand uses. A host doing the same thing would
     * have written "allow whoever asks", which is the one thing the allow-list
     * exists to refuse.
     */
    public function boot(): void
    {
        $configured = rtrim((string) $this->app['config']->get('app.url'), '/');

        /*
         * Read from the server environment rather than from Laravel's request.
         *
         * Two things get in the way of the obvious spellings. `runningInConsole()`
         * answers true under `testbench serve` even while a request is being
         * handled, so a branch guarded by it never runs; and the request bound at
         * the time providers boot has no host, so asking it yields an empty
         * string. `HTTP_HOST` is set by the server for the request actually in
         * flight, which is the thing this needs to know.
         */
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $secure = ($_SERVER['HTTPS'] ?? '') !== '' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $served = $host === '' ? null : ($secure ? 'https://' : 'http://').$host;

        $this->app['config']->set('chatbot.widget.origins', array_values(array_unique(array_filter([
            $configured,
            $served,
        ]))));
    }
}
