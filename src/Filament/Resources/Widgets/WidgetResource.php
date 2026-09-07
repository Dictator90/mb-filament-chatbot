<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Widgets;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use MB\FilamentChatbot\Filament\Concerns\HasChatbotNavigation;
use MB\FilamentChatbot\Filament\Resources\Widgets\Pages\CreateWidget;
use MB\FilamentChatbot\Filament\Resources\Widgets\Pages\EditWidget;
use MB\FilamentChatbot\Filament\Resources\Widgets\Pages\ListWidgets;
use MB\FilamentChatbot\Filament\Resources\Widgets\Schemas\WidgetForm;
use MB\FilamentChatbot\Filament\Resources\Widgets\Tables\WidgetsTable;
use MB\FilamentChatbot\Models\Widget;

/**
 * Where a bot is put, as opposed to what it is.
 *
 * Directly after the bots in the menu because the two are read together and
 * apart for the same reason they are stored apart: a bot is a personality —
 * what it knows, which scenarios it walks, which model thinks for it — and a
 * widget is a placement, which sites carry it and what it looks like there. The
 * same support bot goes on the company site in blue and on a partner's portal
 * in the partner's green, and that is one bot and two widgets.
 *
 * It used to be a tab on the bot form, which could express neither: one look
 * per bot, and an embed tag carrying the bot's own slug onto a public page —
 * so re-pointing a widget meant asking somebody to edit their own website.
 */
class WidgetResource extends Resource
{
    use HasChatbotNavigation;

    protected static ?string $model = Widget::class;

    /** Named by its own name on its own pages — see `BotResource` for why. */
    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleOvalLeftEllipsis;

    /** Under `chatbot/`, for the reason `HasChatbotNavigation` sets out. */
    protected static ?string $slug = 'chatbot/widgets';

    protected static function chatbotNavigationOffset(): int
    {
        return 1;
    }

    public static function getModelLabel(): string
    {
        return __('chatbot::panel.site_widgets.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('chatbot::panel.site_widgets.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return WidgetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WidgetsTable::configure($table);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListWidgets::route('/'),
            'create' => CreateWidget::route('/create'),
            'edit' => EditWidget::route('/{record}/edit'),
        ];
    }
}
