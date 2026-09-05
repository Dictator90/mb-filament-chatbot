<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Channels;

use MB\FilamentChatbot\Models\Message;

/**
 * Turns one recorded message into the text a particular channel should carry.
 *
 * Here rather than inside each driver, and that is the whole point of the
 * class. A channel that cannot draw buttons has to append the options to the
 * text or the question becomes unanswerable — and written per driver, the third
 * one to be added is the one that forgets, on a channel nobody on the team
 * uses, discovered by a customer who could not reply.
 *
 * The stored body is never changed. What goes out on a plain channel and what
 * the transcript says are two different questions: an operator reading the
 * thread afterwards should see the sentence the bot composed, not a rendering
 * artefact of whichever channel it happened to leave through.
 */
final class ChannelText
{
    /**
     * The options offered with this message, if any.
     *
     * Read from `meta.options`, accepting both a plain list of labels and a
     * list of `['label' => ...]` rows. Both shapes occur because the panel
     * writes quick replies as labels while a scenario node knows a label and
     * the exit it belongs to, and normalising at the one place that reads them
     * is cheaper than making every writer agree.
     *
     * @return list<string>
     */
    public static function options(Message $message): array
    {
        $raw = (array) $message->meta;
        $options = $raw['options'] ?? null;

        if (! is_array($options)) {
            return [];
        }

        $labels = [];

        foreach ($options as $option) {
            $label = is_array($option)
                ? trim((string) ($option['label'] ?? ''))
                : trim((string) $option);

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * The body as this driver should send it.
     *
     * A driver that draws buttons gets the body untouched and renders the
     * options itself; one that does not gets them numbered underneath. The
     * numbering starts at one and matches the order the options are stored in,
     * because that is the order the engine resolves a bare digit against — any
     * other numbering here would route the visitor to the option next to the
     * one they picked, which is worse than not offering them at all.
     */
    public static function forDriver(Message $message, ChannelDriver $driver): string
    {
        $body = (string) $message->body;

        if ($driver->supportsButtons()) {
            return $body;
        }

        return self::numbered($body, self::options($message));
    }

    /**
     * The line break is "\n" and never PHP_EOL.
     *
     * A newline inside a chat message belongs to the message, not to the machine
     * that composed it. PHP_EOL is CRLF on Windows, so one scenario would send
     * different bytes depending on where the queue worker happened to run — and
     * the carriage returns travel all the way into Telegram and into the widget's
     * own markup, showing up as stray blank lines with no visible cause.
     *
     * @param  list<string>  $options
     */
    public static function numbered(string $body, array $options): string
    {
        if ($options === []) {
            return $body;
        }

        $lines = [];

        foreach ($options as $position => $label) {
            $lines[] = ($position + 1).'. '.$label;
        }

        $body = trim($body);
        $list = implode("\n", $lines);

        return $body === '' ? $list : $body."\n\n".$list;
    }
}
