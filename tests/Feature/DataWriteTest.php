<?php

declare(strict_types=1);

use MB\FilamentChatbot\Data\DataWriter;
use MB\FilamentChatbot\Data\RuntimeContext;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\DataResource;
use Workbench\App\Models\DemoRecord;

/**
 * Everything the reader gets wrong shows somebody the wrong answer. Everything
 * the writer gets wrong changes a record, and there is nothing to take back — so
 * every test below is about a row that must not exist, or must not have moved.
 */

/**
 * @param  array<array-key, mixed>  $overrides
 */
function writableResource(array $overrides = []): DataResource
{
    return DataResource::factory()->create(array_merge([
        'key' => 'demo-writes',
        'model' => DemoRecord::class,
        'modes' => ['list', 'first'],
        'fields' => [
            ['name' => 'id', 'returned_by_default' => true],
            ['name' => 'name', 'returned_by_default' => true],
            ['name' => 'status', 'returned_by_default' => true],
            ['name' => 'owner_id'],
            ['name' => 'secret_code', 'sensitive' => true],
        ],
        'is_writable' => true,
        'write_fields' => ['name', 'status'],
        'lock_column' => 'updated_at',
        'scopes' => null,
    ], $overrides));
}

function writer(): DataWriter
{
    return new DataWriter;
}

function writeContext(?Conversation $conversation = null): RuntimeContext
{
    return RuntimeContext::forConversation($conversation ?? Conversation::factory()->create());
}

it('refuses to write to a resource that was not declared writable', function (): void {
    writableResource(['is_writable' => false]);

    $result = writer()->insert('demo-writes', ['name' => 'Sneaked in'], writeContext());

    expect($result->ok())->toBeFalse()
        ->and(DemoRecord::query()->count())->toBe(0);
});

/**
 * The write-field list is the whole of what a scenario may touch. A field
 * outside it that were merely ignored would be indistinguishable from one that
 * was written, and the author would believe the scenario does something it does
 * not.
 */
it('writes only the fields the resource declares writable', function (): void {
    writableResource();

    $result = writer()->insert('demo-writes', [
        'name' => 'Acme',
        'status' => 'open',
        'secret_code' => 'S-1',
        'owner_id' => 99,
    ], writeContext());

    expect($result->ok())->toBeTrue();

    $record = DemoRecord::query()->firstOrFail();

    expect($record->name)->toBe('Acme')
        ->and($record->status)->toBe('open')
        ->and($record->secret_code)->toBeNull()
        ->and($record->owner_id)->toBeNull();
});

/**
 * A write that reports success and returns the row would be a second way out of
 * the table with none of the reader's rules on it — no approved-field list, no
 * sensitive-column guard.
 */
it('returns the key of what it wrote and nothing else', function (): void {
    writableResource();

    $result = writer()->insert('demo-writes', ['name' => 'Acme', 'status' => 'open'], writeContext());
    $written = $result->first() ?? [];

    expect(array_keys($written))->toBe(['id'])
        ->and($written['id'])->toBeGreaterThan(0);
});

/**
 * Two conversations about the same record are ordinary — the same person writes
 * from the site and from Telegram within a minute. Without a lock the second
 * write silently undoes the first, and nobody is told.
 */
it('refuses to update a resource that declares no lock column', function (): void {
    writableResource(['lock_column' => null]);
    $record = DemoRecord::create(['name' => 'Acme', 'status' => 'open']);

    $result = writer()->update(
        'demo-writes',
        $record->getKey(),
        ['status' => 'closed'],
        writeContext(),
        $record->updated_at,
    );

    expect($result->ok())->toBeFalse()
        ->and($record->refresh()->status)->toBe('open');
});

/**
 * The caller has to say what it saw, and an update that cannot say is refused.
 *
 * Without this the writer would read the row itself and compare it against
 * itself — a lock that is satisfied every time, which is worse than no lock
 * because it looks like a guard and is written down as one.
 */
