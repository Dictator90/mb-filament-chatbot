<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Exceptions;

use RuntimeException;

/**
 * A data-resource query asked for something the declaration does not allow.
 *
 * Every constructor here marks a place where the alternative was to quietly fix
 * the query up and carry on — drop the unknown field, downgrade the operator,
 * clamp the limit — and where doing so would have produced a confident answer
 * built on data nobody approved. The refusal is the feature.
 *
 * Messages name the resource and the field, never the value that was filtered
 * on: these strings end up in logs and, when a runtime is careless, in a reply,
 * and a customer's phone number does not belong in either.
 */
class DataQueryException extends RuntimeException
{
    public static function unknownField(string $resourceKey, string $field): self
    {
        return new self("Data resource [{$resourceKey}] does not declare a field named [{$field}].");
    }

    public static function operatorNotAllowed(string $resourceKey, string $field, string $operator): self
    {
        return new self("Field [{$field}] of data resource [{$resourceKey}] does not allow the [{$operator}] operator.");
    }

    public static function limitTooHigh(string $resourceKey, int $requested, int $maximum): self
    {
        return new self("Data resource [{$resourceKey}] allows at most {$maximum} rows, {$requested} were requested.");
    }

    public static function modeNotAllowed(string $resourceKey, string $mode): self
    {
        return new self("Data resource [{$resourceKey}] does not allow the [{$mode}] query mode.");
    }

    public static function sensitiveField(string $resourceKey, string $field): self
    {
        return new self("Field [{$field}] of data resource [{$resourceKey}] is marked sensitive and may never be returned.");
    }
}
