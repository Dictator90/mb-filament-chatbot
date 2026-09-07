<?php

declare(strict_types=1);

use MB\FilamentChatbot\Data\DataQuery;
use MB\FilamentChatbot\Data\DataReader;
use MB\FilamentChatbot\Data\DataResourceSync;
use MB\FilamentChatbot\Data\RuntimeContext;
use MB\FilamentChatbot\Enums\DataQueryMode;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\DataResource;
use Workbench\App\Models\DemoLine;
use Workbench\App\Models\DemoRecord;
use Workbench\App\Models\User;

/**
 * Everything the reader gets wrong, it gets wrong while still returning rows.
 *
 * A dropped access scope, a sensitive column served because it was asked for by
 * name, a limit quietly trimmed — each of those produces a reply that reads
 * exactly like a correct one, and the only place the difference is visible is
 * here. So the tests below are about which rows and which columns leave the
 * application, and not one of them is about how the query is spelled.
 */

/**
 * @param  array<array-key, mixed>  $overrides
 */
function demoResource(array $overrides = []): DataResource
{
    return DataResource::factory()->create(array_merge([
        'key' => 'demo-records',
        'model' => DemoRecord::class,
        'modes' => [
            DataQueryMode::List->value,
            DataQueryMode::First->value,
            DataQueryMode::Count->value,
        ],
        'fields' => [
            ['name' => 'id', 'returned_by_default' => true, 'filterable' => true, 'sortable' => true, 'operators' => ['equals', 'in']],
            ['name' => 'name', 'returned_by_default' => true, 'filterable' => true, 'searchable' => true, 'sortable' => true, 'operators' => ['equals', 'contains']],
            ['name' => 'status', 'returned_by_default' => true, 'filterable' => true, 'operators' => ['equals']],
            ['name' => 'owner_id', 'filterable' => true, 'operators' => ['equals']],
            ['name' => 'secret_code', 'sensitive' => true, 'filterable' => true, 'operators' => ['equals']],
        ],
        'default_limit' => 10,
        'max_limit' => 100,
        'scopes' => null,
    ], $overrides));
}

/**
 * Built by hand rather than by a factory: Testbench does not resolve factories
 * for the workbench namespace, and the existing suite creates its users the
 * same way.
 */
function demoUser(string $email): User
{
    return User::create([
        'name' => $email,
        'email' => $email,
        'password' => 'secret-secret',
    ]);
}

function reader(): DataReader
{
    return new DataReader;
}

function anonymousContext(): RuntimeContext
{
    return RuntimeContext::forConversation(Conversation::factory()->create());
}

/**
 * The sensitive flag has to mean "never leaves", not "not included by default".
 * Naming the column explicitly is the exact request the flag was written to
 * answer, so it is the one that must be refused rather than honoured.
 */
it('never returns a sensitive column, not even when it is asked for by name', function (): void {
    demoResource();
    DemoRecord::create(['name' => 'Acme', 'secret_code' => 'S-1']);

    $explicit = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List, select: ['id', 'secret_code']),
        anonymousContext(),
    );

    expect($explicit->ok())->toBeFalse()
        ->and($explicit->error())->toContain('secret_code');

    $default = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List),
        anonymousContext(),
    );

    expect($default->ok())->toBeTrue()
        ->and($default->first())->not->toHaveKey('secret_code');
});

/**
 * An equality filter on a hidden column is an oracle: the value never comes
 * back, but the answer does, and "count where secret_code = X returns 1"
 * confirms the value without ever printing it. The same comparison built from
 * the server-side context is safe by construction — the person asking did not
 * choose the value and learns nothing from the result — which is why the two
 * cases are separated by where the value came from and not by the operator.
 */
