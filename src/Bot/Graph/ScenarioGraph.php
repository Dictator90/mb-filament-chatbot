<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Graph;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Bot\Steps\Types\ApprovalStep;
use MB\FilamentChatbot\Contracts\HasSideEffect;
use MB\FilamentChatbot\Enums\SideEffect;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Turns a scenario into the diagram the editor draws, and back again.
 *
 * There is no table of arrows. A transition is a step key written into
 * next_key, into an option, or into the second-exit setting, and this class is
 * the only place that knows which of the three a given handle on the canvas
 * belongs to. That single-place rule is the whole design: let the editor's
 * JavaScript decide that a lookup node's second handle means "found nothing",
 * and the day a node changes shape the canvas keeps drawing an arrow the bot
 * no longer follows — the picture and the behaviour disagree, and the picture
 * is the one the author trusts.
 *
 * So the mapping is asked of the step kind, every time, through outputs():
 *
 *   None        — no handles at all
 *   Single      — 0 → next_key
 *   Pair        — 0 → next_key, 1 → config.else_key
 *   Options     — i → options[i].next
 *   OptionsElse — i → options[i].next, and the last one → config.else_key
 *
 * A target that a handle owns is stripped from the node payload and travels
 * only as an edge. Kept in both, the two would eventually be saved out of step
 * with each other, and there would be no way to tell which one the runtime
 * reads.
 */
class ScenarioGraph
{
    /**
     * The shape of the payload, carried so that a saved version can still be
     * read after the format moves on. A snapshot with no version in it is a
     * snapshot that has to be guessed at.
     */
    public const int VERSION = 1;

    public const string BLOCKING = 'blocking';

    public const string NOTICE = 'notice';

    public function __construct(protected readonly ChatbotRegistry $registry) {}

    /**
     * @return array{
     *     version: int,
     *     nodes: list<array{key: string, type: string, body: string|null, config: array<string, mixed>, options: list<mixed>, x: int, y: int}>,
     *     edges: list<array{from: string, slot: int, to: string}>,
     *     notes: list<mixed>,
     *     groups: list<mixed>,
     *     viewport: array{x: float|int, y: float|int, zoom: float|int},
     * }
     */
    public function toGraph(Scenario $scenario): array
    {
        $nodes = [];
        $edges = [];

        foreach ($this->steps($scenario) as $step) {
            $outputs = $this->outputsOf($step);

            $nodes[] = [
                'key' => $step->key,
                'type' => $step->type,
                'body' => $step->body,
                'config' => $this->configWithoutTargets($step, $outputs),
                'options' => $this->optionsWithoutTargets($step, $outputs),
                'x' => $step->pos_x,
                'y' => $step->pos_y,
            ];

            foreach ($this->targets($step, $outputs) as $slot => $target) {
                if ($target !== null) {
                    $edges[] = ['from' => $step->key, 'slot' => $slot, 'to' => $target];
                }
            }
        }

        return [
            'version' => self::VERSION,
            'nodes' => $nodes,
            'edges' => $edges,
            'notes' => array_values((array) $scenario->graph_notes),
            'groups' => array_values((array) $scenario->graph_groups),
            'viewport' => $this->viewport($scenario),
        ];
    }

    /**
     * Writes a diagram back over the scenario.
     *
     * One transaction, because a half-applied save is worse than a rejected
     * one: the deletions land, the writes do not, and the author is left with
     * a scenario missing the nodes they were rearranging.
     *
     * Steps are matched by key rather than by id. The editor sends what it has
     * drawn and knows nothing about primary keys, and matching by position — the
     * other candidate — renames every node in the scenario the first time one
     * is dragged past another.
     *
     * A node whose kind nothing in this installation provides is written back
     * exactly as it came. The scenario may have been drawn while a host package
     * was installed; dropping those nodes as unrecognised would delete somebody
     * else's work during an ordinary save, silently and irreversibly.
     *
     * notes, groups and viewport are only touched when the payload mentions
     * them, so that a caller saving nodes alone does not clear the canvas
     * furniture it never sent.
     *
     * @param  array<string, mixed>  $graph
     */
    public function apply(Scenario $scenario, array $graph): void
    {
        $nodes = $this->incomingNodes($graph);
        $edges = $this->incomingEdges($graph);

        DB::transaction(function () use ($scenario, $graph, $nodes, $edges): void {
            Step::query()
                ->where('scenario_id', $scenario->getKey())
                ->whereNotIn('key', array_column($nodes, 'key'))
                ->delete();

            foreach ($nodes as $sort => $node) {
                $this->writeNode($scenario, $node, $edges[$node['key']] ?? [], $sort);
            }

            $canvas = [];

            foreach (['notes' => 'graph_notes', 'groups' => 'graph_groups', 'viewport' => 'viewport'] as $incoming => $column) {
                if (array_key_exists($incoming, $graph)) {
                    $canvas[$column] = $graph[$incoming] === [] ? null : $graph[$incoming];
                }
            }

            if ($canvas !== []) {
                $scenario->forceFill($canvas)->save();
            }
        });

        $scenario->unsetRelation('steps');
    }

