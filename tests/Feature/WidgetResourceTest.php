<?php

declare(strict_types=1);

use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use MB\FilamentChatbot\Filament\Resources\Widgets\Pages\CreateWidget;
use MB\FilamentChatbot\Filament\Resources\Widgets\Pages\EditWidget;
use MB\FilamentChatbot\Filament\Resources\Widgets\Pages\ListWidgets;
use MB\FilamentChatbot\Filament\Resources\Widgets\WidgetResource;
use MB\FilamentChatbot\Http\WidgetDraft;
use MB\FilamentChatbot\Models\Widget;

beforeEach(function (): void {
    signIn();

    config()->set('chatbot.widget.enabled', true);
    config()->set('chatbot.widget.origins', ['https://shop.example.com']);
});

it('lists the widgets that exist', function (): void {
    $widgets = Widget::factory()->count(3)->create();

    Livewire::test(ListWidgets::class)
        ->assertCanSeeTableRecords($widgets);
});

/**
 * Every appearance setting arrives on screen filled in, not only the ones
 * somebody has already changed.
 *
 * A stored theme holds what was set and nothing else, so a form bound straight
 * to it draws one filled colour box and seven empty ones. An empty box does not
 * read as "the default" — it reads as "not set", which is a state this widget is
 * never in, and it leaves no way to learn what the panel background currently
 * is. The preview beside it is drawing that very background, so the screen
 * disagrees with itself.
 *
 * Both pages, because they fill from different places: an existing widget keeps
 * what it stored and gains the rest, a new one starts from the whole set.
 */
it('fills the appearance form with the values the widget actually uses', function (): void {
    $widget = Widget::factory()->create(['theme' => ['accent' => '#0ea5e9']]);

    $stored = Livewire::test(EditWidget::class, ['record' => $widget->getKey()])
        ->get('data')['theme'] ?? [];

    expect($stored['accent'])->toBe('#0ea5e9')
        ->and($stored['surface'])->toBe('#ffffff')
        ->and($stored['agent'])->toBe('#f1f5f9')
        /*
         * By value and not by type: the number survives a round trip through
         * Livewire's state as a float, which is Livewire's business and not a
         * thing this test has an opinion about.
         */
        ->and($stored['width'])->toEqual(360)
        ->and($stored['bubbles'])->toBe('rounded');

    $fresh = Livewire::test(CreateWidget::class)->get('data')['theme'] ?? [];

    expect($fresh['accent'])->toBe('#4f46e5')
        ->and($fresh['surface'])->toBe('#ffffff')
        ->and($fresh['radius'])->toEqual(16);
});

/**
 * The key is filled in from the name once and never touched again.
 *
 * Both halves matter, and the second is the one with teeth. The key is what a
 * tag on somebody else's page carries; rewriting it on a rename would leave
 * that page asking for a widget that no longer exists, and the failure is
 * silent on both sides — the chat simply stops opening, nothing is logged here,
 * and the site owner has no idea a name was changed in a panel they cannot see.
 * Renaming a widget is an everyday act; re-pointing a customer's page is not.
 *
 * The first half is here so the second cannot pass vacuously: a form that
 * derived nothing at all would satisfy "the key did not change" perfectly.
 */
it('derives the key from the name only while it is empty, and never on a rename', function (): void {
    Livewire::test(CreateWidget::class)
        ->set('data.name', 'Night desk')
        ->assertFormSet(['key' => 'night-desk'])
        ->set('data.name', 'Weekend desk')
        ->assertFormSet(['key' => 'night-desk']);
});

/**
 * The embed code carries this installation's own address and this widget's own
 * key.
 *
 * Whoever owns the site is not the person who can look either of those up. A
 * placeholder — the documentation's `app.example.com`, a widget called `demo`
 * because the example was — gets pasted verbatim onto a live page, and the chat
 * never loads: nothing is logged here, because nothing ever arrives.
 */
it('shows an embed code with the real widget key and the real script url', function (): void {
    $widget = Widget::factory()->create(['key' => 'night-desk']);

    Livewire::test(EditWidget::class, ['record' => $widget->getKey()])
        ->assertSuccessful()
        ->assertSchemaComponentExists(
            'widget_snippet',
            checkComponentUsing: function (Text $component): bool {
                $snippet = (string) $component->getContent();

                return str_contains($snippet, 'data-widget="night-desk"')
                    && str_contains($snippet, route('chatbot.widget.script'));
            },
        )
        ->assertSchemaComponentVisible('widget_snippet');
});

/**
 * A switched-off widget gets no code to paste.
 *
 * Showing one is a promise the endpoint refuses to keep: the tag installs
 * cleanly, looks right in the source, and the first message comes back 403 — on
 * somebody else's page, where neither the site owner nor this panel is
 * watching. The reason is said in words instead.
 */
it('hides the embed code for a widget that is switched off', function (): void {
    $widget = Widget::factory()->inactive()->create(['key' => 'night-desk']);

    Livewire::test(EditWidget::class, ['record' => $widget->getKey()])
        ->assertSchemaComponentHidden('widget_snippet')
        ->assertSchemaComponentVisible('widget_snippet_blocked');
});

/**
 * And no code while no site is allowed to embed anything.
 *
 * An empty `CHATBOT_WIDGET_ORIGINS` blocks every origin rather than allowing
 * all of them, which is the safe direction and the surprising one. A snippet
 * handed over in that state fails identically to a broken one, and the setting
 * that would fix it is not on this screen — so it is named here.
 */
