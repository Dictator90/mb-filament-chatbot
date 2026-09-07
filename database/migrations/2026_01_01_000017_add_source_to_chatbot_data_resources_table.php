<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a data resource point somewhere other than this application's models.
 *
 * A separate migration and not an edit to the create, for the reason the widget
 * features column already carries: one installation of a create migration is
 * enough for editing it to stop being a change at all. It runs once, and after
 * that the file says one thing while the database says another — on the
 * developer's machine the two agree, because that is the machine where
 * `migrate:fresh` is normal.
 *
 * `connection` and `source_table` describe the second kind of resource: a table
 * on a connection this application has configured but has no model for — a
 * reporting replica, a legacy database, another service's schema. `model`
 * becomes nullable because such a resource has none, and a resource has exactly
 * one of the two.
 *
 * **`source_table` and not `table`.** `$table` on an Eloquent model is the name
 * of the model's own table, so a column called `table` makes `$resource->table`
 * answer `chatbot_data_resources` for every row — and it does it silently, in
 * the one class whose job is deciding what may be read. Found by writing it the
 * obvious way first: every resource became a table-backed one pointing at this
 * package's own table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_data_resources', function (Blueprint $table): void {
            /*
             * Null means this application's default connection, which is what
             * every resource declared before today meant and goes on meaning.
             * Spelling the default out as a name would freeze it: an
             * installation that renames its connection would find every
             * resource pointing at one that no longer exists.
             */
            $table->string('connection')->nullable()->after('model');
            $table->string('source_table')->nullable()->after('connection');
        });

        /*
         * Widening the model column to nullable is done on its own, because a
         * change of nullability needs doctrine/dbal on some drivers and the
         * added columns above must land regardless.
         */
        Schema::table('chatbot_data_resources', function (Blueprint $table): void {
            $table->string('model')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_data_resources', function (Blueprint $table): void {
            $table->dropColumn(['connection', 'source_table']);
        });
    }
};
