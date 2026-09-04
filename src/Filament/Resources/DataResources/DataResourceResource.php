<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\DataResources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use MB\FilamentChatbot\Filament\Concerns\HasChatbotNavigation;
use MB\FilamentChatbot\Filament\Resources\DataResources\Pages\CreateDataResource;
use MB\FilamentChatbot\Filament\Resources\DataResources\Pages\EditDataResource;
use MB\FilamentChatbot\Filament\Resources\DataResources\Pages\ListDataResources;
use MB\FilamentChatbot\Filament\Resources\DataResources\Schemas\DataResourceForm;
use MB\FilamentChatbot\Filament\Resources\DataResources\Tables\DataResourcesTable;
use MB\FilamentChatbot\Models\DataResource;

/**
 * Declared windows onto the host application's own records.
 *
 * Everything filled in here is a ceiling the runtime refuses to cross rather
 * than a preference it takes into account: an undeclared field, an operator
 * outside the list or a limit above the maximum fails the query instead of
 * being trimmed to fit. That is why this screen is a declaration and not a
 * settings page — every checkbox on it decides what may leave the application.
 */
class DataResourceResource extends Resource
{
    use HasChatbotNavigation;

    protected static ?string $model = DataResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static function chatbotNavigationOffset(): int
    {
        return 4;
    }

    public static function getModelLabel(): string
    {
        return 'data resource';
    }

    public static function getPluralModelLabel(): string
    {
        return 'data resources';
    }

    public static function form(Schema $schema): Schema
    {
        return DataResourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DataResourcesTable::configure($table);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListDataResources::route('/'),
            'create' => CreateDataResource::route('/create'),
            'edit' => EditDataResource::route('/{record}/edit'),
        ];
    }
}
