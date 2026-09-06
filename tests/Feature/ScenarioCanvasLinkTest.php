<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use MB\FilamentChatbot\Filament\Resources\Scenarios\Pages\EditScenario;
use MB\FilamentChatbot\Filament\Resources\Scenarios\Pages\ListScenarios;
use MB\FilamentChatbot\Filament\Resources\Scenarios\ScenarioResource;
use MB\FilamentChatbot\Models\Scenario;

beforeEach(function (): void {
    signIn();
});

/**
 * The canvas is the point of a scenario, and it needs a way in.
 *
 * The page answered on its own URL from the day it was written and nothing
 * linked to it: the list offered "Edit" and "Delete" and the graph was reached
 * by typing the address. That fails silently in the worst way a screen can —
 * the feature is finished, tested, and invisible, and the report comes back as
 * "there is no way to draw a scenario".
 */
it('links to the canvas from the scenario list', function (): void {
    $scenario = Scenario::factory()->create();

    Livewire::test(ListScenarios::class)
        ->assertActionHasUrl(
            TestAction::make('graph')->table($scenario),
            ScenarioResource::getUrl('graph', ['record' => $scenario]),
        );
});

/**
 * And from the settings form, which is where somebody who meant to draw the
 * scenario lands when they click its name.
 */
it('links to the canvas from the scenario form', function (): void {
    $scenario = Scenario::factory()->create();

    Livewire::test(EditScenario::class, ['record' => $scenario->getKey()])
        ->assertActionHasUrl(
            'graph',
            ScenarioResource::getUrl('graph', ['record' => $scenario]),
        );
});
