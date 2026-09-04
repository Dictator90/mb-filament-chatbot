<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MB\FilamentChatbot\Enums\QualityOutcome;
use MB\FilamentChatbot\Models\QualityTest;

/**
 * @extends Factory<QualityTest>
 */
class QualityTestFactory extends Factory
{
    protected $model = QualityTest::class;

    /**
     * A never-run test, which is the state every new one is really in.
     *
     * last_outcome stays null rather than defaulting to passed. A green badge on
     * something that has not run is the one lie this table exists to prevent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bot_id' => null,
            'name' => $this->faker->sentence(3),
            'scenario_id' => null,
            'scenario_slug' => null,
            'channel' => null,
            'context' => null,
            'said' => [$this->faker->sentence()],
            'expectations' => [],
            'is_required' => false,
            'is_active' => true,
            'last_run_at' => null,
            'last_outcome' => null,
            'last_mismatches' => null,
            'last_transcript' => null,
            'last_fingerprint' => null,
        ];
    }

    /**
     * @param  array<int, string>  $said
     * @param  array<array-key, mixed>  $expectations
     */
    public function saying(array $said, array $expectations = []): static
    {
        return $this->state(fn (): array => [
            'said' => $said,
            'expectations' => $expectations,
        ]);
    }

    public function forScenario(?int $scenarioId, ?string $slug = null): static
    {
        return $this->state(fn (): array => [
            'scenario_id' => $scenarioId,
            'scenario_slug' => $slug,
        ]);
    }

    public function required(): static
    {
        return $this->state(fn (): array => ['is_required' => true]);
    }

    public function ranWith(QualityOutcome $outcome): static
    {
        return $this->state(fn (): array => [
            'last_outcome' => $outcome,
            'last_run_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
