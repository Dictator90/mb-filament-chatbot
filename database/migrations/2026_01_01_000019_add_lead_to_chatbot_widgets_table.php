<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one placement asks for before the conversation starts.
 *
 * Nullable, and null means "ask nothing". A form in front of a chat box costs
 * conversations, so a widget installed before this column existed must not
 * acquire one on the day it is migrated — and an installation that wants one
 * turns it on having decided to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_widgets', function (Blueprint $table): void {
            $table->json('lead')->nullable()->after('cookies');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_widgets', function (Blueprint $table): void {
            $table->dropColumn('lead');
        });
    }
};
