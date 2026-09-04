<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\RunStep;

/**
 * @extends Factory<RunStep>
 */
class RunStepFactory extends Factory
{
    protected $model = RunStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'seq' => 1,
            'step_key' => $this->faker->slug(2),
            'step_type' => 'say',
            'scenario_slug' => $this->faker->slug(2),
            'said' => null,
            'outcome' => RunStep::PASSED,
            'payload' => null,
            'occurred_at' => now(),
        ];
    }

    public function said(?string $text = null): static
    {
        return $this->state(fn (): array => [
            'outcome' => RunStep::SAID,
            'said' => $text ?? $this->faker->sentence(),
        ]);
    }

    public function waiting(): static
    {
        return $this->state(fn (): array => [
            'step_type' => 'ask',
            'outcome' => RunStep::WAITING,
        ]);
    }
}
