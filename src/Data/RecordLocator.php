<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Data;

use MB\FilamentChatbot\Data\Concerns\ReachesRecords;
use MB\FilamentChatbot\Models\DataResource;
use Throwable;

/**
 * Turns a key a scenario is holding into a record it may point a conversation at.
 *
 * Deliberately not a reader. It returns no columns at all — only what identifies
 * the row — because everything a scenario is allowed to *say* about a record
 * goes through the reader, where the rules about returnable and sensitive fields
 * apply. A second path out of the same table that answered with data would make
 * those rules optional.
 *
 * What it does share with the reader and the writer is the definition of a
 * reachable record ({@see ReachesRecords}), and that is the whole point of the
 * class. The conversation's contact and subject are morph columns: a class name
 * and an id, written by the package and dereferenced by the host. Filling them
 * from a number the visitor typed, without the resource's own access scopes,
 * would let a scenario aim any conversation at any row of any declared model —
 * and the host, doing what it is told, would then show that record to whoever is
 * in the chat.
 */
final class RecordLocator
{
    use ReachesRecords;

    /**
     * The morph pair for one key, or a refusal saying why not.
     *
     * The type is the record's `getMorphClass()` and not the declared class
     * name. An application with a morph map stores aliases — `order`, not
     * `App\Models\Order` — and a package writing the class name into a mapped
     * column produces rows the host's own relation cannot resolve. Nothing
     * fails at write time; the link simply comes back null, months later,
     * wherever somebody reads it.
     */
    public function locate(string $resourceKey, mixed $key, RuntimeContext $context): DataResult
    {
        if (config('chatbot.data_resources.enabled', true) !== true) {
            return DataResult::failed('Data resources are disabled.');
        }

        if ($key === null || $key === '' || $key === []) {
            return DataResult::failed('No record was named.');
        }

        $resource = DataResource::query()->active()->where('key', $resourceKey)->first();

        if ($resource === null) {
            return DataResult::failed("No active data resource is declared under [{$resourceKey}].");
        }

        try {
            $record = $this->reachable($resource, $key, $context);
        } catch (Throwable $exception) {
            /*
             * Reported, never thrown. One misdeclared resource must not end a
             * conversation with a stack trace where the visitor expected a
             * sentence — the same rule the reader and the writer follow.
             */
            return DataResult::failed($exception->getMessage());
        }

        if ($record === null) {
            return DataResult::failed('No record of that resource is reachable under this conversation.');
        }

        return DataResult::fromRows([[
            'type' => $record->getMorphClass(),
            'id' => $record->getKey(),
        ]]);
    }
}
