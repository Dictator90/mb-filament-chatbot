<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One messenger account, wired to one bot.
 *
 * Credentials used to live in config, one set per installation, which answered
 * the wrong question. A token is not a property of the deployment; it is the
 * account a particular bot speaks from. An agency running a sales bot and a
 * support bot has two Telegram bots, and the config shape could express only
 * one — the second had to wait for a fork.
 *
 * Keyed by bot and channel together: a bot has at most one account per
 * messenger. Two accounts of the same messenger answering as the same bot
 * would make the reply address ambiguous in the one direction that matters,
 * outbound, where the row only says which channel the thread came in on.
 *
 * `public_id` is the unguessable segment of the webhook address, and it is what
 * makes per-bot routing possible at all: the provider's POST arrives with no
 * mention of which bot it belongs to, so the address has to carry it. Separate
 * from the id for the usual reason — a sequential id in a public URL tells a
 * stranger how many installations exist and lets them walk the neighbours.
 *
 * Secrets sit in `credentials` under application encryption rather than in
 * their own columns. They are read together, written together, and never
 * queried: no index would ever be built on a bot token, and a column per
 * provider would mean a migration every time a messenger adds a field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_channel_connections', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bot_id')->constrained('chatbot_bots')->cascadeOnDelete();

            /*
             * The driver key, the same string that lands in
             * `chatbot_conversations.channel`. A plain string rather than an
             * enum because a host may register a channel this package has never
             * heard of, and widening an enum would mean forking the package.
             */
            $table->string('channel', 32);

            $table->string('public_id', 32)->unique();

            /*
             * Off until somebody switches it on, even with a token already
             * saved. Setting up a messenger takes two sides — credentials here,
             * a webhook registered there — and the half-done state is normal
             * for minutes at a time. Answering customers from a half-configured
             * account is worse than not answering yet.
             */
            $table->boolean('is_active')->default(false);

            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();

            /*
             * When the provider last confirmed the credentials, and what it said
             * when it last refused them. Both are observations written by code,
             * which is why they are not in `settings`: the first save of the
             * form would wipe them.
             */
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_event_at')->nullable();

            $table->timestamps();

            $table->unique(['bot_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_channel_connections');
    }
};
