<?php

declare(strict_types=1);

use MB\FilamentChatbot\Bot\Greeting;

/**
 * "Hello" and "hello, where is my order" are opposite messages, and one branch
 * of every scenario in the package turns on telling them apart. This is the file
 * that says the branch can be reached in the language the installation speaks.
 */

/**
 * The openings come from the translations, and this is the check that says so.
 *
 * They used to be a list compiled into the class, in English, on an application
 * that ships Russian translations and had the Russian openings sitting unread in
 * `lang/ru/bot.php`. The consequence was not an error anywhere: «Привет» simply
 * was not a greeting, the condition that answers an opening with a menu was
 * never true, and the bot handed «Привет» to the knowledge base and told its own
 * customer it could not help with that. The scenario on the canvas looked right,
 * the run journal looked right, and every node did exactly what it was told.
 */
it('takes the openings from the language the installation speaks', function (): void {
    app()->setLocale('ru');

    $greeting = new Greeting;

    expect($greeting->isGreeting('Привет'))->toBeTrue()
        ->and($greeting->isGreeting('Здравствуйте!'))->toBeTrue()
        ->and($greeting->isGreeting('добрый день'))->toBeTrue();
});

it('still knows the English openings on an English installation', function (): void {
    app()->setLocale('en');

    expect((new Greeting)->isGreeting('Hi!'))->toBeTrue()
        ->and((new Greeting)->isGreeting('good morning'))->toBeTrue();
});

/**
 * The locale is read at the moment of the question, never at construction.
 *
 * A host serving two languages resolves this object once and answers requests in
 * both. A list captured when it was built would be whichever language happened
 * to arrive first, for the life of the worker — a bot that greets some visitors
 * properly and treats the others' hello as a question, with nothing to
 * distinguish the two but the order they arrived in.
 */
it('answers for the locale of the question and not the one it was built in', function (): void {
    app()->setLocale('en');

    $greeting = new Greeting;

    expect($greeting->isGreeting('Привет'))->toBeFalse();

    app()->setLocale('ru');

    expect($greeting->isGreeting('Привет'))->toBeTrue();
});

/**
 * A greeting with a question attached is a question. This is the whole reason
 * the class exists rather than a keyword branch: the second message contains the
 * first, so anything matching by occurrence answers "where is my order" with a
 * menu.
 */
it('is not a greeting once there is a question attached to it', function (): void {
    app()->setLocale('ru');

    $greeting = new Greeting;

    expect($greeting->isGreeting('Привет, где мой заказ?'))->toBeFalse()
        ->and($greeting->isGreeting('Здравствуйте, хочу тур в Египет'))->toBeFalse()
        ->and($greeting->isGreeting(''))->toBeFalse()
        ->and($greeting->isGreeting(null))->toBeFalse();
});

/**
 * A host's own list wins over the translations wholesale — for a shop whose
 * visitors open with something no language file has any business calling a
 * greeting everywhere.
 */
it('lets a host replace the list outright', function (): void {
    app()->setLocale('ru');

    $greeting = (new Greeting)->using(['доброго здоровьица']);

    expect($greeting->isGreeting('Доброго здоровьица!'))->toBeTrue()
        ->and($greeting->isGreeting('Привет'))->toBeFalse();
});