it('hides the embed code while no origin is allowed to use it', function (): void {
    config()->set('chatbot.widget.origins', []);

    $widget = Widget::factory()->create(['key' => 'night-desk']);

    Livewire::test(EditWidget::class, ['record' => $widget->getKey()])
        ->assertSchemaComponentHidden('widget_snippet')
        ->assertSchemaComponentExists(
            'widget_snippet_blocked',
            checkComponentUsing: fn (Text $component): bool => str_contains(
                (string) $component->getContent(),
                'CHATBOT_WIDGET_ORIGINS',
            ),
        );
});

/**
 * The frame's address carries what is on the form, not what is in the row.
 *
 * This is the join between the two halves, and it is the half that fails
 * quietly: the frame keeps rendering, the widget inside it keeps looking
 * plausible, and it answers with the colour that was saved rather than the one
 * being chosen. Nobody reads that as a stale preview — they read it as a change
 * that did not take, and go looking in the wrong place for an hour.
 *
 * It is also what makes the frame reload at all. The draft is in the address
 * precisely so that a changed field changes `src`; carried any other way, the
 * address would stay the same and the browser would have no reason to fetch it
 * again.
 */
it('points the preview at the draft on the screen rather than at the stored row', function (): void {
    $widget = Widget::factory()->create(['theme' => ['accent' => '#111111']]);

    $html = (string) Livewire::test(EditWidget::class, ['record' => $widget->getKey()])
        ->set('data.theme.accent', '#00ff00')
        ->html();

    expect($html)->toMatch('/widget-preview\/workbench\?draft=[A-Za-z0-9_-]+/');

    preg_match('/widget-preview\/workbench\?draft=([A-Za-z0-9_-]+)/', $html, $found);

    expect(WidgetDraft::decode($found[1]))
        ->toHaveKey('theme.accent', '#00ff00');
});

/**
 * The preview frame is a screen of the panel, not a public page.
 *
 * It lives outside the panel's own path because it has to be a plain document
 * with a script tag on it, and that is exactly how a route ends up
 * unauthenticated by accident. Left open it would render text from a query
 * string inside a chat bubble on this application's own origin, to anybody who
 * guessed the address.
 */
it('refuses the preview frame to anybody who is not signed in', function (): void {
    auth()->guard('workbench')->logout();

    $this->get(WidgetDraft::url('workbench', []))->assertForbidden();
});

/**
 * And to somebody the host has refused the widgets to.
 *
 * The panel screen and the frame are one feature, so a gate that closes the
 * first has to close the second — otherwise the interesting half stays reachable
 * by URL for exactly the people who were told no.
 */
it('obeys a gate that refuses the widgets, on the screen and in the frame', function (): void {
    Gate::define('chatbot.view.widgets', fn (): bool => false);

    expect(WidgetResource::canViewAny())->toBeFalse();

    $this->get(WidgetDraft::url('workbench', []))->assertForbidden();
});

/**
 * The preview draws the real widget and never a picture of one.
 *
 * A hand-rolled bubble in this page would be a second implementation of how the
 * widget looks, and it would part company with the first the week after either
 * changed. The failure that follows is the only one a preview cannot survive:
 * it does not look broken, it looks authoritative and is wrong — somebody picks
 * a colour scheme against it and finds out on a customer's site.
 *
 * So the frame is held to carrying the script and to drawing none of the
 * widget's own furniture itself.
 */
it('draws the preview with the real widget script rather than markup of its own', function (): void {
    $response = $this->get(WidgetDraft::url('workbench', []));

    $response->assertOk()->assertSee(route('chatbot.widget.script'), escape: false);

    $html = (string) $response->getContent();

    expect($html)->not->toContain('chatbot__launcher')
        ->and($html)->not->toContain('chatbot__panel')
        ->and($html)->not->toContain('chatbot__bubble');
});

/**
 * What the frame shows is what is on the screen, not what is in the database.
 *
 * The whole point of a preview is the change that has not been saved yet.
 * Reading the stored row instead would answer with the colour somebody has just
 * replaced — which does not read as a stale preview, it reads as a change that
 * did not take.
 */
it('previews the draft on the screen rather than the widget that was saved', function (): void {
    Widget::factory()->create(['key' => 'night-desk', 'theme' => ['accent' => '#111111']]);

    $this->get(WidgetDraft::url('workbench', ['theme' => ['accent' => '#00ff00']]))
        ->assertOk()
        ->assertSee('#00ff00', escape: false)
        ->assertDontSee('#111111', escape: false);
});

/**
 * The draft goes through the same gate as a saved theme.
 *
 * Two failures at once if it does not. The frame renders a value from the query
 * string straight into a `style` attribute, which turns an authenticated page
 * into a place to write CSS from a URL; and the preview would happily show a
 * design that the real widget refuses, so somebody would settle on a look, save
 * it, and watch it revert.
 */
it('sanitises the draft it is given instead of previewing what cannot be saved', function (): void {
    $this->get(WidgetDraft::url('workbench', ['theme' => ['accent' => 'var(--sneaky)']]))
        ->assertOk()
        ->assertDontSee('var(--sneaky)', escape: false)
        ->assertSee('#4f46e5', escape: false);
});
