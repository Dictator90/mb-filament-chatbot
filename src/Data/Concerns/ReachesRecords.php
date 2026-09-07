<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Data\Concerns;

use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Data\RuntimeContext;
use MB\FilamentChatbot\Models\DataResource;
use RuntimeException;

/**
 * What "a record this conversation may touch" means, written once.
 *
 * Two classes ask the question — the writer, before it changes a row, and the
 * locator, before it lets a conversation point at one — and they have to answer
 * it identically. Copied, the two drift on the first change, and the drift is
 * invisible: the one that forgot a scope keeps returning records, just more of
 * them than the declaration allows, and every read it serves is valid, indexed
 * and indistinguishable in a log from a legitimate one.
 */
trait ReachesRecords
{
    /**
     * The declared model, or an exception naming the resource.
     */
    protected function model(DataResource $resource): Model
    {
        $class = (string) $resource->model;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new RuntimeException("The data resource [{$resource->key}] names no usable model.");
        }

        return new $class;
    }

    /**
     * The scope filters, resolved from server state.
     *
     * A scope that resolves to nothing throws rather than being dropped. The
     * failure this guards against is a typo — `actor.tenant_id` written where
     * the host meant `tenant.id` — and a dropped filter keeps the query working
     * while quietly widening it to the whole table.
     *
     * @return array<string, mixed>
     */
    protected function scopeValues(DataResource $resource, RuntimeContext $context): array
    {
        $applied = [];

        foreach ((array) $resource->scopes as $scope) {
            $field = (string) ($scope['field'] ?? '');
            $source = (string) ($scope['source'] ?? '');

            /*
             * A scope reaching through a relation is refused here rather than
             * ignored, and that asymmetry with the reader is deliberate. The
             * reader can express it — `whereHas` narrows a read — but a write
             * addresses one row by key and has nowhere to put the condition, so
             * carrying on would apply a weaker scope than the declaration says
             * and change a record the conversation was never allowed to see.
             * Refusing costs a resource that can be read but not written; the
             * alternative costs somebody else's row.
             */
            if (trim((string) ($scope['relation'] ?? '')) !== '') {
                throw new RuntimeException(
                    "The data resource [{$resource->key}] is scoped through a relation, which cannot be applied to a write.",
                );
            }

            if ($field === '' || $source === '') {
                continue;
            }

            $value = $context->resolve($source);

            if ($value === null) {
                throw new RuntimeException("The access scope [{$source}] resolved to nothing, so nothing may be written.");
            }

            $applied[$field] = $value;
        }

        return $applied;
    }

    /**
     * The one record of this resource that both exists and is this
     * conversation's to see, or nothing.
     *
     * Nothing is the answer for a record that is somebody else's as well as for
     * one that was never there, and the two must not be told apart out loud. A
     * message that distinguishes them confirms that order 4711 exists to a
     * visitor who is not allowed to know it does.
     */
    protected function reachable(DataResource $resource, mixed $key, RuntimeContext $context): ?Model
    {
        $query = $this->model($resource)->newQuery()->whereKey($key);

        foreach ($this->scopeValues($resource, $context) as $field => $value) {
            $query->where($field, $value);
        }

        return $query->first();
    }
}
