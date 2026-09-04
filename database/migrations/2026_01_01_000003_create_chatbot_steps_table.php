<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One node of a scenario.
 *
 * Transitions are recorded as step keys, never as ids or positions. Steps get
 * reordered and deleted from the middle of a scenario all the time, and a link
 * by position would send the conversation somewhere else after the first drag
 * on the canvas. A key that no longer resolves ends the scenario instead of
 * throwing: scenarios are edited by hand from a panel, and a typo in one
 * transition must not leave every visitor without an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_steps', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('scenario_id')->constrained('chatbot_scenarios')->cascadeOnDelete();

            /*
             * The key is the node's name inside its own scenario — what every
             * transition points at. Uniqueness is per scenario and not global:
             * two scenarios each having an "ask-budget" node is normal, while
             * two of them in one scenario would make every arrow pointing
             * there a coin toss.
             */
            $table->string('key');
            $table->unique(['scenario_id', 'key']);

            $table->integer('sort')->default(0);

            /* Canvas coordinates. Layout is information: the author arranges a
             * scenario the way they think about it, and a graph re-laid out on
             * every open reads as a different scenario each time. */
            $table->integer('pos_x')->default(0);
            $table->integer('pos_y')->default(0);

            /*
             * The kind of step, as a plain string on purpose.
             *
             * Kinds come from the registry, and a host adds its own — a travel
             * agency's "pick one of your bookings", a help desk's "page the
             * on-call engineer". The package cannot know their names, so no
             * enum can be complete.
             *
             * Even for the built-in kinds a string is the right column type,
             * because Eloquent casts an enum through from() and not
             * tryFrom(): the first unfamiliar value would throw on read as
             * well as on write, and the whole scenario — every other node
             * included — would become unopenable. As a string, an unknown kind
             * draws as a defect on the canvas and everything around it still
             * loads, which is the difference between "one node needs
             * attention" and "the editor is broken".
             */
            $table->string('type')->index();

            $table->text('body')->nullable();

            /*
             * Branching options: [{label, next, words[], when{}}]. One shape
             * for every branching kind — a question's buttons, a keyword
             * split's word lists, a condition's tests — because they differ
             * only in which field the runtime reads, and a second storage
             * shape beside this one would drift from it on the first change.
             */
            $table->json('options')->nullable();

            /*
             * Per-kind settings, one bag rather than a column per kind. A
             * dozen kinds with three or four fields each is thirty-odd columns
             * of which any row fills three: that is not a schema, it is a list
             * of every setting that has ever existed. What every kind shares —
             * text, transitions, coordinates — stays in columns above.
             */
            $table->json('config')->nullable();

            $table->string('next_key')->nullable();

            /* Where the visitor's answer is stored, when the step collects
             * one. Left as free text: the set of fields belongs to the host,
             * not to the package. */
            $table->string('collect_into')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_steps');
    }
};
