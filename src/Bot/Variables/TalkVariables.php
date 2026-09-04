<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Variables;

use MB\FilamentChatbot\Models\Conversation;

/**
 * The four things every installation can say about a conversation.
 *
 * Everything here is readable from the thread itself, which is the line that
 * decides what belongs in the package: a bot that greeted someone by name would
 * be useful to every host, but only the host knows which of its tables the name
 * is in. Those live in sources the host registers.
 *
 * `answer` is the odd one out, and the reason this class carries state at all.
 * What the visitor just wrote is not on the conversation row — it arrives with
 * the turn and is written to the transcript afterwards — so a template rendered
 * before that write has nothing to read. It is handed in with `withSaid()`
 * instead, and `Variables` calls that before every read.
 *
 * `withSaid()` returns a copy rather than mutating. The registry keeps one
 * instance of each source and hands the same object to every conversation, so a
 * setter would leave one visitor's sentence sitting on the shared source: the
 * next conversation rendered in the same worker — a queue running a batch of
 * replies, an Octane request — would fill its `{{answer}}` with a stranger's
 * message. A copy cannot be read by anyone the caller did not hand it to.
 */
final class TalkVariables implements VariableSource
{
    private ?string $said = null;

    public function key(): string
    {
        return 'talk';
    }

    public function label(): string
    {
        return 'Conversation';
    }

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return [
            'answer' => 'What the visitor just wrote',
            'channel' => 'Channel',
            'visitor' => 'Visitor name',
            'bot' => 'Bot name',
        ];
    }

    /**
     * A copy of this source that knows what was said on the current turn.
     */
    public function withSaid(?string $said): static
    {
        $copy = clone $this;
        $copy->said = $said;

        return $copy;
    }

    public function value(Conversation $conversation, string $field): ?string
    {
        return match ($field) {
            'answer' => $this->said,
            'channel' => $this->text($conversation->channel),
            'visitor' => $this->visitorName($conversation),
            'bot' => $this->text($conversation->bot?->name),
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public function availableAfter(): array
    {
        return [];
    }

    /**
     * Whatever the channel told us this person is called, and nothing else.
     *
     * Deliberately does not fall through to the linked contact record, for the
     * reason `Conversation::title()` gives: resolving the morph would load a
     * host model this package knows nothing about, once per rendered template,
     * for a class that may not even have a name column.
     *
     * Null rather than a placeholder when there is no name. A sentence that
     * renders as "Hello," reads as a gap the author fixes; one that renders as
     * "Hello, visitor," reads as deliberate and ships.
     */
    private function visitorName(Conversation $conversation): ?string
    {
        if (filled($conversation->display_name)) {
            return (string) $conversation->display_name;
        }

        if (filled($conversation->username)) {
            return (string) $conversation->username;
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
