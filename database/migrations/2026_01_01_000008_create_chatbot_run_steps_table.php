<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One node a conversation genuinely passed through.
 *
 * The trace is what makes a scenario debuggable at all. A transcript shows what
 * the bot said; only the trace shows which branch it took to say it, and those
 * two answers diverge exactly where the scenario is wrong.
 *
 * Every descriptive column here is a copy, not a reference: the step key, its
 * kind and the scenario slug are written down as they were at the time. A join
 * to the live scenario would answer a different question — what the graph looks
 * like now — and would go empty the moment a node is renamed, which is usually
 * the very edit being investigated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_run_steps', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('run_id')->constrained('chatbot_runs')->cascadeOnDelete();

            /*
             * Position within the run. Its own column rather than a reliance
             * on id order: ids are global and a run's rows may be written by
             * more than one process — a delayed step resuming from a queue
             * lands after rows from a different conversation.
             */
            $table->integer('seq');

            $table->string('step_key')->nullable();
            $table->string('step_type')->nullable();
            $table->string('scenario_slug')->nullable();

            /* What the bot said at this node, if anything. */
            $table->text('said')->nullable();

            /*
             * How this node ended. A string with a dictionary of constants on
             * the model rather than an enum column: there are two dozen
             * outcomes, nearly all of them meaning "this branch of the engine
             * fired", and an unfamiliar one has to be readable rather than
             * fatal — an old row must not stop the journal from opening after
             * a branch is renamed or removed.
             */
            $table->string('outcome');

            /* Whatever the branch needs to explain itself: the answer that did
             * not match, the query that returned nothing, the reason the model
             * declined. Free-form because each branch owes a different
             * explanation. */
            $table->json('payload')->nullable();

            $table->timestamp('occurred_at');

            /*
             * The trace is always read whole and in order, for one run. The
             * pair index is that read; an index on run_id alone would still
             * leave a sort behind it.
             */
            $table->index(['run_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_run_steps');
    }
};
