<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\KnowledgeEntries;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use MB\FilamentChatbot\Filament\Concerns\HasChatbotNavigation;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Pages\CreateKnowledgeEntry;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Pages\EditKnowledgeEntry;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Pages\ListKnowledgeEntries;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Schemas\KnowledgeEntryForm;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Tables\KnowledgeEntriesTable;
use MB\FilamentChatbot\Models\KnowledgeEntry;

/**
 * Topics the bot knows about — including the ones it must refuse to answer.
 *
 * An entry is not always an answer, which is why this screen is not a list of
 * canned replies: the entries that withhold are the only record of the
 * questions a bot may not improvise on, and they stay useful with the answer
 * field empty.
 */
class KnowledgeEntryResource extends Resource
{
    use HasChatbotNavigation;

    protected static ?string $model = KnowledgeEntry::class;

    /** Named by its own topic on its own pages — see `BotResource` for why. */
    protected static ?string $recordTitleAttribute = 'topic_key';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static function chatbotNavigationOffset(): int
    {
        return 3;
    }

    public static function getModelLabel(): string
    {
        return __('chatbot::panel.knowledge.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('chatbot::panel.knowledge.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return KnowledgeEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeEntriesTable::configure($table);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeEntries::route('/'),
            'create' => CreateKnowledgeEntry::route('/create'),
            'edit' => EditKnowledgeEntry::route('/{record}/edit'),
        ];
    }
}
