<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the nodes a rehearsal actually went through.
 *
 * The runner has always computed them — the `visits` expectation is checked
 * against exactly this list — and then dropped them on the floor. That was
 * tolerable while checks were run from a terminal, where the answer to "which
 * branch did it take" is another run with a debugger. It is not tolerable on a
 * screen: the path is the third thing a person needs when a check goes red,
 * read with the canvas open beside it, and reconstructing it by eye from the
 * transcript is exactly the work the check was supposed to save.
 *
 * A separate migration and not an edit to the create, for the reason the widget
 * features column already carries: the create has been installed, and editing
 * an installed migration stops being a change at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_quality_tests', function (Blueprint $table): void {
            $table->json('last_visited')->nullable()->after('last_transcript');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_quality_tests', function (Blueprint $table): void {
            $table->dropColumn('last_visited');
        });
    }
};
