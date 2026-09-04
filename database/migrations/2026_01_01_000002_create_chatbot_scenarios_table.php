<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scenario: one self-contained conversation the bot knows how to have.
 *
 * A scenario owns only what belongs to the whole of it — its name, its key,
 * whether it is switched on and in which order it is tried. What starts it and
 * whom it is meant for lives in the "start" node instead. While the trigger was
 * a column here, the diagram began somewhere above itself: looking at the graph
 * you could not tell what a visitor has to say for the bot to speak at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_scenarios', function (Blueprint $table): void {
            $table->id();

            /*
             * A null bot means "available to every bot". A host that runs one
             * bot never fills this in, and requiring it would make the common
             * installation carry a foreign key it has no opinion about.
             *
             * nullOnDelete rather than cascade: deleting a bot must not delete
             * the conversation designs written for it, which are the expensive
             * part and are routinely re-pointed at a replacement bot.
             */
            $table->foreignId('bot_id')->nullable()->constrained('chatbot_bots')->nullOnDelete();

            $table->string('name');

            /*
             * The slug is how one scenario names another: the "jump to
             * scenario" step stores a slug, never an id, so that an export
             * from staging still resolves after import.
             *
             * Uniqueness is per bot because two bots are two separate
             * vocabularies — a support bot and a sales bot may both own a
             * "greeting". Note that SQL treats NULLs as distinct, so this pair
             * does not constrain scenarios shared by every bot; those are
             * few, authored by hand, and the runtime resolves a slug clash by
             * priority rather than refusing to boot.
             */
            $table->string('slug');
            $table->unique(['bot_id', 'slug']);

            $table->boolean('is_active')->default(true);

            /*
             * Resolution order when several scenarios match the same opening
             * line. Without it the bot would be non-deterministic: "hello"
             * fits the greeting for new visitors, the greeting for returning
             * ones, and the catch-all fallback equally well.
             */
            $table->integer('priority')->default(0);

            $table->text('description')->nullable();

            /*
             * The invocation contract: {purpose, useWhen, doNotUseWhen[],
             * examples[]}. A language model reads it to decide which scenario
             * an incoming message belongs to when AI routing is switched on.
             *
             * With AI switched off — the shipped default — routing falls back
             * to the keywords on the "start" node, so a scenario has to stay
             * meaningful without a model. This is not a degraded mode to be
             * fixed later: an unset key, a provider outage and an exhausted
             * budget all land here, and a scenario that only works with a
             * model is a scenario that stops working without warning.
             */
            $table->json('invocation')->nullable();

            /*
             * Editor canvas state: sticky notes, groups drawn around nodes and
             * the pan/zoom position. Kept beside the graph rather than in the
             * browser, because the person who lays out a scenario is rarely
             * the person who reads it next.
             */
            $table->json('graph_notes')->nullable();
            $table->json('graph_groups')->nullable();
            $table->json('viewport')->nullable();

            /*
             * The version currently answering customers. Deliberately a plain
             * indexed column with no foreign key: chatbot_scenario_versions is
             * created by a later migration, and SQLite cannot add a foreign
             * key to an existing table — ALTER TABLE ... ADD CONSTRAINT simply
             * does not exist there. Declaring the constraint later would work
             * on MySQL and silently do nothing on SQLite, which is where the
             * package's own tests run. One honest column beats a constraint
             * that exists on only some installations.
             */
            $table->unsignedBigInteger('live_version_id')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_scenarios');
    }
};
