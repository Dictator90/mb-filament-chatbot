<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_messages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('conversation_id')->constrained('chatbot_conversations')->cascadeOnDelete();
            $table->foreignId('reply_to_id')->nullable()->constrained('chatbot_messages')->nullOnDelete();

            $table->string('direction', 16);

            /*
             * The author is stored next to the direction, not derived from it.
             * Everything the bot says and everything an operator types is
             * outgoing, yet the two are different facts: reports count them
             * apart, and the rule that silences the bot once a human has
             * answered has nothing else to read.
             */
            $table->string('author', 32)->default('visitor');

            /* No foreign key: see the note on `assigned_to_id` in the conversations table. */
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->text('body')->nullable();

            /*
             * The provider's own message id, unique within a conversation.
             * Webhooks retry: a delivery that timed out on our side arrives a
             * second time with the same id, and without this index the visitor
             * would see their message twice and the bot would answer it twice.
             */
            $table->string('external_id', 191)->nullable();
            $table->unique(['conversation_id', 'external_id']);

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_messages');
    }
};
