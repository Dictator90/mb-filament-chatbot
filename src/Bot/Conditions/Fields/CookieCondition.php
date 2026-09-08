<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Conditions\ReceivesName;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * A value the embedding page was allowed to hand over: a campaign, a plan, a
 * chosen branch.
 *
 * The site already knows things the chat cannot see. Which campaign brought the
 * visitor, which tariff their account is on, which city they picked — all of it
 * sits in a cookie on the page the widget is embedded in, and a scenario that
 * can read it skips a question the customer has effectively already answered.
 *
 * **Only cookies the placement declared ever arrive here**, by name, listed by
 * whoever set the widget up. That list is what keeps an embed on a customer's
 * site from being a general-purpose reader of every cookie on it, and it is
 * checked twice: once when the widget is told what to read, and again on the
 * server when the answer comes back.
 *
 * **A cookie may steer a conversation and must never open anything.** It is a
 * value a browser held on a page this application does not control, and anybody
 * can set one: a branch reading `plan = enterprise` is reading a claim by
 * whoever was at the keyboard. Use it to shorten a path, choose a tone, route to
 * the right team — never as the gate in front of somebody's data. That gate is
 * {@see IdentifiedCondition}, which asks whether the host proved who this is.
 *
 * Read as the thread opened rather than as it is now, like the entry page: a
 * visitor keeps browsing while they type, and a branch on the current value
 * would take the same question in different directions depending on how long
 * they spent writing it.
 */
final class CookieCondition extends ConditionField implements ReceivesName
{
    use ComparesValues;

    private ?string $name = null;

    public static function key(): string
    {
        return 'cookie';
    }

    public function label(): string
    {
        return __('chatbot::nodes.conditions.cookie.label');
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Text;
    }

    public function needsName(): bool
    {
        return true;
    }

    public function hint(): ?string
    {
        return __('chatbot::nodes.conditions.cookie.hint');
    }

    public function withName(?string $name): static
    {
        $copy = clone $this;
        $copy->name = $name;

        return $copy;
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        $name = trim((string) $this->name);

        if ($name === '') {
            return false;
        }

        $cookies = (array) (((array) $conversation->meta)['cookies'] ?? []);
        $stored = $cookies[$name] ?? null;

        return $this->compare($operator, is_string($stored) ? $stored : null, $value);
    }
}
