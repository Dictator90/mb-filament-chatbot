<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use Throwable;

/**
 * One thing worth knowing about an installation before it takes a customer.
 *
 * An abstract class rather than an interface, so that a host writing its own
 * check inherits the part that is easy to get wrong — swallowing its own
 * exceptions. A check that throws would take down the whole report, and the one
 * line that mattered is somewhere above the stack trace nobody scrolled to.
 *
 * Checks are resolved from the container, so a host's check may ask for
 * whatever it needs in its constructor.
 */
abstract class DoctorCheck
{
    /**
     * A stable key, used to name the check in scripts and in bug reports.
     */
    abstract public static function key(): string;

    /**
     * The heading a person reads in the report.
     */
    abstract public function title(): string;

    /**
     * What this check found, as one finding or several.
     *
     * @return list<DoctorFinding>
     */
    abstract public function run(): array;

    /**
     * Runs the check without letting it take the report down with it.
     *
     * A check that throws is itself a finding: something it depended on is not
     * where it should be, which is exactly the class of problem the doctor was
     * run to find. Reported as a failure with the exception's own words, it
     * names the fault; left to propagate, it hides every check after it.
     *
     * @return list<DoctorFinding>
     */
    final public function safely(): array
    {
        try {
            return $this->run();
        } catch (Throwable $e) {
            return [DoctorFinding::fail(
                "This check could not run: {$e->getMessage()}",
                'The check itself failed rather than the thing it checks. Fix what it depends on, then run the doctor again.',
            )];
        }
    }
}
