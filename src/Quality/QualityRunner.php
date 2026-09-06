<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Quality;

use Illuminate\Contracts\Container\Container;
use MB\FilamentChatbot\Bot\BotEngine;
use MB\FilamentChatbot\Bot\Graph\ScenarioVersions;
use MB\FilamentChatbot\Channels\WithoutDelivery;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\ConversationStatus;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\DataResource;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\QualityTest;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;
use Throwable;

/**
 * Holds one rehearsal: says the scripted lines to the real bot and grades it.
 *
 * The engine under test is the one that answers customers, driven exactly the
 * way a channel drives it. A runner that walked the graph itself would be a
 * second implementation of the bot, and the interesting bugs — a router that
 * picks the wrong scenario, a node that waits when it should branch — live
 * precisely in the part such a runner would have reimplemented instead of
 * exercised.
 *
 * Three decisions are worth reading before changing anything here.
 *
 * A rehearsal gets its own conversation, marked in `meta` and therefore hidden
 * by the `real` global scope on Conversation. Reusing a customer's thread would
 * put words in their transcript; leaving it visible would put a thread that
 * never happened in the operator's inbox.
 *
 * A named scenario is the subject of the check, not a forced entry point. The
 * router still chooses. Entering the named scenario by hand would let a check
 * stay green while every real visitor saying the same words is routed somewhere
 * else entirely — which is the failure most worth catching.
 *
 * "Could not run" is never reported as "the bot got it wrong". See the outcome
 * enum: a deleted scenario, a node kind nothing provides, a data resource
 * switched off and an engine that threw are all faults in the check's
 * surroundings, and calling them failures hides the cause at the exact moment it
 * is the only thing worth knowing.
 */
final class QualityRunner
{
    public function __construct(
        private readonly Container $container,
        private readonly ScenarioVersions $versions,
        private readonly ChatbotRegistry $registry,
        private readonly WithoutDelivery $undelivered,
    ) {}

    /**
     * Runs the check and records the outcome on its row.
     */
    public function run(QualityTest $check): QualityResult
    {
        $scenario = null;

        if ($check->scenario_id !== null || filled($check->scenario_slug)) {
            $scenario = $this->subject($check);

            if ($scenario === null) {
                return $this->record($check, QualityResult::broken(
                    'Scenario ['.($check->scenario_slug ?? '#'.$check->scenario_id).'] no longer exists.',
                ), null);
            }

            $blocker = $this->blocker($scenario);

            if ($blocker !== null) {
                return $this->record($check, QualityResult::broken($blocker), $scenario);
            }
        }

        $conversation = $this->rehearsalFor($check);

        try {
            $this->talk($check, $conversation);
        } catch (Throwable $exception) {
            return $this->record($check, QualityResult::broken(
                'The engine threw '.$exception::class.': '.$exception->getMessage(),
                $this->transcript($conversation),
                $this->visited($conversation),
            ), $scenario);
        }

        $conversation->refresh();

        $transcript = $this->transcript($conversation);
        $visited = $this->visited($conversation);
        $mismatches = $this->compare($check, $conversation, $transcript, $visited);

        return $this->record($check, $mismatches === []
            ? QualityResult::passed($transcript, $visited)
            : QualityResult::failed($mismatches, $transcript, $visited), $scenario);
    }

    /**
     * The scenario the check is about.
     *
     * Looked up by id first and by slug second, because deleting a scenario
     * nulls the id and leaves the slug behind. The slug is what lets a broken
     * check say which scenario went missing instead of reporting a check with no
     * subject at all.
     */
    private function subject(QualityTest $check): ?Scenario
    {
        if ($check->scenario_id !== null) {
            $byId = Scenario::query()->whereKey($check->scenario_id)->with('steps')->first();

            if ($byId !== null) {
                return $byId;
            }
        }

        $slug = trim((string) $check->scenario_slug);

        return $slug === ''
            ? null
            : Scenario::query()->where('slug', $slug)->with('steps')->first();
    }

    /**
     * Why this check cannot be held at all, or null when it can.
     *
     * Every answer here is about the scenario's surroundings rather than about
     * what the bot says, and each of them would otherwise be reported as a bot
     * that answered wrongly: a scenario switched off is never picked, a scenario
     * with no start node is never entered, a node kind nothing provides sends
     * the conversation to a person, and a data resource switched off makes the
     * node that reads it take its "could not" exit. All four produce a plausible
     * red badge on a bot that is working exactly as drawn.
     */
    private function blocker(Scenario $scenario): ?string
    {
        if (! $scenario->is_active) {
            return "Scenario [{$scenario->slug}] is switched off, so nothing can route a visitor into it.";
        }

        if ($scenario->startStep() === null) {
            return "Scenario [{$scenario->slug}] has no start node, so nothing ever begins it.";
        }

        $orphans = $scenario->steps
            ->reject(fn (Step $step): bool => $this->registry->hasStep((string) $step->type))
            ->pluck('type')
            ->unique()
            ->values()
            ->all();

        if ($orphans !== []) {
            return "Scenario [{$scenario->slug}] uses node kinds nothing provides: ".implode(', ', $orphans).'.';
        }

        return $this->missingResources($scenario);
    }

