<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one bot has spent on models in one calendar month.
 *
 * One row per bot per month rather than one row per call. The question this
 * table exists to answer is asked before every request to a provider — "is
 * there anything left" — and a ledger of calls would have to be summed to
 * answer it, on the hot path, growing all month. Per-call detail already lives
 * in the run journal, which is where somebody goes to find out what a single
 * conversation did.
 *
 * bot_id is nullable because the installation itself has a budget: a call made
 * outside any bot — a knowledge base being re-indexed, a scenario running with
 * no bot attached — still costs money, and a row it could not be written to
 * would make that spending invisible.
 *
 * Every counter is an unsigned integer. Cost is in micro-minor units, the same
 * unit the budgets on chatbot_bots use, for the same reason: pricing is quoted
 * per million tokens, one reply costs a fraction of a cent, and a float column
 * would accumulate rounding error across a month of reservations until this
 * table and the provider's invoice stopped agreeing.
 *
 * The unique index is what makes reserving safe. Two conversations arriving
 * together must contend for one row rather than create one each and both
 * believe the balance is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_budget_periods', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bot_id')->nullable()->constrained('chatbot_bots')->cascadeOnDelete();

            /*
             * 'YYYY-MM' as a string, not a date. The period is a label the
             * application agrees on, and storing it as a date would invite a
             * timezone conversion on read: the month a call belongs to would
             * then depend on which server answered, and the first of the month
             * would be billed twice.
             */
            $table->string('period', 7);

            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_micro')->default(0);
            $table->unsignedInteger('calls')->default(0);

            $table->timestamps();

            $table->unique(['bot_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_budget_periods');
    }
};
