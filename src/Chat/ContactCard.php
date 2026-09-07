<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Chat;

/**
 * What the desk says about the person a thread belongs to.
 *
 * Three fields and no more, because the desk has one line for this and the
 * temptation is to fill it with everything the host knows. A card with a balance,
 * a last order, a tag and a note is a card an operator stops reading — and the
 * one thing they open the thread to learn, who they are talking to, is then
 * somewhere in the middle of it.
 *
 * Whatever appears here is the host's decision. The package never reads the
 * linked record itself, so a card can say "Anna Petrova, VIP since 2019" or
 * "Client #4471" or nothing at all, and no rule of this package pushes it one
 * way — including the rule that the passport on that record is not the desk's
 * business.
 */
final readonly class ContactCard
{
    /**
     * @param  string  $name  who this is, in as few words as the host can manage
     * @param  string|null  $note  one short qualifier — a status, a tag, a balance
     * @param  string|null  $url  where the operator goes to see the record itself
     */
    public function __construct(
        public string $name,
        public ?string $note = null,
        public ?string $url = null,
    ) {}

    /**
     * Whether there is anything worth drawing.
     *
     * A card with an empty name is nothing, however much else it carries: the
     * name is the line, and a note beside a blank one reads as a fault in the
     * screen rather than as information.
     */
    public function filled(): bool
    {
        return trim($this->name) !== '';
    }
}
