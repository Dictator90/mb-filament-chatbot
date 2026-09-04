<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * `external_id` stays null unless a state asks for one. It is unique per
     * conversation, and a factory that invented a value for every message would
     * make the duplicate-delivery guard untestable — every test would already be
     * handing it distinct ids.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'direction' => MessageDirection::Incoming,
            'author' => MessageAuthor::Visitor,
            'body' => $this->faker->sentence(),
        ];
    }

    public function fromVisitor(): static
    {
        return $this->state(fn (): array => [
            'direction' => MessageDirection::Incoming,
            'author' => MessageAuthor::Visitor,
        ]);
    }

    public function fromBot(): static
    {
        return $this->state(fn (): array => [
            'direction' => MessageDirection::Outgoing,
            'author' => MessageAuthor::Bot,
        ]);
    }

    public function fromOperator(): static
    {
        return $this->state(fn (): array => [
            'direction' => MessageDirection::Outgoing,
            'author' => MessageAuthor::Operator,
        ]);
    }

    public function fromSystem(): static
    {
        return $this->state(fn (): array => [
            'direction' => MessageDirection::Outgoing,
            'author' => MessageAuthor::System,
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => ['delivered_at' => now()]);
    }
}
