<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MB\FilamentChatbot\Http\Controllers\WidgetPreviewController;

/**
 * Routes that belong to the panel rather than to the outside world.
 *
 * Kept apart from `routes/chatbot.php` because the two are guarded in opposite
 * directions and mixing them is how one inherits the other's group by accident.
 * Everything there is called by a script on somebody else's domain and runs
 * without a session on purpose; everything here is a screen a signed-in member
 * of staff is looking at, so it needs the `web` group — a session to read, and
 * the cookie encryption that goes with it.
 *
 * The `web` group and not the panel's own middleware stack: a package cannot
 * name a stack it does not own, and a host may run several panels. Which panel
 * decides is named in the path instead, and the controller asks that panel's
 * guard.
 */
Route::middleware('web')
    ->prefix('chatbot')
    ->name('chatbot.')
    ->group(function (): void {
        Route::get('widget-preview/{panel}', WidgetPreviewController::class)
            ->name('widget.preview');
    });
