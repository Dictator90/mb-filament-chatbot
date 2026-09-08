<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Recipes;

/**
 * Hello, then a menu, then a person.
 *
 * The first scenario nearly everybody writes, and the one that shows what a
 * branching node actually is: three buttons, three arrows, and a fallback for
 * the person who typed something else instead of pressing one.
 *
 * **It ends at a person on purpose.** A first scenario that ended by closing
 * the conversation would teach the shape "the bot decides when we are done",
 * and that is the shape a support desk regrets. Every branch here that the
 * author has not filled in yet leads somewhere a human is, which is what makes
 * it safe to put on a live bot before it is finished.
 */
final class GreetingMenuRecipe implements Recipe
{
    public static function key(): string
    {
        return 'greeting-menu';
    }

    public function label(): string
    {
        return __('chatbot::nodes.recipes.greeting_menu.label');
    }

    public function description(): string
    {
        return __('chatbot::nodes.recipes.greeting_menu.description');
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
                'next_key' => 'hello',
                'config' => ['trigger' => 'greeting'],
            ],
            [
                'key' => 'hello',
                'type' => 'say',
                'next_key' => 'menu',
                'body' => __('chatbot::nodes.recipes.greeting_menu.hello'),
            ],
            [
                'key' => 'menu',
                'type' => 'ask',
                'body' => __('chatbot::nodes.recipes.greeting_menu.question'),
                'options' => [
                    ['label' => __('chatbot::nodes.recipes.greeting_menu.option_question'), 'next' => 'knowledge'],
                    ['label' => __('chatbot::nodes.recipes.greeting_menu.option_order'), 'next' => 'human'],
                    ['label' => __('chatbot::nodes.recipes.greeting_menu.option_human'), 'next' => 'human'],
                ],
                'config' => ['else_key' => 'knowledge'],
            ],
            [
                'key' => 'knowledge',
                'type' => 'answer',
                'next_key' => 'anything_else',
                'config' => ['else_key' => 'human'],
            ],
            [
                'key' => 'anything_else',
                'type' => 'say',
                'body' => __('chatbot::nodes.recipes.greeting_menu.anything_else'),
            ],
            [
                'key' => 'human',
                'type' => 'handoff',
                'body' => __('chatbot::nodes.recipes.greeting_menu.human'),
            ],
        ];
    }
}
