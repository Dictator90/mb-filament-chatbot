<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * @extends Factory<Step>
 */
class StepFactory extends Factory
{
    protected $model = Step::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scenario_id' => Scenario::factory(),
            'key' => $this->faker->unique()->slug(2),
            'sort' => 0,
            'pos_x' => 0,
            'pos_y' => 0,
            'type' => 'say',
            'body' => $this->faker->sentence(),
            'options' => null,
            'config' => null,
            'next_key' => null,
            'collect_into' => null,
        ];
    }

    /**
     * The start node. Its key is fixed rather than random, because a scenario
     * has exactly one and every test that builds a graph has to be able to
     * point at it without first looking it up.
     */
    public function start(): static
    {
        return $this->state(fn (): array => [
            'key' => 'start',
            'type' => Step::TYPE_START,
            'body' => null,
            'config' => ['keywords' => []],
        ]);
    }

    public function say(?string $body = null): static
    {
        return $this->state(fn (): array => [
            'type' => 'say',
            'body' => $body ?? $this->faker->sentence(),
        ]);
    }

    /**
     * A question with two answerable options.
     *
     * Both options carry a destination, because an option without one is
     * dropped when the step is read ({@see Step::choices()}) and a factory
     * producing invisible options would make every test built on it lie.
     */
    public function ask(?string $body = null): static
    {
        return $this->state(fn (): array => [
            'type' => 'ask',
            'body' => $body ?? $this->faker->sentence().'?',
            'options' => [
                ['label' => 'Yes', 'next' => 'yes', 'words' => ['yes'], 'when' => []],
                ['label' => 'No', 'next' => 'no', 'words' => ['no'], 'when' => []],
            ],
        ]);
    }
}
