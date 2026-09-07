<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a placement lets a visitor do, as opposed to what it looks like.
 *
 * Kept out of `theme` because the two are read by different things for different
 * reasons: every value in `theme` is sanitised into a CSS custom property and
 * printed into a `style` attribute on somebody else's page, so a boolean landing
 * there is either passed through as a colour or dropped by the vocabulary check.
 * And "this widget accepts files from strangers" is not a matter of appearance —
 * filed under the theme it would sit behind the tab people open to change
 * colours, which is the last place anybody would look for it.
 *
 * Null means the shipped answers: emoji on, attachments off.
 *
 * **A migration of its own rather than another line in the create migration**,
 * and that is the lesson rather than a preference. The column was first added by
 * editing `create_chatbot_widgets_table`, which is fine for an installation that
 * does not exist yet and useless for one that does: the create migration had
 * already run, so nothing added the column, and the first attempt to save a
 * widget answered "no such column: features" from inside a query nobody could
 * connect to a schema change. The package is unreleased, but it is installed —
 * and one installation is enough for the rule to apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_widgets', function (Blueprint $table): void {
            $table->json('features')->nullable()->after('labels');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_widgets', function (Blueprint $table): void {
            $table->dropColumn('features');
        });
    }
};