it('refuses a filter on a sensitive field but honours the same comparison as an access scope', function (): void {
    demoResource();
    DemoRecord::create(['name' => 'Acme', 'secret_code' => 'S-1']);
    DemoRecord::create(['name' => 'Globex', 'secret_code' => 'S-2']);

    $probed = reader()->read(
        new DataQuery('demo-records', DataQueryMode::Count, filters: [
            ['field' => 'secret_code', 'operator' => 'equals', 'value' => 'S-1'],
        ]),
        anonymousContext(),
    );

    expect($probed->ok())->toBeFalse();

    demoResource(['key' => 'scoped-records', 'scopes' => [
        ['field' => 'secret_code', 'operator' => 'equals', 'source' => 'tenant.secret'],
    ]]);

    $scoped = reader()->read(
        new DataQuery('scoped-records', DataQueryMode::List),
        RuntimeContext::forConversation(Conversation::factory()->create(), null, ['secret' => 'S-1']),
    );

    expect($scoped->ok())->toBeTrue()
        ->and($scoped->rows())->toHaveCount(1)
        ->and($scoped->first()['name'])->toBe('Acme');
});

/**
 * Three refusals that each have a plausible, wrong repair: drop the field, use a
 * neighbouring operator, serve the maximum. Every one of those returns rows and
 * looks like an answer to the question that was asked.
 */
it('refuses an unknown field, an operator outside the list and a limit above the ceiling', function (): void {
    demoResource();
    DemoRecord::create(['name' => 'Acme']);

    $unknownField = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List, filters: [
            ['field' => 'secret_note', 'operator' => 'equals', 'value' => 'x'],
        ]),
        anonymousContext(),
    );

    $badOperator = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List, filters: [
            ['field' => 'status', 'operator' => 'contains', 'value' => 'op'],
        ]),
        anonymousContext(),
    );

    $tooMany = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List, limit: 101),
        anonymousContext(),
    );

    $unknownSelect = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List, select: ['id', 'nickname']),
        anonymousContext(),
    );

    expect($unknownField->ok())->toBeFalse()
        ->and($badOperator->ok())->toBeFalse()
        ->and($tooMany->ok())->toBeFalse()
        ->and($unknownSelect->ok())->toBeFalse();
});

/**
 * The installation-wide ceiling is the one an administrator chose for the whole
 * application; a resource row is edited far more often and by more people. If a
 * declaration could raise its own ceiling the setting would not be a limit at
 * all, only a default any form is free to overrule.
 */
it('takes the smaller of the resource ceiling and the installation ceiling', function (): void {
    config()->set('chatbot.data_resources.max_limit', 100);

    demoResource(['max_limit' => 500]);

    foreach (range(1, 12) as $index) {
        DemoRecord::create(['name' => 'Record '.$index]);
    }

    $overCeiling = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List, limit: 200),
        anonymousContext(),
    );

    $atCeiling = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List, limit: 100),
        anonymousContext(),
    );

    expect($overCeiling->ok())->toBeFalse()
        ->and($overCeiling->rows())->toBe([])
        ->and($atCeiling->ok())->toBeTrue()
        ->and($atCeiling->rows())->toHaveCount(12);
});

/**
 * The scope is the only thing standing between "show me my records" and "show me
 * the records", so it is applied to every query with no shape of request able to
 * skip it — the list and the count alike.
 */
it('applies the access scope to every mode', function (): void {
    $owner = demoUser('owner@example.test');
    $stranger = demoUser('stranger@example.test');

    demoResource(['scopes' => [
        ['field' => 'owner_id', 'operator' => 'equals', 'source' => 'actor.id'],
    ]]);

    DemoRecord::create(['name' => 'Mine', 'owner_id' => $owner->getKey()]);
    DemoRecord::create(['name' => 'Theirs', 'owner_id' => $stranger->getKey()]);

    $context = RuntimeContext::forConversation(Conversation::factory()->create(), $owner);

    $listed = reader()->read(new DataQuery('demo-records', DataQueryMode::List), $context);
    $counted = reader()->read(new DataQuery('demo-records', DataQueryMode::Count), $context);

    expect($listed->rows())->toHaveCount(1)
        ->and($listed->first()['name'])->toBe('Mine')
        ->and($counted->count())->toBe(1);
});

/**
 * `bot_answers` is nothing but the visitor's own replies stored under friendly
 * names. A visitor who can name their own owner id is a visitor who can name
 * somebody else's, and the read that results is valid, fast and someone else's
 * data — so the scope value is taken from the conversation's server-side link
 * and never from anything typed into it.
 */
