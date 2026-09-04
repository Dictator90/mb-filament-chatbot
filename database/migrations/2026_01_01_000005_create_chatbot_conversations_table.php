<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns that take part in an index are given explicit lengths.
     *
     * Laravel's default string is 255 characters, and a two-column unique index
     * over two of them is 2040 bytes under utf8mb4 — over the limit on any MySQL
     * still using the COMPACT row format, and the migration fails on the host's
     * server rather than on ours. The bounds below are generous for what the
     * columns actually hold: a channel key, a provider's message id, a hex
     * digest.
     */
    public function up(): void
    {
        Schema::create('chatbot_conversations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bot_id')->nullable()->constrained('chatbot_bots')->nullOnDelete();

            /*
             * A plain string, not an enum. Channels are registered by the host
             * through drivers — a helpdesk adds WhatsApp, an agency adds its own
             * messenger — and a closed list would make anything the package did
             * not ship impossible to add without forking the package.
             */
            $table->string('channel', 64)->index();
            $table->string('external_id', 191)->nullable();
            $table->unique(['channel', 'external_id']);

            /*
             * Who this person is, and what the conversation is about, in the
             * host's own tables. The package writes both pairs and hands them
             * back, but never resolves them: it has no idea whether the host
             * calls the record a client, a patient or a ticket, and the moment
             * it assumed one it would stop installing into the others. No
             * foreign key for the same reason — the target table is unknown,
             * and may not even live in this database.
             */
            $table->string('contact_type')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->index(['contact_type', 'contact_id']);

            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->index(['subject_type', 'subject_id']);

            /*
             * The staff member answering. Deliberately without a foreign key:
             * the users table is named by the host and configured through
             * `chatbot.user_model`, so there is nothing fixed to point at.
             */
            $table->unsignedBigInteger('assigned_to_id')->nullable()->index();

            $table->string('status', 32)->default('open')->index();

            $table->string('display_name')->nullable();
            $table->string('username', 191)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->string('entry_page')->nullable();

            /*
             * A sha256 fingerprint of the visitor's widget token, never the
             * token itself. The column is looked up on every widget request, so
             * a leaked database backup would otherwise hand the reader a set of
             * live credentials to resume other people's conversations with.
             */
            $table->string('visitor_key', 64)->nullable()->index();

            $table->timestamp('identified_at')->nullable();

            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_incoming_at')->nullable();
            $table->timestamp('agent_read_at')->nullable();
            $table->timestamp('visitor_read_at')->nullable();

            $table->string('bot_state', 32)->default('off');
            $table->foreignId('bot_scenario_id')->nullable()->constrained('chatbot_scenarios')->nullOnDelete();
            $table->foreignId('bot_version_id')->nullable()->constrained('chatbot_scenario_versions')->nullOnDelete();

            /*
             * The current run, without a foreign key on purpose. `chatbot_runs`
             * is created two migrations later, and SQLite cannot add a foreign
             * key to a table that already exists — the constraint would have to
             * be bolted on by rebuilding this table, which is exactly the kind
             * of migration that works on the developer's MySQL and destroys the
             * conversation history on a host running SQLite.
             */
            $table->unsignedBigInteger('bot_run_id')->nullable()->index();

            $table->string('bot_step_key', 191)->nullable();
            $table->string('bot_slot', 191)->nullable();
            $table->json('bot_slot_attempts')->nullable();
            $table->json('bot_answers')->nullable();

            /*
             * The call stack of nested scenarios. A `call` node enters another
             * scenario and comes back with a value, unlike `jump`, which simply
             * leaves; without a stack there is nowhere to come back to, and a
             * reusable "collect a phone number" scenario could only ever be the
             * last thing a conversation did.
             */
            $table->json('bot_stack')->nullable();

            $table->timestamp('bot_handed_off_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['status', 'last_message_at']);
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_conversations');
    }
};
