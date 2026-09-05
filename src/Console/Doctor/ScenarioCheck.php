<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use Illuminate\Database\Eloquent\Collection;
use MB\FilamentChatbot\Bot\Graph\ScenarioGraph;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Scenario;

/**
 * The three ways a live scenario can be wrong without anybody noticing.
 *
 * All of them are warnings, and that is a judgement about what a deployment
 * should be allowed to do. A half-drawn scenario is a scenario one person is
 * still working on; refusing the release of an unrelated change because of it
 * would make the doctor the thing that gets skipped. What they have in common
 * is that the panel shows none of them from the list screen, and a conversation
 * that meets one of them ends with a visitor being handed to a person — the
 * safe outcome, and the one nobody investigates.
 *
 * The blocking-defect check runs the same code the publish button runs, rather
 * than a lighter version of it. Two implementations of "is this scenario sound"
 * disagreeing is how an author ends up with a green report and a publish that
 * says no.
 */
final class ScenarioCheck extends DoctorCheck
{
    public function __construct(protected readonly ScenarioGraph $graph) {}

    public static function key(): string
    {
        return 'scenarios';
    }

    public function title(): string
    {
        return 'Scenarios';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        /** @var Collection<int, Scenario> $scenarios */
        $scenarios = Scenario::query()->active()->with('steps')->orderBy('slug')->get();

        if ($scenarios->isEmpty()) {
            return [DoctorFinding::warn(
                'No scenario is active, so the bot answers nothing.',
                'Switch one on, or run php artisan chatbot:demo-data to see a working set.',
            )];
        }

        return array_values(array_filter([
            $this->startNodes($scenarios),
            $this->blockingDefects($scenarios),
            $this->fallbacks($scenarios),
        ]));
    }

    /**
     * @param  Collection<int, Scenario>  $scenarios
     */
    protected function startNodes(Collection $scenarios): DoctorFinding
    {
        $without = $scenarios
            ->filter(static fn (Scenario $scenario): bool => $scenario->startStep() === null)
            ->pluck('slug')
            ->values()
            ->all();

        if ($without !== []) {
            return DoctorFinding::warn(
                count($without).' active scenarios have no start node.',
                'Nothing ever begins them, so they are switched on and unreachable. Draw a start node or switch them off.',
                $without,
            );
        }

        return DoctorFinding::pass('Every active scenario begins somewhere.');
    }

    /**
     * @param  Collection<int, Scenario>  $scenarios
     */
    protected function blockingDefects(Collection $scenarios): DoctorFinding
    {
        $broken = [];

        foreach ($scenarios as $scenario) {
            $blocking = array_filter(
                $this->graph->issues($scenario),
                static fn (array $issue): bool => $issue['severity'] === ScenarioGraph::BLOCKING,
            );

            if ($blocking !== []) {
                $broken[] = $scenario->slug.' ('.count($blocking).')';
            }
        }

        if ($broken !== []) {
            return DoctorFinding::warn(
                count($broken).' active scenarios hold defects that would refuse a release.',
                'Open each on the canvas: the nodes carrying the defect are marked. A conversation that reaches one is handed to a person.',
                $broken,
            );
        }

        return DoctorFinding::pass('No active scenario holds a blocking defect.');
    }

    /**
     * More than one scenario claiming to answer whatever nothing else did.
     *
     * Counted per bot and including the scenarios attached to no bot, because a
     * shared fallback and a bot's own fallback are two fallbacks for that bot —
     * counting only within a bot_id would report the most confusing arrangement
     * of all as healthy. Which of the two answers then depends on priority and
     * on row order, so the same message gets a different reply on different
     * days and nobody can say why.
     *
     * @param  Collection<int, Scenario>  $scenarios
     */
    protected function fallbacks(Collection $scenarios): ?DoctorFinding
    {
        $fallbacks = $scenarios->filter(
            static fn (Scenario $scenario): bool => $scenario->startStep()?->setting('trigger') === 'fallback',
        );

        if ($fallbacks->isEmpty()) {
            return DoctorFinding::warn(
                'No active scenario is the fallback.',
                'A message that matches no keyword gets no answer at all. Mark one scenario as answering anything nothing else did.',
            );
        }

        $racing = [];

        foreach ($this->botIds($fallbacks) as $botId) {
            $forBot = $fallbacks
                ->filter(static fn (Scenario $scenario): bool => $scenario->bot_id === null || $scenario->bot_id === $botId)
                ->pluck('slug')
                ->values()
                ->all();

            if (count($forBot) > 1) {
                $racing[] = ($this->botName($botId)).': '.implode(', ', $forBot);
            }
        }

        if ($racing !== []) {
            return DoctorFinding::warn(
                'More than one fallback answers for the same bot.',
                'Which one replies depends on priority and on row order, so the same message can get different answers on different days. Leave one fallback per bot.',
                $racing,
            );
        }

        return DoctorFinding::pass('Each bot has exactly one fallback.');
    }

    /**
     * Every bot a fallback could answer for, plus the shared group.
     *
     * The null entry stands for "no bot at all", which is a real audience: an
     * installation may run scenarios before it has created a bot, and two
     * shared fallbacks race there too.
     *
     * @param  Collection<int, Scenario>  $fallbacks
     * @return list<int|null>
     */
    protected function botIds(Collection $fallbacks): array
    {
        $ids = Bot::query()->active()->pluck('id')->all();

        if ($fallbacks->contains(static fn (Scenario $scenario): bool => $scenario->bot_id === null)) {
            $ids[] = null;
        }

        return array_values($ids);
    }

    protected function botName(?int $botId): string
    {
        if ($botId === null) {
            return 'no bot';
        }

        return (string) (Bot::query()->whereKey($botId)->value('slug') ?? "bot #{$botId}");
    }
}
