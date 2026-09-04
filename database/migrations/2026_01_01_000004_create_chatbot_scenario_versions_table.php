<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A frozen snapshot of a scenario that was once live.
 *
 * Without versions every edit reaches customers the moment it is saved: an
 * author rewording one reply also changes the conversation running right now,
 * and renaming a node drops a visitor mid-dialogue back to scenario selection,
 * so they receive a greeting instead of an answer. There is no way back
 * either — "put it as it was" means rebuilding the graph from memory.
 *
 * The snapshot is stored whole rather than as a diff against the previous
 * version. A diff can only be read together with its entire ancestry, and the
 * first pruned or lost version makes every later one unreadable. A rollback has
 * to work from a single row, otherwise it is a reconstruction and not a
 * rollback.
 *
 * Rows are written once and never edited: editing a published version would be
 * rewriting the history of what the bot actually said to people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_scenario_versions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('scenario_id')->constrained('chatbot_scenarios')->cascadeOnDelete();

            /*
             * The human number, restarting inside each scenario. This is what
             * a version is called out loud ("roll back to three"), and a
             * global id would not do: one scenario's versions would run
             * 4, 17, 39. The pair is unique because two rows sharing a number
             * make "version three" stop pointing at one row, and rollback
             * becomes a matter of luck.
             */
            $table->integer('number');
            $table->unique(['scenario_id', 'number']);

            /* Nodes and transitions exactly as the editor hands them over. */
            $table->json('graph');

            /*
             * Frozen dependencies: the data-resource keys, host action names
             * and sub-scenario slugs this version relies on.
             *
             * Without pins a released version silently changes behaviour when
             * the host edits a resource or renames an action — the graph is
             * unchanged, the bot answers differently, and nothing in the
             * history says why. With pins the divergence is visible: what the
             * version was published against is written down beside it, and can
             * be compared with what the application offers today.
             */
            $table->json('pins')->nullable();

            /*
             * Digest of the snapshot, answering "has the draft diverged from
             * what is live?". Indexed because that question is asked on every
             * open of the editor, for every scenario in the list.
             */
            $table->string('fingerprint')->index();

            $table->string('note')->nullable();

            $table->timestamp('published_at')->nullable();

            /*
             * Who published it. A plain indexed column and not a foreign key,
             * because the users table belongs to the host: its name, its key
             * type and whether it exists at all are the host's business, and a
             * package that constrains it cannot be installed beside an
             * application that keeps operators somewhere else.
             */
            $table->unsignedBigInteger('published_by_id')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_scenario_versions');
    }
};
