<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Runs;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Filament\Concerns\HasChatbotNavigation;
use MB\FilamentChatbot\Filament\Resources\Runs\Pages\ListRuns;
use MB\FilamentChatbot\Filament\Resources\Runs\Pages\ViewRun;
use MB\FilamentChatbot\Filament\Resources\Runs\Schemas\RunInfolist;
use MB\FilamentChatbot\Filament\Resources\Runs\Tables\RunsTable;
use MB\FilamentChatbot\Models\Run;

/**
 * The journal: which nodes a conversation genuinely passed through.
 *
 * A transcript shows what the bot said; only the trace shows which branch it
 * took to say it, and those two answers diverge exactly where the scenario is
 * wrong. Without it, a branch taken, an empty lookup and an unmatched knowledge
 * topic leave no record at all, and "why did it send them there" has no answer.
 *
 * Read-only, and that is a property of a journal rather than of this screen: a
 * trace that can be edited is a trace nobody can cite. The refusals below are
 * unconditional for that reason, not delegated to a policy a host might write
 * permissively by accident.
 */
class RunResource extends Resource
{
    use HasChatbotNavigation;

    protected static ?string $model = Run::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static function chatbotNavigationOffset(): int
    {
        return 5;
    }

    public static function getModelLabel(): string
    {
        return __('chatbot::panel.runs.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('chatbot::panel.runs.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return RunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RunsTable::configure($table);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListRuns::route('/'),
            /*
             * The trace is a page rather than a modal: a run has a couple of
             * dozen steps with the detail of every branch beside them, and
             * that detail is the reason anyone opens it.
             */
            'view' => ViewRun::route('/{record}'),
        ];
    }
}
