<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MB\FilamentChatbot\Enums\KnowledgeMode;
use MB\FilamentChatbot\Models\KnowledgeEntry;

/**
 * @extends Factory<KnowledgeEntry>
 */
class KnowledgeEntryFactory extends Factory
{
    protected $model = KnowledgeEntry::class;

    /**
     * The default entry belongs to no bot, which makes it shared by all of them.
     *
     * A default that created a bot would give every test a second row it never
     * asked for, and tests about which entries a bot can see would then be
     * counting fixtures instead of behaviour.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bot_id' => null,
            'topic_key' => 'topic-'.$this->faker->unique()->numerify('######'),
            'category' => null,
            'question' => $this->faker->sentence(),
            'answer' => $this->faker->paragraph(),
            'scenario_slug' => null,
            'keywords' => [],
            'variants' => [],
            'mode' => KnowledgeMode::Answer,
            'priority' => null,
            'is_active' => true,
        ];
    }

    public function forBot(int $botId): static
    {
        return $this->state(fn (): array => ['bot_id' => $botId]);
    }

    /**
     * A topic that starts a scenario carries no answer text.
     *
     * Leaving the text in place would let a runtime that reads the wrong field
     * pass its tests while sending a paragraph where a form was meant.
     */
    public function leadingToScenario(string $slug): static
    {
        return $this->state(fn (): array => [
            'mode' => KnowledgeMode::Scenario,
            'scenario_slug' => $slug,
            'answer' => null,
        ]);
    }

    /**
     * A topic the bot must never answer on its own.
     */
    public function handoff(): static
    {
        return $this->state(fn (): array => [
            'mode' => KnowledgeMode::Handoff,
            'answer' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
