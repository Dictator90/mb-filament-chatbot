<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use MB\FilamentChatbot\Filament\Resources\KnowledgeEntries\KnowledgeEntryResource;
use MB\FilamentChatbot\Knowledge\KnowledgeExchange;
use MB\FilamentChatbot\Knowledge\KnowledgeImportPlan;
use MB\FilamentChatbot\Models\KnowledgeEntry;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListKnowledgeEntries extends ListRecords
{
    protected static string $resource = KnowledgeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->exportAction(),
            $this->importAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Exports what is selected on screen.
     *
     * `getTableQueryForExport()` is the table's filters, search and sorting
     * without the paging. Taking every row instead would be a silent
     * substitution: somebody narrows to one section, gets a file with the whole
     * base in it, fixes twenty rows, and hands back a complete file in which
     * everything else has meanwhile moved on.
     *
     * Its own `authorize()` is not optional — a header action in Filament does
     * not consult the policy on its own.
     */
    private function exportAction(): Action
    {
        return Action::make('exportKnowledge')
            ->label(__('chatbot::panel.knowledge.exchange.export'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize(fn (): bool => Filament::auth()->user()?->can('viewAny', KnowledgeEntry::class) === true)
            ->action(function (): StreamedResponse {
                $contents = app(KnowledgeExchange::class)->export($this->getTableQueryForExport()->cursor());

                return response()->streamDownload(
                    fn () => print $contents,
                    KnowledgeExchange::fileName(),
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                );
            });
    }

    /**
     * Loads the edits back.
     *
     * **The dialog shows the plan before anything is written, not after.** A
     * base is a fortnight of somebody's writing, and "it loaded something" is
     * not an answer here. The plan is computed twice — once for the preview and
     * once inside the action — which is cheaper than keeping it between requests
     * and hoping the file in the component's state is the one the preview
     * described.
     */
    private function importAction(): Action
    {
        return Action::make('importKnowledge')
            ->label(__('chatbot::panel.knowledge.exchange.import'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->authorize(fn (): bool => Filament::auth()->user()?->can('create', KnowledgeEntry::class) === true)
            ->modalHeading(__('chatbot::panel.knowledge.exchange.import_heading'))
            ->modalDescription(__('chatbot::panel.knowledge.exchange.import_description'))
            ->modalSubmitActionLabel(__('chatbot::panel.knowledge.exchange.write'))
            ->schema([
                FileUpload::make('file')
                    ->label(__('chatbot::panel.knowledge.exchange.file'))
                    /*
                     * The list is deliberately wide, down to "no idea what this
                     * is". Windows reports the same CSV as `text/csv`, as
                     * `application/vnd.ms-excel` or as `text/plain` depending on
                     * whether Excel is the default program — a narrow list would
                     * refuse the correct file on half the machines, and the
                     * parsing below names everything it did not understand
                     * anyway.
                     */
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/csv',
                        'application/vnd.ms-excel',
                        'application/octet-stream',
                    ])
                    ->maxSize(KnowledgeExchange::MAX_SIZE_KB)
                    /*
                     * Never stored. It is wanted for one parse, and a saved copy
                     * would sit in the filesystem as a duplicate of the
                     * knowledge base that nobody will ever delete.
                     */
                    ->storeFiles(false)
                    ->required()
                    ->live()
                    ->helperText(__('chatbot::panel.knowledge.exchange.file_help')),

                Text::make(fn (Get $get): HtmlString => $this->preview($get('file'))),
            ])
            ->action(function (array $data): void {
                $contents = self::contentsOf($data['file'] ?? null);

                if ($contents === null) {
                    Notification::make()
                        ->danger()
                        ->title(__('chatbot::panel.knowledge.exchange.unreadable'))
                        ->send();

                    return;
                }

                $exchange = app(KnowledgeExchange::class);
                $plan = $exchange->plan($contents);

                if ($plan->isEmpty()) {
                    Notification::make()
                        ->danger()
                        ->title(__('chatbot::panel.knowledge.exchange.nothing_loaded'))
                        ->body(implode(' ', $plan->warnings))
                        ->send();

                    return;
                }

                $exchange->apply($plan);

                Notification::make()
                    ->success()
                    ->title(__('chatbot::panel.knowledge.exchange.loaded'))
                    ->body($plan->summary())
                    ->send();
            });
    }

    /**
     * What the dialog says will happen, computed from the file in the field.
     */
    private function preview(mixed $file): HtmlString
    {
        $contents = self::contentsOf($file);

        if ($contents === null) {
            return new HtmlString(e(__('chatbot::panel.knowledge.exchange.choose_file')));
        }

        $plan = app(KnowledgeExchange::class)->plan($contents);

        $lines = [
            $plan->isEmpty() ? __('chatbot::panel.knowledge.exchange.nothing_loaded') : $plan->summary(),
            ...$this->firstWarnings($plan),
        ];

        return new HtmlString(implode('<br>', array_map(e(...), $lines)));
    }

    /**
     * The first few warnings and a count of the rest.
     *
     * All of them would be a dialog nobody scrolls to the bottom of, and the
     * bottom is where the submit button is. Three name the kind of mistake,
     * which is what somebody needs in order to go and fix the file.
     *
     * @return list<string>
     */
    private function firstWarnings(KnowledgeImportPlan $plan): array
    {
        $shown = array_slice($plan->warnings, 0, 3);
        $rest = count($plan->warnings) - count($shown);

        if ($rest > 0) {
            $shown[] = __('chatbot::panel.knowledge.exchange.more_warnings', ['count' => $rest]);
        }

        return $shown;
    }

    /**
     * The bytes behind whatever the file field holds.
     *
     * The field is `storeFiles(false)`, so what arrives is Livewire's temporary
     * upload rather than a path — and it arrives as an array of them, because a
     * file field is a list even when it holds one.
     */
    private static function contentsOf(mixed $file): ?string
    {
        $file = is_array($file) ? reset($file) : $file;

        if ($file instanceof TemporaryUploadedFile || $file instanceof UploadedFile) {
            return (string) $file->get();
        }

        return null;
    }
}
