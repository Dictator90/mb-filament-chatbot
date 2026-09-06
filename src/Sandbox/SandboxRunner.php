<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Sandbox;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use MB\FilamentChatbot\Bot\BotEngine;
use MB\FilamentChatbot\Channels\WithoutDelivery;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\ConversationStatus;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;

/**
 * Lets somebody in the panel talk to the bot without being a customer.
 *
 * The conversation is held by the real engine, driven exactly the way a channel
 * drives it. That is the whole point and the only thing here worth defending: a
 * sandbox that walked the graph itself would agree with the drawing and disagree
 * with production, and it would do so most confidently about the branches an
 * author came here to check.
 *
 * A sandbox thread is marked in `meta` and therefore hidden by the `real` global
 * scope on Conversation — the same mechanism that keeps a quality rehearsal off
 * the desk, not a second one beside it. An operator must never open a thread
 * that somebody was testing with, answer it, and find nobody there; the reports
 * must never count it as work.
 *
 * **The model stays on here, unlike in a quality rehearsal.** The two look like
 * the same thing and are not: a run of checks is unattended, repeats itself and
 * would spend a month's budget before anybody is awake, while this is one person
 * pressing send and waiting to see what the customer would read — shown the
 * "could not" branch instead, they would take it for a fault in the scenario
 * they came to test. The calls are reserved and settled like any others, so a
 * sandbox cannot spend past the ceiling either: it is charged, not exempt.
 *
 * Nothing accumulates. One thread per person per screen, replaced when they
 * start again, and anything older than the configured window is swept the next
 * time somebody opens a sandbox. Messages and runs go with it by foreign key.
 * The alternative is a table that grows for the life of the installation with
 * threads nobody can see and nobody will ever delete by hand.
 */
final class SandboxRunner
{
    public function __construct(
        private readonly Container $container,
        private readonly WithoutDelivery $undelivered,
    ) {}

    /**
     * Names the one thread that belongs to this person on this screen.
     *
     * Two people trying the same bot get two threads, and the same person gets
     * one per screen: the canvas beside a scenario and the sandbox screen are
     * different questions, and sharing a thread between them would have one
     * answer the other's half-finished conversation.
     */
    public static function ownerKey(string $surface, int|string|null $user): string
    {
        return $surface.':'.($user ?? 'guest');
    }

    /**
     * The person's thread on this screen, resumed if it is still there.
     *
     * Resumed rather than always fresh, because a Livewire page is remounted by
     * an ordinary browser reload, and a sandbox that forgot the conversation on
     * every reload would be unable to test anything that takes more than one
     * message — which is every scenario worth drawing.
     */
    public function open(string $owner, ?Bot $bot, string $channel = 'site'): Conversation
    {
        $this->sweep();

        $existing = $this->find($owner);

        if ($existing !== null && (int) $existing->bot_id === (int) $bot?->getKey()) {
            return $existing;
        }

        $existing?->delete();

        return Conversation::query()->create([
            'bot_id' => $bot?->getKey(),
            'channel' => $channel,
            'status' => ConversationStatus::Open,
            'bot_state' => BotState::Off,
            'display_name' => 'Sandbox',
            'meta' => [Conversation::SANDBOX_META_KEY => $owner],
        ]);
    }

    /**
     * Throws this person's thread away and hands back a new one.
     */
    public function restart(string $owner, ?Bot $bot, string $channel = 'site'): Conversation
    {
        $this->find($owner)?->delete();

        return $this->open($owner, $bot, $channel);
    }

    /**
     * @return Conversation|null the thread marked with this owner, if any
     */
    public function find(string $owner): ?Conversation
    {
        return $this->query()
            ->where('meta->'.Conversation::SANDBOX_META_KEY, $owner)
            ->latest('id')
            ->first();
    }

    /**
     * Says one line as the visitor and lets the bot take its turn.
     *
     * A fresh engine per line, as the quality runner does it: the engine carries
     * per-turn state — the jump budget, the journal it is writing — and one
     * instance reused across five lines is not five turns.
     *
     * `$enter` pins the scenario the turn must begin in, and only the canvas
     * passes it. There the author is testing one drawing, and letting the router
     * choose would mean a keyword-triggered scenario could not be tried at all
     * without guessing its own keyword. Everywhere else the router decides, which
     * is the only way routing itself can be tested.
     *
     * @return bool whether the bot took the turn
     */
    public function say(Conversation $conversation, string $said, ?Scenario $enter = null): bool
    {
        $said = trim($said);

        if ($said === '') {
            return false;
        }

        return $this->undelivered->run(function () use ($conversation, $said, $enter): bool {
            $message = Message::query()->create([
                'conversation_id' => $conversation->getKey(),
                'direction' => MessageDirection::Incoming,
                'author' => MessageAuthor::Visitor,
                'body' => $said,
            ]);

            $conversation->refresh();

            $engine = $this->container->make(BotEngine::class);

            return $this->beginsAfresh($conversation, $enter)
                ? $engine->enter($conversation, $enter, $said)
                : $engine->handle($conversation, $message);
        });
    }

