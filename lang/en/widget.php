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
     * The name of the button a picture sits inside. A thumbnail is a hundred
     * pixels of a screenshot somebody sent to be read, and without a name on it
     * nothing says the picture can be opened at all.
     */
    'expand' => 'Open the picture',

    /*
     * The line under the title, where a placement shows one.
     *
     * It reports the hours the installation declared and never a person at a
     * desk — see the note on `src/Channels/WidgetPresence.php`. "Away" therefore
     * says when an answer is coming rather than that nobody is there: a visitor
     * who reads "offline" leaves, and one who reads "in the morning" writes
     * anyway, which is the message the desk wanted.
     */
    'status_online' => 'Online',
    'status_away' => 'We answer in the morning',

    /*
     * Who said it, over the first bubble of each run.
     *
     * Three names, and none of them a real one. The bot's own name is already on
     * the title directly above; repeating it over every reply would print it
     * five times on one screen. An operator's name is not this application's to
     * publish on somebody else's site — and on a desk where three people share a
     * shift, naming them would make one conversation look like three.
     */
    'author_you' => 'You',
    'author_bot' => 'Assistant',
    'author_operator' => 'Support',

    /*
     * The sentence beside the launcher, where a placement shows one.
     *
     * A round launcher is a coloured circle, and a coloured circle in the corner
     * of a page is not self-evidently a chat. This is what the pill-shaped
     * launcher says on itself, said next to the circle instead.
     */
    'teaser' => 'Chat with us',

    /*
     * Quoting one message rather than answering the thread.
     *
     * `reply` names the button on a bubble; `reply_cancel` is the way out of the
     * chip it puts over the composer, and it matters more than it looks. Without
     * it, somebody who quoted the wrong message has no way back except sending
     * it and then saying "not that one".
     */
    'reply' => 'Reply',
    'reply_cancel' => 'Not this one',

    /*
     * The opening form, where a placement asks for one.
     *
     * The intro says why, and says it in one line. A form in front of a chat box
     * already costs conversations; a paragraph of justification above it costs
     * more of them than the sentence saves, and "so we can get back to you" is
     * the only reason a visitor has any interest in.
     */
    'lead_intro' => 'Leave your details and we will pick this up.',
    'lead_name' => 'Name',
    'lead_phone' => 'Phone',
    'lead_email' => 'E-mail',
    'lead_submit' => 'Start the chat',

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
