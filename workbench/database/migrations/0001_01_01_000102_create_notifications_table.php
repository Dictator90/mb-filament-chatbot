<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's own notifications table, which the workbench needs and a host
 * usually already has.
 *
 * The bell that says a conversation is waiting for a person is a Filament
 * database notification, and those are rows here. Without the table the package
 * still works — the bell swallows the failure rather than costing a customer
 * their message — and that is precisely why the table has to exist in the
 * workbench: the silent fallback would make a broken bell look exactly like a
 * quiet one, and every test about who hears it would pass by not ringing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
