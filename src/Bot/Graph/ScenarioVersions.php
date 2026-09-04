<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Graph;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MB\FilamentChatbot\Exceptions\ScenarioNotPublishable;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\ScenarioVersion;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Releasing a scenario, and going back to a release that worked.
 *
 * A scenario is edited continuously and answers customers continuously, and
 * without a release those are the same act: a half-drawn branch saved at five
 * o'clock is what the bot says at five o'clock and one. A version is the line
 * between the two — this is what we drew, that is what people are being told.
 */
class ScenarioVersions
{
    public function __construct(
        protected readonly ScenarioGraph $graph,
        protected readonly ChatbotRegistry $registry,
    ) {}

    /**
     * Freezes the scenario as it stands and points the bot at the snapshot.
     *
     * Blocking defects refuse the release rather than being reported and
     * released anyway, because this is the last moment at which any of them is
     * cheap. A transition into a deleted node costs nothing to fix while the
     * author is looking at the canvas; the same defect discovered from a live
     * conversation costs a customer who was told nothing, and the report of it
     * arrives as "the bot ignored me", days later, without the node's name.
     *
     * @throws ScenarioNotPublishable
     */
    public function publish(Scenario $scenario, ?int $userId = null, ?string $note = null): ScenarioVersion
    {
        $blocking = array_values(array_filter(
            $this->graph->issues($scenario),
            static fn (array $issue): bool => $issue['severity'] === ScenarioGraph::BLOCKING,
        ));

        if ($blocking !== []) {
            throw ScenarioNotPublishable::because($scenario->name, $blocking);
        }

        return $this->release($scenario, $userId, $note);
    }

    /**
     * Puts an earlier release back into service.
     *
     * The old snapshot is written over the scenario and published again as a
     * new version, rather than the live pointer being moved back and the newer
     * versions deleted. History is what an incident is reconstructed from: the
     * question afterwards is always "what were people being told between two
     * and four", and a rollback that erased the version in between answers it
     * with nothing. The numbers only ever climb, so "version seven" means the
     * same thing tomorrow as it does today.
     *
     * The release check runs on the way through, on purpose. Rolling back is
     * still publishing, and a snapshot drawn when a host package was installed
     * can no longer be run without it — putting it live unchecked would replace
     * one broken bot with another one.
     *
     * @throws ScenarioNotPublishable
     */
    public function rollBack(Scenario $scenario, ScenarioVersion $version): ScenarioVersion
    {
        if ($version->scenario_id !== $scenario->getKey()) {
            throw new InvalidArgumentException(
                "Version {$version->number} belongs to another scenario and cannot be rolled back into [{$scenario->slug}].",
            );
        }

        $this->graph->apply($scenario, (array) $version->graph);

        return $this->publish(
            $scenario->refresh(),
            $version->published_by_id,
            "Rolled back to version {$version->number}.",
        );
    }

    /**
     * A hash of what the scenario says and does.
     *
     * Coordinates, sort order, ids and timestamps are all left out, and that is
     * the entire point of computing it by hand instead of hashing the graph
     * payload. Dragging a node changes its position and nothing else; include
     * the position and the panel announces unpublished changes after somebody
     * tidied the canvas, until "there are changes to publish" means nothing and
     * gets published blind.
     *
     * Steps are sorted by key rather than taken in row order for the same
     * reason: which row comes first is a detail of how the editor happened to
     * send them.
     */
    public function fingerprint(Scenario $scenario): string
    {
        $steps = $this->steps($scenario)
            ->sortBy('key')
            ->map(static fn (Step $step): array => [
                'key' => $step->key,
                'type' => $step->type,
                'body' => $step->body,
                'options' => $step->options,
                'config' => $step->config,
                'next_key' => $step->next_key,
            ])
            ->values()
            ->all();

        return hash('sha256', (string) json_encode($this->canonical([
            'slug' => $scenario->slug,
            'name' => $scenario->name,
            'is_active' => $scenario->is_active,
            'priority' => $scenario->priority,
            'invocation' => $scenario->invocation,
            'steps' => $steps,
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Whether the scenario differs from what is live.
     *
     * A scenario that has never been published always differs, however empty it
     * is. It is running as a draft, which means every edit reaches customers
     * the moment it is saved, and that is the state the panel most needs to say
     * out loud.
     */
    public function hasUnpublishedChanges(Scenario $scenario): bool
    {
        $live = $scenario->liveVersion;

        return $live === null || $live->fingerprint !== $this->fingerprint($scenario);
    }

    /**
     * The external things the scenario leans on, gathered from its nodes.
     *
     * Frozen into the release so that a scenario which starts behaving
     * differently can be told from one that was changed. The data resource it
     * reads, the action it runs and the scenario it calls all live outside this
     * scenario and are edited by other people on other days; without the pins,
     * the version history says "nothing changed here" while the bot's answers
     * move underneath it.
     *
     * @return array<string, list<string>>
     */
    public function pins(Scenario $scenario): array
    {
        $pins = [];

        foreach ($this->steps($scenario) as $step) {
            foreach ($this->registry->step($step->type)?->pins($step) ?? [] as $group => $references) {
                foreach ((array) $references as $reference) {
                    $pins[$group][(string) $reference] = true;
                }
            }
        }

        foreach ($pins as $group => $references) {
            $keys = array_keys($references);
            sort($keys);
            $pins[$group] = $keys;
        }

        ksort($pins);

        return $pins;
    }

    /**
     * Writes the snapshot and makes it live.
     *
     * The number is taken from the highest one this scenario has ever had, not
     * from how many rows exist: a deleted version must not hand its number to
     * the next release, or the history reads as though one release were two
     * different things.
     */
    protected function release(Scenario $scenario, ?int $userId, ?string $note): ScenarioVersion
    {
        return DB::transaction(function () use ($scenario, $userId, $note): ScenarioVersion {
            $number = (int) ScenarioVersion::query()
                ->where('scenario_id', $scenario->getKey())
                ->max('number');

            $pins = $this->pins($scenario);

            $version = ScenarioVersion::query()->create([
                'scenario_id' => $scenario->getKey(),
                'number' => $number + 1,
                'graph' => $this->graph->toGraph($scenario),
                'pins' => $pins === [] ? null : $pins,
                'fingerprint' => $this->fingerprint($scenario),
                'note' => $note,
                'published_at' => now(),
                'published_by_id' => $userId,
            ]);

            $scenario->forceFill(['live_version_id' => $version->getKey()])->save();
            $scenario->setRelation('liveVersion', $version);

            return $version;
        });
    }

    /**
     * @return Collection<int, Step>
     */
    protected function steps(Scenario $scenario): Collection
    {
        return $scenario->relationLoaded('steps')
            ? $scenario->steps
            : $scenario->steps()->get();
    }

    /**
     * The same content in the same order every time.
     *
     * Two scenarios that say the same thing have to hash the same, and a JSON
     * object remembers the order its keys were written in: a settings bag saved
     * with the limit before the resource one day and after it the next is the
     * same configuration and a different hash, which would report a change
     * nobody made.
     */
    protected function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $item): mixed => $this->canonical($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
