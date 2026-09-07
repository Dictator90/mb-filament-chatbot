<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file somebody put into a conversation.
 *
 * A table of its own rather than a key in the message's `meta` bag, and the
 * reason is the download route. A file has to be addressable on its own — the
 * URL names one file, and the request has to be answered by asking "does this
 * belong to the conversation the caller owns". Held inside a JSON column that
 * question becomes a scan with no index behind it, and the answer has to be
 * reassembled from a message that may itself have been deleted. A row makes the
 * check one indexed comparison, and the foreign keys make a deleted conversation
 * take its files' records with it.
 *
 * It also stops the file list from riding along in `meta`, which is the bot's own
 * bookkeeping and is written by every node that runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_attachments', function (Blueprint $table): void {
            $table->id();

            /*
             * What the URL carries. Random rather than the primary key, because
             * the address of a file people attach passports to should not be
             * reachable by adding one to the last address somebody saw. It is not
             * the protection — the route checks who is asking — but a guessable
             * id turns any future slip in that check into an enumerable archive.
             */
            $table->uuid('uuid')->unique();

            $table->foreignId('message_id')->constrained('chatbot_messages')->cascadeOnDelete();

            /*
             * Denormalised on purpose, and it is the column authorisation reads.
             *
             * The alternative is walking `$attachment->message->conversation_id`,
             * which is one more query on the hot path and — worse — answers null
             * for a message that is gone. A comparison against null is a
             * comparison that passes by accident in exactly one direction.
             */
            $table->foreignId('conversation_id')->constrained('chatbot_conversations')->cascadeOnDelete();

            $table->string('disk', 64);
            $table->string('path', 2048);

            /*
             * The name as it was sent, kept for display only. Nothing on disk is
             * ever named from it: it arrives from the visitor's machine, it can
             * be `../../.env` or a thousand characters of Unicode, and a store
             * that used it would be storing a path chosen by a stranger.
             */
            $table->string('name', 255);

            /*
             * The type detected from the bytes, never the one the browser
             * announced. It is what the download route sends back as
             * Content-Type, so a stored client header would let an uploader
             * choose how their own file is interpreted by everybody else's
             * browser.
             */
            $table->string('mime', 191);

            $table->unsignedBigInteger('size');

            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_attachments');
    }
};
