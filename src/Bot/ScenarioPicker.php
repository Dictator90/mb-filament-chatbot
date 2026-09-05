<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot;

use Illuminate\Support\Collection;
use MB\FilamentChatbot\Bot\Routing\ScenarioRouter;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Decides which scenario answers an incoming message.
 *
 * Deterministic first, and that is deliberate: routing by keyword works with
 * the AI switched off, with no key configured, and with the provider down. A
 * model may be asked afterwards — a scenario carries an invocation contract for
 * exactly that — but it never becomes the only way in, and it is never asked
 * about a message the words already claimed. A bot whose routing needs a
 * working provider is a bot that goes silent when the bill is not paid.
 *
 * Everything the picker needs is on the scenario's start node, not on the
 * scenario row. Two places describing how a conversation begins would disagree
 * on the first edit, and the one an author can see is the canvas.
 */
class ScenarioPicker
{
    /**
     * The router is optional, and the package binds none by default.
     *
     * That is what keeps this class's behaviour identical on an installation
     * with no model: nothing is resolved, nothing is asked, and the order below
     * is the order it always was. A host that wants a model to help binds
     * {@see ScenarioRouter}, and even then it is asked last.
     */
    public function __construct(
        private readonly Greeting $greeting,
        private readonly ?ScenarioRouter $router = null,
    ) {}

    /**
     * The best scenario for this message, or null when nothing claims it.
     *
     * Preference is by trigger kind first and priority second: an explicit
     * keyword beats a greeting, and a greeting beats the catch-all. Ordering by
     * priority alone would let a high-priority fallback swallow every keyword
     * in the installation, and the author would have no way to see why.
     *
     * A model, when one is bound, is asked between the greeting and the
     * fallback and nowhere else. Before the keywords it would spend a request
     * on every message the words already answer, and lose the one property an
     * author can rely on — that what is written on the start node is what
     * routes. After the fallback it would never be asked at all, because a
     * catch-all matches everything by definition.
     */
    public function pick(Conversation $conversation, ?string $said, bool $isFirstMessage): ?Scenario
    {
        $candidates = $this->candidates($conversation);

        foreach (['keyword', 'greeting'] as $trigger) {
            $match = $this->firstMatching($candidates, $conversation, $said, $isFirstMessage, $trigger);

            if ($match !== null) {
                return $match;
            }
        }

        return $this->routed($conversation, $said, $candidates)
            ?? $this->firstMatching($candidates, $conversation, $said, $isFirstMessage, 'fallback');
    }

    /**
     * @param  Collection<int, Scenario>  $candidates
     */
    protected function firstMatching(
        Collection $candidates,
        Conversation $conversation,
        ?string $said,
        bool $isFirstMessage,
        string $trigger,
    ): ?Scenario {
        return $candidates
            ->filter(fn (Scenario $scenario): bool => $this->triggerOf($scenario) === $trigger)
            ->first(fn (Scenario $scenario): bool => $this->matches($scenario, $conversation, $said, $isFirstMessage, $trigger));
    }

    /**
     * What a bound router makes of the message, if anything.
     *
     * The list handed over is already narrowed to scenarios this conversation
     * could legitimately be sent to — the channel and the audience of the start
     * node still decide that, not the router. A model has no way to know that a
     * scenario is for signed-in customers on the site only, and a router allowed
     * to return one anyway would walk a Telegram visitor into it.
     *
     * @param  Collection<int, Scenario>  $candidates
     */
    protected function routed(Conversation $conversation, ?string $said, Collection $candidates): ?Scenario
    {
        if ($this->router === null) {
            return null;
        }

        $eligible = $candidates
            ->filter(fn (Scenario $scenario): bool => $this->isReachable($scenario, $conversation))
            ->values();

        return $eligible->isEmpty() ? null : $this->router->route($conversation, $said, $eligible);
    }

    /**
     * Whether this conversation is allowed to reach the scenario at all.
     */
    protected function isReachable(Scenario $scenario, Conversation $conversation): bool
    {
        $start = $scenario->startStep();

        return $start !== null
            && $this->coversChannel($start, $conversation)
            && $this->coversAudience($start, $conversation);
    }

    /**
     * Active scenarios of this bot and the shared ones, steps already loaded.
     *
     * Steps come along because every candidate is asked about its start node.
     * Fetching them per scenario would be one query per scenario on every
     * incoming message — the busiest path in the package.
     *
     * @return Collection<int, Scenario>
     */
    protected function candidates(Conversation $conversation): Collection
    {
        return Scenario::query()
            ->active()
            ->forBot($conversation->bot_id)
            ->with('steps')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
    }

    protected function triggerOf(Scenario $scenario): string
    {
        $start = $scenario->startStep();

        if ($start === null) {
            return 'call';
        }

        return (string) $start->setting('trigger', 'keyword');
    }

    /**
     * Whether a scenario is willing to take this conversation.
     */
    protected function matches(
        Scenario $scenario,
        Conversation $conversation,
        ?string $said,
        bool $isFirstMessage,
        string $trigger,
    ): bool {
        $start = $scenario->startStep();

        if ($start === null) {
            return false;
        }

        if (! $this->coversChannel($start, $conversation)) {
            return false;
        }

        if (! $this->coversAudience($start, $conversation)) {
            return false;
        }

        return match ($trigger) {
            'keyword' => $this->hasKeyword($start, $said),
            'greeting' => $isFirstMessage || $this->greeting->isGreeting($said),
            'fallback' => true,
            default => false,
        };
    }

    /**
     * An empty channel list means every channel.
     *
     * The reverse — empty meaning none — would make a freshly drawn scenario
     * silently unreachable, and the author would look for the fault in the
     * keywords.
     */
    protected function coversChannel(Step $start, Conversation $conversation): bool
    {
        $channels = (array) $start->setting('channels', []);

        return $channels === [] || in_array($conversation->channel, $channels, true);
    }

    protected function coversAudience(Step $start, Conversation $conversation): bool
    {
        $audience = (string) $start->setting('audience', 'any');

        return match ($audience) {
            'known' => $conversation->isIdentified(),
            'anonymous' => ! $conversation->isIdentified(),
            default => true,
        };
    }

    /**
     * Keywords match on word boundaries, never as substrings.
     *
     * A substring match makes the keyword "no" fire on "notebook" and "cancel"
     * fire on "cancellation policy" — and the author, reading a list of
     * perfectly sensible words, has no way to see it coming.
     */
    protected function hasKeyword(Step $start, ?string $said): bool
    {
        $said = mb_strtolower(trim((string) $said));

        if ($said === '') {
            return false;
        }

        foreach ((array) $start->setting('keywords', []) as $keyword) {
            $keyword = mb_strtolower(trim((string) $keyword));

            if ($keyword === '') {
                continue;
            }

            if (preg_match('/(?<!\w)'.preg_quote($keyword, '/').'(?!\w)/u', $said) === 1) {
                return true;
            }
        }

        return false;
    }
}
