<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\QualityTest;
use MB\FilamentChatbot\Quality\QualityRunner;

/**
 * Runs the saved rehearsals and says whether the bot still survives them.
 *
 * Written to be put in a deployment pipeline, and every decision below follows
 * from that.
 *
 * **Only a required check can fail the run.** The distinction is not politeness:
 * a suite grows by people adding the conversation they were curious about that
 * week, and half of those describe a wording somebody is still arguing over. A
 * command that refused a release over any of them would be taken out of the
 * pipeline within a fortnight, taking the checks that matter with it. Required
 * is the small set somebody agreed must never break; everything else is
 * reported and does not stop the world.
 *
 * **A required check that could not run also fails.** It has proved nothing, and
 * "could not run" is what a check looks like the day the scenario it guards is
 * deleted — which is exactly the release it exists to stop.
 */
class RunQualityChecksCommand extends Command
{
    protected $signature = 'chatbot:quality
                            {--bot= : Only checks of this bot, by id or slug}
                            {--check=* : Only these checks, by id or name}
                            {--required : Only the checks that block a release}
                            {--all : Include the checks that are switched off}';

    protected $description = 'Run the saved quality checks against the bot and report what broke';

    public function handle(QualityRunner $runner): int
    {
        $checks = $this->selection();

        if ($checks->isEmpty()) {
            return $this->nothingMatched();
        }

        $rows = [];
        $reasons = [];
        $blocking = 0;
        $informative = 0;

        foreach ($checks as $check) {
            $result = $runner->run($check);

            $rows[] = [
                $check->name,
                $this->scenarioOf($check),
                sprintf('<fg=%s>%s</>', $result->outcome()->color(), $result->outcome()->getLabel()),
                (string) count($result->mismatches()),
            ];

            /*
             * The reason is printed under the table rather than in it. It is a
             * sentence, and a sentence in a cell either wraps the whole table
             * into unreadable columns or is cut off in the middle of the name of
             * the thing that went missing.
             */
            if ($result->reason() !== null) {
                $reasons[] = $check->name.': '.$result->reason();
            }

            if ($result->isPassing()) {
                continue;
            }

            $check->is_required ? $blocking++ : $informative++;
        }

        $this->newLine();
        $this->table(['Check', 'Scenario', 'Outcome', 'Mismatches'], $rows);

        foreach ($reasons as $reason) {
            $this->line('  <fg=yellow>'.$reason.'</>');
        }

        return $this->verdict($blocking, $informative);
    }

    /**
     * The checks this invocation is about.
     *
     * Switched-off checks are left out unless asked for. A check is switched off
     * because it describes a conversation that no longer applies, and running it
     * anyway would report a failure whose fix is to delete it.
     *
     * @return Collection<int, QualityTest>
     */
    protected function selection(): Collection
    {
        $query = QualityTest::query()->with('scenario')->orderBy('id');

        if (! (bool) $this->option('all')) {
            $query->active();
        }

        if ((bool) $this->option('required')) {
            $query->required();
        }

        $bot = trim((string) $this->option('bot'));

        if ($bot !== '') {
            $query->where('bot_id', $this->botId($bot));
        }

        /** @var list<string> $named */
        $named = array_values(array_filter(
            (array) $this->option('check'),
            static fn (mixed $name): bool => is_string($name) && trim($name) !== '',
        ));

        if ($named !== []) {
            $query->where(function ($scoped) use ($named): void {
                $scoped->whereIn('name', $named);

                $ids = array_values(array_filter($named, static fn (string $name): bool => ctype_digit($name)));

                if ($ids !== []) {
                    $scoped->orWhereIn('id', $ids);
                }
            });
        }

        return $query->get();
    }

    /**
     * A bot named by slug or by id, resolved to an id.
     *
     * Slugs are accepted because that is what a pipeline can hard-code: an id
     * differs between the staging database and the production one, and a
     * pipeline pinned to one runs the wrong bot's checks on the other without
     * ever reporting an error.
     */
    protected function botId(string $bot): ?int
    {
        if (ctype_digit($bot)) {
            return (int) $bot;
        }

        $id = Bot::query()->where('slug', $bot)->value('id');

        return $id === null ? null : (int) $id;
    }

    protected function scenarioOf(QualityTest $check): string
    {
        return (string) ($check->scenario_slug ?? $check->scenario?->slug ?? '-');
    }

    /**
     * An empty selection, answered differently depending on who emptied it.
     *
     * An installation with no checks at all is a normal installation, and a
     * command that failed on it would fail every pipeline the day the package is
     * installed. A selection emptied by a filter is the opposite: somebody asked
     * for a named check and it is not there, and passing silently would let a
     * pipeline report success for a check that has been deleted.
     */
    protected function nothingMatched(): int
    {
        $filtered = (array) $this->option('check') !== []
            || trim((string) $this->option('bot')) !== ''
            || (bool) $this->option('required');

        if ($filtered) {
            $this->components->error('No check matched. Run without options to see everything that is saved.');

            return self::FAILURE;
        }

        $this->components->warn('There are no quality checks saved yet, so nothing was rehearsed.');

        return self::SUCCESS;
    }

    protected function verdict(int $blocking, int $informative): int
    {
        $this->newLine();

        if ($blocking > 0) {
            $this->components->error($blocking.' required checks did not pass. This build should not ship.');

            return self::FAILURE;
        }

        if ($informative > 0) {
            $this->components->warn($informative.' checks did not pass. None of them is required, so the run stands.');

            return self::SUCCESS;
        }

        $this->components->info('Every check the bot was given passed.');

        return self::SUCCESS;
    }
}
