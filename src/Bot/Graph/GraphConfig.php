<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Graph;

use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Slots\Slot;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Bot\Variables\VariableSource;
use MB\FilamentChatbot\Capabilities\CapabilityAction;
use MB\FilamentChatbot\Models\DataResource;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Everything the editor needs about this installation, in one answer.
 *
 * One request and no others, and that is the whole reason the class exists.
 * The canvas redraws on every drag, and a node has to know its colour, its
 * icon, how many handles it has and what its two exits are called in order to
 * be drawn at all. Fetched when needed, those become a request per animation
 * frame — and the node draws blank until each one comes back, so a scenario
 * being dragged flickers. The lists are also stable for the whole session:
 * step kinds and conditions come from service providers and cannot change
 * while the page is open.
 *
 * What is not stable is here too — the defects, the dangling keys, the
 * unreachable nodes — because they are recomputed after each save and the
 * editor asks for the whole box again anyway.
 */
class GraphConfig
{
    /**
     * The canvas geometry the editor and this side have to agree on.
     *
     * Sent from here rather than written into the JavaScript because the grid
     * step is what apply() rounds coordinates against and what the editor snaps
     * to. Two spellings of it produce nodes that jump slightly on every save,
     * which reads as the canvas being unable to keep still.
     */
    protected const CANVAS = [
        'grid' => 20,
        'node_width' => 240,
        'zoom' => ['min' => 0.25, 'max' => 2.0, 'step' => 0.1],
    ];

    public function __construct(
        protected readonly ChatbotRegistry $registry,
        protected readonly ScenarioGraph $graph,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forScenario(Scenario $scenario): array
    {
        $scenario->loadMissing('steps');

        return [
            'types' => $this->types(),
            'defaults' => $this->defaults(),
            'slots' => $this->slots(),
            'conditions' => $this->conditions(),
            'variables' => $this->variables(),
            'dataResources' => $this->dataResources(),
            'capabilities' => $this->capabilities(),
            'scenarios' => $this->scenarios($scenario),
            'issues' => $this->graph->issues($scenario),
            'dangling' => $this->graph->danglingKeys($scenario),
            'unreachable' => $this->graph->unreachableKeys($scenario),
            'canvas' => self::CANVAS,
        ];
    }

    /**
     * The step kinds, each describing itself.
     *
     * Described by the kind and not by a table in the editor, so that a node a
     * host registered this morning is drawn with its own colour, icon and
     * settings panel without a line of JavaScript being written for it.
     *
     * @return list<array<string, mixed>>
     */
    protected function types(): array
    {
        return array_values(array_map(
            static fn (StepType $type): array => $type->toArray(),
            $this->registry->steps(),
        ));
    }

    /**
     * What a node of each kind starts out holding when it is dropped.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function defaults(): array
    {
        $defaults = [];

        foreach ($this->registry->steps() as $key => $type) {
            $defaults[$key] = $type->defaults();
        }

        return $defaults;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function slots(): array
    {
        return array_values(array_map(
            static fn (Slot $slot): array => $slot->toArray(),
            $this->registry->slots(),
        ));
    }

    /**
     * Condition fields, each carrying the operators it allows and which of them
     * still want a value from the author.
     *
     * The second half travels with the first because the editor decides whether
     * to draw the value box from it. Left to be worked out in the browser, the
     * rule would exist twice, and the copy that drifts is the one that shows a
     * box for "is filled" — an author then types a threshold into a field
     * nothing reads.
     *
     * @return list<array<string, mixed>>
     */
    protected function conditions(): array
    {
        $conditions = [];

        foreach ($this->registry->conditions() as $field) {
            /** @var ConditionField $field */
            $described = $field->toArray();
            $needsValue = [];

            foreach ($field->kind()->operators() as $operator) {
                $needsValue[$operator] = ConditionKind::operatorNeedsValue($operator);
            }

            $described['operator_values'] = $needsValue;
            $conditions[] = $described;
        }

        return $conditions;
    }

    /**
     * The variables a message may use, grouped by the source that fills them.
     *
     * availableAfter travels with each group so the editor can stop offering a
     * variable to a node placed above the step that fills it — where it renders
     * as a gap in a sentence the visitor still receives.
     *
     * @return list<array{key: string, label: string, available_after: list<string>, fields: list<array{name: string, label: string}>}>
     */
    protected function variables(): array
    {
        $sources = [];

        foreach ($this->registry->variableSources() as $source) {
            /** @var VariableSource $source */
            $fields = [];

            foreach ($source->fields() as $name => $label) {
                $fields[] = [
                    'name' => $source->key().'.'.$name,
                    'label' => $label,
                ];
            }

            $sources[] = [
                'key' => $source->key(),
                'label' => $source->label(),
                'available_after' => $source->availableAfter(),
                'fields' => $fields,
            ];
        }

        return $sources;
    }

    /**
     * @return list<array{key: string, label: string, fields: list<array{name: string, label: string}>}>
     */
    protected function dataResources(): array
    {
        return DataResource::query()
            ->active()
            ->orderBy('label')
            ->get()
            ->map(static fn (DataResource $resource): array => [
                'key' => $resource->key,
                'label' => $resource->label,
                'fields' => array_values(array_map(
                    static fn (array $descriptor): array => [
                        'name' => $descriptor['name'],
                        'label' => $descriptor['label'],
                    ],
                    $resource->fieldDescriptors(),
                )),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function capabilities(): array
    {
        return array_values(array_map(
            static fn (CapabilityAction $action): array => $action->toArray(),
            $this->registry->capabilities(),
        ));
    }

    /**
     * The scenarios a jump or a call may name.
     *
     * Scoped to the same bot, and including the ones belonging to no bot at
     * all, because those are exactly the shared pieces — identifying a visitor,
     * handing over — that a call node exists to reuse. Offer every scenario in
     * the installation instead and an author picks another bot's, which is a
     * defect nothing on the canvas shows.
     *
     * @return list<array{slug: string, name: string, is_self: bool}>
     */
    protected function scenarios(Scenario $scenario): array
    {
        return Scenario::query()
            ->forBot($scenario->bot_id)
            ->orderBy('name')
            ->get(['id', 'slug', 'name'])
            ->map(static fn (Scenario $other): array => [
                'slug' => $other->slug,
                'name' => $other->name,
                'is_self' => $other->getKey() === $scenario->getKey(),
            ])
            ->all();
    }
}