    /**
     * Everything wrong with the scenario as it is drawn right now.
     *
     * Reported here, at editing time, and not left to the runtime: by the time
     * a conversation walks into a broken node the visitor is already reading
     * the consequence, and for a node that writes to the host's records the
     * write has already happened. An author who is looking at the canvas can
     * fix any of these in a minute.
     *
     * Blocking issues stop a release. Unreachable nodes do not — a branch is
     * unreachable for the whole time it is being built, and a checker that
     * refused to publish because of it would be wrong on every ordinary
     * afternoon.
     *
     * @return list<array{severity: string, key: string|null, message: string}>
     */
    public function issues(Scenario $scenario): array
    {
        $steps = $this->steps($scenario);
        $issues = [];
        $startKeys = [];

        foreach ($steps as $step) {
            $type = $this->registry->step($step->type);

            if ($type === null) {
                $issues[] = $this->issue(self::BLOCKING, $step->key, "This node is of the kind [{$step->type}], which nothing in this installation provides. It is kept as it is, and a conversation reaching it stops.");
            }

            foreach ($type?->issues($step, $scenario) ?? [] as $issue) {
                $issues[] = $this->issue(
                    (string) ($issue['severity'] ?? self::BLOCKING),
                    $step->key,
                    (string) ($issue['message'] ?? ''),
                );
            }

            if ($step->type === Step::TYPE_START) {
                $startKeys[] = $step->key;
            }

            foreach ($this->targets($step, $this->outputsOf($step)) as $slot => $target) {
                if ($target !== null && ! $steps->has($target)) {
                    $issues[] = $this->issue(self::BLOCKING, $step->key, "Exit {$slot} of this node leads to [{$target}], and the scenario has no such node.");
                }
            }
        }

        if ($startKeys === []) {
            $issues[] = $this->issue(self::BLOCKING, null, 'The scenario has no start node, so nothing ever begins it.');
        }

        if (count($startKeys) > 1) {
            foreach ($startKeys as $key) {
                $issues[] = $this->issue(self::BLOCKING, $key, 'The scenario has more than one start node, and which of them runs is not something an author can decide from the canvas.');
            }
        }

        foreach ($this->unreachableKeys($scenario) as $key) {
            $issues[] = $this->issue(self::NOTICE, $key, 'Nothing leads to this node from the start, so no conversation reaches it yet.');
        }

        foreach ($this->unapprovedWriteKeys($steps) as $key) {
            $issues[] = $this->issue(self::BLOCKING, $key, 'This node changes data and can be reached without a confirmation node above it. Add one on every path that leads here.');
        }

        return $issues;
    }

    /**
     * The problems in a diagram that has not been saved.
     *
     * The draft is turned into step rows that are never written and checked by
     * exactly the code that checks a saved scenario. The alternative — a
     * lighter check written for the editor — answers a slightly different
     * question from the one that refuses a release, and the two disagreeing is
     * how an author ends up with a canvas showing no problems and a publish
     * button that says no.
     *
     * @param  array<string, mixed>  $graph
     * @return list<array{severity: string, key: string|null, message: string}>
     */
    public function issuesForDraft(Scenario $scenario, array $graph): array
    {
        $steps = [];
        $edges = $this->incomingEdges($graph);

        foreach ($this->incomingNodes($graph) as $sort => $node) {
            $step = new Step($this->nodeAttributes($node, $edges[$node['key']] ?? [], $sort));
            $step->scenario_id = $scenario->getKey();

            $steps[] = $step;
        }

        $draft = clone $scenario;
        $draft->setRelation('steps', new EloquentCollection($steps));

        return $this->issues($draft);
    }

