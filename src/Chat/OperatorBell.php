<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Chat;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use MB\FilamentChatbot\Contracts\OperatorAudience;
use MB\FilamentChatbot\Filament\Pages\ChatInbox;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use Throwable;

/**
 * Says that a conversation is waiting for a person.
 *
 * **Rung in two places and no others**, and the restraint is the design. Ring
 * when the visitor writes and the desk fills with threads the bot was about to
 * answer perfectly well; people learn to ignore the bell within a week, and the
 * one conversation that genuinely needed them waits with the rest. So: when the
 * bot hands over, and when a message arrives that the bot did not take. Both
 * mean the same thing — somebody is waiting and nothing automatic is coming.
 *
 * **Not on a thread that is already waiting.** The second message of an
 * unanswered conversation is not new information; it is the same person, still
 * waiting, and a second bell says nothing the first did not.
 *
 * Sent immediately rather than queued, like everything else that lives only in
 * the panel: writing the row is one statement, and a stopped worker would mean
 * the bell never rings at all rather than ringing late.
 */
final class OperatorBell
{
    public function __construct(private readonly OperatorAudience $audience) {}

    /**
     * Tells whoever should hear it, and never gets in the way of the message.
     *
     * Every failure is swallowed on purpose. On the other side of this call is a
     * customer's message inside the transaction that stores it: a host audience
     * that throws, a notifications table that is not there, a panel that cannot
     * build a URL — none of those may cost the customer their message or answer
     * the channel with a failure it will retry.
     */
    public function ring(Conversation $conversation, ?Message $message): void
    {
        try {
            $recipients = [...$this->audience->for($conversation)];

            if ($recipients === []) {
                return;
            }

            Notification::make()
                ->title($conversation->title())
                ->body($this->excerpt($conversation, $message))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->actions($this->actions($conversation))
                ->sendToDatabase($recipients);
        } catch (Throwable) {
            return;
        }
    }

    /**
     * The channel and the first words, which is what makes the bell worth
     * opening.
     *
     * A notification saying only that somebody wrote is one people clear
     * without reading. The excerpt is short on purpose: it goes in a panel
     * dropdown, and a paragraph there pushes the next notification off the
     * screen.
     */
    private function excerpt(Conversation $conversation, ?Message $message): string
    {
        $body = trim((string) $message?->body);
        $channel = (string) $conversation->channel;

        return $body === ''
            ? $channel
            : $channel.' · '.str($body)->limit(120)->value();
    }

    /**
     * A way into the thread, when one can be worked out.
     *
     * The address depends on which panel the plugin was registered in, and this
     * runs in places that have no panel at all — a webhook, a queued
     * continuation. Where it cannot be built the notification still names the
     * conversation and the channel, which is enough to find it; a bell that
     * threw because it could not make a link would be worse than a bell without
     * one.
     *
     * @return array<int, Action>
     */
    private function actions(Conversation $conversation): array
    {
        try {
            $url = ChatInbox::getUrl(['conversation' => $conversation->getKey()]);
        } catch (Throwable) {
            return [];
        }

        return [
            Action::make('open')
                ->label(__('chatbot::panel.inbox.open_conversation'))
                ->url($url),
        ];
    }
}
