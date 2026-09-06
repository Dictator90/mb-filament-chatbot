<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Livewire\Livewire;
use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Ai\Drivers\NullGateway;
use MB\FilamentChatbot\Filament\Resources\Bots\Pages\EditBot;
use MB\FilamentChatbot\Models\Bot;

beforeEach(function (): void {
    signIn();

    config()->set('chatbot.widget.enabled', true);
    config()->set('chatbot.widget.origins', ['https://shop.example.com']);
});

/**
 * The driver list is the factory's list, never a literal.
 *
 * A host adds its own provider with `extend()`, and a hard-coded list on this
 * screen would leave that provider unreachable from the only form that picks
 * one — with nothing to see: the field looks complete, the driver is simply not
 * in it, and whoever registered it goes looking in their own code.
 */
it('offers every registered AI driver, including one a host added', function (): void {
    app(AiGatewayFactory::class)->extend('house', fn (array $config): NullGateway => new NullGateway);

    $bot = Bot::factory()->create();

    Livewire::test(EditBot::class, ['record' => $bot->getKey()])
        ->assertSchemaComponentExists(
            'ai_driver',
            checkComponentUsing: fn (Select $component): bool => array_key_exists('house', $component->getOptions())
                && array_key_exists('openai', $component->getOptions()),
        );
});

/**
 * The driver is chosen from a list rather than typed.
 *
 * The factory declines an unknown name instead of throwing, on purpose — a typo
 * in configuration must not take the panel down — so a misspelled driver in a
 * text box produces a bot that answers everything scripted and silently never
 * thinks. Nothing on the screen, in the log or in the run journal names the
 * typo as the cause.
 */
it('does not let the AI driver be typed by hand', function (): void {
    $bot = Bot::factory()->create();

    Livewire::test(EditBot::class, ['record' => $bot->getKey()])
        ->assertSchemaComponentExists(
            'ai_driver',
            checkComponentUsing: fn (mixed $component): bool => $component instanceof Select,
        );
});

/**
 * An installation with no model says so where the model settings are.
 *
 * "null" is the shipped default and a fine way to run the package, but the
 * fields around it — a system prompt, a model name, a monthly ceiling — all
 * accept what is typed into them and none of them is read by anything. The
 * silence is the failure: a bot configured with care that never once asks a
 * model, and a screen that looks exactly like one that does.
 */
it('says that no model answers when the driver resolves to null', function (): void {
    config()->set('chatbot.ai.default', 'null');

    $bot = Bot::factory()->create(['ai_driver' => null]);

    Livewire::test(EditBot::class, ['record' => $bot->getKey()])
        ->assertSchemaComponentVisible('ai_disabled_notice')
        ->fillForm(['ai_driver' => 'openai'])
        ->assertSchemaComponentHidden('ai_disabled_notice');
});

/**
 * The embed code carries this installation's own address and this bot's own
 * slug.
 *
 * Whoever owns the site is not the person who can look either of those up. A
 * placeholder — the documentation's `app.example.com`, a bot named `support`
 * because the example was — gets pasted verbatim onto a live page, and the
 * widget never loads: nothing is logged here, because nothing ever arrives.
 */
it('shows an embed code with the real bot slug and the real widget url', function (): void {
    $bot = Bot::factory()->create([
        'slug' => 'night-desk',
        'is_public_widget' => true,
    ]);

    Livewire::test(EditBot::class, ['record' => $bot->getKey()])
        ->assertSuccessful()
        ->assertSchemaComponentExists(
            'widget_snippet',
            checkComponentUsing: function (Text $component): bool {
                $snippet = (string) $component->getContent();

                return str_contains($snippet, 'data-bot="night-desk"')
                    && str_contains($snippet, route('chatbot.widget.script'));
            },
        )
        ->assertSchemaComponentVisible('widget_snippet');
});

/**
 * A bot that does not answer the widget gets no code to paste.
 *
 * Showing one is a promise the endpoint refuses to keep: the tag installs
 * cleanly, the launcher opens, and the first message comes back 403 — on
 * somebody else's page, where neither the site owner nor this panel is
 * watching. The reason is said in words instead.
 */
it('hides the embed code for a bot whose widget is switched off', function (): void {
    $bot = Bot::factory()->create([
        'slug' => 'night-desk',
        'is_public_widget' => false,
    ]);

    Livewire::test(EditBot::class, ['record' => $bot->getKey()])
        ->assertSchemaComponentHidden('widget_snippet')
        ->assertSchemaComponentVisible('widget_snippet_blocked');
});

/**
 * And no code while no site is allowed to embed it.
 *
 * An empty `CHATBOT_WIDGET_ORIGINS` blocks every origin rather than allowing
 * all of them, which is the safe direction and the surprising one. A snippet
 * handed over in that state fails identically to a broken one, and the setting
 * that would fix it is not on this screen — so it is named here.
 */
it('hides the embed code while no origin is allowed to use it', function (): void {
    config()->set('chatbot.widget.origins', []);

    $bot = Bot::factory()->create([
        'slug' => 'night-desk',
        'is_public_widget' => true,
    ]);

    Livewire::test(EditBot::class, ['record' => $bot->getKey()])
        ->assertSchemaComponentHidden('widget_snippet')
        ->assertSchemaComponentExists(
            'widget_snippet_blocked',
            checkComponentUsing: fn (Text $component): bool => str_contains(
                (string) $component->getContent(),
                'CHATBOT_WIDGET_ORIGINS',
            ),
        );
});
