<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Bots\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Str;
use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Http\WidgetEmbed;

class BotForm
{
    /**
     * Three tabs, split by who answers the question the setting asks.
     *
     * The sections used to be one flat column, and the complaint about it was
     * exact: nothing said where the AI settings ended. A prompt, a provider and
     * a monthly ceiling sat between working hours and an origin list, so an
     * installation with no model configured still read as one where every
     * second field was about a model. The tabs are drawn along the only line
     * that matters to whoever fills them in — what the bot is (Basics), what
     * thinks for it (AI), and what a site owner has to be given (Widget).
     *
     * The budgets belong with the model rather than with the rest: they are a
     * ceiling on what a provider may be asked to do, and mean nothing at all on
     * an installation whose driver is "null".
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Tabs::make('bot')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Basics')->schema(self::basics()),
                    Tab::make('AI')->schema(self::ai()),
                    Tab::make('Widget')->schema(self::widget()),
                ]),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected static function basics(): array
    {
        return [
            Section::make('Identity')->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(191)
                        ->live(onBlur: true)
                        /*
                         * Fills the slug only while it is still empty. Deriving
                         * it on every rename would rewrite the slug of a bot
                         * already embedded on a site, and the site would go on
                         * asking for a name nothing answers to.
                         */
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                            if (blank($get('slug'))) {
                                $set('slug', Str::slug($state ?? ''));
                            }
                        }),
                    /*
                     * The slug is what an embed code and a knowledge topic name
                     * the bot by, so it is typed once and then left alone.
                     * Renaming it after a widget is embedded does not break
                     * loudly — the site keeps asking for a bot that no longer
                     * answers to that name, and the chat simply stops opening.
                     */
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true)
                        ->live(onBlur: true)
                        ->helperText('Used by embed codes and by knowledge topics. Changing it silently detaches an embedded widget.'),
                ]),
                Toggle::make('is_active')
                    ->default(true)
                    ->helperText('A switched-off bot never answers; conversations go straight to a person.'),
            ]),

            Section::make('Voice')->schema([
                Textarea::make('greeting')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('The first thing a visitor reads. Sent before any scenario runs.'),
                Textarea::make('prompt')
                    ->label('System prompt')
                    ->rows(6)
                    ->maxLength(8000)
                    ->helperText('Given to the model on every turn. Ignored while the AI driver is "null".'),
            ]),

            Section::make('Working hours')
                ->description('Both left empty means the bot answers around the clock. A window whose end is before its start crosses midnight.')
                ->schema([
                    Grid::make(2)->schema([
                        TimePicker::make('hours_from')->label('From')->seconds(false),
                        TimePicker::make('hours_till')->label('Until')->seconds(false),
                    ]),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function ai(): array
    {
        return [
            Section::make('Model')
                ->description('Left empty, both fall back to the installation defaults, so rotating the whole installation to another provider stays a one-setting change.')
                ->schema([
                    Grid::make(2)->schema([
                        /*
                         * A list of registered drivers, never a text box.
                         *
                         * The factory declines an unknown name instead of
                         * throwing — a typo in a config file must not take the
                         * panel and every conversation down with it — so a
                         * misspelled driver typed here produces a bot that
                         * answers everything scripted and silently refuses to
                         * think, with nothing on the screen to say why. The
                         * options come from the factory rather than from a
                         * literal so that a driver a host registered through
                         * extend() is as choosable as a built-in one; an author
                         * has no way to tell which is which, and a hard-coded
                         * list would leave the host's own provider unreachable
                         * from the only screen that picks one.
                         */
                        Select::make('ai_driver')
                            ->label('AI driver')
                            ->options(fn (): array => app(AiGatewayFactory::class)->names())
                            ->placeholder('The one this installation is set to')
                            ->live()
                            ->helperText(fn (): string => 'Installation default: '.self::defaultDriverName().'.'),
                        /*
                         * Left as free text on purpose: model names are the one
                         * thing no registry here knows. They are a provider's
                         * own catalogue, they change without a release of this
                         * package, and a Select would be a list that is wrong
                         * the week after it is written.
                         */
                        TextInput::make('ai_model')
                            ->label('AI model')
                            ->maxLength(191)
                            ->placeholder('installation default')
                            ->helperText('Whatever the chosen provider calls it, spelled its way — "gpt-4o-mini", "claude-sonnet-4". Empty uses the installation default.'),
                    ]),

                    /*
                     * Says out loud that nothing here is switched on.
                     *
                     * "null" is the shipped default and a perfectly ordinary way
                     * to run this package: scenarios and the knowledge base
                     * answer without a model at all. What is not ordinary is
                     * filling in a system prompt, a model name and a monthly
                     * budget and getting no model — three fields that all look
                     * accepted, and a bot that never uses any of them.
                     */
                    Text::make(fn (): string => 'No model answers for this bot: the driver is "null". '
                        .'Scenarios and the knowledge base still work, but the system prompt, the model name and the budgets below are not read by anything. '
                        .'Pick a driver above, or set CHATBOT_AI_DRIVER for the whole installation.')
                        ->key('ai_disabled_notice')
                        ->color('warning')
                        ->visible(fn (Get $get): bool => self::effectiveDriver($get('ai_driver')) === 'null'),
                ]),

            Section::make('Monthly budget')
                ->description('Reserved before a request to the model leaves, so two conversations arriving together cannot both spend the same remaining balance. Empty means no ceiling.')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('monthly_token_budget')
                            ->label('Tokens per month')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('monthly_cost_budget')
                            ->label('Cost per month')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('In millionths of a minor unit — the same scale the runtime bills in.'),
                    ]),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function widget(): array
    {
        return [
            Section::make('Site widget')->schema([
                Toggle::make('is_public_widget')
                    ->label('Answers the public widget')
                    ->live()
                    ->helperText('The bot an embed code reaches when it names none.'),
                /*
                 * Origins are listed rather than free text because the list is
                 * what an empty value means here: no origin at all, not every
                 * origin. Getting that backwards turns a forgotten field into
                 * an endpoint any site can embed and bill to this
                 * installation's AI budget.
                 */
                /*
                 * Live, so the sentence under the embed code re-reads it as it
                 * is typed: that sentence names the sites this bot answers on,
                 * and one that lagged a field on the same screen would be read
                 * as the answer and be wrong.
                 *
                 * The helper text says "narrows" and not "blocks". It used to
                 * claim an empty list blocked every site, which was false twice
                 * over — nothing read the column at all, and now that something
                 * does, an empty list cannot mean nobody: every bot has one
                 * until somebody fills it in, and reading it that way would
                 * silence every widget in every installation on upgrade. The
                 * list that fails closed is the installation's own.
                 */
                TagsInput::make('allowed_origins')
                    ->placeholder('https://example.com')
                    ->live(onBlur: true)
                    ->helperText('Narrows this bot to some of the allowed sites. Empty means every site the installation allows.'),

                /*
                 * The embed code, with this installation's own URL and this
                 * bot's own slug in it.
                 *
                 * Whoever owns the site is not the person who can look any of
                 * this up. Left to the documentation they copy the example
                 * address out of it, paste a tag pointing at app.example.com,
                 * and the widget never loads — with nothing in the panel that
                 * would have shown them the right one.
                 *
                 * Shown only when a copied tag would actually open a
                 * conversation. A snippet handed over while the widget is off,
                 * or while the allow-list is empty, is a promise kept by a 403
                 * on somebody else's page: it installs cleanly, looks right in
                 * the source, and fails where neither of us is watching.
                 */
                Section::make('Embed code')->schema([
                    Text::make(fn (Get $get): string => WidgetEmbed::snippet((string) $get('slug')))
                        ->key('widget_snippet')
                        ->fontFamily(FontFamily::Mono)
                        ->extraAttributes(['style' => 'white-space: pre-wrap; word-break: break-all;'])
                        ->copyable()
                        ->copyMessage('Embed code copied')
                        ->tooltip('Click to copy')
                        ->visible(fn (Get $get): bool => self::isEmbeddable($get)),

                    /*
                     * The list is reported for this bot and not for the
                     * installation. Both lists decide where the tag works — the
                     * configured one says which sites may reach the endpoints at
                     * all, the bot's own narrows that to the sites it belongs on
                     * — and naming only the first would tell somebody who has
                     * just restricted this bot to one site that it answers on
                     * five.
                     */
                    Text::make(function (Get $get): string {
                        $origins = WidgetEmbed::allowedOrigins($get('allowed_origins') ?? []);

                        return 'Paste it just before the closing </body> tag of every page the chat should appear on. '
                            .'It answers only on the sites below, matched exactly on scheme, host and port: '
                            .($origins === [] ? 'none — the sites this bot names are not among the allowed origins.' : implode(', ', $origins).'.');
                    })
                        ->key('widget_snippet_hint')
                        ->visible(fn (Get $get): bool => self::isEmbeddable($get)),

                    Text::make(fn (Get $get): string => self::embedObstacle($get))
                        ->key('widget_snippet_blocked')
                        ->color('warning')
                        ->visible(fn (Get $get): bool => ! self::isEmbeddable($get)),
                ]),
            ]),
        ];
    }

    /**
     * The driver this bot would actually use, with an empty field meaning the
     * installation default — which is what the runtime does with it.
     */
    protected static function effectiveDriver(mixed $chosen): string
    {
        $chosen = trim((string) ($chosen ?? ''));

        return $chosen !== '' ? $chosen : self::defaultDriverName();
    }

    protected static function defaultDriverName(): string
    {
        $default = trim((string) config('chatbot.ai.default', 'null'));

        return $default !== '' ? $default : 'null';
    }

    /**
     * Whether the snippet would work if it were copied out of this form now.
     */
    protected static function isEmbeddable(Get $get): bool
    {
        return (bool) $get('is_public_widget')
            && filled($get('slug'))
            && WidgetEmbed::isServable();
    }

    /**
     * Names the one thing standing between this bot and a working embed.
     *
     * One reason at a time, and the nearest one first: a list of everything
     * that could be wrong reads as a wall, and the reader only ever has to fix
     * the first of them before the next is worth knowing about.
     */
    protected static function embedObstacle(Get $get): string
    {
        if (! $get('is_public_widget')) {
            return 'This bot does not answer the public widget, so there is nothing to embed. '
                .'Switch it on above and the code to paste appears here.';
        }

        if (blank($get('slug'))) {
            return 'Give the bot a slug first: the embed code is how a site names which bot answers, and it names it by slug.';
        }

        if (! config('chatbot.widget.enabled', true)) {
            return 'The site widget is switched off for this whole installation (chatbot.widget.enabled), '
                .'so every widget request is refused whatever is pasted onto a page.';
        }

        return 'No site may embed the widget yet: CHATBOT_WIDGET_ORIGINS is empty, and an empty list blocks every origin '
            .'rather than allowing all of them. List the sites that should carry the chat — scheme, host and port, comma separated — and the code appears here.';
    }
}
