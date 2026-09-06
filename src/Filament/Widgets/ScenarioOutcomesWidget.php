<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use MB\FilamentChatbot\Enums\RunOutcome;
use MB\FilamentChatbot\Filament\Widgets\Concerns\ReadsChatStatistics;
use MB\FilamentChatbot\Models\Run;

/**
 * Where each scenario's runs end: finished, handed over, or broken.
 *
 * The tile on the stats widget says how often the bot needs a person. This says
 * which scenario is doing it, which is the difference between knowing there is
 * a problem and being able to fix one — and the fix is usually a single missing
 * arrow in one scenario, invisible in any total.
 *
 * "Broken" is its own column and not part of the handovers, although the engine
 * calls a human in both cases. A dead end, a loop that hit the step limit, a
 * step deleted under a waiting visitor and a crash are all mistakes in the
 * drawing that somebody can go and correct; a handoff is the scenario doing
 * what it was built to do. Merged into one bar, the most common authoring
 * mistake there is would be permanently disguised as the bot working properly.
 */
class ScenarioOutcomesWidget extends ChartWidget
{
    use ReadsChatStatistics;

    protected const int WINDOW_DAYS = 30;

    /**
     * How many scenarios get a column.
     *
     * The busiest few, because the chart is read to decide what to fix next and
     * a scenario that ran twice this month is not that. The rest are still
     * counted in the stats widget's handover share, so nothing goes missing —
     * it is only this chart that stops at six.
     */
    private const int SCENARIO_LIMIT = 6;

    protected ?string $heading = 'Where runs end';

    protected ?string $description = 'Finished scenario runs over the last 30 days, busiest scenarios first.';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * Finished runs per scenario, split three ways.
     *
     * Public so a test can read the counts rather than the drawing.
     *
     * One grouped query, and one that stays one however many scenarios a host
     * has: counting per scenario in a loop would put the number of queries on
     * the dashboard in the hands of whoever adds scenarios.
     *
     * `whereHas('conversation')` keeps out rehearsals. Their runs are ordinary
     * rows here — the engine runs for real during a quality check — and only
     * the conversation behind them is marked, so asking for the conversation is
     * what brings its global scope to bear. A suite rehearsed nightly would
     * otherwise dominate this chart and send somebody off to fix a scenario
     * that no customer has ever failed on.
     *
     * @return array<string, array{finished: int, handed_off: int, broken: int}>
     */
    public function outcomes(): array
    {
        return $this->remember('outcomes', function (): array {
            $rows = Run::query()
                ->whereHas('conversation')
                ->whereNotNull('ended_at')
                ->where('ended_at', '>=', $this->since(self::WINDOW_DAYS))
                ->selectRaw('scenario_slug')
                ->selectRaw('outcome')
                ->selectRaw('count(*) as total')
                ->groupBy('scenario_slug', 'outcome')
                ->get();

            $scenarios = [];

            foreach ($rows as $row) {
                $slug = (string) ($row->scenario_slug ?? 'unknown');
                $scenarios[$slug] ??= ['finished' => 0, 'handed_off' => 0, 'broken' => 0];
                $scenarios[$slug][$this->bucket($row->outcome)] += (int) $row->total;
            }

            uasort($scenarios, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

            return array_slice($scenarios, 0, self::SCENARIO_LIMIT, preserve_keys: true);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $scenarios = $this->outcomes();

        return [
            'labels' => array_keys($scenarios),
            'datasets' => [
                [
                    'label' => 'Finished',
                    'data' => array_values(array_map(static fn (array $row): int => $row['finished'], $scenarios)),
                    'backgroundColor' => 'rgb(22, 163, 74)',
                ],
                [
                    'label' => 'Handed to a person',
                    'data' => array_values(array_map(static fn (array $row): int => $row['handed_off'], $scenarios)),
                    'backgroundColor' => 'rgb(217, 119, 6)',
                ],
                [
                    'label' => 'Broken',
                    'data' => array_values(array_map(static fn (array $row): int => $row['broken'], $scenarios)),
                    'backgroundColor' => 'rgb(220, 38, 38)',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|RawJs|null
     */
    protected function getOptions(): array|RawJs|null
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }

    /**
     * Which of the three columns an outcome belongs in.
     *
     * A run still going has no place here at all, and it cannot arrive: only
     * runs with an end date are counted. The default catches it anyway rather
     * than leaving a `match` to throw on a dashboard — an outcome added to the
     * enum tomorrow would take the panel's front page down with it.
     */
    private function bucket(RunOutcome|string|null $outcome): string
    {
        $outcome = $outcome instanceof RunOutcome ? $outcome : RunOutcome::tryFrom((string) $outcome);

        return match ($outcome) {
            RunOutcome::Closed => 'finished',
            RunOutcome::DeadEnd, RunOutcome::StepLimit, RunOutcome::Lost, RunOutcome::Failed => 'broken',
            default => 'handed_off',
        };
    }
}
