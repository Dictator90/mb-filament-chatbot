<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\ScenarioVersion;

/**
 * @extends Factory<Scenario>
 */
class ScenarioFactory extends Factory
{
    protected $model = Scenario::class;

    /**
     * bot_id stays null, which means "available to every bot".
     *
     * A factory that made a bot of its own would drag the whole bot table into
     * every test that only wanted a scenario, and would quietly stop testing
     * the shared-scenario case — the one hosts running a single bot actually
     * use. Tests that need a bot say so with ->for($bot).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'bot_id' => null,
            'name' => $name,
            'slug' => $this->faker->unique()->slug(2),
            'is_active' => true,
            'priority' => 0,
            'description' => null,
            'invocation' => null,
            'live_version_id' => null,
        ];
    }

    /**
     * A scenario with a published version behind it.
     *
     * The version is created afterwards and pointed at with a second write,
     * because the two rows reference each other: the version needs the
     * scenario's id, and the scenario's live_version_id needs the version's.
     * One of the two links has to wait for the other.
     */
    public function published(?string $note = null): static
    {
        return $this->afterCreating(function (Scenario $scenario) use ($note): void {
            $version = ScenarioVersion::factory()
                ->for($scenario)
                ->published()
                ->create([
                    'number' => 1,
                    'note' => $note,
                ]);

            $scenario->forceFill(['live_version_id' => $version->getKey()])->save();
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
