<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use MB\FilamentChatbot\Models\Widget;

/**
 * @extends Factory<Widget>
 */
class WidgetFactory extends Factory
{
    protected $model = Widget::class;

    /**
     * No bot, no theme and no origin list by default, because that is the state
     * a widget is created in and every one of those empties means something:
     * hand the conversation to a person, use the package's colours, and belong
     * wherever the installation allows. A factory that filled them would leave
     * all three untested.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'key' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => Str::ucfirst($name),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * @param  list<string>  $origins
     */
    public function onSites(array $origins): static
    {
        return $this->state(fn (): array => ['allowed_origins' => $origins]);
    }

    /**
     * A placement that takes files.
     *
     * A state and not the default, because "off" is the shipped answer and a
     * factory that quietly switched it on would leave the one thing worth
     * proving — that a widget nobody configured refuses uploads — tested by
     * nothing.
     */
    public function acceptingFiles(): static
    {
        return $this->state(fn (): array => ['features' => ['attachments' => true]]);
    }
}
