<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\QualityTests\Tables;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use MB\FilamentChatbot\Bot\Graph\ScenarioVersions;
use MB\FilamentChatbot\Enums\QualityOutcome;
use MB\FilamentChatbot\Models\QualityTest;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Quality\QualityResult;
use MB\FilamentChatbot\Quality\QualityRunner;

/**
 * The suite, and the button that runs it.
 *
 * **The verdict column shows three things the outcome column cannot.** A check
 * that has never run is not passing; a check whose scenario has been redrawn
 * since is neither passing nor failing — its verdict describes a graph that no
 * longer exists. Both used to read as the stored outcome, which is how a green
 * tick ends up under a scenario somebody changed this morning.
 */
class QualityTestsTable
{
    /**
     * States that are not run outcomes: caption key and colour.
     *
     * Neither is green or red on purpose. "Needs a rerun" is not a failure and
     * must not be read as one — nothing is known to be broken, and colouring it
     * red teaches people to ignore red.
     */
    private const array DERIVED = [
        'never' => 'gray',
        'stale' => 'info',
    ];

    /**
     * Colours for the outcomes themselves.
     *
     * Here rather than on the enum, which implements `HasLabel` and nothing
     * else on purpose: what a verdict is called is the package's business
     * everywhere, what colour it is drawn is this screen's.
     *
     * Broken is a warning and not a failure. It means the rehearsal could not
     * be held at all — the scenario is gone, a data resource it reads is
     * switched off — and painting that red beside genuine mismatches is how a
     * suite becomes a wall people stop reading.
     */
    private const array TONES = [
        'passed' => 'success',
        'failed' => 'danger',
        'broken' => 'warning',
    ];

    public static function configure(Table $table): Table
    {
        /*
         * One fingerprinter for the whole render rather than one per row. The
         * fingerprint of a scenario costs a walk of its steps, and without a
         * shared instance every row would pay for it again.
         *
         * Filament's closures capture it by value, so it lives exactly as long
         * as this table is being built — too short a life to go stale against
         * somebody else's edit.
         */
        $versions = app(ScenarioVersions::class);

        $verdictOf = self::verdict($versions);

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('chatbot::panel.quality.table.check'))
                    ->description(fn (QualityTest $record): string => implode(' → ', $record->saidLines()))
                    ->searchable()
                    ->wrap(),

                /*
                 * The scenario as a computed column and not `scenario.name`: an
                 * empty key means two different things — "the whole bot" and
                 * "the scenario was deleted" — and a placeholder cannot tell
                 * them apart.
                 */
                TextColumn::make('scenario')
                    ->label(__('chatbot::panel.quality.scenario'))
                    ->state(fn (QualityTest $record): string => match (true) {
                        $record->scenario !== null => (string) $record->scenario->name,
                        $record->isOrphaned() => __('chatbot::panel.quality.table.orphaned', ['slug' => (string) $record->scenario_slug]),
                        default => __('chatbot::panel.quality.table.whole_bot'),
                    })
                    ->color(fn (QualityTest $record): ?string => $record->isOrphaned() ? 'danger' : null)
                    ->wrap(),

                TextColumn::make('channel')
                    ->label(__('chatbot::panel.quality.channel'))
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('outcome')
                    ->label(__('chatbot::panel.quality.table.verdict'))
                    ->state(fn (QualityTest $record): string => self::caption($verdictOf($record)))
                    ->color(fn (QualityTest $record): string => self::tone($verdictOf($record)))
                    ->description(fn (QualityTest $record): ?string => self::firstMismatches($record))
                    ->badge()
                    ->wrap(),

                IconColumn::make('is_required')
                    ->label(__('chatbot::panel.quality.table.before_release'))
                    ->boolean(),

