<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cookie names one placement of the bot is allowed to read off its page.
 *
 * A list rather than a switch, and that is the whole security of the feature.
 * The widget runs on somebody else's page and could read every cookie there is;
 * what it actually sends is the names written here, by the person who set the
 * placement up. Without the list the embed would be a general-purpose cookie
 * exfiltrator sitting on a customer's site, which is not a thing to ship on the
 * grounds that a scenario might find a marketing campaign useful.
 *
 * Nullable, and empty means "read nothing" rather than "read everything": a
 * widget installed before this column existed must not start sending cookies
 * the day it is migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_widgets', function (Blueprint $table): void {
            $table->json('cookies')->nullable()->after('features');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_widgets', function (Blueprint $table): void {
            $table->dropColumn('cookies');
        });
    }
};
