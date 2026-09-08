<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Recipes;

/**
 * Answer from the knowledge base, and fetch a person when it cannot.
 *
 * The smallest bot worth switching on. It needs no menu and no wording of its
 * own: whatever somebody types goes to the knowledge base, and the "did not
 * know" exit goes to a human. An installation with a filled-in base and this
 * one scenario answers most of what arrives.
 *
 * **It exists as a recipe because that second exit is the thing authors forget.**
 * A knowledge node drawn with only its first arrow connected produces a bot
 * that answers the questions it knows and goes silent on the rest — and silence
 * is indistinguishable, from the visitor's chair, from a company that ignored
 * them.
 */
final class KnowledgeDeskRecipe implements Recipe
{
    public static function key(): string
    {
        return 'knowledge-desk';
    }

    public function label(): string
    {
        return __('chatbot::nodes.recipes.knowledge_desk.label');
    }

    public function description(): string
    {
        return __('chatbot::nodes.recipes.knowledge_desk.description');
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
                'next_key' => 'knowledge',
                'config' => ['trigger' => 'fallback'],
            ],
            [
                'key' => 'knowledge',
                'type' => 'answer',
                'next_key' => 'more',
                'config' => ['else_key' => 'sorry'],
            ],
            [
                'key' => 'more',
                'type' => 'say',
                'body' => __('chatbot::nodes.recipes.knowledge_desk.more'),
            ],
            [
                'key' => 'sorry',
                'type' => 'say',
                'next_key' => 'human',
                'body' => __('chatbot::nodes.recipes.knowledge_desk.sorry'),
            ],
            [
                'key' => 'human',
                'type' => 'handoff',
            ],
        ];
    }
}