it('ignores an owner id planted in the conversation answers', function (): void {
    $owner = demoUser('owner@example.test');
    $stranger = demoUser('stranger@example.test');

    demoResource(['scopes' => [
        ['field' => 'owner_id', 'operator' => 'equals', 'source' => 'conversation.contact_id'],
    ]]);

    DemoRecord::create(['name' => 'Mine', 'owner_id' => $owner->getKey()]);
    DemoRecord::create(['name' => 'Theirs', 'owner_id' => $stranger->getKey()]);

    $conversation = Conversation::factory()->create([
        'contact_type' => User::class,
        'contact_id' => $owner->getKey(),
        'bot_answers' => [
            'owner_id' => $stranger->getKey(),
            'contact_id' => $stranger->getKey(),
        ],
    ]);

    $result = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List),
        RuntimeContext::forConversation($conversation),
    );

    expect($result->rows())->toHaveCount(1)
        ->and($result->first()['name'])->toBe('Mine');
});

/**
 * The most expensive mistake available here, and it is a typo: `actor.tenant_id`
 * written where the host meant `tenant.id` is wrong in a way nobody notices,
 * because the query keeps working. It just stops being restricted, and one
 * resource quietly starts answering with the whole table.
 */
it('refuses the query when a scope source resolves to nothing', function (): void {
    demoResource(['scopes' => [
        ['field' => 'owner_id', 'operator' => 'equals', 'source' => 'actor.tenant_id'],
    ]]);

    DemoRecord::create(['name' => 'Mine', 'owner_id' => 1]);
    DemoRecord::create(['name' => 'Theirs', 'owner_id' => 2]);

    $result = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List),
        RuntimeContext::forConversation(Conversation::factory()->create(), demoUser('owner@example.test')),
    );

    expect($result->ok())->toBeFalse()
        ->and($result->rows())->toBe([]);
});

/**
 * An appended attribute exists in no migration and in no field list, so nobody
 * reviewing the declaration ever saw it. Serialising the model whole is the
 * cheapest way to put it in a reply anyway.
 */
it('keeps an appended model attribute out of the returned row', function (): void {
    demoResource();
    DemoRecord::create(['name' => 'Acme']);

    $row = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List),
        anonymousContext(),
    )->first();

    expect($row)->not->toBeNull()
        ->and(array_keys($row))->toBe(['id', 'name', 'status'])
        ->and($row)->not->toHaveKey('shouted_name');
});

/**
 * A bot that answers "I could not find anything" to a permission failure invites
 * the visitor to keep trying, hides the misconfiguration from whoever could fix
 * it, and says a record does not exist when the truth is that it may not be
 * shown. The scenario branches on `ok()`, so the two outcomes have to differ
 * there and not only in a log line.
 */
it('tells an empty result apart from a refused one', function (): void {
    demoResource();
    DemoRecord::create(['name' => 'Acme', 'status' => 'open']);

    $empty = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List, filters: [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'archived'],
        ]),
        anonymousContext(),
    );

    $refused = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List, filters: [
            ['field' => 'nonsense', 'operator' => 'equals', 'value' => 'archived'],
        ]),
        anonymousContext(),
    );

    expect($empty->ok())->toBeTrue()
        ->and($empty->isEmpty())->toBeTrue()
        ->and($empty->error())->toBeNull()
        ->and($refused->ok())->toBeFalse()
        ->and($refused->isEmpty())->toBeTrue()
        ->and($refused->error())->not->toBeNull();
});

/**
 * A lone per-cent sign typed into the chat would otherwise turn a lookup into a
 * full scan matching every row — a table scan the visitor chose, returned as
 * though it answered their question.
 */
it('defuses like wildcards arriving in a filter value', function (): void {
    demoResource();
    DemoRecord::create(['name' => 'Acme']);
    DemoRecord::create(['name' => 'Globex']);

    $result = reader()->read(
        new DataQuery('demo-records', DataQueryMode::List, filters: [
            ['field' => 'name', 'operator' => 'contains', 'value' => '%'],
        ]),
        anonymousContext(),
    );

    expect($result->ok())->toBeTrue()
        ->and($result->rows())->toBe([]);
});