    /**
     * Data resources the scenario reads that are gone or switched off.
     *
     * The list comes from the same pins the release machinery writes, rather
     * than from a second walk over the nodes looking for a `resource` setting. A
     * host node reads its resource through the same pin, and a hand-rolled walk
     * would see the package's own nodes only — reporting green for a scenario
     * that cannot read a thing.
     */
    private function missingResources(Scenario $scenario): ?string
    {
        $wanted = $this->versions->pins($scenario)['data_resources'] ?? [];

        if ($wanted === []) {
            return null;
        }

        $available = DataResource::query()
            ->active()
            ->whereIn('key', $wanted)
            ->pluck('key')
            ->all();

        $missing = array_values(array_diff($wanted, $available));

        if ($missing === []) {
            return null;
        }

        return "Scenario [{$scenario->slug}] reads data resources that are missing or switched off: "
            .implode(', ', $missing).'.';
    }

    /**
     * A fresh rehearsal thread, replacing this check's previous one.
     *
     * Replaced rather than added to, because a suite run nightly would otherwise
     * leave a year of dead threads behind it — hidden from the inbox, still in
     * the table, still carrying every message and run row they produced. The
     * transcript worth keeping is already on the check's own row.
     */
    private function rehearsalFor(QualityTest $check): Conversation
    {
        Conversation::query()
            ->withoutGlobalScope(Conversation::REAL_SCOPE)
            ->where('meta->'.Conversation::QUALITY_META_KEY, $check->getKey())
            ->delete();

        /** @var array<string, mixed> $context */
        $context = (array) $check->context;

        /** @var array<string, mixed> $variables */
        $variables = (array) ($context['variables'] ?? []);

        /** @var array<string, mixed> $meta */
        $meta = (array) ($context['meta'] ?? []);

        return Conversation::query()->create([
            'bot_id' => $check->bot_id,
            'channel' => filled($check->channel) ? (string) $check->channel : 'site',
            'status' => ConversationStatus::Open,
            'bot_state' => BotState::Off,
            'display_name' => (string) ($context['display_name'] ?? 'Quality check'),
            'entry_page' => $context['page'] ?? null,
            'identified_at' => ($context['identified'] ?? false) === true ? now() : null,
            'bot_answers' => $variables === [] ? null : $variables,
            'meta' => $meta + [Conversation::QUALITY_META_KEY => $check->getKey()],
        ]);
    }

    /**
     * Says every scripted line, one turn at a time.
     *
     * A new engine per line, because the engine carries per-turn state — the
     * jump budget and the journal it is writing. One instance reused across five
     * lines is not five turns: the fifth would be refused for jumping too often
     * in a conversation that jumped once.
     *
     * The conversation is re-read before each turn for the same reason the test
     * helper does it. The engine writes the row and reads it back next turn, and
     * a stale model would have the bot answer the state of two lines ago.
     */
    private function talk(QualityTest $check, Conversation $conversation): void
    {
        $this->undelivered->run(function () use ($check, $conversation): void {
            foreach ((array) $check->said as $line) {
                $line = trim((string) $line);

                if ($line === '') {
                    continue;
                }

                $message = Message::query()->create([
                    'conversation_id' => $conversation->getKey(),
                    'direction' => MessageDirection::Incoming,
                    'author' => MessageAuthor::Visitor,
                    'body' => $line,
                ]);

                $this->container->make(BotEngine::class)->handle($conversation->refresh(), $message);
            }
        });
    }

    /**
     * Everything said in the rehearsal, in order, both sides.
     *
     * @return list<array{from: string, body: string}>
     */
    private function transcript(Conversation $conversation): array
    {
        return $conversation->messages()
            ->orderBy('id')
            ->get()
            ->map(static fn (Message $message): array => [
                'from' => $message->direction === MessageDirection::Incoming ? 'visitor' : 'bot',
                'body' => (string) $message->body,
            ])
            ->values()
            ->all();
    }

    /**
     * The node keys the conversation genuinely passed through.
     *
     * Read from the run journal rather than reconstructed from the graph,
     * because the whole question `visits` asks is which branch was taken — and
     * a path worked out from the scenario would answer with the branch the
     * author drew, which is the very thing under test.
     *
     * @return list<string>
     */
    private function visited(Conversation $conversation): array
    {
        return RunStep::query()
            ->whereIn('run_id', Run::query()->select('id')->where('conversation_id', $conversation->getKey()))
            ->orderBy('run_id')
            ->orderBy('seq')
            ->pluck('step_key')
            ->filter(static fn (mixed $key): bool => is_string($key) && $key !== '')
            ->values()
            ->all();
    }

