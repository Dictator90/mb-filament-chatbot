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

    /**
     * The button an optional question in a form carries, and the whole answers
     * that mean the same thing when typed.
     *
     * Matched as complete answers, never as words inside one: "don't know"
     * declines a budget question, "don't know where, somewhere warm" answers
     * a destination question.
     */
    'form' => [
        'skip' => 'Skip',
        'skip_words' => ['skip', 'pass', "don't know", 'dont know', 'not sure', 'rather not say', 'no idea', 'next'],
    ],

    /**
     * Month names as regular-expression fragments, January first.
     *
     * Fragments rather than words because a month is written in several forms
     * and only a pattern can say "jan or january" without also matching "may"
     * inside "maybe" — each fragment is matched as a whole word. English is
     * always read as well as the app's own language.
     */
    'months' => [
        'jan(?:uary)?', 'feb(?:ruary)?', 'mar(?:ch)?', 'apr(?:il)?', 'may', 'june?',
        'july?', 'aug(?:ust)?', 'sep(?:t(?:ember)?)?', 'oct(?:ober)?', 'nov(?:ember)?', 'dec(?:ember)?',
    ],

    /**
     * The words after an amount that multiply it, as whole-word patterns.
     *
     * "150k" and "1.5 million" are how a budget is said; read without these
     * they become a hundred and fifty and one and a half.
     */
    'money_multipliers' => [
        'thousand' => ['k', 'thousands?', 'grand'],
        'million' => ['m', 'mln', 'millions?'],
    ],

    /**
     * What replaces a piece of personal data on its way to a language model.
     *
     * Translated because the model reads them: an English placeholder dropped
     * into a Russian sentence is one more word it has to make sense of. Kept in
     * square brackets so that a person reading the transcript afterwards can
     * tell a redaction from something the visitor typed.
     */
    'redacted' => [
        'email' => '[email]',
        'phone' => '[phone]',
        'number' => '[number]',
    ],
];
