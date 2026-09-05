<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

/**
 * One thing the doctor found, and what to do about it.
 *
 * The advice is a separate field rather than a second sentence in the message,
 * because it is the half that gets read. A report that says "the queue is
 * synchronous" tells somebody a fact about their configuration; one that also
 * says which setting to change turns the fact into an action, and the person
 * reading it is usually deploying at the time and did not write any of this.
 *
 * A finding also carries the subjects it is about — scenario slugs, node kinds,
 * bot names. Naming them is the difference between a warning somebody acts on
 * and a warning somebody rediscovers by opening scenarios one at a time.
 */
final readonly class DoctorFinding
{
    /**
     * @param  list<string>  $subjects  what the finding is about, by name
     */
    public function __construct(
        public DoctorStatus $status,
        public string $message,
        public ?string $advice = null,
        public array $subjects = [],
    ) {}

    /**
     * @param  list<string>  $subjects
     */
    public static function pass(string $message, array $subjects = []): self
    {
        return new self(DoctorStatus::Pass, $message, null, $subjects);
    }

    public static function skipped(string $message): self
    {
        return new self(DoctorStatus::Skipped, $message);
    }

    /**
     * @param  list<string>  $subjects
     */
    public static function warn(string $message, ?string $advice = null, array $subjects = []): self
    {
        return new self(DoctorStatus::Warn, $message, $advice, $subjects);
    }

    /**
     * @param  list<string>  $subjects
     */
    public static function fail(string $message, ?string $advice = null, array $subjects = []): self
    {
        return new self(DoctorStatus::Fail, $message, $advice, $subjects);
    }
}
