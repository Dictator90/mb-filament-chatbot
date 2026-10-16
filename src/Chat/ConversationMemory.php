<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Chat;

use MB\FilamentChatbot\Ai\AiMessage;
use MB\FilamentChatbot\Ai\Redactor;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;

/**
 * How much of a conversation a model is shown, and in what shape.
 *
 * A model that leads a conversation has to remember it, and "remember it" is
 * not the same as "receive all of it". A thread with a regular customer runs to
 * hundreds of messages over months; sending them costs money on every single
 * turn, pushes the agency's own instruction further from the model's attention
 * the longer the thread gets, and eventually gets refused outright for length.
 * So three decisions live here, and all three are the host's to tune.
 *
 * **A window.** The last few exchanges, verbatim. This is what makes "а в июле
 * дешевле?" answerable, and it is almost always the only history that matters.
 *
 * **A summary of what fell out of it.** Three lines about who this is and what
 * they want, written once and carried forward, so a thread that has scrolled
 * past its window still knows the customer is flying from Donetsk with two
 * children. Without it the model forgets the first half of its own interview
 * and asks again — which is the single thing that makes a bot feel like a form.
 *
 * **A line that can be drawn under all of it.** When a scenario finishes — the
 * order is filed, the thread is handed to a person — the window starts again
 * from that point. Nothing is deleted: the transcript is the record and stays
 * whole. What changes is where the model starts reading, and that is the
 * difference between "we are still arranging your July trip" and a model
 * re-opening a request that was closed three weeks ago.
 *
 * **Everything leaving goes through the redactor**, the visitor's own words
 * included, for the same reason a single question does ({@see Redactor}): the
 * numbers in a transcript are passports, cards and phone numbers, and a model
 * that leads the conversation is shown far more of them than a model that
 * answers one question.
 */
final class ConversationMemory
{
    /**
     * The key under `meta` holding the running summary, and the key holding the
     * message the window starts after.
     */
    public const SUMMARY_KEY = 'ai_summary';

    public const WINDOW_KEY = 'ai_window_from';

    /**
     * The last message the summary already accounts for.
     *
     * Without it the same lines are summarised again on every turn: a paid call
     * each time, and a summary that drifts further from the conversation with
     * every rewriting of a rewriting.
     */
    public const SUMMARISED_KEY = 'ai_summarised_until';

    public function __construct(private readonly Redactor $redactor) {}

