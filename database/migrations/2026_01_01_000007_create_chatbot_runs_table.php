<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One pass of the bot through a conversation: from entering a scenario to
 * handing over, closing, or breaking off.
 *
 * A run outlives a scenario change. The "jump to scenario" step does not open a
 * second run, it keeps writing the same trace — which is why every trace step
 * carries its own scenario slug. Splitting on every jump would cut the one
 * thing the journal exists for, the path a person actually walked, into
 * fragments nobody can reassemble.
 *
 * The counters are denormalised on purpose: the journal list shows "how long
 * did it talk" for hundreds of rows at a time, and counting trace steps per row
 * is the difference between one query and one query per run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_runs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('conversation_id')->constrained('chatbot_conversations')->cascadeOnDelete();

            /*
             * Scenario and version go null when they are deleted, and the run
             * stays. A run records what the bot did, not which rows still
             * exist; deleting a retired scenario must not erase the evidence
             * of the conversations it held. The slug beside them survives the
             * deletion and is what the journal actually displays.
             */
            $table->foreignId('scenario_id')->nullable()->constrained('chatbot_scenarios')->nullOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('chatbot_scenario_versions')->nullOnDelete();
            $table->string('scenario_slug')->nullable();

            $table->string('channel')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            /*
             * Indexed because it is the only column anyone filters by: the
             * whole point of the journal is finding the runs that ended in a
             * dead end or hit the step limit.
             */
            $table->string('outcome')->nullable()->index();

            $table->integer('steps_count')->default(0);
            $table->integer('said_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_runs');
    }
};
