<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Commands;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Console\Command;
use MB\FilamentChatbot\Demo\DemoData;
use MB\FilamentChatbot\Models\Bot;
use Throwable;

/**
 * Puts a working demonstration bot into the database.
 *
 * Guarded in production, and the guard is not ceremony. This command does not
 * write sample rows into a table nobody reads — it writes scenarios, and a
 * scenario is live the moment it is saved. A fallback called "Fallback" would
 * start answering real customers on the next message, competing with the
 * installation's own fallback for every message that reaches it, and the demo's
 * cheerful "Order ABC-123 left the warehouse" is a statement about somebody's
 * actual order. Running it once by accident is not undone by deleting the rows:
 * the conversations already happened.
 */
class SeedDemoDataCommand extends Command
{
    protected $signature = 'chatbot:demo-data
                            {--bot=demo : Slug of the demo bot}
                            {--fresh : Remove the demo data first}
                            {--force : Allow this in production}';

    protected $description = 'Seed a demonstration bot with scenarios, a knowledge base and quick replies';

    public function handle(DemoData $demo): int
    {
        $slug = (string) $this->option('bot');

        if ($this->getLaravel()->environment('production') && ! $this->option('force')) {
            $this->components->error('Refusing to seed demo data in production.');
            $this->line('  These scenarios go live as soon as they are written: the demo fallback would');
            $this->line('  answer real customers, and its invented order status would be read as a promise.');
            $this->line('  Pass --force if you are certain this installation has no customers on it.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $demo->wipe($slug);
            $this->components->info("Removed the demo data for bot [{$slug}].");
        }

        $bot = $demo->seed($slug);

        $this->summary($demo->counts());
        $this->nextSteps($bot);

        return self::SUCCESS;
    }

    /**
     * @param  array{scenarios: int, steps: int, topics: int, quick_replies: int}  $counts
     */
    protected function summary(array $counts): void
    {
        $this->newLine();
        $this->components->info('Demo data is in place.');

        $this->components->twoColumnDetail('Scenarios', (string) $counts['scenarios']);
        $this->components->twoColumnDetail('Steps', (string) $counts['steps']);
        $this->components->twoColumnDetail('Knowledge topics', (string) $counts['topics']);
        $this->components->twoColumnDetail('Quick replies', (string) $counts['quick_replies']);
    }

    protected function nextSteps(Bot $bot): void
    {
        $this->newLine();
        $this->line('<options=bold>Try it</>');
        $this->line("  php artisan chatbot:demo --bot={$bot->slug} \"where is my order\" \"ABC-123\"");
        $this->line("  php artisan chatbot:demo --bot={$bot->slug} \"my printer is broken\" \"Alex\" \"alex@example.test\" \"it will not switch on\" \"today\"");

        $path = $this->panelPath();

        if ($path !== null) {
            $this->line("  Then open the panel at {$path} to see the scenarios on the canvas.");
        }
    }

    /**
     * The path of a panel that actually carries the chat screens.
     *
     * Read from the panel rather than guessed as "/admin", because the package
     * is added to whichever panel the host chose and pointing a new user at a
     * URL that 404s is worse than saying nothing. Wrapped, because resolving
     * panels touches the host's own providers and a demo seeder must not fail
     * over a line of help text.
     */
    protected function panelPath(): ?string
    {
        try {
            foreach (Filament::getPanels() as $panel) {
                if ($panel instanceof Panel && $panel->hasPlugin('chatbot')) {
                    return '/'.ltrim($panel->getPath(), '/');
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
