<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot;

use Illuminate\Database\Eloquent\Collection;
use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Bot\Graph\ScenarioGraph;
use MB\FilamentChatbot\Bot\Graph\ScenarioVersions;
use MB\FilamentChatbot\Enums\QualityOutcome;
use MB\FilamentChatbot\Enums\RunOutcome;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\KnowledgeEntry;
use MB\FilamentChatbot\Models\QualityTest;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\Scenario;

/**
 * Whether the bot is ready to work, and what is stopping it — in one answer.
 *
 * Before this, the state of the bot was assembled from four screens: the
 * scenarios, the canvas, the run journal and the saved checks. "Why is the bot
 * silent" meant visiting all four, and the commonest answer — because it is
 * switched off — was learned last, because the switch is on a fifth.
 *
 * **No panel addresses here, and there cannot be any.** A summary that knew URLs
 * would be a domain class reaching into the panel, and the package supports
 * hosts that register these screens in a panel of their own. So an item names a
 * *target* — a kind of screen and, where it applies, the key of a record — and
 * the page turns that into a link (`src/Filament/Pages/BotReadinessPage.php`).
 *
 * **A general piece of advice is worse than nothing here.** "Keep an eye on the
 * quality of your scenarios" cannot be done and cannot be crossed off, and a
 * list of those stops being read on the second day. Every item names a record
 * and leads to it; an item that does not apply is simply absent.
 *
 * The list is never empty, though. Nothing where a list of problems should be
 * reads as "not calculated" rather than "no problems", and the reader would go
 * and check by hand anyway.
 */
final class Readiness
{
    public const TARGET_BOTS = 'bots';

    public const TARGET_SCENARIOS = 'scenarios';

    /** One scenario's canvas; `record` is its key. */
    public const TARGET_SCENARIO = 'scenario';

    public const TARGET_QUALITY = 'quality';

    public const TARGET_KNOWLEDGE = 'knowledge';

    public const TARGET_WIDGETS = 'widgets';

    /**
     * Two states a check can be in that are not among the stored outcomes.
     *
     * "Never run" is an empty column, and "needs re-running" is a comparison
     * between the scenario's fingerprint now and the one the check was recorded
     * against. Neither is storable, which is why the enum does not carry them.
     */
    private const VERDICT_NEVER = 'never';

    private const VERDICT_STALE = 'stale';

    /**
     * How far back runs are counted.
     *
     * A week rather than a month. The summary is opened when the bot is
     * behaving oddly, and a month's counting would dissolve yesterday's
     * breakage into three weeks of everything being fine.
     */
    private const RUN_WINDOW_DAYS = 7;

    public function __construct(
        private readonly ScenarioGraph $graph,
        private readonly ScenarioVersions $versions,
        private readonly AiGatewayFactory $gateways,
    ) {}

    /**
     * @return array{tiles: list<array<string, mixed>>, todos: list<array<string, mixed>>}
     */
    public function summary(): array
    {
        $scenarios = Scenario::query()->with('steps')->orderBy('slug')->get();
        $quality = QualityTest::query()->orderBy('id')->get();

        $active = $scenarios->where('is_active', true);
        $drafts = $active->filter(fn (Scenario $scenario): bool => $this->versions->hasUnpublishedChanges($scenario));
        $problems = $this->problems($scenarios);
        $verdicts = $this->verdicts($quality);
        $runs = $this->runCounts();

        return [
            'tiles' => $this->tiles($scenarios, $active, $drafts, $problems, $verdicts, $runs),
            'todos' => $this->todos($scenarios, $active, $drafts, $problems, $quality, $verdicts),
        ];
    }

