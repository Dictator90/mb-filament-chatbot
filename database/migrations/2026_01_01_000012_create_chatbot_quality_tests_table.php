<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rehearsals for a bot: a few lines a person might say, and what must come back.
 *
 * A scenario is edited by whoever answers the phone, not by whoever wrote the
 * package, and an edit that looks harmless — a reworded branch, a renamed
 * variable, a step moved one place up — breaks a path nobody walks until a
 * customer walks it. These rows are that walk, run on demand and before release.
 *
 * The scenario is kept twice, by id and by slug. The id is the live link and goes
 * null when the scenario is deleted; the slug survives, so a run can still say
 * which scenario went missing instead of reporting a test with no subject.
 *
 * The last run is stored on the row rather than in a history table: what a person
 * opening the list needs is "is this red now", and a full history would grow
 * without bound for an answer nobody reads. last_fingerprint holds a digest of
 * the scenario the outcome was produced against, so a green result recorded
 * against a since-edited scenario can be shown as stale rather than as proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_quality_tests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bot_id')->nullable()->constrained('chatbot_bots')->nullOnDelete();

            $table->string('name');

            $table->foreignId('scenario_id')->nullable()->constrained('chatbot_scenarios')->nullOnDelete();
            $table->string('scenario_slug', 191)->nullable();

            $table->string('channel', 64)->nullable();

            /*
             * Whatever the run has to pretend about the conversation: whether the
             * person is recognised, whether they have written before, what a data
             * resource is to answer with. Json rather than a column per fact, and
             * that is the whole point — the facts belong to the host. A column
             * named has_bookings would put one travel agency inside a package that
             * a help desk and a clinic install for entirely different questions.
             */
            $table->json('context')->nullable();

            $table->json('said');
            $table->json('expectations');

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_run_at')->nullable();
            $table->string('last_outcome', 32)->nullable();
            $table->json('last_mismatches')->nullable();
            $table->json('last_transcript')->nullable();
            $table->string('last_fingerprint', 64)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_quality_tests');
    }
};
