<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

/**
 * How badly one finding wants attention.
 *
 * Fail and Warn are separated by one question: does the installation work. A
 * queue running synchronously makes pauses feel wrong; a widget with no allowed
 * origins does not answer at all. Only the second may stop a deployment, and
 * collapsing them would either block releases over a preference or let a dead
 * widget through — after which nobody would look at the report again.
 *
 * Skipped is not a pass. A check whose subject is not installed has proved
 * nothing, and printing PASS for it would be a green line about a thing that
 * was never looked at.
 */
enum DoctorStatus: string
{
    case Pass = 'pass';
    case Skipped = 'skipped';
    case Warn = 'warn';
    case Fail = 'fail';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'PASS',
            self::Skipped => 'SKIP',
            self::Warn => 'WARN',
            self::Fail => 'FAIL',
        };
    }

    /**
     * The console colour, as a Symfony tag name.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pass => 'green',
            self::Skipped => 'gray',
            self::Warn => 'yellow',
            self::Fail => 'red',
        };
    }

    /**
     * How loud this is, so that a run of many findings can be reduced to the
     * single worst one without a table of comparisons at every call site.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Pass => 0,
            self::Skipped => 1,
            self::Warn => 2,
            self::Fail => 3,
        };
    }

    public function isWorseThan(self $other): bool
    {
        return $this->severity() > $other->severity();
    }
}
