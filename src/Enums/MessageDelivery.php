<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Enums;

/**
 * How far one message has got towards the person it was written for.
 *
 * Three stages and a failure, rather than the single boolean the row used to be
 * read as. "Delivered or not" was answered from `delivered_at` alone, and on the
 * site channel that column says nothing about the visitor at all: it is stamped
 * by a queued job, so a message the visitor had already read still said
 * "sending…" whenever nobody was running a worker — which is exactly what
 * happened, with thirty-seven jobs waiting and an operator watching a clock that
 * would never turn into anything.
 *
 * The three stages are ordered and each is a fact about the conversation rather
 * than about our own machinery: the row exists, the other side fetched it, the
 * other side said they had read it. `Failed` is deliberately not the fourth step
 * of that ladder — a message that failed has no stage, it has a reason, and the
 * reason is what belongs on screen.
 */
enum MessageDelivery: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /**
     * What a screen reader is told, because the tick itself says nothing.
     *
     * One tick and two ticks are a convention people read at a glance and a
     * blank to everybody else: rendered as a bare character with no name, the
     * whole state is invisible to the reader who most needs it spelled out.
     */
    public function label(): string
    {
        return __('chatbot::panel.inbox.receipt.'.$this->value);
    }
}
