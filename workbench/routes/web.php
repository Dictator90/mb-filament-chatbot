<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * A page that embeds the widget the way a customer's site would.
 *
 * It exists because the widget is the one part of the package that cannot be
 * proved by a test suite: whether it draws, whether it fits on a phone, whether
 * the reply appears without a reload. All of that is a thing somebody has to
 * look at, and asking them to build a site first is asking them not to look.
 *
 * It is also the honest end of the origin allow-list. The page is served by this
 * application, so `chatbot.widget.origins` has to name this application's own
 * URL for the widget to work here — which is exactly the setting a host will get
 * wrong first, discovered here rather than on their marketing site.
 */
Route::get('/demo', function (): string {
    $base = url('/');

    /*
     * The demo page busts the cache; a host's page would not.
     *
     * Here it stands for the version a real deployment changes on release, and
     * it exists because without it every edit to the widget is invisible until
     * the browser feels like asking again — which is a slow way to learn that
     * the widget you are looking at is last week's.
     */
    $version = (int) filemtime(dirname(__DIR__, 2).'/resources/widget/widget.js');

    return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Widget demo</title>
            <style>
                body {
                    margin: 0;
                    padding: 64px 24px;
                    font: 16px/1.6 system-ui, sans-serif;
                    color: #0f172a;
                    background: #f8fafc;
                }
                main { max-width: 640px; margin: 0 auto; }
                h1 { font-size: 28px; margin: 0 0 8px; }
                p { color: #475569; }
            </style>
        </head>
        <body>
            <main>
                <h1>Somebody else's website</h1>
                <p>
                    This page is standing in for a customer's own site. The only
                    thing on it that belongs to the package is the script tag
                    below — everything else is decoration.
                </p>
                <p>Say <strong>hello</strong> in the widget to start a conversation.</p>
            </main>

            <link rel="stylesheet" href="{$base}/chatbot/widget.css?v={$version}">
            <script src="{$base}/chatbot/widget.js?v={$version}" async
                    data-bot="demo"
                    data-title="Demo support"
                    data-greeting="Hello. Ask me about an order, or say hello."
                    data-launcher="Chat with us"></script>
        </body>
        </html>
        HTML;
})->name('widget-demo');
