<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Conditions\Fields;

use Illuminate\Support\Str;
use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

/**
 * Which channel this thread arrived on.
 *
 * Worth branching on because the channels are not interchangeable: the site
 * widget can show a form, Telegram cannot, and a scenario that ignores the
 * difference sends one of them a message that does not work.
 *
 * The options come from configuration rather than a fixed list, because
 * channels are registered by the host — a closed list here would leave every
 * channel the package did not ship out of the editor's picker while still
 * working at runtime, which is the kind of gap authors work around by typing
 * the key into some other field.
 */
final class ChannelCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'channel';
    }

    public function label(): string
    {
        return __('chatbot::nodes.conditions.channel.label');
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Choice;
    }

    /**
     * @return array<string, string>|null
     */
    public function options(): ?array
    {
        $channels = config('chatbot.channels');

        if (! is_array($channels) || $channels === []) {
            return null;
        }

        $options = [];

        foreach (array_keys($channels) as $key) {
            $options[(string) $key] = Str::headline((string) $key);
        }

        return $options;
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        return $this->compare(
            $operator,
            is_scalar($conversation->channel) ? (string) $conversation->channel : null,
            $value,
        );
    }
}
