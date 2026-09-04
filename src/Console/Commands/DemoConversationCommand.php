<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Commands;

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
 * Shipped with the package rather than kept in the workbench, because the first
 * thing anyone wants after installing a chatbot is to watch it answer, and the
 * alternative is asking them to build a widget before they know whether the
 * thing works at all.
 *
 * It also proves something a test suite cannot. The suite drives the engine
 * through a test case; this drives it through the application, with the host's
 * providers, the host's registry and the host's database — which is exactly
 * where a missing registration or a mis-ordered boot shows up.
 *
 * It writes a real conversation, so it refuses to run in production without
 * being told twice: the transcript would sit in the inbox looking like a
 * customer nobody answered.
 */
class DemoConversationCommand extends Command
{
    protected $signature = 'chatbot:demo
                            {said?* : What the visitor says, in order}
                            {--bot=demo : Slug of the bot to talk to}
                            {--force : Allow this in production}';

    protected $description = 'Hold a scripted conversation with a bot and print the transcript';

    public function handle(BotEngine $engine): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->components->error(
                'This writes a real conversation into the inbox. Pass --force if that is what you want.',
            );

            return self::FAILURE;
        }

        $bot = Bot::query()->where('slug', $this->option('bot'))->first();

        if ($bot === null) {
            $this->components->error(
                "No bot with slug [{$this->option('bot')}]. Run chatbot:demo-data first.",
            );

            return self::FAILURE;
        }

        /*
         * The default script walks the support scenario, which is the one that
         * calls another scenario and comes back. A script that only said hello
         * would print a perfectly good transcript on a package whose call stack
         * was broken.
         */
        $lines = $this->argument('said') ?: [
            'my printer is broken',
            'Alex',
            'alex@example.test',
            'it will not switch on any more',
            'today',
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
                'body' => (string) $said,
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

    /**
     * The run journal, which is the half people actually need when the
     * transcript surprises them.
     */
    protected function state(Conversation $conversation): void
    {
        $this->newLine();
        $this->line('<options=bold>State</>');
        $this->line('  bot state: '.$conversation->bot_state->value);
        $this->line('  collected: '.json_encode(
            $conversation->bot_answers,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        $run = Run::query()
            ->where('conversation_id', $conversation->getKey())
            ->latest('id')
            ->first();

        if ($run === null) {
            $this->line('  no run recorded');

            return;
        }

        $this->newLine();
        $this->line('<options=bold>Run</>  outcome: '.($run->outcome?->value ?? 'open'));

        foreach ($run->steps()->orderBy('seq')->get() as $step) {
            $this->line(sprintf(
                '  %2d. %-14s %-10s %s',
                $step->seq,
                (string) $step->step_key,
                (string) $step->step_type,
                $step->outcome.($step->payload
                    ? ' '.json_encode($step->payload, JSON_UNESCAPED_UNICODE)
                    : ''),
            ));
        }
    }
}