    /**
     * The six numbers somebody opens this page for.
     *
     * Ordered by what a silent bot is most often silent *because of*: no active
     * bot at all, then no scenario, then a broken diagram. A tile that reads
     * "everything is fine" still says what "fine" means — a green tile with no
     * sentence under it is one the reader has to interpret.
     *
     * @param  Collection<int, Scenario>  $scenarios
     * @param  Collection<int, Scenario>  $active
     * @param  Collection<int, Scenario>  $drafts
     * @param  array<int, list<string>>  $problems
     * @param  array<int, string>  $verdicts
     * @param  array<string, int>  $runs
     * @return list<array<string, mixed>>
     */
    private function tiles(
        Collection $scenarios,
        Collection $active,
        Collection $drafts,
        array $problems,
        array $verdicts,
        array $runs,
    ): array {
        $bots = Bot::query()->where('is_active', true)->count();
        $flawed = count(array_filter($problems, static fn (array $list): bool => $list !== []));
        $knowledge = KnowledgeEntry::query()->where('is_active', true)->count();
        $silent = KnowledgeEntry::query()->where('is_active', true)->whereNull('answer')->count();

        $counts = array_count_values($verdicts);
        $failed = ($counts[QualityOutcome::Failed->value] ?? 0) + ($counts[QualityOutcome::Broken->value] ?? 0);
        $waiting = ($counts[self::VERDICT_STALE] ?? 0) + ($counts[self::VERDICT_NEVER] ?? 0);

        $started = array_sum($runs);
        $broken = $this->brokenRuns($runs);

        return [
            $this->tile(
                'enabled',
                __('chatbot::panel.readiness.tiles.enabled'),
                $this->botsAnswering() ? __('chatbot::panel.readiness.answering') : __('chatbot::panel.readiness.silent'),
                /*
                 * `trans_choice` and not `__`. A pluralised line read through
                 * `__` is returned with its `{1}…|[2,*]…` syntax intact, and
                 * the panel prints the rules at the reader — which is exactly
                 * the kind of thing that goes unnoticed in a language whose
                 * plural happens to be the first branch.
                 */
                $this->botsAnswering()
                    ? trans_choice('chatbot::panel.readiness.enabled_note', $bots, ['count' => $bots])
                    : __('chatbot::panel.readiness.disabled_note'),
                $this->botsAnswering() ? 'success' : 'danger',
            ),
            $this->tile(
                'scenarios',
                __('chatbot::panel.readiness.tiles.scenarios'),
                (string) $active->count(),
                $active->isEmpty()
                    ? __('chatbot::panel.readiness.no_scenarios')
                    : __('chatbot::panel.readiness.scenarios_note', [
                        'live' => $active->count() - $drafts->count(),
                        'drafts' => $drafts->count(),
                    ]),
                match (true) {
                    $active->isEmpty() => 'danger',
                    $drafts->isNotEmpty() => 'warning',
                    default => 'success',
                },
            ),
            $this->tile(
                'problems',
                __('chatbot::panel.readiness.tiles.problems'),
                (string) $flawed,
                $flawed === 0
                    ? __('chatbot::panel.readiness.no_problems')
                    : __('chatbot::panel.readiness.has_problems'),
                $flawed === 0 ? 'success' : 'danger',
            ),
            $this->tile(
                'quality',
                __('chatbot::panel.readiness.tiles.quality'),
                (string) count($verdicts),
                $verdicts === []
                    ? __('chatbot::panel.readiness.no_checks')
                    : __('chatbot::panel.readiness.checks_note', [
                        'passed' => $counts[QualityOutcome::Passed->value] ?? 0,
                        'failed' => $failed,
                        'waiting' => $waiting,
                    ]),
                match (true) {
                    $verdicts === [] => 'gray',
                    $failed > 0 => 'danger',
                    $waiting > 0 => 'warning',
                    default => 'success',
                },
            ),
            $this->tile(
                'runs',
                __('chatbot::panel.readiness.tiles.runs'),
                (string) $started,
                $started === 0
                    ? __('chatbot::panel.readiness.no_runs')
                    : __('chatbot::panel.readiness.runs_note', [
                        'closed' => $runs[RunOutcome::Closed->value] ?? 0,
                        'handed' => $runs[RunOutcome::HandedOff->value] ?? 0,
                        'broken' => $broken,
                    ]),
                match (true) {
                    $started === 0 => 'gray',
                    $broken > 0 => 'danger',
                    default => 'success',
                },
            ),
            $this->tile(
                'knowledge',
                __('chatbot::panel.readiness.tiles.knowledge'),
                (string) $knowledge,
                match (true) {
                    $knowledge === 0 => __('chatbot::panel.readiness.no_knowledge'),
                    $silent > 0 => trans_choice('chatbot::panel.readiness.knowledge_silent', $silent, ['count' => $silent]),
                    default => __('chatbot::panel.readiness.knowledge_answered'),
                },
                match (true) {
                    $knowledge === 0 => 'gray',
                    $silent > 0 => 'warning',
                    default => 'success',
                },
            ),
        ];
    }

