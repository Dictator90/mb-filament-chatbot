<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Data;

use MB\FilamentChatbot\Enums\DataQueryMode;

/**
 * One request to read a declared resource, as a value rather than an array.
 *
 * Immutable on purpose: the same query object travels from the step that built
 * it into the reader that checks it, and a mutable one could be checked in one
 * place and changed in another, which is the shape of every authorisation bug
 * that survives a code review.
 */
final class DataQuery
{
    /**
     * @param  array<int, string>  $select
     * @param  list<array{field: string, operator: string, value: mixed}>  $filters
     * @param  list<array{field: string, direction: string}>  $sort
     */
    public function __construct(
        public readonly string $resource,
        public readonly DataQueryMode $mode,
        public readonly array $select = [],
        public readonly array $filters = [],
        public readonly array $sort = [],
        public readonly ?int $limit = null,
    ) {}

    /**
     * Lays a raw payload out into the typed shape, and repairs nothing.
     *
     * Parsing that silently drops what it does not understand turns "unknown
     * field" into "field missing from the answer", and the difference is found
     * by the customer rather than by the developer: the reply arrives complete,
     * confident and short of one column, with nothing anywhere saying why. So a
     * field nobody declared, an operator outside the allow-list and a limit
     * above the ceiling all survive this method intact, and are refused later by
     * the reader, out loud, with the resource and the field named.
     *
     * The only thing decided here is structure — that a filter is a triple and a
     * sort entry is a pair — because a half-built triple has nothing to refuse.
     * Entries that are not even that shape are dropped, since they carry no
     * field name to report.
     *
     * An unrecognised mode falls back to List rather than throwing. The mode is
     * still checked against the resource's own allow-list, so the fallback picks
     * the value most likely to be refused there rather than granting anything.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $mode = $payload['mode'] ?? null;

        if (! $mode instanceof DataQueryMode) {
            $mode = DataQueryMode::tryFrom(is_string($mode) ? $mode : '') ?? DataQueryMode::List;
        }

        $filters = [];

        foreach ((array) ($payload['filters'] ?? []) as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $field = $filter['field'] ?? null;

            if (! is_string($field) || $field === '') {
                continue;
            }

            $operator = $filter['operator'] ?? null;

            $filters[] = [
                'field' => $field,
                'operator' => is_string($operator) ? $operator : (string) ($operator?->value ?? ''),
                'value' => $filter['value'] ?? null,
            ];
        }

        $sort = [];

        foreach (self::sortEntries($payload['sort'] ?? []) as $entry) {
            $field = $entry['field'] ?? null;

            if (! is_string($field) || $field === '') {
                continue;
            }

            $direction = $entry['direction'] ?? 'asc';

            $sort[] = [
                'field' => $field,
                'direction' => is_string($direction) ? strtolower($direction) : 'asc',
            ];
        }

        $select = array_values(array_filter(
            (array) ($payload['select'] ?? []),
            static fn (mixed $field): bool => is_string($field) && $field !== '',
        ));

        $limit = $payload['limit'] ?? null;

        return new self(
            resource: (string) ($payload['resource'] ?? ''),
            mode: $mode,
            select: $select,
            filters: $filters,
            sort: $sort,
            limit: is_numeric($limit) ? (int) $limit : null,
        );
    }

    /**
     * Accepts both spellings of a sort declaration.
     *
     * A single pair and a list of pairs are both written by hand — in a config
     * file, in a step's settings — and demanding one of them would make the
     * other fail as "no sort at all", which looks like the query worked.
     *
     * @return list<array<string, mixed>>
     */
    private static function sortEntries(mixed $sort): array
    {
        if (! is_array($sort) || $sort === []) {
            return [];
        }

        if (array_key_exists('field', $sort)) {
            return [$sort];
        }

        return array_values(array_filter($sort, static fn (mixed $entry): bool => is_array($entry)));
    }
}