it('refuses an update that does not say how the record looked when it was read', function (): void {
    writableResource();
    $record = DemoRecord::create(['name' => 'Acme', 'status' => 'open']);

    $result = writer()->update('demo-writes', $record->getKey(), ['status' => 'closed'], writeContext());

    expect($result->ok())->toBeFalse()
        ->and($record->refresh()->status)->toBe('open');
});

it('refuses an update when the record moved while the scenario was deciding', function (): void {
    writableResource();
    $record = DemoRecord::create(['name' => 'Acme', 'status' => 'open']);
    $seen = $record->updated_at;

    /*
     * Somebody else writes between the read and the write. The scenario decided
     * what to write from what it read, and that reading is now stale — applying
     * it anyway would act on a record that no longer exists in that form.
     */
    DemoRecord::query()->whereKey($record->getKey())->update([
        'status' => 'escalated',
        'updated_at' => now()->addMinute(),
    ]);

    $result = writer()->update(
        'demo-writes',
        $record->getKey(),
        ['status' => 'closed'],
        writeContext(),
        $seen,
    );

    expect($result->ok())->toBeFalse()
        ->and($record->refresh()->status)->toBe('escalated');
});

it('updates a record that has not moved', function (): void {
    writableResource();
    $record = DemoRecord::create(['name' => 'Acme', 'status' => 'open']);

    $result = writer()->update(
        'demo-writes',
        $record->getKey(),
        ['status' => 'closed'],
        writeContext(),
        $record->updated_at,
    );

    expect($result->ok())->toBeTrue()
        ->and($record->refresh()->status)->toBe('closed');
});

/**
 * The scope decides which records a conversation may touch, and it is applied to
 * a write as well as to a read. Applied last, so nothing the scenario supplied
 * can override it — otherwise a bot could open a record belonging to somebody
 * else simply by being asked to, and leave a row behind.
 */
it('stamps the access scope onto what it writes, over anything the scenario said', function (): void {
    $conversation = Conversation::factory()->create([
        'contact_type' => DemoRecord::class,
        'contact_id' => 7,
    ]);

    writableResource([
        'write_fields' => ['name', 'status', 'owner_id'],
        'scopes' => [['field' => 'owner_id', 'source' => 'conversation.contact_id']],
    ]);

    writer()->insert('demo-writes', [
        'name' => 'Acme',
        'owner_id' => 999,
    ], writeContext($conversation));

    expect(DemoRecord::query()->firstOrFail()->owner_id)->toBe(7);
});

/**
 * A scope source that resolves to nothing must stop the write, not drop the
 * filter. A typo in a source name would otherwise turn "this visitor's records"
 * into "anybody's", and the row it wrote would look entirely ordinary.
 */
it('writes nothing when an access scope resolves to nothing', function (): void {
    writableResource([
        'scopes' => [['field' => 'owner_id', 'source' => 'actor.id']],
    ]);

    $result = writer()->insert('demo-writes', ['name' => 'Acme'], writeContext());

    expect($result->ok())->toBeFalse()
        ->and(DemoRecord::query()->count())->toBe(0);
});

it('refuses a record that the access scope does not reach', function (): void {
    $mine = Conversation::factory()->create(['contact_type' => DemoRecord::class, 'contact_id' => 7]);
    $record = DemoRecord::create(['name' => 'Somebody else', 'status' => 'open', 'owner_id' => 8]);

    writableResource([
        'scopes' => [['field' => 'owner_id', 'source' => 'conversation.contact_id']],
    ]);

    $result = writer()->update(
        'demo-writes',
        $record->getKey(),
        ['status' => 'closed'],
        writeContext($mine),
        $record->updated_at,
    );

    expect($result->ok())->toBeFalse()
        ->and($record->refresh()->status)->toBe('open');
});

/**
 * A resource naming a class that is not a model would otherwise end the
 * conversation with a stack trace where the visitor expected a sentence.
 */
it('reports a misdeclared resource instead of throwing', function (): void {
    writableResource(['model' => 'Nothing\\Like\\This']);

    $result = writer()->insert('demo-writes', ['name' => 'Acme'], writeContext());

    expect($result->ok())->toBeFalse()
        ->and($result->error())->not->toBeEmpty();
});
