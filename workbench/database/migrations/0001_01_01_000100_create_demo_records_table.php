<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns exist to be shapes, not business fields.
 *
 * A data resource is a statement about columns, so proving one needs a column of
 * each kind: something to own a row by (`owner_id`, which an access scope
 * narrows on), something to filter and sort by (`status`, `created_at`), and
 * something that must never leave the application however it is asked for
 * (`secret_code`). Without a genuinely sensitive column in the schema there is
 * no way to demonstrate that a sensitive column stays put.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('status', 32)->default('open');
            $table->string('secret_code', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_records');
    }
};
