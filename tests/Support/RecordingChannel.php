<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Tests\Support;

use MB\FilamentChatbot\Channels\ChannelDriver;
use MB\FilamentChatbot\Channels\ChannelText;
use MB\FilamentChatbot\Channels\DeliveryResult;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * A channel that keeps what it was asked to send instead of sending it.
 *
 * Stands in for the third-party channels the package will grow — SMS, e-mail,
 * a plain webhook — and in particular for the ones that draw no buttons. The
 * two shipped drivers both support them, so without a driver like this the rule
 * that turns options into a numbered list would have no channel to be wrong on,
 * and would be proved only in the helper that implements it.
 *
 * It renders through `ChannelText` exactly as a real driver does, which is what
 * makes the assertion worth anything: a driver that formatted its own text
 * would be testing the test.
 */
final class RecordingChannel implements ChannelDriver
{
    /** @var list<string> */
    public array $sent = [];

    public bool $configured = true;

    public ?string $refusal = null;

    public function __construct(
        private readonly string $key = 'recording',
        private readonly bool $buttons = false,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Recording';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(Conversation $conversation, Message $message): DeliveryResult
    {
        if ($this->refusal !== null) {
            return DeliveryResult::failed($this->refusal);
        }

        $this->sent[] = ChannelText::forDriver($message, $this);

        return DeliveryResult::sent('recorded-'.$message->getKey());
    }

    public function supportsButtons(): bool
    {
        return $this->buttons;
    }

    /**
     * Stands in for a channel that leaves the building — the kind whose
     * delivery is queued, retried and stamped.
     */
    public function sendsOutward(): bool
    {
        return true;
    }
}
