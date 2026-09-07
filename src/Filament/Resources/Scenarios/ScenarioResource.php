<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Scenarios;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use MB\FilamentChatbot\Filament\Concerns\HasChatbotNavigation;
use MB\FilamentChatbot\Filament\Resources\Scenarios\Pages\EditScenario;
use MB\FilamentChatbot\Filament\Resources\Scenarios\Pages\EditScenarioGraph;
use MB\FilamentChatbot\Filament\Resources\Scenarios\Pages\ListScenarios;
use MB\FilamentChatbot\Filament\Resources\Scenarios\Schemas\ScenarioForm;
use MB\FilamentChatbot\Filament\Resources\Scenarios\Tables\ScenariosTable;
use MB\FilamentChatbot\Models\Scenario;

/**
 * The conversations a bot knows how to have.
 *
 * This screen owns everything true of a scenario as a whole — its name, whether
 * it is switched on, in what order it is tried, and the contract a routing model
 * reads. What the scenario actually does is the graph, and the graph is a
 * separate screen because it is a canvas, not a form.
 */
class ScenarioResource extends Resource
{
    use HasChatbotNavigation;

    protected static ?string $model = Scenario::class;

    /** Named by its own name on its own pages — see `BotResource` for why. */
    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static function chatbotNavigationOffset(): int
    {
        return 2;
    }

    public static function getModelLabel(): string
    {
        return __('chatbot::panel.scenarios.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('chatbot::panel.scenarios.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return ScenarioForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScenariosTable::configure($table);
    }

    /**
     * The form and the canvas are two pages of one record, deliberately.
     *
     * A scenario has two kinds of property and they want different screens: what
     * is true of it as a whole belongs in a form, and what it does is a shape
     * that only reads as a drawing. Squeezing the graph into a form field would
     * give a canvas the height of a text area.
     *
     * Everything that links to a scenario's graph does so through
     * `ScenarioResource::getUrl('graph', ...)`, so this array is the single
     * place that decides whether those links resolve.
     *
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListScenarios::route('/'),
            'edit' => EditScenario::route('/{record}/edit'),
            'graph' => EditScenarioGraph::route('/{record}/graph'),
        ];
    }
}
