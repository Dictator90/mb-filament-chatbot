<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Ai\Drivers\NullGateway;
use MB\FilamentChatbot\Filament\Resources\Bots\Pages\EditBot;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\ChannelConnection;

/*
 * The embed code and the public-widget switch used to be checked in this file.
 * They moved to `WidgetResourceTest` along with the screen that carries them:
 * both describe a placement rather than a bot, and one bot has several — see
 * the docblock on `src/Filament/Resources/Bots/Schemas/BotForm.php`.
 */

beforeEach(function (): void {
    signIn();
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
 * The messenger tabs are rows of another table, carried in and out by hand.
 *
 * Which is exactly why this is worth a test: nothing in Filament fails when
 * that wiring is missing. The form saves, the page reloads, the fields are
 * empty again, and the only sign is a messenger that never delivers —
 * discovered by a customer rather than by the person who filled the form in.
 */
it('saves a messenger account from the bot screen and gives it an address', function (): void {
    $bot = Bot::factory()->create();

    Livewire::test(EditBot::class, ['record' => $bot->getKey()])
        ->fillForm([
            'channels.telegram.is_active' => true,
            'channels.telegram.credentials.token' => '123:abc',
            'channels.telegram.credentials.secret' => 'shared-secret',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $connection = ChannelConnection::query()->where('bot_id', $bot->getKey())->sole();

    expect($connection->channel)->toBe('telegram')
        ->and($connection->is_active)->toBeTrue()
        ->and($connection->credential('token'))->toBe('123:abc')
        ->and($connection->public_id)->toHaveLength(32)
        /*
         * Encrypted at rest, and the assertion is against the stored column
         * rather than the model: a cast that was dropped would still read back
         * correctly through the model and leave the token in plain text in the
         * database, where a backup carries it.
         */
        ->and(DB::table('chatbot_channel_connections')->value('credentials'))->not->toContain('123:abc');
});

/**
 * A messenger nobody filled in gets no row.
 *
 * The alternative is a connection per registered driver for every bot, each
 * with a public address that answers 404 and a line on the readiness screen
 * saying it is not configured — noise that grows with every channel the
 * package learns.
 */
it('creates nothing for a messenger left empty', function (): void {
    $bot = Bot::factory()->create();

    Livewire::test(EditBot::class, ['record' => $bot->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ChannelConnection::query()->count())->toBe(0);
});
