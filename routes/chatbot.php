<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use MB\FilamentChatbot\Http\Controllers\ChannelWebhookController;
use MB\FilamentChatbot\Http\Controllers\SiteWidgetController;
use MB\FilamentChatbot\Http\Controllers\SocketAuthController;
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
        /*
         * How the widget should look, asked for before it draws anything.
         *
         * Its own endpoint rather than a field on the first history call: the
         * launcher is on screen before any conversation exists, and the launcher
         * is what carries the colour and the shape. Folded into history, the
         * widget would appear in the package's default indigo and then change
         * colour under the visitor a moment later.
         *
         * Throttled with the history limiter. This is asked once per page load,
         * which is far rarer than polling, so the looser of the two ceilings is
         * still tighter than it needs.
         */
        Route::get('theme', [SiteWidgetController::class, 'theme'])
            ->middleware(ThrottleRequests::class.':chatbot-site-history')
            ->name('theme');

        /*
         * Admission to this visitor's own live channel.
         *
         * Inside this group, so it is behind the same origin allow-list and the
         * same visitor token as everything else — a socket subscription hands
         * out a live copy of a conversation, which is more than any single read
         * here does, and it must not be the one door with weaker rules on it.
         *
         * Under the send limiter rather than the history one: this is asked once
         * per connection and a connection is a thing worth spending, whereas a
         * caller asking repeatedly is retrying a handshake that is failing.
         */
        Route::post('socket', SocketAuthController::class)
            ->middleware(ThrottleRequests::class.':chatbot-site-send')
            ->name('socket');

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
         * One attached file, for the visitor whose token owns the thread it is
         * in. There is no upload route beside it: a file rides along with the
         * message that carries it, and is therefore already behind this group's
         * origin list and behind the send limiter, without either having been
         * written down twice.
         *
         * Fetched under the history limiter rather than a ceiling of its own.
         * The widget asks for a file while drawing a transcript it has just
         * polled for, so it belongs to that rhythm; a fifth number here would be
         * one more thing a host has to guess at, answering no question the first
         * four do not.
         */
        Route::get('attachments/{attachment}', [SiteWidgetController::class, 'attachment'])
            ->middleware(ThrottleRequests::class.':chatbot-site-history')
            ->where('attachment', '[0-9a-fA-F-]{36}')
            ->name('attachment');

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
     * browser — so the driver behind the channel authenticates each one itself.
     *
     * The address carries both the messenger and the account: a provider's
     * delivery says nothing about which bot it belongs to, and without the
     * second segment an installation could run only one account per messenger.
     *
     * GET is here for one provider only. WhatsApp verifies a new address by
     * fetching it and expecting its own challenge back, and that happens before
     * any secret exists to check — so the method has to be routed even though
     * nothing is ever read from it.
     */
    Route::match(['get', 'post'], 'webhook/{channel}/{connection}', ChannelWebhookController::class)
        ->where('channel', '[a-z0-9_-]+')
        ->where('connection', '[A-Za-z0-9]+')
        ->middleware(ThrottleRequests::class.':chatbot-webhook')
        ->name('webhook');
});
