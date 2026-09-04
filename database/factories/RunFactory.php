<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MB\FilamentChatbot\Enums\RunOutcome;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\Scenario;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    protected $model = Run::class;

    /**
     * A fresh run is open: no ended_at and the Running outcome. The two are
     * set together on purpose — they are the same state written twice, and a
     * factory allowed to produce them out of step would seed rows that no real
     * engine path can create.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'scenario_id' => Scenario::factory(),
            'version_id' => null,
            'scenario_slug' => $this->faker->slug(2),
            'channel' => 'site',
            'started_at' => now(),
            'ended_at' => null,
            'outcome' => RunOutcome::Running,
            'steps_count' => 0,
            'said_count' => 0,
        ];
    }

    public function endedWith(RunOutcome $outcome): static
    {
        return $this->state(fn (): array => [
            'outcome' => $outcome,
            'ended_at' => now(),
        ]);
    }
}
