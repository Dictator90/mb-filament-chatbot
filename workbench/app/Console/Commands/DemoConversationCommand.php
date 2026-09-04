<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use MB\FilamentChatbot\Bot\BotEngine;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\Run;

/**
 * Holds a scripted conversation and prints the transcript.
 *
 * Here because a green test suite and a working package are not the same claim.
 * The suite drives the engine through a test case; this drives it through the
 * application, with the real provider, the real registry and a real database —
 * which is where a missing registration or a mis-ordered boot actually shows up.
 *
 * It is also the fastest way to see what a change did to the bot's manners,
 * which no assertion is good at.
 */
class DemoConversationCommand extends Command
{
    protected $signature = 'chatbot:demo
                            {--bot=workbench : Slug of the bot to talk to}
                            {said?* : What the visitor says, in order}';

    protected $description = 'Walk a seeded scenario and print the transcript';

    public function handle(BotEngine $engine): int
    {
        $bot = Bot::query()->where('slug', $this->option('bot'))->first();

        if ($bot === null) {
            $this->error("No bot with slug [{$this->option('bot')}]. Run the workbench seeder first.");

            return self::FAILURE;
        }

        $lines = $this->argument('said') ?: [
            'my order is broken',
            'Something else',
            'Alex',
            'alex@example.test',
            'the widget stopped working',
        ];

        $conversation = Conversation::create([
            'bot_id' => $bot->getKey(),
            'channel' => 'site',
        ]);

        foreach ($lines as $said) {
            $message = Message::create([
                'conversation_id' => $conversation->getKey(),
                'direction' => MessageDirection::Incoming,
                'author' => MessageAuthor::Visitor,
                'body' => $said,
            ]);

            $engine->handle($conversation->refresh(), $message);
        }

        $this->transcript($conversation->refresh());
        $this->state($conversation);

        return self::SUCCESS;
    }

    protected function transcript(Conversation $conversation): void
    {
        $this->newLine();
        $this->line('<options=bold>Transcript</>');

        foreach ($conversation->messages()->orderBy('id')->get() as $message) {
            $incoming = $message->direction === MessageDirection::Incoming;

            $this->line(sprintf(
                '  %s %s',
                $incoming ? '<fg=cyan>visitor</>' : '<fg=green>bot    </>',
                (string) $message->body,
            ));
        }
    }

    protected function state(Conversation $conversation): void
    {
        $this->newLine();
        $this->line('<options=bold>State</>');
        $this->line('  bot state: '.$conversation->bot_state->value);
        $this->line('  collected: '.json_encode($conversation->bot_answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $run = Run::query()->where('conversation_id', $conversation->getKey())->latest('id')->first();

        if ($run === null) {
            $this->line('  no run recorded');

            return;
        }

        $this->newLine();
        $this->line('<options=bold>Run</>  outcome: '.($run->outcome?->value ?? 'open'));

        foreach ($run->steps()->orderBy('seq')->get() as $step) {
            $this->line(sprintf(
                '  %2d. %-12s %-10s %s',
                $step->seq,
                (string) $step->step_key,
                (string) $step->step_type,
                $step->outcome.($step->payload ? ' '.json_encode($step->payload, JSON_UNESCAPED_UNICODE) : ''),
            ));
        }
    }
}
