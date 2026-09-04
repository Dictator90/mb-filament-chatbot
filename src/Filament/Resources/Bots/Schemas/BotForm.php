<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Bots\Schemas;

use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
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

            Section::make('Model')
                ->description('Left empty, both fall back to the installation defaults, so rotating the whole installation to another provider stays a one-setting change.')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('ai_driver')
                            ->label('AI driver')
                            ->maxLength(64)
                            ->placeholder((string) config('chatbot.ai.default')),
                        TextInput::make('ai_model')
                            ->label('AI model')
                            ->maxLength(191)
                            ->placeholder('installation default'),
                    ]),
                ]),

            Section::make('Working hours')
                ->description('Both left empty means the bot answers around the clock. A window whose end is before its start crosses midnight.')
                ->schema([
                    Grid::make(2)->schema([
                        TimePicker::make('hours_from')->label('From')->seconds(false),
                        TimePicker::make('hours_till')->label('Until')->seconds(false),
                    ]),
                ]),

            Section::make('Site widget')->schema([
                Toggle::make('is_public_widget')
                    ->label('Answers the public widget')
                    ->helperText('The bot an embed code reaches when it names none.'),
                /*
                 * Origins are listed rather than free text because the list is
                 * what an empty value means here: no origin at all, not every
                 * origin. Getting that backwards turns a forgotten field into
                 * an endpoint any site can embed and bill to this
                 * installation's AI budget.
                 */
                TagsInput::make('allowed_origins')
                    ->placeholder('https://example.com')
                    ->helperText('Sites allowed to embed this bot. Empty blocks every site rather than allowing all of them.'),
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
        ]);
    }
}
