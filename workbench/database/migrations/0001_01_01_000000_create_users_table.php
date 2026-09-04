<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workbench application's own users table.
 *
 * Owned here rather than borrowed from Testbench's skeleton, because the
 * workbench is the stand-in for a host application and a host application owns
 * its users. It also keeps the package honest about a boundary that matters:
 * nothing in the package may add a foreign key to this table, since a real host
 * may name it anything, put it on another connection, or not have one at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