    /**
     * "What next" — specific actions on named records.
     *
     * @param  Collection<int, Scenario>  $scenarios
     * @param  Collection<int, Scenario>  $active
     * @param  Collection<int, Scenario>  $drafts
     * @param  array<int, list<string>>  $problems
     * @param  Collection<int, QualityTest>  $quality
     * @param  array<int, string>  $verdicts
     * @return list<array{text: string, target: string, record: int|null, tone: string}>
     */
    private function todos(
        Collection $scenarios,
        Collection $active,
        Collection $drafts,
        array $problems,
        Collection $quality,
        array $verdicts,
    ): array {
        $todos = [];

        if (! $this->botsAnswering()) {
            $todos[] = $this->todo(
                __('chatbot::panel.readiness.todo.no_bot'),
                self::TARGET_BOTS,
                tone: 'danger',
            );
        }

        if ($active->isEmpty()) {
            $todos[] = $this->todo(
                __('chatbot::panel.readiness.todo.no_scenario'),
                self::TARGET_SCENARIOS,
                tone: 'danger',
            );
        }

        foreach ($scenarios as $scenario) {
            $flaws = $problems[(int) $scenario->getKey()] ?? [];

            if ($flaws === []) {
                continue;
            }

            $todos[] = $this->todo(
                __('chatbot::panel.readiness.todo.flawed', [
                    'scenario' => $scenario->name,
                    'problem' => $flaws[0],
                ]),
                self::TARGET_SCENARIO,
                (int) $scenario->getKey(),
                'danger',
            );
        }

        foreach ($drafts as $draft) {
            $todos[] = $this->todo(
                __('chatbot::panel.readiness.todo.unpublished', ['scenario' => $draft->name]),
                self::TARGET_SCENARIO,
                (int) $draft->getKey(),
            );
        }

        foreach ($quality as $check) {
            $verdict = $verdicts[(int) $check->getKey()] ?? self::VERDICT_NEVER;

            if (! in_array($verdict, [QualityOutcome::Failed->value, QualityOutcome::Broken->value, self::VERDICT_STALE], true)) {
                continue;
            }

            $todos[] = $this->todo(
                __('chatbot::panel.readiness.todo.check_'.($verdict === self::VERDICT_STALE ? 'stale' : 'failed'), [
                    'check' => $check->name,
                ]),
                self::TARGET_QUALITY,
                (int) $check->getKey(),
                $verdict === self::VERDICT_STALE ? 'warning' : 'danger',
            );
        }

        if (KnowledgeEntry::query()->where('is_active', true)->whereNull('answer')->exists()) {
            $todos[] = $this->todo(
                __('chatbot::panel.readiness.todo.knowledge_silent'),
                self::TARGET_KNOWLEDGE,
            );
        }

        if ($todos === []) {
            $todos[] = $this->todo(
                __('chatbot::panel.readiness.todo.all_clear'),
                self::TARGET_SCENARIOS,
                tone: 'success',
            );
        }

        /*
         * The model comes after "nothing is in the way", and deliberately does
         * not suppress it. Nothing in this package needs a model — the bot
         * answers from words and from the knowledge base with one switched off —
         * so an installation without a provider is a working installation, and
         * this is a note rather than a fault. Counted as one it would mean the
         * "all clear" line never appeared on the installations that are most
         * entitled to it.
         */
        if (! $this->gateways->make()->isAvailable()) {
            $todos[] = $this->todo(
                __('chatbot::panel.readiness.todo.no_model'),
                self::TARGET_BOTS,
                tone: 'gray',
            );
        }

        return $todos;
    }

