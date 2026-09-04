<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canned replies an operator inserts by hand while typing.
 *
 * An empty user_id means the reply belongs to the whole desk; a filled one makes
 * it that operator's own. Both live in one table because the picker shows them
 * together, and splitting them would mean two queries and two screens to say the
 * same thing.
 *
 * user_id is indexed but carries no foreign key on purpose. The package does not
 * know which table the host authenticates against — it may be users, staff, or a
 * model behind a custom guard — and a constraint pointing at a guessed table
 * fails the migration in exactly the installations the package was meant to fit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_quick_replies', function (Blueprint $table): void {
            $table->id();

            $table->string('title');
            $table->text('body');
            $table->string('shortcut', 64)->nullable();

            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->integer('sort')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_quick_replies');
    }
};