    /**
     * The last `$limit` messages, oldest first, as the model should read them.
     *
     * Bot and operator lines are both the assistant's: to the model they are
     * what "this bot" has said in this thread, and telling them apart would
     * invite it to contradict the person who took the conversation over.
     *
     * @return list<AiMessage>
     */
    public function window(Conversation $conversation, int $limit): array
    {
        $limit = max(1, min($limit, 100));

        $messages = Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->when($this->windowFrom($conversation) > 0, fn ($query) => $query->where('id', '>', $this->windowFrom($conversation)))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'author', 'body'])
            ->reverse()
            ->values();

        $window = [];

        foreach ($messages as $message) {
            $text = $this->textOf($message);

            if ($text === '') {
                continue;
            }

            $window[] = $message->author === MessageAuthor::Visitor
                ? AiMessage::fromVisitor($text)
                : AiMessage::fromBot($text);
        }

        return $window;
    }

    /**
     * What is known about this person from the part of the thread that has
     * scrolled out of the window, or null.
     */
    public function summary(Conversation $conversation): ?string
    {
        $summary = trim((string) ($this->meta($conversation)[self::SUMMARY_KEY] ?? ''));

        return $summary === '' ? null : $summary;
    }

    public function remember(Conversation $conversation, string $summary, ?int $until = null): void
    {
        $summary = trim($summary);

        if ($summary === '') {
            return;
        }

        $this->write($conversation, array_filter([
            self::SUMMARY_KEY => mb_substr($summary, 0, 2000),
            self::SUMMARISED_KEY => $until,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * The lines that have scrolled out of the window and are not in the summary
     * yet, with the id up to which summarising them would account for.
     *
     * Returned as prose lines rather than as models, because the one caller
     * hands them to a model to compress. Who said what is kept — a summary
     * written from an undifferentiated wall of text comes back describing the
     * bot's own suggestions as the customer's wishes.
     *
     * The ceiling is there for the thread nobody summarised for a month: sixty
     * lines is already more than any summary needs, and an uncapped read would
     * put a year of conversation into one request on the turn somebody finally
     * triggered it.
     *
     * @return array{lines: list<string>, until: int}
     */
    public function fallenOut(Conversation $conversation, int $window): array
    {
        $window = max(1, min($window, 100));
        $from = max($this->windowFrom($conversation), $this->summarisedUntil($conversation));

        $recent = Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->orderByDesc('id')
            ->limit($window)
            ->pluck('id');

        if ($recent->count() < $window) {
            return ['lines' => [], 'until' => 0];
        }

        $messages = Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('id', '>', $from)
            ->where('id', '<', (int) $recent->min())
            ->orderBy('id')
            ->limit(60)
            ->get(['id', 'author', 'body']);

        $lines = [];

        foreach ($messages as $message) {
            $text = $this->textOf($message);

            if ($text === '') {
                continue;
            }

            $who = $message->author === MessageAuthor::Visitor
                ? __('chatbot::bot.memory.visitor')
                : __('chatbot::bot.memory.bot');

            $lines[] = $who.': '.$text;
        }

        return [
            'lines' => $lines,
            'until' => (int) ($messages->last()?->getKey() ?? 0),
        ];
    }

    /**
     * A message as the model may read it: the visitor's words cleaned, the
     * bot's own left as they were said.
     *
     * Redacting the bot's lines broke the conversation in a way that looked
     * like the model's fault. It had told a customer "заявка № 261015-0064";
     * on the next turn it read its own words back as "№ [number]-0064", saw
     * the full number in the record, and announced to the customer that it
     * had named the order wrongly and was correcting itself. What the bot said
     * came from the host's own data, read through the rules that decide what
     * the bot may show; hiding it from the model hides nothing from anybody.
     */
    private function textOf(Message $message): string
    {
        $body = (string) $message->body;

        return $message->author === MessageAuthor::Visitor
            ? $this->redactor->clean($body)
            : trim($body);
    }

    public function summarisedUntil(Conversation $conversation): int
    {
        return (int) ($this->meta($conversation)[self::SUMMARISED_KEY] ?? 0);
    }

    /**
     * Draws the line: the window starts after whatever has been said so far.
     *
     * The summary is left in place, and that is the decision. A customer who
     * comes back a month after their trip was booked is the same customer —
     * forgetting that they fly from Donetsk with two children would make the
     * second conversation start from nothing — but the *request* is finished,
     * and a window still holding it has the model confirming dates nobody is
     * asking about any more.
     */
    public function closeWindow(Conversation $conversation): void
    {
        $last = (int) Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->max('id');

        $this->write($conversation, [self::WINDOW_KEY => $last, self::SUMMARISED_KEY => $last]);
    }

    /**
     * Forgets the lot — the summary and everything before now.
     *
     * For "забудьте, что я говорил", and for a thread being handed to somebody
     * else's customer: a relinked conversation whose summary still describes
     * the previous person is worse than one that knows nothing.
     */
    public function forget(Conversation $conversation): void
    {
        $this->closeWindow($conversation);
        $this->write($conversation, [self::SUMMARY_KEY => null, self::SUMMARISED_KEY => null]);
    }

    private function windowFrom(Conversation $conversation): int
    {
        return (int) ($this->meta($conversation)[self::WINDOW_KEY] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(Conversation $conversation): array
    {
        return (array) $conversation->meta;
    }

    /**
     * Merged into `meta` rather than replacing it, because that column also
     * carries the marks that hide a rehearsal from every query in the package:
     * a thread that stopped being hidden would put a test conversation on
     * somebody's desk.
     *
     * @param  array<string, mixed>  $values
     */
    private function write(Conversation $conversation, array $values): void
    {
        $meta = $this->meta($conversation);

        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($meta[$key]);

                continue;
            }

            $meta[$key] = $value;
        }

        $conversation->forceFill(['meta' => $meta])->save();
    }
}
