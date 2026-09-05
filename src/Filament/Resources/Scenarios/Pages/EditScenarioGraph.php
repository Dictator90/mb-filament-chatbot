<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Scenarios\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use MB\FilamentChatbot\Bot\Graph\GraphConfig;
use MB\FilamentChatbot\Bot\Graph\ScenarioGraph;
use MB\FilamentChatbot\Bot\Graph\ScenarioVersions;
use MB\FilamentChatbot\Exceptions\ScenarioNotPublishable;
use MB\FilamentChatbot\Filament\Resources\Scenarios\ScenarioResource;
use MB\FilamentChatbot\Models\Scenario;

/**
 * The canvas where a scenario is drawn.
 *
 * The whole contract with the browser is four things: two methods it may call,
 * the dictionaries it is given once, and the graph it starts with. Keeping that
 * surface deliberately small is what lets the canvas be replaced, tested or
 * driven by something else entirely without touching the engine — and it is
 * what stops a redraw from turning into a server round trip.
 *
 * The draft in the browser is not the scenario. Nothing is written until save,
 * and `checkGraph` exists precisely so an author can ask "what is wrong with
 * this" before committing to it.
 */
class EditScenarioGraph extends Page
{
    protected string $view = 'chatbot::scenario-graph';

    public ?int $record = null;

    /**
     * @var array<string, mixed>
     */
    public array $graph = [];

    public static function getResource(): string
    {
        return ScenarioResource::class;
    }

    public function mount(int|string $record): void
    {
        $this->record = (int) $record;
        $this->graph = app(ScenarioGraph::class)->toGraph($this->scenario());
    }

    public function getTitle(): string|Htmlable
    {
        return $this->scenario()->name;
    }

    public function getBreadcrumbs(): array
    {
        return [
            ScenarioResource::getUrl('index') => 'Scenarios',
            $this->scenario()->name,
        ];
    }

    /**
     * The scenario, resolved once per request.
     *
     * Steps come with it because everything on this page asks about them — the
     * graph, the problems, the release state — and a page that fetched them
     * three times would do it on every Livewire round trip as well.
     */
    public function scenario(): Scenario
    {
        return once(fn (): Scenario => Scenario::query()
            ->with('steps')
            ->findOrFail($this->record));
    }

    /**
     * Everything the canvas needs to draw itself, in one payload.
     *
     * @return array<string, mixed>
     */
    public function graphConfig(): array
    {
        return app(GraphConfig::class)->forScenario($this->scenario());
    }

    /**
     * Writes the draft and reports what is still wrong with it.
     *
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public function saveGraph(array $graph): array
    {
        $scenario = $this->scenario();

        app(ScenarioGraph::class)->apply($scenario, $graph);

        $fresh = $scenario->fresh(['steps']);
        $issues = app(ScenarioGraph::class)->issues($fresh);

        Notification::make()
            ->title('Scenario saved')
            ->success()
            ->send();

        return [
            'issues' => $issues,
            'blocking' => $this->blockingCount($issues),
        ];
    }

    /**
     * Reports the problems in a draft without writing anything.
     *
     * The check runs against a copy of the scenario carrying the draft's steps,
     * so it exercises exactly the same code as the real one. A second, "quick"
     * validator written for the browser would answer a different question from
     * the one that blocks a release, which is the answer that matters.
     *
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public function checkGraph(array $graph): array
    {
        $issues = app(ScenarioGraph::class)->issuesForDraft($this->scenario(), $graph);

        return [
            'issues' => $issues,
            'blocking' => $this->blockingCount($issues),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->publishAction(),
        ];
    }

    protected function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->icon('heroicon-o-rocket-launch')
            ->requiresConfirmation()
            ->modalHeading('Publish this scenario')
            ->modalDescription('The live version is what conversations already in progress keep using. New conversations start on the version you publish.')
            ->action(function (): void {
                try {
                    $version = app(ScenarioVersions::class)->publish(
                        $this->scenario(),
                        Filament::auth()->id(),
                    );
                } catch (ScenarioNotPublishable $exception) {
                    /*
                     * The refusal names what is wrong rather than saying no.
                     * Publication is the last moment a defect is still cheap,
                     * and an author who cannot see which node is at fault will
                     * go looking on the canvas, where blocking problems are
                     * already marked — so the message points there.
                     */
                    Notification::make()
                        ->title('Not ready to publish')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("Published version {$version->number}")
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  list<array{severity: string, key: ?string, message: string}>  $issues
     */
    protected function blockingCount(array $issues): int
    {
        return count(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['severity'] ?? '') === 'blocking',
        ));
    }
}
