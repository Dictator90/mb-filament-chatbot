<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Chat;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * One human answering one person.
 *
 * A class rather than a few lines in the inbox page, because typing a reply is
 * not only writing a row: it decides who owns the conversation and whether the
 * bot is still allowed to speak. The panel is already the second caller — a
 * host answering from its own screen, an API, a "reply from the notification"
 * link are all the third — and every copy of those decisions that is not made
 * here is a copy that will be missing one of them.
 */
class OperatorReply
{
    /**
     * Writes the reply, takes the conversation and silences the bot.
     *
     * @param  mixed  $user  Whoever is answering: a model, an id, or nothing at
     *                       all when a host replies outside a session.
     */
    public function send(Conversation $conversation, string $body, mixed $user = null): Message
    {
        $body = trim($body);

        /*
         * An empty reply is refused rather than written as a blank line.
         *
         * The person on the other end would receive a message with nothing in
         * it — on most channels an audible notification for no content — and the
         * operator would see their own empty bubble and assume they had
         * answered. Refusing loudly is what keeps the caller from discovering
         * this on a customer.
         */
        if ($body === '') {
            throw new InvalidArgumentException('An operator reply cannot be empty.');
        }

        $userId = $this->userId($user);

        $this->takeOver($conversation, $userId);

        /*
         * The message is created after the conversation has been settled, and
         * outside any transaction, because creating it is what sends it.
         *
         * `MessageObserver` queues delivery the moment the row exists, and
         * nothing recalls a message a channel has already accepted. Written
         * first, a request that died in between — or a transaction that rolled
         * back after the job was queued — would leave the customer holding an
         * answer while the bot was still running over the operator, which is the
         * one order of events that cannot be repaired afterwards. Settling first
         * fails the harmless way round: a conversation assigned to somebody who
         * has not spoken yet.
         */
        $message = Message::create([
            'conversation_id' => $conversation->getKey(),
            'direction' => MessageDirection::Outgoing,
            'author' => MessageAuthor::Operator,
            'user_id' => $userId,
            'body' => $body,
        ]);

        /*
         * The read mark is stamped by the same act. An operator who has just
         * answered has plainly read everything above their own reply, and a
         * conversation that stayed unread after being answered would keep its
         * place in the unread badge and send them back to a thread they had
         * already dealt with.
         */
        $conversation->forceFill([
            'last_message_at' => $message->created_at ?? now(),
            'agent_read_at' => now(),
        ])->save();

        return $message;
    }

    /**
     * Puts the answering human on the conversation and stops the bot.
     *
     * Both happen without being asked for, and that is the point. `BotEngine`
     * falls silent when a conversation has an assignee or has been handed off,
     * so the person who starts typing is also the person who decides whether the
     * bot keeps talking — and expecting them to remember a "take it" button
     * first means the bot answers over the top of their reply while they are
     * still reading the customer's question.
     *
     * An owner is never taken away from somebody else here: an operator helping
     * out on a colleague's conversation should not silently inherit it.
     */
    private function takeOver(Conversation $conversation, ?int $userId): void
    {
        $changes = [];

        if ($conversation->assigned_to_id === null && $userId !== null) {
            $changes['assigned_to_id'] = $userId;
        }

        if ($conversation->bot_state === BotState::Active) {
            $changes['bot_state'] = BotState::HandedOff;
            $changes['bot_step_key'] = null;
            $changes['bot_handed_off_at'] = now();
        }

        if ($changes !== []) {
            $conversation->forceFill($changes)->save();
        }
    }

    /**
     * The id behind whatever the caller passed.
     *
     * A model, a bare id and null all reach this class: the panel has an
     * authenticated user, a host's own code often has only an id, and a reply
     * sent by an automation has neither. Resolving all three in one place keeps
     * the alternative — every caller normalising it first — from producing the
     * one call site that stores an object's string cast in an integer column.
     */
    private function userId(mixed $user): ?int
    {
        if ($user instanceof Model) {
            $key = $user->getKey();

            return is_numeric($key) ? (int) $key : null;
        }

        return is_numeric($user) ? (int) $user : null;
    }
}
