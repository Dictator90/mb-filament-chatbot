<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A widget is one placement of a bot on one set of sites.
 *
 * Separate from the bot because the two answer different questions and change
 * for different reasons. A bot is a personality: what it knows, which scenarios
 * it walks, which model thinks for it. A widget is a deployment: which sites may
 * embed it, what colour it is, what the launcher says. The same support bot
 * belongs on the main site in the company's blue and on the partner portal in
 * the partner's, with different origins allowed for each — and that is one bot
 * and two widgets, which the previous shape could not express at all.
 *
 * It also fixes what the embed code says. The tag used to carry `data-bot`, so a
 * bot's own name travelled onto a customer's public page and could not be
 * changed without editing that page. It now carries an opaque widget key, and
 * which bot answers behind it is a setting on this side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_widgets', function (Blueprint $table): void {
            $table->id();

            /*
             * The key is what a customer's page carries. Unique across the
             * installation and never reused: a key that came back pointed a
             * stranger's page at a bot they were never given.
             */
            $table->string('key', 64)->unique();
            $table->string('name', 191);

            /*
             * Nullable, and that is a real state rather than an unfinished one:
             * a widget with no bot hands every conversation straight to a person.
             * Small installations run that way on purpose, and it is what a
             * widget falls back to when its bot is switched off.
             */
            $table->foreignId('bot_id')->nullable()->constrained('chatbot_bots')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            /*
             * Empty allows nobody, exactly as the installation-wide list does.
             * A widget is a thing you hand to somebody else's website, and the
             * safe default for that is "no website yet".
             */
            $table->json('allowed_origins')->nullable();

            $table->json('theme')->nullable();
            $table->json('labels')->nullable();

            /*
             * What this placement lets a visitor do, as opposed to what it looks
             * like. Kept out of `theme` because the two are read by different
             * things for different reasons: every value in `theme` is sanitised
             * into a CSS custom property and printed into a `style` attribute on
             * somebody else's page, so a flag landing there is either passed
             * through as a colour or dropped by the vocabulary check. And "this
             * widget accepts files from strangers" is not a matter of appearance
             * — filed under the theme it would live behind the tab people open to
             * change colours, which is the last place anybody would look for it.
             *
             * Null means the shipped answers: emoji on, attachments off.
             */
            $table->json('features')->nullable();

            $table->text('greeting')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'bot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_widgets');
    }
};