    /**
     * Everything said in the thread, in order, both sides.
     *
     * @return list<array{from: string, body: string}>
     */
    public function transcript(Conversation $conversation): array
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
     * What the bot is waiting to be answered with, when it offered a choice.
     *
     * Asked of the conversation, which is where the widget asks it, so the
     * sandbox meets an `ask` node the way a visitor does. Reading the messages
     * table alone does not do that: options are rendered per channel and are no
     * part of the message body, so a question carrying three buttons arrives in
     * the transcript as one bare line. An author testing that node sees the
     * question, sees nothing to answer it with, and concludes the node is broken
     * — when what is broken is the screen they are looking at.
     *
     * @return list<array{label: string}>
     */
    public function choices(Conversation $conversation): array
    {
        return $conversation->pendingChoices();
    }

    /**
     * The nodes the conversation genuinely passed through, in order.
     *
     * Read from the run journal rather than worked out from the graph, because
     * the question a sandbox is opened to answer is which branch was taken — and
     * a path derived from the scenario would answer with the branch the author
     * drew, which is the thing under test.
     *
     * @return list<array{seq: int, key: string, type: string, scenario: string, outcome: string, said: ?string}>
     */
    public function trace(Conversation $conversation): array
    {
        return $this->steps($conversation)
            ->map(static fn (RunStep $step): array => [
                'seq' => (int) $step->seq,
                'key' => (string) $step->step_key,
                'type' => (string) $step->step_type,
                'scenario' => (string) $step->scenario_slug,
                'outcome' => (string) $step->outcome,
                'said' => $step->said === null ? null : (string) $step->said,
            ])
            ->values()
            ->all();
    }

    /**
     * The node keys to light up on one scenario's canvas.
     *
     * Filtered by the scenario's slug, and that filter is not tidiness: node keys
     * are unique inside a scenario and nowhere else, so a conversation that
     * jumped into another scenario with a node also called `ask` would light up
     * a node on this canvas that the visitor never reached. Highlighting is only
     * worth having while it can be trusted.
     *
     * @return list<string>
     */
    public function visited(Conversation $conversation, ?string $scenarioSlug = null): array
    {
        return $this->steps($conversation)
            ->when(
                $scenarioSlug !== null,
                static fn (Collection $steps): Collection => $steps->filter(
                    static fn (RunStep $step): bool => (string) $step->scenario_slug === $scenarioSlug,
                ),
            )
            ->map(static fn (RunStep $step): string => (string) $step->step_key)
            ->filter(static fn (string $key): bool => $key !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Where the conversation stands, in the words the screens print.
     *
     * Closed is asked first because a closed thread also has its bot switched
     * off, and answering "off" for it would tell an author their goodbye node
     * never ran.
     */
    public function state(Conversation $conversation): string
    {
        if ($conversation->status === ConversationStatus::Closed) {
            return 'closed';
        }

        if ($conversation->bot_state === BotState::HandedOff) {
            return 'handed off';
        }

        if ($conversation->bot_step_key !== null) {
            return 'waiting';
        }

        return 'finished';
    }

    /**
     * Whether this turn should start the pinned scenario from its start node.
     *
     * Not while the conversation is waiting on a node: the author's answer
     * belongs to the question that was asked, and re-entering would ask it
     * again forever. Not after a handover either — a bot that keeps talking
     * over a handover is the one thing the engine's silence rules exist to
     * prevent, and a sandbox that hid that from an author would hide it in
     * production too.
     */
    private function beginsAfresh(Conversation $conversation, ?Scenario $enter): bool
    {
        return $enter !== null
            && $conversation->bot_step_key === null
            && $conversation->bot_state !== BotState::HandedOff
            && $conversation->assigned_to_id === null;
    }

    /**
     * @return Collection<int, RunStep>
     */
    private function steps(Conversation $conversation): Collection
    {
        return RunStep::query()
            ->whereIn('run_id', Run::query()->select('id')->where('conversation_id', $conversation->getKey()))
            ->orderBy('run_id')
            ->orderBy('seq')
            ->get();
    }

    /**
     * Drops sandbox threads nobody has touched for a while.
     *
     * On open rather than on a schedule, because a package cannot assume the
     * host runs one — and a cleanup that only happens where a scheduler was
     * configured is a cleanup that does not happen. The window is generous: it
     * exists to stop a year of abandoned experiments accumulating, not to take a
     * thread away from somebody who went to lunch.
     */
    private function sweep(): void
    {
        $hours = (int) config('chatbot.sandbox.keep_hours', 24);

        if ($hours <= 0) {
            return;
        }

        $this->query()
            ->whereNotNull('meta->'.Conversation::SANDBOX_META_KEY)
            ->where('updated_at', '<', now()->subHours($hours))
            ->get()
            ->each(static fn (Conversation $conversation) => $conversation->delete());
    }

    /**
     * Sandbox threads are hidden from the package by the `real` global scope, so
     * the sandbox itself is the one place that lifts it — by name, so that the
     * decision is readable rather than accidental.
     *
     * @return Builder<Conversation>
     */
    private function query(): Builder
    {
        return Conversation::query()->withoutGlobalScope(Conversation::REAL_SCOPE);
    }
}
