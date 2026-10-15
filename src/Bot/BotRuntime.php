<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot;

use MB\FilamentChatbot\Bot\Variables\Variables;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * The runtime a step gets for the duration of one turn.
 *
 * Built per turn rather than injected as a singleton, because it is bound to a
 * particular conversation and to the one thing the visitor just said. A shared
 * instance would have to be told which conversation it is serving on every
 * call, and the first time that was forgotten a reply would go to the wrong
 * person.
 *
 * Saying something writes an outgoing message and stops there. Getting that
 * message onto Telegram or into the site widget is a channel's job, and keeping
 * that out of here has a reason: a step that spoke must not be able to fail
 * because a provider was down. The message is recorded, delivery is retried,
 * and the scenario carries on either way.
 */
class BotRuntime implements Runtime
{
    /**
     * Answers written this turn, held in memory as well as saved.
     *
     * A step reads back what an earlier step in the same walk stored, and the
     * conversation model in hand is the one being written to — so the two must
     * not disagree. Reading from the database instead would also cost a query
     * per lookup on a walk that may touch forty nodes.
     *
     * @var array<string, string>
     */
    private array $answers = [];

    public function __construct(
        private readonly Conversation $conversation,
        private readonly Variables $variables,
        private readonly RunJournal $journal,
        private readonly ?string $said = null,
    ) {
        $this->answers = array_map(
            static fn (mixed $value): string => (string) $value,
            (array) $conversation->bot_answers,
        );
    }

    /**
     * @param  list<string>  $options
     */
    public function say(string $text, array $options = [], bool $wantsContact = false): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $offered = array_values(array_filter(
            array_map(static fn (mixed $label): string => trim((string) $label), $options),
            static fn (string $label): bool => $label !== '',
        ));

        /*
         * Left absent rather than written empty when nothing is offered, so an
         * ordinary sentence carries no keys at all and every channel's "is
         * there a menu here" stays a straight look at the meta.
         */
        $meta = array_filter([
            'options' => $offered === [] ? null : $offered,
            'request_contact' => $wantsContact ?: null,
        ]);

        Message::create([
            'conversation_id' => $this->conversation->getKey(),
            'direction' => MessageDirection::Outgoing,
            'author' => MessageAuthor::Bot,
            'body' => $text,
            'meta' => $meta === [] ? null : $meta,
        ]);

        $this->conversation->forceFill(['last_message_at' => now()])->save();
        $this->journal->spoke();
    }

    /**
     * An empty value is written, not skipped.
     *
     * A node may run a second time against a different record, and a value left
     * over from the first run is a sentence about somebody else's data. Blank is
     * the honest answer there; stale is not.
     */
    public function store(string $name, ?string $value): void
    {
        $name = trim($name);

        if ($name === '') {
            return;
        }

        $this->answers[$name] = mb_substr((string) $value, 0, 1000);

        $this->conversation->forceFill(['bot_answers' => $this->answers])->save();
    }

    public function value(string $name): ?string
    {
        if (array_key_exists($name, $this->answers)) {
            return $this->answers[$name];
        }

        return $this->variables->value($this->conversation, $name, $this->said);
    }

    public function fill(?string $template): string
    {
        return $this->variables->fill($template, $this->conversation, $this->said);
    }

    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        return $this->variables->known($this->conversation);
    }

    public function said(): ?string
    {
        return $this->said;
    }
}
