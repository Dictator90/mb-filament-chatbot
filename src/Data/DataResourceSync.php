<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Data;

use BackedEnum;
use InvalidArgumentException;
use MB\FilamentChatbot\Enums\DataQueryMode;
use MB\FilamentChatbot\Models\DataResource;

/**
 * Brings the declarations in `config('chatbot.data_resources.resources')` into
 * the table the runtime reads.
 *
 * The point of the detour through configuration is review. A data resource is
 * the sentence that says which of the host's columns a chat may reach, and a
 * sentence like that belongs in a file that goes through a pull request and
 * ships with a deployment — not only in a form somebody can edit in production
 * at four in the afternoon with nobody watching. The table stays the single
 * thing the reader consults, so the panel keeps working; this class is how a
 * host makes the file the authority over the rows it cares about.
 *
 * Rows are matched on `key`, never on the primary key. Declarations are copied
 * between installations where the auto-increment counters have nothing to do
 * with each other, and a sync keyed on id would quietly rewrite whichever
 * resource happened to be seventh.
 */
class DataResourceSync
{
    /**
     * Writes every declared resource and returns how many were touched.
     *
     * Rows whose key is absent from the configuration are left exactly as they
     * are — not deactivated, not deleted. Two kinds of row share this table: the
     * ones this file owns and the ones somebody created in the panel, and there
     * is no column telling them apart, so "delete what I did not declare" would
     * mean the first host to call this method loses every resource their staff
     * built. Which leaves a real gap worth naming rather than hiding: deleting a
     * declaration from the config file does not revoke access. Revoking is done
     * by keeping the declaration and setting `is_active` to false, which is a
     * reviewable line in a diff, unlike a deletion that leaves nothing behind.
     */
    public function sync(): int
    {
        $declarations = (array) config('chatbot.data_resources.resources', []);
        $synced = 0;

        foreach ($declarations as $index => $declaration) {
            if (! is_array($declaration)) {
                continue;
            }

            $this->syncOne($declaration, is_string($index) ? $index : null);

            $synced++;
        }

        return $synced;
    }

    /**
     * Writes one declaration.
     *
     * A declaration with no key or no model throws instead of being skipped. The
     * skip is the worse outcome by far: the resource simply never exists, every
     * scenario pointing at it answers "unknown resource" at runtime, and the one
     * place that knew why — this method — said nothing at the moment it could
     * have named the offending entry.
     *
     * @param  array<string, mixed>  $declaration
     */
    public function syncOne(array $declaration, ?string $fallbackKey = null): DataResource
    {
        $key = (string) ($declaration['key'] ?? $fallbackKey ?? '');

        if ($key === '') {
            throw new InvalidArgumentException('A data resource declaration must carry a key.');
        }

        $model = (string) ($declaration['model'] ?? '');
        $table = (string) ($declaration['table'] ?? '');

        /*
         * One of the two, and either will do. A resource is a model of this
         * application or a table on a connection it has configured; a
         * declaration naming neither is a resource nothing can read, and every
         * scenario pointing at it would answer "unknown resource" months later
         * instead of failing here, where the offending entry can be named.
         */
        if ($model === '' && $table === '') {
            throw new InvalidArgumentException("Data resource declaration [{$key}] names neither a model nor a table.");
        }

        return DataResource::query()->updateOrCreate(
            ['key' => $key],
            [
                'label' => (string) ($declaration['label'] ?? $key),
                'description' => $declaration['description'] ?? null,
                'query_guidance' => $declaration['query_guidance'] ?? null,
                'model' => $model ?: null,
                'connection' => $declaration['connection'] ?? null,
                'source_table' => $table ?: null,
                'modes' => $this->modes($declaration['modes'] ?? null),
                'fields' => (array) ($declaration['fields'] ?? []),
                'default_sort' => $declaration['default_sort'] ?? null,
                'default_limit' => (int) ($declaration['default_limit'] ?? config('chatbot.data_resources.default_limit', 10)),
                'max_limit' => (int) ($declaration['max_limit'] ?? config('chatbot.data_resources.max_limit', 100)),
                'scopes' => $declaration['scopes'] ?? null,
                'is_writable' => (bool) ($declaration['is_writable'] ?? false),
                'write_fields' => $declaration['write_fields'] ?? null,
                'lock_column' => $declaration['lock_column'] ?? null,
                'is_active' => (bool) ($declaration['is_active'] ?? true),
            ],
        );
    }

    /**
     * A declaration that names no mode gets Count and nothing else.
     *
     * Falling back to List would hand rows out to a resource whose author never
     * said rows may leave it, and the omission most likely to happen is exactly
     * the one where nobody thought about that question at all.
     *
     * @return array<int, string>
     */
    protected function modes(mixed $declared): array
    {
        $modes = array_values(array_filter(
            array_map(
                static fn (mixed $mode): ?string => $mode instanceof BackedEnum
                    ? (string) $mode->value
                    : (is_string($mode) ? $mode : null),
                (array) $declared,
            ),
            static fn (?string $mode): bool => $mode !== null && DataQueryMode::tryFrom($mode) !== null,
        ));

        return $modes === [] ? [DataQueryMode::Count->value] : array_values(array_unique($modes));
    }
}
