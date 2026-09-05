<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Data;

use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Models\DataResource;
use Throwable;

/**
 * Writes one row of a declared resource, and refuses everything else.
 *
 * The counterpart to the reader, and deliberately much narrower. A bot that can
 * read the wrong row shows somebody the wrong answer; a bot that can write the
 * wrong row changes it, and there is nothing to take back. So this does exactly
 * two things — insert one row, or update one row it can name — and each of them
 * only when the resource was declared writable and only into the columns the
 * declaration named.
 *
 * What is not here is as deliberate as what is. No delete: a conversation is a
 * bad place to decide something is gone forever. No bulk update: a filter that
 * matches more rows than the author pictured is the classic way to lose a
 * table, and here the author is not necessarily a programmer. No raw
 * expressions.
 *
 * Whether a confirmation stands above the write is not asked here, for the same
 * reason the capability gateway does not ask it: this is handed one call and
 * cannot see the graph. The release refuses to publish a scenario where a write
 * is reachable without an approval node above it, which is the moment the graph
 * is in hand — see `docs/data-resources.md`.
 */
class DataWriter
{
    /**
     * Adds a row.
     *
     * @param  array<string, mixed>  $values
     */
    public function insert(string $resourceKey, array $values, RuntimeContext $context): DataResult
    {
        return $this->guarded($resourceKey, function (DataResource $resource) use ($values, $context): DataResult {
            $model = $this->model($resource);
            $writable = $this->writableValues($resource, $values);

            if ($writable === []) {
                return DataResult::failed('Nothing to write: none of the given fields is writable.');
            }

            /*
             * Scopes are applied to an insert as well as a read, and they are
             * applied last so nothing the scenario supplied can override them.
             * Without this a bot could open a record belonging to somebody else
             * simply by being asked to, which is the same hole the reader closes
             * — except this one leaves a row behind.
             */
            foreach ($this->scopeValues($resource, $context) as $field => $value) {
                $writable[$field] = $value;
            }

            $record = $model->newInstance($writable);
            $record->save();

            return DataResult::fromRows([$this->identify($record)]);
        });
    }

    /**
     * Changes one row, and only if it still looks the way the caller last saw it.
     *
     * **The expected lock value is supplied by the caller and never re-read
     * here.** That is the whole mechanism. A writer that fetched the row and
     * used its current lock value would compare the record against itself and
     * pass every time — an optimistic lock that is always satisfied, which is
     * worse than none because it looks like a guard.
     *
     * What the caller supplies is the value it read when it decided what to
     * write. Two conversations about the same record are ordinary — the same
     * person writes from the site and from Telegram within a minute — and this
     * is what stops the second from silently undoing the first.
     *
     * @param  array<string, mixed>  $values
     * @param  mixed  $expectedLock  the lock column's value as the caller read it
     */
    public function update(
        string $resourceKey,
        mixed $key,
        array $values,
        RuntimeContext $context,
        mixed $expectedLock = null,
    ): DataResult {
        return $this->guarded($resourceKey, function (DataResource $resource) use ($key, $values, $context, $expectedLock): DataResult {
            $lock = trim((string) $resource->lock_column);

            if ($lock === '') {
                return DataResult::failed('This resource declares no lock column, so it cannot be updated safely.');
            }

            if ($expectedLock === null || $expectedLock === '') {
                return DataResult::failed(
                    "An update needs the value of [{$lock}] as it was read, and none was given.",
                );
            }

            $model = $this->model($resource);
            $writable = $this->writableValues($resource, $values);

            if ($writable === []) {
                return DataResult::failed('Nothing to write: none of the given fields is writable.');
            }

            $query = $model->newQuery()->whereKey($key);

            foreach ($this->scopeValues($resource, $context) as $field => $value) {
                $query->where($field, $value);
            }

            $record = $query->first();

            if ($record === null) {
                return DataResult::failed('No record of that resource is reachable under this conversation.');
            }

            $changed = $model->newQuery()
                ->whereKey($record->getKey())
                ->where($lock, $expectedLock)
                ->update($writable);

            /*
             * Zero rows means somebody else wrote first. Reported as a failure
             * rather than retried: the scenario decided what to write from what
             * it read, and that reading is now stale — repeating the write would
             * apply a decision made about a record that no longer exists in that
             * form.
             */
            if ($changed === 0) {
                return DataResult::failed('The record changed while this conversation was deciding what to write.');
            }

            return DataResult::fromRows([$this->identify($record->refresh())]);
        });
    }

    /**
     * The guards every write shares, in one place.
     *
     * @param  callable(DataResource): DataResult  $write
     */
    protected function guarded(string $resourceKey, callable $write): DataResult
    {
        if (config('chatbot.data_resources.enabled', true) !== true) {
            return DataResult::failed('Data resources are disabled.');
        }

        $resource = DataResource::query()->active()->where('key', $resourceKey)->first();

        if ($resource === null) {
            return DataResult::failed("No active data resource is declared under [{$resourceKey}].");
        }

        if ($resource->is_writable !== true) {
            return DataResult::failed("The data resource [{$resourceKey}] is declared read-only.");
        }

        try {
            return $write($resource);
        } catch (Throwable $exception) {
            /*
             * A failing write is reported, never thrown. One misdeclared
             * resource must not end a conversation with a stack trace where the
             * visitor expected a sentence.
             */
            return DataResult::failed($exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function writableValues(DataResource $resource, array $values): array
    {
        $allowed = array_map(
            static fn (mixed $field): string => (string) $field,
            (array) $resource->write_fields,
        );

        return array_intersect_key($values, array_flip($allowed));
    }

    /**
     * @return array<string, mixed>
     */
    protected function scopeValues(DataResource $resource, RuntimeContext $context): array
    {
        $applied = [];

        foreach ((array) $resource->scopes as $scope) {
            $field = (string) ($scope['field'] ?? '');
            $source = (string) ($scope['source'] ?? '');

            if ($field === '' || $source === '') {
                continue;
            }

            $value = $context->resolve($source);

            if ($value === null) {
                throw new \RuntimeException("The access scope [{$source}] resolved to nothing, so nothing may be written.");
            }

            $applied[$field] = $value;
        }

        return $applied;
    }

    protected function model(DataResource $resource): Model
    {
        $class = (string) $resource->model;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new \RuntimeException("The data resource [{$resource->key}] names no usable model.");
        }

        return new $class;
    }

    /**
     * What a write reports back: the key, and nothing else.
     *
     * Not the row. A scenario that wanted to read the record can say so with a
     * read node, where every rule about approved and sensitive fields applies —
     * returning it from here would be a second, unguarded way out of the same
     * table.
     *
     * @return array<string, mixed>
     */
    protected function identify(Model $record): array
    {
        return [$record->getKeyName() => $record->getKey()];
    }
}