    /**
     * The step keys that are pointed at and do not exist.
     *
     * The missing target rather than the node holding the broken arrow,
     * because that is the string the author has to recognise: they renamed a
     * node and this is the old name, still written into three transitions.
     *
     * @return list<string>
     */
    public function danglingKeys(Scenario $scenario): array
    {
        $steps = $this->steps($scenario);
        $missing = [];

        foreach ($steps as $step) {
            foreach ($this->targets($step, $this->outputsOf($step)) as $target) {
                if ($target !== null && ! $steps->has($target)) {
                    $missing[$target] = true;
                }
            }
        }

        $keys = array_keys($missing);
        sort($keys);

        return $keys;
    }

    /**
     * The nodes no conversation can arrive at.
     *
     * A scenario with no start node returns nothing at all here. Every node in
     * it is unreachable, technically, and saying so would bury the one problem
     * that matters — there is no start — under a warning per node.
     *
     * @return list<string>
     */
    public function unreachableKeys(Scenario $scenario): array
    {
        $steps = $this->steps($scenario);
        $start = $steps->firstWhere('type', Step::TYPE_START);

        if ($start === null) {
            return [];
        }

        $reached = $this->reachable($steps, $start);

        return array_values(array_diff($steps->keys()->all(), $reached));
    }

    /**
     * Where each handle of a node points, indexed by handle number.
     *
     * A kind nobody provides is read as if it had one exit. Its recorded
     * next_key is real data an author wrote, and treating the node as terminal
     * would quietly drop that transition on the next save — the one thing
     * apply() promises not to do to a node it does not understand.
     *
     * @return list<string|null>
     */
    public function targets(Step $step, ?StepOutputs $outputs = null): array
    {
        $outputs ??= $this->outputsOf($step);

        return match ($outputs) {
            StepOutputs::None => [],
            StepOutputs::Single => [$this->target($step->next_key)],
            StepOutputs::Pair => [$this->target($step->next_key), $step->secondTarget()],
            StepOutputs::Options => $this->optionTargets($step),
            StepOutputs::OptionsElse => [...$this->optionTargets($step), $step->secondTarget()],
        };
    }

    /**
     * The shape of a node's handles, defaulting to one exit for a kind this
     * installation does not know ({@see self::targets()}).
     */
    public function outputsOf(Step $step): StepOutputs
    {
        return $this->registry->step($step->type)?->outputs() ?? StepOutputs::Single;
    }

    /**
     * Steps keyed by their step key, from the loaded relation when there is
     * one. The editor asks for the diagram, the defects, the dangling keys and
     * the unreachable ones on the same screen, and four independent queries for
     * the same rows is what makes a canvas feel slow to open.
     *
     * @return Collection<string, Step>
     */
    protected function steps(Scenario $scenario): Collection
    {
        $steps = $scenario->relationLoaded('steps')
            ? $scenario->steps
            : $scenario->steps()->get();

        return $steps->keyBy('key');
    }

