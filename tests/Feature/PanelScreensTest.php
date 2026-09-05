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

beforeEach(function (): void {
    signIn();
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
 * No screen asks the *application* who is signed in.
 *
 * `auth()` resolves the application's default guard. A panel may sign people in
 * on a guard of its own — hosts commonly do, staff against one table and
 * customers against another — and against such a host every `auth()` call in
 * this package answers `null` while an operator is plainly looking at the page.
 * `Filament::auth()` asks the current panel instead, which is right in both
 * shapes.
 *
 * Read from the source rather than exercised, because the failure has no
 * symptom to catch: a screen simply disappears from the menu, a badge counts a
 * guest, an author is stored as null. The suite covers the behaviour on the
 * desk, where it was found; this stops the next screen reintroducing it, which
 * a behavioural test could only do by growing a case per screen forever.
 */
it('never asks the default guard who is signed in', function (): void {
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src/Filament'),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        /*
         * Tokenised rather than searched as text. The docblock explaining this
         * very rule has to quote the call it forbids, and a plain search reads
         * that sentence as a violation — a rule that cannot be documented
         * without breaking itself.
         */
        $code = '';

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        if (preg_match('/(?<!::)\bauth\s*\(\s*\)/', $code) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    $this->assertSame(
        [],
        $offenders,
        'These panel classes ask the application default guard who is signed in, '
        .'which answers null on a panel with its own guard. Use Filament::auth(): '
        .implode(', ', $offenders),
    );
});

/**
 * The canvas follows Filament's theme, and takes it from the `dark` class alone.
 *
 * Filament decides light or dark itself — in its "system" setting too, where it
 * reads the media query and resolves it — and says so in one way only: `dark` on
 * `<html>` for dark, no class at all for light. There is no `light` class to
 * match on.
 *
 * So a `prefers-color-scheme` rule in this stylesheet does not add a fallback,
 * it overrides Filament. The file used to carry one, with a `:root.light` block
 * meant to overrule it; `:root.light` matched nothing, and the person the pair
 * was written to protect — somebody who chooses light on a machine set to dark,
 * an ordinary thing to do — got a dark canvas inside a light panel.
 *
 * Checked against the built bundle rather than the source, because the bundle is
 * what ships: `dist/` is committed and a host never runs the build, so a fixed
 * source with a stale bundle is exactly as broken and looks fixed in review.
 */
it('themes the canvas by Filament class, never by the system colour scheme', function (): void {
    $bundle = dirname(__DIR__, 2).'/dist/chatbot-graph.css';

    $this->assertFileExists($bundle, 'The canvas stylesheet is committed, not built on install.');

    $css = (string) file_get_contents($bundle);

    $this->assertStringNotContainsString(
        'prefers-color-scheme',
        $css,
        'The canvas must not read the system colour scheme: it overrides the theme '
        .'Filament resolved, and gives a dark canvas to anybody who chose light on a dark machine.',
    );

    $this->assertStringNotContainsString(
        ':root.light',
        $css,
        'Filament sets no `light` class, so this selector matches nothing and whatever it was overruling wins.',
    );

    $this->assertStringContainsString(
        ':root.dark .chatbot-graph',
        $css,
        'Dark is the one thing Filament marks, and the canvas has to answer to it.',
    );
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
