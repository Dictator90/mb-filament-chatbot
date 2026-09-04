<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MB\FilamentChatbot\Models\QuickReply;

/**
 * @extends Factory<QuickReply>
 */
class QuickReplyFactory extends Factory
{
    protected $model = QuickReply::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->words(3, true),
            'body' => $this->faker->paragraph(),
            'shortcut' => null,
            'user_id' => null,
            'sort' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Makes the reply private to one operator.
     */
    public function ownedBy(int $userId): static
    {
        return $this->state(fn (): array => ['user_id' => $userId]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
