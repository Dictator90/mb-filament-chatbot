<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Runs\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\RunStep;

/**
 * A run in full: the summary, then the trace step by step.
 *
 * The summary sits above the trace on purpose. The question people arrive with
 * is "where did it turn", and the trace answers it row by row; the detail of
 * each branch is a second look, taken by whoever spotted an unexpected turn.
 */
class RunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Run')->schema([
                Grid::make(4)->schema([
                    TextEntry::make('scenario_slug')
                        ->label('Scenario')
                        ->state(fn (Run $record): string => $record->scenario?->name ?? (string) $record->scenario_slug)
                        /*
                         * The slug beneath the name rather than instead of it:
                         * scenarios are named alike ("Greeting", "Greeting for
                         * returning visitors"), and the point of the journal is
                         * knowing which of them answered.
                         */
                        ->helperText(fn (Run $record): string => $record->scenario === null
                            ? $record->scenario_slug.' — scenario deleted'
                            : (string) $record->scenario_slug),
                    TextEntry::make('channel')->badge()->color('gray'),
                    TextEntry::make('outcome')->label('How it ended')->badge()->placeholder('—'),
                    TextEntry::make('conversation_id')
                        ->label('Conversation')
                        ->state(fn (Run $record): string => $record->conversation?->title() ?? 'gone'),
                ]),
                Grid::make(4)->schema([
                    TextEntry::make('started_at')->label('Started')->dateTime(),
                    TextEntry::make('ended_at')
                        ->label('Ended')
                        ->dateTime()
                        ->placeholder('still running'),
                    TextEntry::make('steps_count')->label('Steps'),
                    TextEntry::make('said_count')->label('Replies sent'),
                ]),
            ]),

            Section::make('Trace')
                ->description('One row per node the conversation genuinely passed through.')
                ->schema([
                    RepeatableEntry::make('steps')
                        ->hiddenLabel()
                        ->placeholder('No steps recorded')
                        ->schema([
                            Grid::make(5)->schema([
                                TextEntry::make('seq')->label('#'),
                                TextEntry::make('step_key')->label('Node')->weight('medium'),
                                TextEntry::make('step_type')
                                    ->label('Kind')
                                    ->badge()
                                    ->color('gray')
                                    ->placeholder('—'),
                                TextEntry::make('outcome')
                                    ->label('What happened')
                                    ->badge()
                                    ->color(fn (RunStep $record): string => self::outcomeColor($record)),
                                TextEntry::make('occurred_at')->label('When')->dateTime('H:i:s'),
                            ]),
                            Grid::make(3)->schema([
                                TextEntry::make('said')->label('Visitor wrote')->placeholder('—'),
                                TextEntry::make('payload')
                                    ->label('Detail')
                                    ->state(fn (RunStep $record): string => self::details($record))
                                    ->placeholder('—'),
                                /*
                                 * The scenario slug is on every row, not only
                                 * where it changed: a jump carries the
                                 * conversation into another scenario within
                                 * the same run, and without this column two
                                 * scenarios read as one. Comparing against the
                                 * run's own slug to hide repeats would mean
                                 * asking the parent from every row — tidiness
                                 * bought with a query per step.
                                 */
                                TextEntry::make('scenario_slug')->label('Scenario of the step'),
                            ]),
                        ]),
                ]),
        ]);
    }

    /**
     * Red for what the scenario's author has to fix, amber for what merely
     * left the bot.
     *
     * Spelled out here because RunStep outcomes are constants rather than an
     * enum — a host registers its own steps with their own outcomes, and an
     * unfamiliar value has to stay readable rather than take the journal down.
     */
    private static function outcomeColor(RunStep $step): string
    {
        return match ($step->outcome) {
            RunStep::FAILED => 'danger',
            RunStep::UNKNOWN, RunStep::HANDED_OFF, RunStep::DECLINED, RunStep::EMPTY_RESULT => 'warning',
            RunStep::CLOSED, RunStep::ANSWERED, RunStep::APPROVED => 'success',
            default => 'gray',
        };
    }

    /**
     * The step's payload as "key: value" lines.
     *
     * Unpacked rather than shown as raw json, because it is read by whoever is
     * fixing the scenario and `{"option":"yes","goto":"pay"}` reads to them as
     * a broken screen. An empty value stays visible as a dash: "topic: —" means
     * the knowledge base matched nothing, and dropping the line would answer
     * the reader's question with silence.
     */
    private static function details(RunStep $step): string
    {
        $lines = [];

        foreach ((array) $step->payload as $name => $value) {
            $lines[] = $name.': '.match (true) {
                $value === null, $value === '' => '—',
                is_bool($value) => $value ? 'yes' : 'no',
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '—',
                default => (string) $value,
            };
        }

        return implode("\n", $lines);
    }
}
