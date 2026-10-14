<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\ChannelConnection;

/**
 * @extends Factory<ChannelConnection>
 */
class ChannelConnectionFactory extends Factory
{
    protected $model = ChannelConnection::class;

    /**
     * Telegram by default, switched on, with credentials that look like the
     * real ones: almost every test about connections is about a working one,
     * and a factory that produced a half-filled row would make each of them
     * start by finishing the setup.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'channel' => 'telegram',
            'is_active' => true,
            'credentials' => [
                'token' => '1234567:'.$this->faker->lexify('??????????????????????'),
                'secret' => $this->faker->lexify('????????????????'),
            ],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function on(string $channel, array $credentials = []): static
    {
        return $this->state(fn (): array => [
            'channel' => $channel,
            'credentials' => $credentials,
        ]);
    }
}
