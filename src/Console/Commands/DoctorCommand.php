<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Commands;

use Illuminate\Console\Command;
use MB\FilamentChatbot\Console\Doctor\Doctor;
use MB\FilamentChatbot\Console\Doctor\DoctorFinding;
use MB\FilamentChatbot\Console\Doctor\DoctorStatus;

/**
 * Checks an installation and says what is wrong with it.
 *
 * Written to be run twice: once by a person after installing, and once by a
 * deployment pipeline. Hence the exit code — a `FAIL` returns 1, because the
 * kinds of fault this finds are exactly the ones nobody notices until a
 * customer does. An empty widget origin list, an unpublished asset bundle, a
 * scenario whose node kind no longer exists: each of them looks fine on the
 * screen that reports success and breaks the one that matters.
 *
 * Warnings do not fail the run. A queue on the sync driver is wrong in
 * production and perfectly reasonable on a laptop, and a command that refused
 * to pass on a laptop would be turned off everywhere within a week.
 */
class DoctorCommand extends Command
{
    protected $signature = 'chatbot:doctor
                            {--check=* : Run only these checks, by key}
                            {--offline : Do not call any outside service; say what could not be verified}
                            {--strict : Treat warnings as failures too}';

    protected $description = 'Check the chatbot installation and report anything that would break quietly';

    public function handle(Doctor $doctor): int
    {
        /** @var list<string> $only */
        $only = (array) $this->option('check');

        $report = $doctor->run($only, (bool) $this->option('offline'));

        if ($report === []) {
            $this->components->error('No checks matched. Run without --check to see the full list.');

            return self::FAILURE;
        }

        foreach ($report as $entry) {
            $this->section($entry['title'], $entry['findings']);
        }

        return $this->verdict($doctor->worst($report));
    }

    /**
     * @param  list<DoctorFinding>  $findings
     */
    protected function section(string $title, array $findings): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");

        foreach ($findings as $finding) {
            $this->line(sprintf(
                '  <fg=%s>%-7s</> %s',
                $finding->status->color(),
                strtoupper($finding->status->label()),
                $finding->message,
            ));

            /*
             * The names come after the message rather than inside it. A finding
             * about eleven scenarios is one line to read and eleven to act on,
             * and putting them in the sentence makes the sentence unreadable
             * exactly when there are enough of them to matter.
             */
            if ($finding->subjects !== []) {
                $this->line('          <fg=gray>'.implode(', ', $finding->subjects).'</>');
            }

            if ($finding->advice !== null) {
                $this->line('          <fg=gray>'.$finding->advice.'</>');
            }
        }
    }

    protected function verdict(DoctorStatus $worst): int
    {
        $this->newLine();

        if ($worst === DoctorStatus::Fail) {
            $this->components->error('Something here will break quietly. Fix the failures above before deploying.');

            return self::FAILURE;
        }

        if ($worst === DoctorStatus::Warn) {
            $strict = (bool) $this->option('strict');

            $this->components->warn($strict
                ? 'Warnings, and --strict was asked for.'
                : 'Warnings only. Nothing is broken, but read them.');

            return $strict ? self::FAILURE : self::SUCCESS;
        }

        $this->components->info('Everything the doctor knows how to check is in order.');

        return self::SUCCESS;
    }
}