    /**
     * Every expectation the rehearsal broke.
     *
     * All of them are evaluated, never stopping at the first. A check reporting
     * one mismatch at a time turns a wrong branch into as many runs as it has
     * consequences, and the person fixing it reads the least informative of them
     * first.
     *
     * @param  list<array{from: string, body: string}>  $transcript
     * @param  list<string>  $visited
     * @return list<array{kind: string, expected: string, actual: string}>
     */
    private function compare(QualityTest $check, Conversation $conversation, array $transcript, array $visited): array
    {
        /** @var array<string, mixed> $expectations */
        $expectations = (array) $check->expectations;

        $said = implode("\n", array_map(
            static fn (array $line): string => $line['body'],
            array_values(array_filter($transcript, static fn (array $line): bool => $line['from'] === 'bot')),
        ));

        $heard = $said === '' ? 'the bot said nothing' : $said;
        $mismatches = [];

        foreach ($this->phrases($expectations['says'] ?? []) as $phrase) {
            if (mb_stripos($said, $phrase) === false) {
                $mismatches[] = ['kind' => 'says', 'expected' => $phrase, 'actual' => $heard];
            }
        }

        foreach ($this->phrases($expectations['never_says'] ?? []) as $phrase) {
            if (mb_stripos($said, $phrase) !== false) {
                $mismatches[] = ['kind' => 'never_says', 'expected' => 'never '.$phrase, 'actual' => $heard];
            }
        }

        $endsWith = trim((string) ($expectations['ends_with'] ?? ''));

        if ($endsWith !== '' && $endsWith !== ($ending = $this->ending($conversation))) {
            $mismatches[] = ['kind' => 'ends_with', 'expected' => $endsWith, 'actual' => $ending];
        }

        foreach ($this->phrases($expectations['visits'] ?? []) as $key) {
            if (! in_array($key, $visited, true)) {
                $mismatches[] = [
                    'kind' => 'visits',
                    'expected' => $key,
                    'actual' => $visited === [] ? 'no node was recorded' : implode(' > ', $visited),
                ];
            }
        }

        /** @var array<string, mixed> $collected */
        $collected = (array) ($expectations['collected'] ?? []);
        $answers = (array) $conversation->bot_answers;

        foreach ($collected as $name => $want) {
            $got = $answers[(string) $name] ?? null;

            if (! $this->sameValue($want, $got)) {
                $mismatches[] = [
                    'kind' => 'collected',
                    'expected' => (string) $name.' = '.$this->readable($want),
                    'actual' => (string) $name.' = '.$this->readable($got),
                ];
            }
        }

        return $mismatches;
    }

    /**
     * How the conversation stands now, in the words `ends_with` uses.
     *
     * Closed is asked first because a closed thread also has its bot switched
     * off, and answering "off" for it would make every check that expects a
     * goodbye report the wrong ending.
     */
    private function ending(Conversation $conversation): string
    {
        if ($conversation->status === ConversationStatus::Closed) {
            return 'closed';
        }

        if ($conversation->bot_state === BotState::HandedOff) {
            return 'handed_off';
        }

        if ($conversation->bot_step_key !== null) {
            return 'waiting';
        }

        return 'finished';
    }

    /**
     * One phrase or a list of them, whichever the author wrote.
     *
     * Both shapes are accepted on purpose: `says` with a single string is what
     * anybody writes first, and refusing it would turn a check into a puzzle
     * about json.
     *
     * @return list<string>
     */
    private function phrases(mixed $value): array
    {
        $phrases = [];

        foreach (is_array($value) ? $value : [$value] as $phrase) {
            $phrase = trim($this->readable($phrase));

            if ($phrase !== '' && $phrase !== 'nothing') {
                $phrases[] = $phrase;
            }
        }

        return $phrases;
    }

    /**
     * Whether a collected answer is the value the check asked for.
     *
     * Compared as trimmed, case-folded text rather than by identity, because
     * every collected answer arrives as a string typed by a person: an
     * expectation written as the number 3 and an answer stored as "3" are the
     * same answer, and a strict comparison would fail a working bot over the
     * shape of a json literal.
     */
    private function sameValue(mixed $expected, mixed $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }

        return mb_strtolower(trim($this->readable($expected))) === mb_strtolower(trim($this->readable($actual)));
    }

    private function readable(mixed $value): string
    {
        if ($value === null) {
            return 'nothing';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Writes the outcome onto the check.
     *
     * The fingerprint is of the scenario the run was held against, so that a
     * green result recorded before somebody rewrote that scenario can be shown
     * as stale rather than as proof. Without it the list says "passed" about a
     * scenario that has not been rehearsed since.
     */
    private function record(QualityTest $check, QualityResult $result, ?Scenario $scenario): QualityResult
    {
        $check->forceFill([
            'last_run_at' => now(),
            'last_outcome' => $result->outcome(),
            'last_mismatches' => $result->mismatches() === [] ? null : $result->mismatches(),
            'last_transcript' => $result->transcript() === [] ? null : $result->transcript(),
            'last_fingerprint' => $scenario === null ? null : $this->versions->fingerprint($scenario),
        ])->save();

        return $result;
    }
}
