<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A child row that knows its parent and nothing about who owns it.
 *
 * That absence is the whole point of the table. Every normalised schema has one:
 * a booking belongs to a customer through its order, an invoice line through its
 * invoice, and neither carries a customer column. Without a table shaped like
 * this there is nowhere to prove that an access scope can reach through a
 * relation — and the day that stopped working, the resource would either become
 * unreachable or, far worse, be redeclared unscoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('demo_record_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_lines');
    }
};
