<?php

declare(strict_types=1);

/**
 * The wording of the chat bubble an external site embeds.
 *
 * These reach the browser through the widget's own API rather than through the
 * script, and that choice is forced. `widget.js` is served as one static file
 * with an ETag and a shared cache, identical for every site and every visitor;
 * baking a locale into those bytes would hand the second caller whatever
 * language the first one happened to warm the cache with. The endpoints already
 * answer per request, so the labels ride along with the transcript and cost
 * nothing extra.
 *
 * `data-title`, `data-placeholder` and `data-launcher` on the script tag still
 * win over anything here. A site owner who wrote them down did so on purpose,
 * they are documented, and installations already rely on them — a server label
 * that overrode them would silently rename a widget somebody had named.
 */
return [
    'title' => 'Chat',
    'launcher' => 'Chat with us',
    'placeholder' => 'Write a message',
    'send' => 'Send',
    'close' => 'Close',
    'failed' => 'That did not send. Try again in a moment.',
    'emoji' => 'Emoji',
    'attach' => 'Attach a file',
    'remove' => 'Remove',

    /*
     * What each tick beside the visitor's own message means.
     *
     * A tick is a character, and a character on its own says nothing to anybody
     * not looking at it — so the mark carries one of these as its accessible
     * name. Sent is a message the widget has drawn and the server has not
     * confirmed yet, delivered is one the server has, read is one somebody at
     * the desk has opened the thread on.
     */
    'receipt_sent' => 'Sent',
    'receipt_delivered' => 'Delivered',
    'receipt_read' => 'Read',

    /*
     * Why a file was not accepted, said to the visitor.
     *
     * Sent from the server for the same reason every other label here is, and
     * worded specifically on purpose: "that did not send" over a file that was
     * too big sends somebody straight back to try the same file again.
     */
    'attachment_off' => 'This chat does not accept files.',
    'attachment_too_large' => 'That file is bigger than :limit KB.',
    'attachment_wrong_type' => 'That kind of file is not accepted here.',
];
