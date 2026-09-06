<?php

declare(strict_types=1);

/**
 * What the bot itself says when nobody wrote the sentence for it.
 *
 * Only the words a visitor can end up reading belong here. The journal's own
 * `reason` payloads stay English on purpose: they are written into `run_steps`
 * at the moment a node runs, so translating them would freeze whichever locale
 * happened to be active into stored data, and a journal read six months later
 * would be half in one language and half in another.
 */
return [

    /**
     * The openings that count as a greeting and nothing more.
     *
     * A list rather than a sentence, because it is matched against, not shown.
     * Every word added here is a word that can no longer start a real
     * conversation — "help" is the trap: on its own it looks like a pleasantry
     * and is in fact the most urgent thing a visitor ever types.
     */
    'greetings' => [
        'hi', 'hey', 'hello', 'yo',
        'good morning', 'good afternoon', 'good evening', 'good day',
        'morning', 'evening',
    ],

    /**
     * The words a confirmation node reads as a yes and as a no, beyond the two
     * labels the author wrote on the node itself.
     *
     * Matched, not shown. A confirmation node is the last thing standing
     * between a conversation and a write, so a list that misses the ordinary
     * way of agreeing in the visitor's language turns "ok" into a refusal and
     * the branch nobody tested into the one everybody takes.
     */
    'approval' => [
        'yes' => ['yes', 'yeah', 'yep', 'ok', 'okay', 'sure', 'confirm', 'confirmed', 'agree'],
        'no' => ['no', 'nope', 'nah', 'cancel', 'stop', 'decline', 'abort'],
    ],
];
