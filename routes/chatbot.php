<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use MB\FilamentChatbot\Http\Controllers\SiteWidgetController;
use MB\FilamentChatbot\Http\Controllers\TelegramWebhookController;
use MB\FilamentChatbot\Http\Controllers\WidgetScriptController;
use MB\FilamentChatbot\Http\Middleware\WidgetOrigin;

/**
 * The package's public HTTP surface.
 *
 * **Loaded without the `web` group, deliberately.** Every route here is called
 * by something that is not a browser sitting on this application's own pages —
 * a widget on a customer's domain, Telegram's servers — and the `web` group
 * would give all of them a session cookie they cannot use and a CSRF check they
 * cannot pass. What guards them instead is stated on each group below.
 *
 * The throttle middleware is referenced by class rather than by the `throttle`
 * alias. Aliases belong to the host's HTTP kernel, and a package that assumes
 * one is there breaks on the first application that renamed it.
 */
Route::prefix('chatbot')->name('chatbot.')->group(function (): void {
    /*
     * The widget's own files, served rather than published into `public/`.
     *
     * Publishing would put one more step between installing the package and a
     * working chat — and, worse, a step nobody repeats after an upgrade, leaving
     * a stale widget talking to a newer endpoint.
     *
     * Outside the origin allow-list on purpose: this is a public script tag,
     * identical for every site, and checking the origin of a `<script src>`
     * would only break the browsers that do not send one. What the allow-list
     * guards is below — the endpoints that carry a conversation.
     */
    Route::get('widget.js', [WidgetScriptController::class, 'script'])->name('widget.script');
    Route::get('widget.css', [WidgetScriptController::class, 'styles'])->name('widget.styles');

    /*
     * The widget's own endpoints. Guarded by the origin allow-list and by the
     * visitor token, in place of the CSRF token they cannot carry.
     *
     * A limiter per endpoint rather than one for the group: polling for history
     * happens every few seconds and legitimately, while sending a message is
     * how a conversation is created and how the model budget gets spent. One
     * shared ceiling would have to be set high enough for the first, which
     * leaves it uselessly high for the second.
     */
    Route::middleware(WidgetOrigin::class)->prefix('site')->name('site.')->group(function (): void {
        Route::get('messages', [SiteWidgetController::class, 'history'])
            ->middleware(ThrottleRequests::class.':chatbot-site-history')
            ->name('history');

        Route::post('messages', [SiteWidgetController::class, 'send'])
            ->middleware(ThrottleRequests::class.':chatbot-site-send')
            ->name('send');

        Route::post('read', [SiteWidgetController::class, 'read'])
            ->middleware(ThrottleRequests::class.':chatbot-site-read')
            ->name('read');

        Route::post('typing', [SiteWidgetController::class, 'typing'])
            ->middleware(ThrottleRequests::class.':chatbot-site-typing')
            ->name('typing');

        /*
         * The browser asks permission before sending a custom header, and it
         * asks on the same path with OPTIONS. Without a route to match, that
         * preflight is a 404 and every widget request fails before it is made —
         * with nothing in the log but a missing route nobody wrote.
         *
         * A controller method and not a closure: a host that runs `route:cache`
         * cannot serialize a closure, and the failure appears as a deploy
         * command refusing to run rather than as anything about this file.
         */
        Route::options('{endpoint}', [SiteWidgetController::class, 'preflight'])
            ->where('endpoint', 'messages|read|typing')
            ->name('preflight');
    });

    /*
     * Provider webhooks. No origin list applies — the caller is a server, not a
     * browser — so each one authenticates itself in its own controller.
     */
    Route::post('webhook/telegram', TelegramWebhookController::class)
        ->middleware(ThrottleRequests::class.':chatbot-webhook')
        ->name('webhook.telegram');
});
