<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\QualityTests;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use MB\FilamentChatbot\Filament\Concerns\HasChatbotNavigation;
use MB\FilamentChatbot\Filament\Resources\QualityTests\Pages\ManageQualityTests;
use MB\FilamentChatbot\Filament\Resources\QualityTests\Pages\ViewQualityTest;
use MB\FilamentChatbot\Filament\Resources\QualityTests\Schemas\QualityTestForm;
use MB\FilamentChatbot\Filament\Resources\QualityTests\Schemas\QualityTestInfolist;
use MB\FilamentChatbot\Filament\Resources\QualityTests\Tables\QualityTestsTable;
use MB\FilamentChatbot\Models\QualityTest;

/**
 * Scripted conversations the bot has to survive, and the screen they live on.
 *
 * The runner and the console command existed before this did, and that gap was
 * the whole problem: a check could be written by hand into the database and
 * replayed by `chatbot:quality`, which is a way of saying it belonged to whoever
 * had a terminal. The people who know what the bot must never say are the ones
 * who write its wording, and they do not have one.
 *
 * **A list with modals plus one read-only screen.** Writing a check is a form,
 * and reading a failed one is a transcript — two different jobs, and the second
 * is the one that gets opened. The transcript is a page rather than a modal
 * because it is read next to the canvas, scrolled, and quoted into a message to
 * whoever wrote the scenario.
 */
class QualityTestResource extends Resource
{
    use HasChatbotNavigation;

    protected static ?string $model = QualityTest::class;

    /** Named by its own title on its own pages — see `BotResource` for why. */
    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    /** Under `chatbot/`, for the reason `HasChatbotNavigation` sets out. */
    protected static ?string $slug = 'chatbot/quality-checks';

    /**
     * Beside the run journal rather than beside the scenarios.
     *
     * A check is opened after something has gone wrong, in the same movement as
     * the journal — not while a scenario is being drawn.
     */
    protected static function chatbotNavigationOffset(): int
    {
        return 6;
    }

    public static function getModelLabel(): string
    {
        return __('chatbot::panel.quality.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('chatbot::panel.quality.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return QualityTestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return QualityTestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QualityTestsTable::configure($table);
    }

    /**
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ManageQualityTests::route('/'),
            'view' => ViewQualityTest::route('/{record}'),
        ];
    }
}
