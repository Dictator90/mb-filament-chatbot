<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use MB\FilamentChatbot\Demo\DemoData;
use Workbench\App\Models\User;

/**
 * Enough of an application to sign in and hold a conversation.
 *
 * The scenarios are not written here. They come from the package's own demo
 * seeder — the same rows a host gets from `chatbot:demo-data` — because two
 * copies of a demo drift apart within a week, and the one that drifts is always
 * the one shipped to hosts rather than the one the developer looks at every day.
 * Kept here as well, a node kind would end up exercised in the workbench and
 * broken on install with nothing to notice it.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'workbench@example.test'],
            [
                'name' => 'Workbench Operator',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        app(DemoData::class)->seed();
    }
}
