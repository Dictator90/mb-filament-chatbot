<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use Workbench\App\Models\User;

/**
 * Enough of an application to open the panel and hold a conversation.
 *
 * The scenario seeded here is deliberately the smallest one that exercises the
 * parts most likely to be broken by a change: a keyword trigger, a question with
 * branches, a form that skips what it already knows, and a handover. A demo that
 * only said hello would stay green through most of the ways this package can
 * break.
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

        $bot = Bot::query()->firstOrCreate(
            ['slug' => 'workbench'],
            [
                'name' => 'Workbench Assistant',
                'is_active' => true,
                'is_public_widget' => true,
                'greeting' => 'Hello — what can I help with?',
            ],
        );

        $this->seedSupportScenario($bot);
    }

    protected function seedSupportScenario(Bot $bot): void
    {
        if (Scenario::query()->where('slug', 'support')->exists()) {
            return;
        }

        $scenario = Scenario::query()->create([
            'bot_id' => $bot->getKey(),
            'name' => 'Support',
            'slug' => 'support',
            'is_active' => true,
            'priority' => 10,
            'description' => 'Answers a question or fetches a person.',
            'invocation' => [
                'purpose' => 'Handle a support request.',
                'useWhen' => 'The visitor reports a problem or asks for help.',
                'doNotUseWhen' => ['The visitor is asking about pricing.'],
                'examples' => ['Something is broken', 'I need help with my account'],
            ],
        ]);

        $steps = [
            ['key' => 'start', 'type' => 'start', 'next_key' => 'route', 'config' => [
                'trigger' => 'fallback',
                'audience' => 'any',
            ]],
            ['key' => 'route', 'type' => 'ask', 'next_key' => null, 'body' => 'Is this about an existing order, or something else?', 'options' => [
                ['label' => 'An existing order', 'next' => 'details'],
                ['label' => 'Something else', 'next' => 'details'],
            ], 'config' => ['else_key' => 'details']],
            ['key' => 'details', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
                'slots' => ['name', 'email', 'request'],
                'attempts' => 2,
                'else_key' => 'human',
            ]],
            ['key' => 'thanks', 'type' => 'say', 'next_key' => 'human', 'body' => 'Thanks {{name}} — passing this to the team.'],
            ['key' => 'human', 'type' => 'handoff', 'body' => 'Someone will be with you shortly.'],
        ];

        foreach ($steps as $index => $step) {
            Step::query()->create($step + [
                'scenario_id' => $scenario->getKey(),
                'sort' => $index,
                'pos_x' => 80 + $index * 260,
                'pos_y' => 160,
            ]);
        }
    }
}
