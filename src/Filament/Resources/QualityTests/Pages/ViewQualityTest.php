<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\QualityTests\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use MB\FilamentChatbot\Enums\QualityOutcome;
use MB\FilamentChatbot\Filament\Resources\QualityTests\QualityTestResource;
use MB\FilamentChatbot\Models\QualityTest;
use MB\FilamentChatbot\Quality\QualityRunner;

class ViewQualityTest extends ViewRecord
{
    protected static string $resource = QualityTestResource::class;

    /**
     * Says which run this is, because a transcript with no date is read as the
     * current state of the bot — which is exactly what a stale one is not.
     */
    public function getSubheading(): ?string
    {
        /** @var QualityTest $check */
        $check = $this->getRecord();

        if ($check->last_run_at === null) {
            return __('chatbot::panel.quality.detail.never_run');
        }

        return __('chatbot::panel.quality.detail.ran_at', [
            'when' => $check->last_run_at->diffForHumans(),
            'outcome' => mb_strtolower((string) $check->last_outcome?->getLabel()),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Re-run from here rather than only from the list, because this is
             * where somebody lands after editing the scenario the check
             * complained about: the answer to "did I fix it" is one button
             * away, and the transcript below refreshes into the new one.
             */
            Action::make('run')
                ->label(__('chatbot::panel.quality.actions.run'))
                ->icon(Heroicon::OutlinedPlay)
                ->authorize(fn (): bool => Filament::auth()->user()?->can('update', $this->getRecord()) === true)
                ->action(function (): void {
                    /** @var QualityTest $check */
                    $check = $this->getRecord();

                    $result = app(QualityRunner::class)->run($check);

                    $this->refreshFormData([
                        'last_run_at',
                        'last_outcome',
                        'last_mismatches',
                        'last_transcript',
                        'last_visited',
                    ]);

                    Notification::make()
                        ->title($result->outcome()->getLabel())
                        ->status($result->outcome() === QualityOutcome::Passed ? 'success' : 'danger')
                        ->send();
                }),

            EditAction::make(),
        ];
    }
}
