<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How this account's updates get here, and how far they have been read.
 *
 * A separate migration rather than an edit of the create: installations are
 * already running with connections in them, and a changed create-migration
 * never reaches a database that has been migrated once — the schema would
 * diverge silently, which is the one failure nobody notices until a column is
 * missing in production.
 *
 * `delivery` defaults to the webhook because that is what every existing
 * connection does, and a default of anything else would quietly stop delivery
 * for all of them on upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_channel_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('chatbot_channel_connections', 'delivery')) {
                $table->string('delivery', 16)->default('webhook')->after('is_active');
            }

            /*
             * Written by the poller, never by the form. It is a position in
             * somebody else's queue — Telegram's `update_id`, the next cursor
             * another provider hands back — so it is text rather than a number:
             * the one thing it must survive is a provider that numbers its
             * updates differently.
             */
            if (! Schema::hasColumn('chatbot_channel_connections', 'poll_cursor')) {
                $table->string('poll_cursor', 64)->nullable()->after('last_event_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_channel_connections', function (Blueprint $table): void {
            if (Schema::hasColumn('chatbot_channel_connections', 'poll_cursor')) {
                $table->dropColumn('poll_cursor');
            }

            if (Schema::hasColumn('chatbot_channel_connections', 'delivery')) {
                $table->dropColumn('delivery');
            }
        });
    }
};
