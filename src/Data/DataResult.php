<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Data;

/**
 * The outcome of one read, with "nothing matched" and "not allowed" kept apart.
 *
 * They are two different answers and a scenario branches on them differently:
 * an empty result continues the conversation — offer to search again, ask for
 * another reference number — while a refusal has to stop and hand over, because
 * nothing the visitor types next will change it. Collapsing both into an empty
 * array is what makes a bot reply "I could not find anything" to a permission
 * failure: it invites the visitor to keep trying, it hides a misconfiguration
 * from whoever could fix it, and it tells someone that a record does not exist
 * when in truth they are simply not allowed to see it.
 */
final class DataResult
{
    /**
     * @param  list<array<string, scalar|null>>  $rows
     */
    private function __construct(
        private readonly bool $ok,
        private readonly array $rows,
        private readonly int $count,
        private readonly ?string $error,
    ) {}

    /**
     * Named `fromRows` and not `rows` because PHP has one namespace for static
     * and instance methods, and the reader that builds a result is not the same
     * caller as the scenario that reads one. Of the two, the accessor is the one
     * every consumer touches, so it keeps the short name.
     *
     * @param  list<array<string, scalar|null>>  $rows
     */
    public static function fromRows(array $rows): self
    {
        return new self(true, array_values($rows), count($rows), null);
    }

    /**
     * A successful count with no rows behind it.
     *
     * Kept separate from `fromRows()` so that `isEmpty()` on a count of zero
     * means "nothing matched" and not "this mode returns no rows anyway".
     */
    public static function counted(int $number): self
    {
        return new self(true, [], max(0, $number), null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, [], 0, $reason);
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<string, scalar|null>|null
     */
    public function first(): ?array
    {
        return $this->rows[0] ?? null;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * True for a refusal as well as for an empty match.
     *
     * A refusal has nothing to show, so anything rendering rows must treat it as
     * empty; anything *deciding* what to say must ask `ok()` first. The two
     * questions are separate for that reason, and neither answers the other.
     */
    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}
