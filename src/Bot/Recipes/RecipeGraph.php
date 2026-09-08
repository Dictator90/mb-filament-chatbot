<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Recipes;

use Illuminate\Database\Eloquent\Collection;
use MB\FilamentChatbot\Bot\Graph\ScenarioGraph;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Turns a recipe into the drawing the canvas already knows how to read.
 *
 * **The translation is the canvas's own, not a second one.** The steps are put
 * into `Step` models that are never saved, and handed to `ScenarioGraph::toGraph()`
 * — the same method that draws a stored scenario. A parser written here instead
 * would be a second definition of what a node's arrows are, and the one that
 * drifted would produce a recipe that looks different on the board from the same
 * recipe after it is saved.
 *
 * Nothing is written and nothing is positioned. The nodes come back without
 * coordinates and the editor lays them out with the same auto-arrange the author
 * uses on their own drawing: a recipe placed at coordinates chosen here would
 * land on top of whatever is already on the board.
 */
final class RecipeGraph
{
    public function __construct(
        private readonly ChatbotRegistry $registry,
        private readonly ScenarioGraph $graph,
    ) {}

    /**
     * Every recipe, as a list somebody can choose from.
     *
     * The nodes are not included. A list of three shapes with three whole
     * diagrams attached is a payload the canvas fetches on every page load and
     * uses on almost none of them — the drawing is asked for when one is picked.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public function all(): array
    {
        $offered = [];

        foreach ($this->registry->recipes() as $key => $recipe) {
            $offered[] = [
                'key' => (string) $key,
                'label' => $recipe->label(),
                'description' => $recipe->description(),
            ];
        }

        return $offered;
    }

    /**
     * One recipe's nodes and arrows, or null when nothing claims the key.
     *
     * Null rather than an exception: the key comes from a browser that may have
     * had the page open since before a host package was removed, and a canvas
     * that threw on a stale button would lose whatever the author had drawn.
     *
     * @return array<string, mixed>|null
     */
    public function graph(string $key): ?array
    {
        $recipe = $this->registry->recipe($key);

        if ($recipe === null) {
            return null;
        }

        $steps = [];

        foreach ($recipe->steps() as $sort => $step) {
            $steps[] = new Step($step + ['sort' => $sort, 'pos_x' => 0, 'pos_y' => 0]);
        }

        $scenario = new Scenario;
        $scenario->setRelation('steps', new Collection($steps));

        $drawn = $this->graph->toGraph($scenario);

        /*
         * The canvas furniture is dropped rather than sent empty. A recipe is
         * inserted *into* a scenario somebody is already drawing, and a payload
         * carrying notes, groups and a viewport would invite the editor to
         * replace theirs with three empty lists and a reset zoom.
         */
        return [
            'nodes' => $drawn['nodes'],
            'edges' => $drawn['edges'],
        ];
    }
}
