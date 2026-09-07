<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Bots;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use MB\FilamentChatbot\Filament\Concerns\HasChatbotNavigation;
use MB\FilamentChatbot\Filament\Resources\Bots\Pages\EditBot;
use MB\FilamentChatbot\Filament\Resources\Bots\Pages\ListBots;
use MB\FilamentChatbot\Filament\Resources\Bots\Schemas\BotForm;
use MB\FilamentChatbot\Filament\Resources\Bots\Tables\BotsTable;
use MB\FilamentChatbot\Models\Bot;

/**
 * The assistants themselves: voice, working hours and budget.
 *
 * First in the block because everything else in it points at a bot — scenarios,
 * knowledge topics, runs. Someone arriving at an empty installation has to find
 * the screen that makes the others addressable, and an alphabetical menu would
 * have hidden it in the middle.
 */
class BotResource extends Resource
{
    use HasChatbotNavigation;

    protected static ?string $model = Bot::class;

    /**
     * Names the record on its own pages instead of repeating the model label.
     *
     * Filament titles an edit page "Edit :label", and with nothing named here
     * `:label` falls back to the model label: "Edit bot", on the page for one
     * particular bot among several — a heading that answers a question nobody
     * asked. In Russian it is also ungrammatical, because the phrase wants a
     * genitive and one label cannot be that and a menu entry at once. The
     * record's own name fixes both at once and says which one you are looking
     * at.
     */
    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static function chatbotNavigationOffset(): int
    {
        return 0;
    }

    public static function getModelLabel(): string
    {
        return __('chatbot::panel.bots.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('chatbot::panel.bots.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return BotForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BotsTable::configure($table);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListBots::route('/'),
            'edit' => EditBot::route('/{record}/edit'),
        ];
    }
}
