<?php

declare(strict_types=1);

use Livewire\Livewire;
use MB\FilamentChatbot\Bot\Graph\ScenarioGraph;
use MB\FilamentChatbot\Bot\Recipes\Recipe;
use MB\FilamentChatbot\Bot\Recipes\RecipeGraph;
use MB\FilamentChatbot\Filament\Resources\Scenarios\Pages\EditScenarioGraph;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Scenarios worth starting from, offered on the canvas.
 *
 * An empty board with one "Start" node on it does not suggest how a
 * conversation is put together out of fifteen boxes, and the shapes that would
 * teach it lived in a seeder — reachable from the console and from nowhere near
 * the screen where somebody is staring at the blank canvas.
 */

/**
 * A recipe is nodes and arrows, and the arrows have to survive the trip.
 *
 * The translation is the canvas's own — the same one that draws a stored
 * scenario — precisely so that a recipe on the board is the same drawing it
 * would be after a save. A parser of its own would drift, and the drift would
 * show as a recipe that loses an arrow the moment it is saved.
 */
it('hands the canvas a drawing with its arrows attached', function (): void {
    $drawn = app(RecipeGraph::class)->graph('greeting-menu');

    expect($drawn)->not->toBeNull()
        ->and($drawn['nodes'])->not->toBeEmpty()
        ->and($drawn['edges'])->not->toBeEmpty();

    $keys = array_column($drawn['nodes'], 'key');

    foreach ($drawn['edges'] as $edge) {
        expect($keys)->toContain($edge['from'])
            ->and($keys)->toContain($edge['to']);
    }
});

/**
 * Every shipped recipe has to be a scenario the engine would accept — otherwise
 * the shortcut past a blank canvas is a shortcut to a page full of red badges,
 * and the author's first act is to repair somebody else's work.
 *
 * Checked through the canvas's own defect rules rather than by eye, which is
 * the same check that refuses a release.
 */
it('offers no recipe the canvas would mark as broken', function (): void {
    foreach (app(ChatbotRegistry::class)->recipes() as $key => $recipe) {
        $scenario = Scenario::factory()->create(['bot_id' => null, 'slug' => 'recipe-'.$key]);

        app(ScenarioGraph::class)->apply($scenario, app(RecipeGraph::class)->graph((string) $key));

        $blocking = array_filter(
            app(ScenarioGraph::class)->issues($scenario->fresh('steps')),
            static fn (array $issue): bool => $issue['severity'] === ScenarioGraph::BLOCKING,
        );

        expect($blocking)->toBe([], "the [{$key}] recipe is not a scenario the canvas accepts");
    }
});

/**
 * A key from a browser that has had the page open since before a host package
 * was removed. Throwing there would lose whatever the author had drawn.
 */
it('answers with nothing for a recipe that is not registered', function (): void {
    expect(app(RecipeGraph::class)->graph('gone-with-its-package'))->toBeNull();
});

/**
 * The list the canvas is given carries names and one-liners and no diagrams:
 * three whole drawings would ride along with every page load of every scenario
 * and be used on almost none of them.
 */
it('lists the recipes without their diagrams', function (): void {
    $offered = app(RecipeGraph::class)->all();

    expect($offered)->not->toBeEmpty()
        ->and($offered[0])->toHaveKeys(['key', 'label', 'description'])
        ->and($offered[0])->not->toHaveKey('nodes');
});

/**
 * A recipe is a piece of somebody's copywriting, so a claimed key is left alone.
 * Two packages claiming one is not a customised built-in — it is one company's
 * wording silently replacing another's on the board.
 */
it('leaves a recipe key its owner already claimed', function (): void {
    $registry = app(ChatbotRegistry::class);

    $registry->registerRecipe(new class implements Recipe
    {
        public static function key(): string
        {
            return 'greeting-menu';
        }

        public function label(): string
        {
            return 'The host wrote this one';
        }

        public function description(): string
        {
            return 'And it must not be replaced by the package on the next boot.';
        }

        public function steps(): array
        {
            return [];
        }
    }::class);

    expect($registry->recipe('greeting-menu')?->label())
        ->toBe(__('chatbot::nodes.recipes.greeting_menu.label'));
});

/**
 * The canvas asks for one recipe when it is picked, and nothing is written by
 * the asking: the nodes arrive as an unsaved change like any other edit.
 */
it('answers the canvas without writing a scenario', function (): void {
    signIn();

    $scenario = Scenario::factory()->create(['bot_id' => null]);
    $before = $scenario->steps()->count();

    $answer = Livewire::test(EditScenarioGraph::class, ['record' => $scenario->getKey()])
        ->call('recipeGraph', 'knowledge-desk')
        ->assertOk()
        ->effects['returns'][0] ?? null;

    expect($answer)->not->toBeNull()
        ->and($answer['nodes'])->not->toBeEmpty()
        ->and($scenario->steps()->count())->toBe($before);
});
