<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use MB\FilamentChatbot\Models\Bot;

/**
 * @extends Factory<Bot>
 */
class BotFactory extends Factory
{
    protected $model = Bot::class;

    /**
     * The default bot leaves hours, budgets and AI settings empty, which is the
     * state a bot is actually created in. A factory that filled them would let
     * an "empty means always on" bug through every test in the suite, because
     * nothing would ever exercise the empty case.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'is_active' => true,
            'greeting' => 'Hello! How can I help?',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * A bot with an explicit window. Given as a state rather than a default so
     * that tests asking about working hours have to say so out loud.
     */
    public function workingHours(string $from, string $till): static
    {
        return $this->state(fn (): array => [
            'hours_from' => $from,
            'hours_till' => $till,
        ]);
    }
}
