<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot;

use Illuminate\Support\Collection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Decides which scenario answers an incoming message.
 *
 * Deterministic, and that is deliberate: routing by keyword works with the AI
 * switched off, with no key configured, and with the provider down. The model
 * gets to improve this later — a scenario carries an invocation contract for
 * exactly that — but it never becomes the only way in. A bot whose routing
 * needs a working provider is a bot that goes silent when the bill is not paid.
 *
 * Everything the picker needs is on the scenario's start node, not on the
 * scenario row. Two places describing how a conversation begins would disagree
 * on the first edit, and the one an author can see is the canvas.
 */
class ScenarioPicker
{
    public function __construct(private readonly Greeting $greeting) {}

    /**
     * The best scenario for this message, or null when nothing claims it.
     *
     * Preference is by trigger kind first and priority second: an explicit
     * keyword beats a greeting, and a greeting beats the catch-all. Ordering by
     * priority alone would let a high-priority fallback swallow every keyword
     * in the installation, and the author would have no way to see why.
     */
    public function pick(Conversation $conversation, ?string $said, bool $isFirstMessage): ?Scenario
    {
        $candidates = $this->candidates($conversation);

        foreach (['keyword', 'greeting', 'fallback'] as $trigger) {
            $match = $candidates
                ->filter(fn (Scenario $scenario): bool => $this->triggerOf($scenario) === $trigger)
                ->first(fn (Scenario $scenario): bool => $this->matches($scenario, $conversation, $said, $isFirstMessage, $trigger));

            if ($match !== null) {
                return $match;
            }
        }

        return null;
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
