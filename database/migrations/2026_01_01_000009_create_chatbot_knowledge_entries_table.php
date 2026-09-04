<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Answers the bot is allowed to give without asking a model to invent one.
 *
 * The table stores a mode next to every entry because not every topic wants an
 * answer. Price, refund windows and house rules are the topics where a plausible
 * invention is worse than silence, and those entries exist precisely to stop the
 * bot from replying at all. An entry may also lead into a scenario instead of
 * answering, which is why scenario_slug sits here rather than in a second table:
 * "how do I check my booking" is one topic with one set of keywords whether it
 * ends in a paragraph of text or in a form.
 *
 * The scenario is addressed by slug, not by id. Knowledge bases get exported
 * from a staging installation and imported into a live one, where the same
 * scenario carries a different primary key; a slug survives that trip and an id
 * silently points at whatever scenario happens to occupy that row.
 *
 * A null bot_id marks an entry shared by every bot. Uniqueness of the pair is
 * enforced by the index only where bot_id is filled, since SQL treats nulls as
 * distinct from one another — duplicate shared topics are caught by the
 * application, not by the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_knowledge_entries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bot_id')->nullable()->constrained('chatbot_bots')->nullOnDelete();

            $table->string('topic_key', 191);
            $table->string('category')->nullable();

            $table->text('question');
            $table->text('answer')->nullable();

            $table->string('scenario_slug', 191)->nullable();

            $table->json('keywords')->nullable();
            $table->json('variants')->nullable();

            $table->string('mode', 32)->default('answer');
            $table->string('priority', 32)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['bot_id', 'topic_key']);
            $table->index(['is_active', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_knowledge_entries');
    }
};
