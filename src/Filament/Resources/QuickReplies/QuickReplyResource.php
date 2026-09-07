<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\QuickReplies;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use MB\FilamentChatbot\Filament\Concerns\HasChatbotNavigation;
use MB\FilamentChatbot\Filament\Resources\QuickReplies\Pages\ManageQuickReplies;
use MB\FilamentChatbot\Filament\Resources\QuickReplies\Schemas\QuickReplyForm;
use MB\FilamentChatbot\Filament\Resources\QuickReplies\Tables\QuickRepliesTable;
use MB\FilamentChatbot\Models\QuickReply;

/**
 * Phrases an operator drops into a conversation instead of retyping them.
 *
 * One page with modals, not a list plus two screens: a quick reply is written
 * mid-shift out of a sentence the operator has just typed for the third time,
 * and a workflow that navigates away from the list is one they stop using.
 */
class QuickReplyResource extends Resource
{
    use HasChatbotNavigation;

    protected static ?string $model = QuickReply::class;

    /** Named by its own title on its own pages — see `BotResource` for why. */
    protected static ?string $recordTitleAttribute = 'title';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;

    protected static function chatbotNavigationOffset(): int
    {
        return 4;
    }

    public static function getModelLabel(): string
    {
        return __('chatbot::panel.quick_replies.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('chatbot::panel.quick_replies.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return QuickReplyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuickRepliesTable::configure($table);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ManageQuickReplies::route('/'),
        ];
    }
}
