<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\QualityTests\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use MB\FilamentChatbot\Enums\QualityOutcome;
use MB\FilamentChatbot\Filament\Resources\QualityTests\QualityTestResource;
use MB\FilamentChatbot\Models\QualityTest;
use MB\FilamentChatbot\Quality\QualityRunner;

class ManageQualityTests extends ManageRecords
{
    protected static string $resource = QualityTestResource::class;

    public function getSubheading(): string
    {
        return __('chatbot::panel.quality.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * "Run the lot" is the button this screen exists for. Selecting
             * rows first is the workflow for a suite somebody is editing; the
             * question actually asked before a release is whether all of it is
             * green, and making that require a select-all is how the answer
             * stops being checked.
             *
             * Confirmed because it holds the request open for as long as the
             * suite takes, and a person who clicked it by accident on a
             * hundred checks has no way to stop it.
             */
            Action::make('runAll')
                ->label(__('chatbot::panel.quality.actions.run_all'))
                ->icon(Heroicon::OutlinedPlay)
                ->color('gray')
                ->requiresConfirmation()
                ->authorize(fn (): bool => Filament::auth()->user()?->can('create', QualityTest::class) === true)
                ->action(function (): void {
                    $runner = app(QualityRunner::class);

                    $outcomes = QualityTest::query()
                        ->active()
                        ->get()
                        ->map(fn (QualityTest $check): QualityOutcome => $runner->run($check)->outcome());

                    $failed = $outcomes->reject(
                        fn (QualityOutcome $outcome): bool => $outcome === QualityOutcome::Passed,
                    )->count();

                    Notification::make()
                        ->title(__('chatbot::panel.quality.actions.ran', ['count' => $outcomes->count()]))
                        ->body($failed === 0
                            ? __('chatbot::panel.quality.actions.all_passed')
                            : __('chatbot::panel.quality.actions.some_failed', ['count' => $failed]))
                        ->status($failed === 0 ? 'success' : 'danger')
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}
