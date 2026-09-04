<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\ScenarioVersion;

/**
 * @extends Factory<ScenarioVersion>
 */
class ScenarioVersionFactory extends Factory
{
    protected $model = ScenarioVersion::class;

    /**
     * published_at is null by default: a row exists as soon as a snapshot is
     * taken, and being published is a separate event. A factory that stamped
     * both at once would leave the unpublished snapshot untested, which is the
     * state every version passes through.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scenario_id' => Scenario::factory(),
            'number' => 1,
            'graph' => ['steps' => [], 'edges' => []],
            'pins' => null,
            'fingerprint' => $this->faker->sha1(),
            'note' => null,
            'published_at' => null,
            'published_by_id' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['published_at' => now()]);
    }
}
