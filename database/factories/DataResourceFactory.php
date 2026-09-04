<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MB\FilamentChatbot\Enums\DataFilterOperator;
use MB\FilamentChatbot\Enums\DataQueryMode;
use MB\FilamentChatbot\Models\DataResource;

/**
 * @extends Factory<DataResource>
 */
class DataResourceFactory extends Factory
{
    protected $model = DataResource::class;

    /**
     * The default row is deliberately dull and deliberately narrow.
     *
     * One returnable field, equality only, no write access. A factory whose
     * defaults are generous makes every test that forgets to restrict something
     * pass, which is the opposite of what a suite about refusals is for.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'resource-'.$this->faker->unique()->numerify('######'),
            'label' => $this->faker->words(2, true),
            'description' => null,
            'query_guidance' => null,
            'model' => 'Workbench\\App\\Models\\User',
            'modes' => [DataQueryMode::List->value, DataQueryMode::First->value],
            'fields' => [
                [
                    'name' => 'id',
                    'label' => 'Id',
                    'returned_by_default' => true,
                    'filterable' => true,
                    'sortable' => true,
                    'operators' => [DataFilterOperator::Equals->value],
                ],
            ],
            'default_sort' => null,
            'default_limit' => 10,
            'max_limit' => 100,
            'scopes' => null,
            'is_writable' => false,
            'write_fields' => null,
            'lock_column' => null,
            'is_active' => true,
        ];
    }

    /**
     * Points the resource at a model with the exact field list a test needs.
     *
     * The field list is the subject of nearly every data-resource test, so it is
     * passed whole rather than merged into the default one: a test that thinks it
     * declared two fields and silently got three cannot prove anything about what
     * is returned by default.
     *
     * @param  class-string|string  $model
     * @param  array<array-key, mixed>  $fields
     */
    public function forModel(string $model, array $fields): static
    {
        return $this->state(fn (): array => [
            'model' => $model,
            'fields' => $fields,
        ]);
    }

    /**
     * @param  array<int, string>  $modes
     */
    public function withModes(array $modes): static
    {
        return $this->state(fn (): array => ['modes' => $modes]);
    }

    /**
     * @param  array<int, string>  $writeFields
     */
    public function writable(array $writeFields = [], ?string $lockColumn = null): static
    {
        return $this->state(fn (): array => [
            'is_writable' => true,
            'write_fields' => $writeFields,
            'lock_column' => $lockColumn,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
