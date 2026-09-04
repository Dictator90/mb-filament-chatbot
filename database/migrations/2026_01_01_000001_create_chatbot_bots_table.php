<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_bots', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug', 191)->unique();
            $table->boolean('is_active')->default(true);

            $table->text('prompt')->nullable();

            /*
             * Empty means "use the application defaults" rather than "no AI".
             * Storing the resolved driver and model on every bot would freeze
             * each one to whatever provider was configured on the day it was
             * created: rotating the whole installation to a new model would
             * then require touching every row instead of one config value.
             */
            $table->string('ai_driver', 64)->nullable();
            $table->string('ai_model', 191)->nullable();

            $table->text('greeting')->nullable();
            $table->string('avatar')->nullable();

            $table->time('hours_from')->nullable();
            $table->time('hours_till')->nullable();

            /*
             * Which bot answers the site widget when the embed code does not
             * name one. A flag on the bot rather than a config key, because
             * the choice is data a panel user changes, and a config key would
             * mean a deploy every time the agency swaps its public bot.
             */
            $table->boolean('is_public_widget')->default(false);
            $table->json('allowed_origins')->nullable();

            /*
             * Budgets are whole numbers of micro-minor units — millionths of a
             * cent, of a kopeck, of whatever the installation bills in. Model
             * pricing is quoted per million tokens, so a single reply costs a
             * fraction of a cent, and a float column would accumulate rounding
             * error across thousands of reservations until the ledger and the
             * provider's invoice no longer agreed. Integers cannot drift.
             */
            $table->unsignedBigInteger('monthly_token_budget')->nullable();
            $table->unsignedBigInteger('monthly_cost_budget')->nullable();

            $table->json('settings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_bots');
    }
};
