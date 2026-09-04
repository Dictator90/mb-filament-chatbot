<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Capabilities;

/**
 * What a host action reports back, with "it worked" kept apart from what it
 * returned.
 *
 * A bare array would have to carry both answers at once, and every caller would
 * invent its own way of asking: an empty array means the action did nothing, or
 * it means the action failed, depending on which action it was. A scenario
 * branches on those two differently — a failure takes the second exit and ends
 * up in front of a person, an empty success carries on — so the difference is
 * a field here rather than a convention each host is left to guess at.
 *
 * The reason travels with the failure because it is the only thing anyone can
 * act on afterwards. The visitor is told nothing about it; the trace is where
 * "the action threw" is told apart from "the action refused".
 */
final class CapabilityResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private readonly bool $ok,
        private readonly array $data,
        private readonly ?string $error,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function succeeded(array $data): self
    {
        return new self(true, $data, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, [], $reason);
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}
