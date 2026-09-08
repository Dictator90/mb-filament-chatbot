<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Recipes;

/**
 * Collect a name and a way to reach somebody, then hand the thread over.
 *
 * The shape that turns an abandoned chat into something the desk can act on.
 * Somebody writes at eleven at night, nobody is there, and without this the
 * thread is a sentence and a browser token; with it the morning shift has a
 * name and a phone number.
 *
 * **The form node is the thing this recipe exists to demonstrate.** It asks only
 * for what it does not already have, it keeps what it collects under names the
 * rest of the scenario can read, and it has a second exit for the person who
 * will not answer — three behaviours nobody guesses from a node called "form".
 * Taking this apart teaches all three in a minute.
 *
 * The "could not collect" branch still fetches a person, deliberately. A visitor
 * who refuses to give a phone number is not a visitor to stop talking to.
 */
final class CallbackFormRecipe implements Recipe
{
    public static function key(): string
    {
        return 'callback-form';
    }

    public function label(): string
    {
        return __('chatbot::nodes.recipes.callback_form.label');
    }

    public function description(): string
    {
        return __('chatbot::nodes.recipes.callback_form.description');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function steps(): array
    {
        return [
            [
                'key' => 'start',
                'type' => 'start',
                'next_key' => 'why',
                'config' => ['trigger' => 'keyword', 'keywords' => ['call me', 'call back', 'ring me']],
            ],
            [
                'key' => 'why',
                'type' => 'say',
                'next_key' => 'details',
                'body' => __('chatbot::nodes.recipes.callback_form.why'),
            ],
            [
                'key' => 'details',
                'type' => 'form',
                'next_key' => 'thanks',
                'config' => ['slots' => ['name', 'phone'], 'attempts' => 2, 'else_key' => 'human'],
            ],
            [
                'key' => 'thanks',
                'type' => 'say',
                'next_key' => 'human',
                'body' => __('chatbot::nodes.recipes.callback_form.thanks'),
            ],
            [
                'key' => 'human',
                'type' => 'handoff',
            ],
        ];
    }
}
