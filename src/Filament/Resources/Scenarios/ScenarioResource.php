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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static function chatbotNavigationOffset(): int
    {
        return 1;
    }

    public static function getModelLabel(): string
    {
        return 'scenario';
    }

    public static function getPluralModelLabel(): string
    {
        return 'scenarios';
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
     * The graph editor belongs here as a third page, at 'graph' =>
     * EditScenarioGraph::route('/{record}/graph').
     *
     * It is absent rather than forgotten: the canvas is a Livewire page with
     * its own front-end build, and shipping a route to a screen that cannot
     * draw anything yet would put a dead link in the record actions of every
     * row. Whoever adds it registers it here and nowhere else — the list and
     * the run journal both link to a scenario's graph by
     * `ScenarioResource::getUrl('graph', ...)`, so this array is the single
     * place that decides whether that link resolves.
     *
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListScenarios::route('/'),
            'edit' => EditScenario::route('/{record}/edit'),
        ];
    }
}
