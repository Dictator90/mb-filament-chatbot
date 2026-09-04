<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Livewire\Livewire;
use MB\FilamentChatbot\ChatbotPlugin;
use MB\FilamentChatbot\Enums\KnowledgeMode;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Pages\CreateKnowledgeEntry;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Pages\ListKnowledgeEntries;
use MB\FilamentChatbot\Filament\Resources\Runs\RunResource;
use MB\FilamentChatbot\Models\KnowledgeEntry;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * Walks the plugin's own list rather than naming the screens here.
 *
 * A test that names six resources proves the six it names and nothing about the
 * seventh: a resource written and never added to the plugin leaves the suite
 * green and the menu short. Reading the list from the plugin is what makes a
 * forgotten registration fail.
 */
it('registers every screen the plugin declares, and each one opens', function (): void {
    $declared = (new ChatbotPlugin)->resources();
    $registered = Filament::getPanel('workbench')->getResources();

    expect($declared)->not->toBeEmpty();

    foreach ($declared as $resource) {
        expect($registered)->toContain($resource);

        $this->get($resource::getUrl('index'))->assertOk();
    }
});

it('shows the knowledge base entries that exist', function (): void {
    $entries = KnowledgeEntry::factory()->count(3)->create();

    Livewire::test(ListKnowledgeEntries::class)
        ->assertCanSeeTableRecords($entries);
});

/**
 * The Handoff mode means the bot never answers this topic itself. A visible
 * answer box there is a promise the runtime will not keep — somebody writes a
 * refund date into it and it is never sent, which is worse than an empty field
 * because it looks done.
 */
it('hides the answer box for a topic the bot must never answer', function (): void {
    Livewire::test(CreateKnowledgeEntry::class)
        ->fillForm(['mode' => KnowledgeMode::Answer->value])
        ->assertFormFieldExists('answer')
        ->fillForm(['mode' => KnowledgeMode::Handoff->value])
        ->assertFormFieldHidden('answer');
});

/**
 * Runs are a record of what happened. A screen that let somebody edit one would
 * make the journal worth exactly as much as the memory of whoever last opened
 * it — which is none, on the only occasion anyone reads it.
 */
it('does not let anybody create or edit a run', function (): void {
    expect(RunResource::canCreate())->toBeFalse()
        ->and(RunResource::getPages())->not->toHaveKey('create')
        ->and(RunResource::getPages())->not->toHaveKey('edit');
});

/**
 * The host decides where this block of screens sits in its own menu, in one
 * setting. Six literals in six classes would mean six overrides to move the
 * menu, and the one somebody missed would split it across two groups named
 * almost the same.
 */
it('takes its navigation group from configuration, not from a literal', function (): void {
    config(['chatbot.panel.navigation_group' => 'Customer care']);

    foreach ((new ChatbotPlugin)->resources() as $resource) {
        expect($resource::getNavigationGroup())->toBe('Customer care');
    }
});