    /**
     * Node keys reachable from the start, following every handle.
     *
     * @param  Collection<string, Step>  $steps
     * @return list<string>
     */
    protected function reachable(Collection $steps, Step $start): array
    {
        $seen = [$start->key => true];
        $queue = [$start->key];

        while ($queue !== []) {
            $step = $steps->get((string) array_shift($queue));

            if ($step === null) {
                continue;
            }

            foreach ($this->targets($step, $this->outputsOf($step)) as $target) {
                if ($target !== null && ! isset($seen[$target])) {
                    $seen[$target] = true;
                    $queue[] = $target;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Nodes that change data and can be reached without passing a confirmation.
     *
     * Walked as (node, has a confirmation been passed) pairs rather than as
     * plain nodes, because reachability alone cannot answer this: the very same
     * write node is safe on the branch below an approval and unsafe on the
     * branch that skips it, and a walk that visited each node once would report
     * whichever path it happened to take first.
     *
     * Only the approving exit of a confirmation carries the approval forward.
     * The refusing exit means the person said no, and a write placed there is
     * exactly the mistake this check is for.
     *
     * @param  Collection<string, Step>  $steps
     * @return list<string>
     */
    protected function unapprovedWriteKeys(Collection $steps): array
    {
        $start = $steps->firstWhere('type', Step::TYPE_START);

        if ($start === null) {
            return [];
        }

        $flagged = [];
        $seen = [];
        $queue = [[$start->key, false]];

        while ($queue !== []) {
            [$key, $approved] = array_shift($queue);
            $mark = $key.'|'.($approved ? '1' : '0');

            if (isset($seen[$mark])) {
                continue;
            }

            $seen[$mark] = true;
            $step = $steps->get($key);

            if ($step === null) {
                continue;
            }

            $type = $this->registry->step($step->type);

            if (! $approved && $this->writes($type, $step)) {
                $flagged[$key] = true;
            }

            $confirms = $this->confirms($type, $step);

            foreach ($this->targets($step, $this->outputsOf($step)) as $slot => $target) {
                if ($target !== null) {
                    $queue[] = [$target, $approved || ($confirms && $slot === 0)];
                }
            }
        }

        return array_keys($flagged);
    }

    /**
     * Whether the node changes the host's data.
     *
     * Two answers, because a node can say it two ways and both have to count.
     * A kind that knows it writes says so through {@see HasSideEffect}. A node
     * that runs a host action instead names that action in its pins, and the
     * action itself carries the answer — asking the pins covers every node that
     * calls into the host without the graph having to recognise the node kind
     * or the name of the setting it keeps the action in.
     *
     * An action the host marked as needing a confirmation counts as a write
     * even when it only reads. That is the host saying "ask first" about
     * something the effect flag alone cannot express — reading a customer's
     * documents out loud, for instance — and overruling it here would make the
     * flag decorative.
     */
    protected function writes(?StepType $type, Step $step): bool
    {
        if ($type instanceof HasSideEffect) {
            return $type->sideEffect($step) === SideEffect::Write;
        }

        foreach ($type?->pins($step)['capabilities'] ?? [] as $reference) {
            $action = $this->registry->capability(explode('@', (string) $reference)[0]);

            if ($action?->effect === SideEffect::Write || $action?->requiresApproval === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the node asks a person before what comes after it.
     *
     * Recognised by the class as well as by the key, because a host may replace
     * the built-in confirmation with a subclass of its own — and it would be a
     * poor reward for extending the package to have every write below it start
     * failing to publish.
     */
    protected function confirms(?StepType $type, Step $step): bool
    {
        return $type instanceof ApprovalStep || $step->type === ApprovalStep::key();
    }

    /**
     * @return list<string|null>
     */
    protected function optionTargets(Step $step): array
    {
        $targets = [];

        foreach ($this->rawOptions($step) as $option) {
            $targets[] = is_array($option) ? $this->target($option['next'] ?? null) : null;
        }

        return $targets;
    }

    /**
     * @return list<mixed>
     */
    protected function rawOptions(Step $step): array
    {
        return array_values((array) $step->options);
    }

    /**
     * The node's options with the targets removed, when the handles own them.
     *
     * @return list<mixed>
     */
    protected function optionsWithoutTargets(Step $step, StepOutputs $outputs): array
    {
        if (! $outputs->authoredByHand()) {
            return $this->rawOptions($step);
        }

        return array_map(static function (mixed $option): mixed {
            if (is_array($option)) {
                unset($option['next']);
            }

            return $option;
        }, $this->rawOptions($step));
    }

    /**
     * @return array<string, mixed>
     */
    protected function configWithoutTargets(Step $step, StepOutputs $outputs): array
    {
        $config = (array) $step->config;

        if ($outputs === StepOutputs::Pair || $outputs === StepOutputs::OptionsElse) {
            unset($config[Step::SECOND_TARGET]);
        }

        return $config;
    }

    /**
     * @param  array{key: string, type: string, body: string|null, config: array<string, mixed>, options: list<mixed>, x: int, y: int}  $node
     * @param  array<int, string>  $edges
     */
    protected function writeNode(Scenario $scenario, array $node, array $edges, int $sort): void
    {
        $attributes = $this->nodeAttributes($node, $edges, $sort);
        unset($attributes['key']);

        Step::query()->updateOrCreate(
            ['scenario_id' => $scenario->getKey(), 'key' => $node['key']],
            $attributes,
        );
    }

    /**
     * One node as step columns, with every handle laid back into the column it
     * belongs to.
     *
     * Shared by the save and by the check on an unsaved draft, so that the two
     * cannot disagree about where a handle goes — a draft that checks clean and
     * saves differently is worse than no check at all.
     *
     * @param  array{key: string, type: string, body: string|null, config: array<string, mixed>, options: list<mixed>, x: int, y: int}  $node
     * @param  array<int, string>  $edges
     * @return array<string, mixed>
     */
    protected function nodeAttributes(array $node, array $edges, int $sort): array
    {
        $outputs = $this->registry->step($node['type'])?->outputs() ?? StepOutputs::Single;

        $config = $node['config'];
        $options = $node['options'];
        $nextKey = null;

        if ($outputs === StepOutputs::Single || $outputs === StepOutputs::Pair) {
            $nextKey = $edges[0] ?? null;
        }

        if ($outputs === StepOutputs::Pair) {
            $config = $this->withSecondTarget($config, $edges[1] ?? null);
        }

        if ($outputs->authoredByHand()) {
            $options = $this->withOptionTargets($options, $edges);
        }

        if ($outputs === StepOutputs::OptionsElse) {
            $config = $this->withSecondTarget($config, $edges[count($options)] ?? null);
        }

        return [
            'key' => $node['key'],
            'type' => $node['type'],
            'body' => $node['body'],
            'config' => $config === [] ? null : $config,
            'options' => $options === [] ? null : $options,
            'next_key' => $nextKey,
            'sort' => $sort,
            'pos_x' => $node['x'],
            'pos_y' => $node['y'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function withSecondTarget(array $config, ?string $target): array
    {
        if ($target === null) {
            unset($config[Step::SECOND_TARGET]);

            return $config;
        }

        $config[Step::SECOND_TARGET] = $target;

        return $config;
    }

    /**
     * @param  list<mixed>  $options
     * @param  array<int, string>  $edges
     * @return list<mixed>
     */
    protected function withOptionTargets(array $options, array $edges): array
    {
        foreach ($options as $index => $option) {
            if (! is_array($option)) {
                continue;
            }

            if (isset($edges[$index])) {
                $option['next'] = $edges[$index];
            } else {
                unset($option['next']);
            }

            $options[$index] = $option;
        }

        return $options;
    }

    /**
     * Nodes as the editor sent them, cleaned up enough to be written.
     *
     * A node with no key is dropped rather than given one: a generated key
     * would be a node the author never named, wired to nothing, reappearing on
     * the canvas after every save. A repeated key is dropped for the same
     * reason the steps table refuses one — two nodes answering to the same name
     * make every transition to it ambiguous.
     *
     * @param  array<string, mixed>  $graph
     * @return list<array{key: string, type: string, body: string|null, config: array<string, mixed>, options: list<mixed>, x: int, y: int}>
     */
    protected function incomingNodes(array $graph): array
    {
        $nodes = [];
        $taken = [];

        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }

            $key = trim((string) ($node['key'] ?? ''));
            $type = trim((string) ($node['type'] ?? ''));

            if ($key === '' || $type === '' || isset($taken[$key])) {
                continue;
            }

            $taken[$key] = true;

            $body = $node['body'] ?? null;

            $nodes[] = [
                'key' => $key,
                'type' => $type,
                'body' => $body === null ? null : (string) $body,
                'config' => (array) ($node['config'] ?? []),
                'options' => array_values((array) ($node['options'] ?? [])),
                'x' => (int) ($node['x'] ?? 0),
                'y' => (int) ($node['y'] ?? 0),
            ];
        }

        return $nodes;
    }

    /**
     * Edges grouped by their source node and handle.
     *
     * Two edges leaving the same handle cannot both be honoured — one handle is
     * one transition column — so the last one wins and the earlier is dropped
     * here rather than being written and immediately overwritten further down.
     *
     * @param  array<string, mixed>  $graph
     * @return array<string, array<int, string>>
     */
    protected function incomingEdges(array $graph): array
    {
        $edges = [];

        foreach ((array) ($graph['edges'] ?? []) as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $from = trim((string) ($edge['from'] ?? ''));
            $to = trim((string) ($edge['to'] ?? ''));

            if ($from === '' || $to === '') {
                continue;
            }

            $edges[$from][(int) ($edge['slot'] ?? 0)] = $to;
        }

        return $edges;
    }

    /**
     * @return array{x: float|int, y: float|int, zoom: float|int}
     */
    protected function viewport(Scenario $scenario): array
    {
        $viewport = (array) $scenario->viewport;

        return [
            'x' => $viewport['x'] ?? 0,
            'y' => $viewport['y'] ?? 0,
            'zoom' => $viewport['zoom'] ?? 1,
        ];
    }

    /**
     * @return array{severity: string, key: string|null, message: string}
     */
    protected function issue(string $severity, ?string $key, string $message): array
    {
        return ['severity' => $severity, 'key' => $key, 'message' => $message];
    }

    protected function target(mixed $key): ?string
    {
        $key = trim((string) $key);

        return $key === '' ? null : $key;
    }
}