/**
 * A child table is scoped through its parent, because it has nothing else.
 *
 * The case every normalised application meets on its second resource: a booking
 * belongs to a customer through its order and carries no customer column.
 * Without this the table has two possible declarations — unreachable, or
 * unscoped, which is the whole table — and the second is the one an author
 * reaches for when the first stops them getting their work done.
 *
 * The other record is what makes the assertion mean anything: a scope has to be
 * shown keeping somebody else's rows out, not merely letting the right ones
 * through.
 */
it('narrows a child resource through a scope on its parent', function (): void {
    $mine = DemoRecord::create(['name' => 'Mine', 'owner_id' => 7]);
    $theirs = DemoRecord::create(['name' => 'Theirs', 'owner_id' => 99]);

    DemoLine::create(['demo_record_id' => $mine->getKey(), 'title' => 'Ours']);
    DemoLine::create(['demo_record_id' => $theirs->getKey(), 'title' => 'Somebody else']);

    DataResource::factory()->create([
        'key' => 'demo-lines',
        'model' => DemoLine::class,
        'modes' => [DataQueryMode::List->value],
        'fields' => [['name' => 'title', 'returned_by_default' => true]],
        'scopes' => [[
            'relation' => 'record',
            'field' => 'owner_id',
            'operator' => 'equals',
            'source' => 'conversation.contact_id',
        ]],
    ]);

    $result = reader()->read(
        new DataQuery(resource: 'demo-lines', mode: DataQueryMode::List),
        RuntimeContext::forConversation(Conversation::factory()->create([
            'contact_type' => DemoRecord::class,
            'contact_id' => 7,
        ])),
    );

    expect($result->ok())->toBeTrue()
        ->and($result->count())->toBe(1)
        ->and($result->first()['title'])->toBe('Ours');
});

/**
 * A relation the model has not got refuses the read instead of widening it.
 *
 * The failure guarded here is a typo in a config file — `recrods` where the host
 * meant `record` — and only two outcomes are available to it: no rows, or every
 * row. Anything that swallowed the exception would choose the second.
 */
it('refuses a read whose scope names a relation the model has not got', function (): void {
    DemoLine::create([
        'demo_record_id' => DemoRecord::create(['name' => 'Mine', 'owner_id' => 7])->getKey(),
        'title' => 'Ours',
    ]);

    DataResource::factory()->create([
        'key' => 'demo-lines',
        'model' => DemoLine::class,
        'modes' => [DataQueryMode::List->value],
        'fields' => [['name' => 'title', 'returned_by_default' => true]],
        'scopes' => [[
            'relation' => 'recrods',
            'field' => 'owner_id',
            'source' => 'conversation.contact_id',
        ]],
    ]);

    $result = reader()->read(
        new DataQuery(resource: 'demo-lines', mode: DataQueryMode::List),
        RuntimeContext::forConversation(Conversation::factory()->create([
            'contact_type' => DemoRecord::class,
            'contact_id' => 7,
        ])),
    );

    expect($result->ok())->toBeFalse()
        ->and($result->rows())->toBe([]);
});

/**
 * A declaration belongs in a file that goes through a pull request, not only in
 * a form somebody can edit in production. The sync is what makes the file the
 * authority, and it matches on the business key because declarations travel
 * between installations whose primary keys have nothing in common.
 */
it('writes configured resources into the table under their business key', function (): void {
    config()->set('chatbot.data_resources.resources', [
        [
            'key' => 'demo-records',
            'label' => 'Demo records',
            'model' => DemoRecord::class,
            'modes' => [DataQueryMode::List->value],
            'fields' => [['name' => 'name', 'returned_by_default' => true]],
            'max_limit' => 25,
        ],
    ]);

    (new DataResourceSync)->sync();
    (new DataResourceSync)->sync();

    $resources = DataResource::query()->where('key', 'demo-records')->get();

    expect($resources)->toHaveCount(1)
        ->and($resources->first()->max_limit)->toBe(25)
        ->and($resources->first()->model)->toBe(DemoRecord::class);
});
