<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Commands;

use Illuminate\Console\Command;
use MB\FilamentChatbot\Knowledge\KnowledgeExchange;
use MB\FilamentChatbot\Models\Bot;

/**
 * Loads a knowledge base from a file, from the console.
 *
 * The same file the panel exports and accepts — deliberately the same, and not
 * a format of its own. Two formats would be two parsers, and the one nobody
 * uses daily is the one that quietly stops matching the other.
 *
 * It exists beside the screen because the two are reached at different moments.
 * The screen is for the fortnight of writing answers; this is for the first
 * day, when an analyst hands over a list of a hundred and seventy questions and
 * nobody wants to paste them into a form — and for every deployment afterwards,
 * where a base belongs in the same script as the migrations.
 *
 * **`--skeleton` is the flag that makes the first day safe.** Such a list
 * arrives with the answer column empty or filled with somebody's guesses, and
 * loaded a second time over a base the office has been writing it would replace
 * their words with a stranger's. With the flag the answer column is not read at
 * all, so re-running an updated list is something that can be done without
 * thinking about it — which is the only way a list actually stays updated.
 */
class ImportKnowledgeCommand extends Command
{
    protected $signature = 'chatbot:knowledge-import
        {file : The CSV to read, in the shape the panel exports}
        {--bot= : The slug of the bot these topics belong to; omitted, they are shared by every bot}
        {--skeleton : Never write the answer column, whatever the file says}
        {--dry-run : Say what would happen and change nothing}';

    protected $description = 'Loads knowledge base topics from a CSV, recognising each by its topic key';

    public function handle(KnowledgeExchange $exchange): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error("There is no readable file at [{$path}].");

            return self::FAILURE;
        }

        $bot = $this->bot();

        if ($bot === false) {
            return self::FAILURE;
        }

        $plan = $exchange->plan((string) file_get_contents($path), (bool) $this->option('skeleton'));

        foreach ($plan->warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($plan->isEmpty()) {
            $this->components->error('Nothing in that file could be loaded.');

            return self::FAILURE;
        }

        $this->components->info($plan->summary());

        if ($this->option('dry-run')) {
            $this->components->info('Nothing was written: --dry-run.');

            return self::SUCCESS;
        }

        $exchange->apply($plan, $bot);

        $this->components->info('Written.');

        return self::SUCCESS;
    }

    /**
     * The bot to file these topics under, or false when the named one does not
     * exist.
     *
     * Refusing an unknown slug rather than falling back to the shared base is
     * the whole of it: a typo in `--bot` would otherwise put a hundred and
     * seventy topics in front of every bot in the installation, and nothing
     * would say so until somebody's chat started answering another company's
     * questions.
     */
    private function bot(): int|null|false
    {
        $slug = trim((string) $this->option('bot'));

        if ($slug === '') {
            return null;
        }

        $bot = Bot::query()->where('slug', $slug)->first();

        if ($bot === null) {
            $this->components->error("No bot has the slug [{$slug}].");

            return false;
        }

        return (int) $bot->getKey();
    }
}