                TextColumn::make('last_run_at')
                    ->label(__('chatbot::panel.quality.table.last_run'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('name')
            /*
             * The steps are loaded with the list because the fingerprint — and
             * so "needs a rerun" in every row — is computed from them. Without
             * this the verdict column would go to the database once per check.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('scenario.steps'))
            ->filters([
                SelectFilter::make('scenario_id')
                    ->label(__('chatbot::panel.quality.scenario'))
                    ->options(fn (): array => Scenario::query()->orderBy('name')->pluck('name', 'id')->all()),

                /*
                 * Filtering by verdict works through a list of keys rather than
                 * a `WHERE`: "needs a rerun" is a fingerprint comparison and is
                 * not a column. One extra query when the filter is applied,
                 * against a column that would otherwise lie about stale results.
                 */
                SelectFilter::make('outcome')
                    ->label(__('chatbot::panel.quality.table.verdict'))
                    ->options(self::verdictOptions())
                    ->query(function (Builder $query, array $data) use ($verdictOf): Builder {
                        $wanted = $data['value'] ?? null;

                        if (blank($wanted)) {
                            return $query;
                        }

                        return $query->whereKey(
                            QualityTest::query()
                                ->with('scenario.steps')
                                ->get()
                                ->filter(fn (QualityTest $record): bool => $verdictOf($record) === $wanted)
                                ->modelKeys(),
                        );
                    }),
            ])
            ->recordActions(ActionGroup::make([
                Action::make('run')
                    ->label(__('chatbot::panel.quality.actions.run'))
                    ->icon(Heroicon::OutlinedPlay)
                    ->authorize(fn (QualityTest $record): bool => Filament::auth()->user()?->can('update', $record) === true)
                    ->action(function (QualityTest $record): void {
                        self::report(app(QualityRunner::class)->run($record), $record->refresh());
                    }),
                ViewAction::make()
                    ->label(__('chatbot::panel.quality.actions.view')),
                EditAction::make(),
                ReplicateAction::make()
                    ->label(__('chatbot::panel.quality.actions.copy'))
                    /*
                     * The copy arrives never having run. The marks of the
                     * previous run belong to the previous conversation, and
                     * carried into a copy they put a green tick under a check
                     * nobody has ever executed.
                     */
                    ->excludeAttributes([
                        'last_run_at',
                        'last_outcome',
                        'last_mismatches',
                        'last_transcript',
                        'last_visited',
                        'last_fingerprint',
                    ])
                    ->beforeReplicaSaved(function (QualityTest $replica): void {
                        $replica->name = $replica->name.' '.__('chatbot::panel.quality.actions.copy_suffix');
                    }),
                DeleteAction::make(),
            ]))
            ->toolbarActions([
                /*
                 * The group run is **not wrapped in a transaction**, and that is
                 * not an oversight: the rehearsal rolls back its own, and an
                 * outer one would leave the marks of every run hanging inside
                 * somebody else's transaction.
                 */
                BulkAction::make('run')
                    ->label(__('chatbot::panel.quality.actions.run_selected'))
                    ->icon(Heroicon::OutlinedPlay)
                    ->authorize(fn (): bool => Filament::auth()->user()?->can('create', QualityTest::class) === true)
                    ->action(function (Collection $records): void {
                        $runner = app(QualityRunner::class);

                        self::reportMany($records
                            ->map(fn (QualityTest $record): QualityOutcome => $runner->run($record)->outcome())
                            ->all());
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('chatbot::panel.quality.table.empty_heading'))
            ->emptyStateDescription(__('chatbot::panel.quality.table.empty_description'));
    }

    /**
     * What the verdict column shows: the run outcome, or the state between runs.
     */
    private static function verdict(ScenarioVersions $versions): Closure
    {
        return function (QualityTest $record) use ($versions): string {
            if ($record->last_run_at === null) {
                return 'never';
            }

            $scenario = $record->scenario;

            if ($record->needsRerun($scenario === null ? null : $versions->fingerprint($scenario))) {
                return 'stale';
            }

            return $record->last_outcome?->value ?? 'never';
        };
    }

    /**
     * The first couple of mismatches, under the badge.
     *
     * Two rather than all of them: the list is read while scanning for which
     * check to open, and a check that missed nine expectations would push every
     * other row off the screen.
     */
    private static function firstMismatches(QualityTest $record): ?string
    {
        $mismatches = (array) $record->last_mismatches;

        if ($mismatches === []) {
            return null;
        }

        return implode('; ', array_map(
            static fn (mixed $mismatch): string => is_array($mismatch)
                ? (string) ($mismatch['expected'] ?? '')
                : (string) $mismatch,
            array_slice($mismatches, 0, 2),
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function verdictOptions(): array
    {
        $options = [];

        foreach (array_keys(self::DERIVED) as $verdict) {
            $options[$verdict] = __('chatbot::panel.quality.verdicts.'.$verdict);
        }

        foreach (QualityOutcome::cases() as $outcome) {
            $options[$outcome->value] = $outcome->getLabel();
        }

        return $options;
    }

    private static function caption(string $verdict): string
    {
        return array_key_exists($verdict, self::DERIVED)
            ? __('chatbot::panel.quality.verdicts.'.$verdict)
            : QualityOutcome::from($verdict)->getLabel();
    }

    private static function tone(string $verdict): string
    {
        return self::DERIVED[$verdict] ?? self::TONES[$verdict] ?? 'gray';
    }

    /**
     * The result of one run, as a notification.
     *
     * The mismatches are listed right here rather than pointing at the detail
     * screen: a check is run while the scenario is already being edited, and one
     * more navigation between screens means they do not get read.
     */
    private static function report(QualityResult $result, QualityTest $record): void
    {
        $notification = Notification::make()
            ->title($record->name.': '.mb_strtolower($result->outcome()->getLabel()));

        if ($result->outcome() === QualityOutcome::Passed) {
            $notification->success()->send();

            return;
        }

        $notification
            ->body(implode("\n", array_map(
                static fn (array $mismatch): string => ($mismatch['expected'] ?? '').' — '.($mismatch['actual'] ?? ''),
                $result->mismatches(),
            )) ?: (string) $result->reason())
            ->status($result->outcome() === QualityOutcome::Failed ? 'danger' : 'warning')
            ->persistent()
            ->send();
    }

    /**
     * @param  list<QualityOutcome>  $outcomes
     */
    private static function reportMany(array $outcomes): void
    {
        $failed = count(array_filter(
            $outcomes,
            static fn (QualityOutcome $outcome): bool => $outcome !== QualityOutcome::Passed,
        ));

        Notification::make()
            ->title(__('chatbot::panel.quality.actions.ran', ['count' => count($outcomes)]))
            ->body($failed === 0
                ? __('chatbot::panel.quality.actions.all_passed')
                : __('chatbot::panel.quality.actions.some_failed', ['count' => $failed]))
            ->status($failed === 0 ? 'success' : 'danger')
            ->send();
    }
}