    /**
     * Whether anything would answer a message at all.
     *
     * Two switches, and both of them silence the bot with the scenarios intact:
     * the installation-wide one in the config, and having no active bot row.
     * They are reported as one line because the visitor's experience is the
     * same and the question being asked is "does anything answer".
     */
    private function botsAnswering(): bool
    {
        return (bool) config('chatbot.bot.enabled', true)
            && Bot::query()->where('is_active', true)->exists();
    }

    /**
     * The problems in every scenario, read from the graph rather than counted
     * again here.
     *
     * The canvas already decides what a broken diagram is, in the same words it
     * shows the author. A second implementation would eventually disagree with
     * the first, and the summary would call a scenario healthy on a page the
     * canvas marks with a red node.
     *
     * @param  Collection<int, Scenario>  $scenarios
     * @return array<int, list<string>>
     */
    private function problems(Collection $scenarios): array
    {
        $problems = [];

        foreach ($scenarios as $scenario) {
            $problems[(int) $scenario->getKey()] = array_values(array_map(
                static fn (array $issue): string => (string) $issue['message'],
                array_filter(
                    $this->graph->issues($scenario),
                    static fn (array $issue): bool => $issue['severity'] === ScenarioGraph::BLOCKING,
                ),
            ));
        }

        return $problems;
    }

    /**
     * Each saved check's state, including the two that are not stored.
     *
     * "Needs re-running" is the one worth the work: a check that passed against
     * a scenario which has since been edited is a green tick for an answer
     * nobody has seen. Left as its stored outcome it reads as proof.
     *
     * @param  Collection<int, QualityTest>  $quality
     * @return array<int, string>
     */
    private function verdicts(Collection $quality): array
    {
        $verdicts = [];

        foreach ($quality as $check) {
            $scenario = $check->scenario;

            $verdicts[(int) $check->getKey()] = match (true) {
                $check->last_outcome === null => self::VERDICT_NEVER,
                $scenario !== null && $check->last_fingerprint !== $this->versions->fingerprint($scenario) => self::VERDICT_STALE,
                default => $check->last_outcome->value,
            };
        }

        return $verdicts;
    }

    /**
     * @return array<string, int>
     */
    private function runCounts(): array
    {
        return Run::query()
            ->where('created_at', '>=', now()->subDays(self::RUN_WINDOW_DAYS))
            ->selectRaw('outcome, count(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome')
            ->mapWithKeys(static fn (int $total, ?string $outcome): array => [(string) $outcome => $total])
            ->all();
    }

    /**
     * Runs that ended somewhere nobody meant them to.
     *
     * A handover is not one of them, and that is the distinction the number
     * exists to draw: a bot fetching a person is the bot working. A dead end, a
     * step limit, a lost thread and a failure are four different ways of a
     * conversation stopping with nothing said, and counting them apart would
     * make each one look rare.
     *
     * @param  array<string, int>  $runs
     */
    private function brokenRuns(array $runs): int
    {
        $broken = 0;

        foreach ([RunOutcome::DeadEnd, RunOutcome::StepLimit, RunOutcome::Lost, RunOutcome::Failed] as $outcome) {
            $broken += $runs[$outcome->value] ?? 0;
        }

        return $broken;
    }

    /**
     * @return array<string, mixed>
     */
    private function tile(string $key, string $label, string $value, string $note, string $tone): array
    {
        return compact('key', 'label', 'value', 'note', 'tone');
    }

    /**
     * @return array{text: string, target: string, record: int|null, tone: string}
     */
    private function todo(string $text, string $target, ?int $record = null, string $tone = 'warning'): array
    {
        return compact('text', 'target', 'record', 'tone');
    }
}
