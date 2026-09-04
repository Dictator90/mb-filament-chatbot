<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\ConversationStatus;
use MB\FilamentChatbot\Models\Conversation;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * `external_id` is unique per channel in the schema, so it is randomised
     * here rather than sequenced: a test that creates conversations across two
     * channels would otherwise collide on the second one and fail with a
     * constraint error that has nothing to do with what it was testing.
     *
     * No contact and no subject by default. The morphs are the package's only
     * link to the host, and leaving them empty is what proves the chat works
     * before anything has been linked to it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => 'site',
            'external_id' => Str::lower(Str::random(16)),
            'status' => ConversationStatus::Open,
            'bot_state' => BotState::Off,
            'display_name' => $this->faker->name(),
            'last_message_at' => now(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => ConversationStatus::Closed]);
    }

    public function botRunning(): static
    {
        return $this->state(fn (): array => ['bot_state' => BotState::Active]);
    }

    public function handedOff(): static
    {
        return $this->state(fn (): array => [
            'bot_state' => BotState::HandedOff,
            'bot_handed_off_at' => now(),
        ]);
    }

    /**
     * Nobody has read this one yet: an incoming message exists and there is no
     * read mark against it.
     */
    public function unread(): static
    {
        return $this->state(fn (): array => [
            'last_incoming_at' => now(),
            'agent_read_at' => null,
        ]);
    }

    /**
     * Anonymous: no name, no username, no phone, no e-mail. Used to reach the
     * last branch of `title()`, which nothing else ever exercises.
     */
    public function anonymous(): static
    {
        return $this->state(fn (): array => [
            'display_name' => null,
            'username' => null,
            'contact_phone' => null,
            'contact_email' => null,
        ]);
    }
}
