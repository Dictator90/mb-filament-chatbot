<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MB\FilamentChatbot\Http\Controllers\AttachmentController;
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

        /*
         * A file from a conversation, for the operator reading it.
         *
         * Not published into `public/` and not served from a signed URL. The
         * files are passports, invoices and photographs of somebody's front
         * door: what decides is the policy on the conversation, asked on every
         * request, and a link that carried its own permission would go on
         * working after the person who copied it stopped being staff.
         */
        Route::get('attachments/{panel}/{attachment}', AttachmentController::class)
            ->name('attachment');
    });
