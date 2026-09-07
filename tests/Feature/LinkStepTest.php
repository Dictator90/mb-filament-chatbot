<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\DataResource;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use Workbench\App\Models\DemoRecord;

/**
 * A conversation that finds a record and cannot say so is where the data nodes
 * stopped. The key sat in a variable and died with the run; the thread an
 * operator opened afterwards still said nothing about which customer or which
 * order it was.
 *
 * What this node writes is a morph pair the package never dereferences, which is
 * exactly why the checks below are about who may write it rather than about what
 * comes back: the host renders whatever the columns name, to whoever is in the
 * chat.
 *
 * @param  array<array-key, mixed>  $overrides
 */
function linkableResource(array $overrides = []): DataResource
{
    return DataResource::factory()->create(array_merge([
        'key' => 'demo-records',
        'model' => DemoRecord::class,
        'modes' => ['list', 'first'],
        'fields' => [
            ['name' => 'id', 'returned_by_default' => true],
            ['name' => 'name', 'returned_by_default' => true],
            ['name' => 'owner_id'],
        ],
        'scopes' => null,
    ], $overrides));
}

/**
 * A bot whose only scenario remembers a key and links it.
 */
function linkingBot(string $key, string $role = 'subject', bool $replace = false): Bot
{
    $bot = Bot::factory()->create();

    $scenario = Scenario::factory()->create([
        'bot_id' => $bot->getKey(),
        'slug' => 'link',
        'is_active' => true,
        'priority' => 50,
    ]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'start',
        'type' => 'start',
        'next_key' => 'remember',
        'config' => ['trigger' => 'fallback'],
    ]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'remember',
        'type' => 'set',
        'next_key' => 'attach',
        'config' => ['name' => 'record', 'value' => $key],
    ]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'attach',
        'type' => 'link',
        'next_key' => null,
        'config' => [
            'resource' => 'demo-records',
            'role' => $role,
            'key' => 'record',
            'replace' => $replace,
        ],
    ]);

    return $bot;
}

/**
 * The plain case, and the one the node exists for: after this, the thread names
 * the record instead of a number somebody would have to read out of a message.
 *
 * The morph map is enforced here on purpose. An application that maps its models
 * stores aliases, and a package writing the class name into a mapped column
 * produces rows the host's own relation resolves to null — nothing fails at
 * write time, and the link is simply missing months later wherever it is read.
 */
it('points the conversation at the record, under the name the application maps it to', function (): void {
    Relation::morphMap(['demo' => DemoRecord::class]);

    linkableResource();
    $record = DemoRecord::create(['name' => 'A record']);

    $conversation = Conversation::factory()->create([
        'bot_id' => linkingBot((string) $record->getKey())->getKey(),
    ]);

    visitorSays($conversation, 'hello');

    $conversation->refresh();

    expect($conversation->subject_type)->toBe('demo')
        ->and((string) $conversation->subject_id)->toBe((string) $record->getKey());
});

/**
 * The morph map is process-wide and survives a rolled-back database, so it is
 * cleared here rather than at the end of the test that sets it: an assertion
 * failing before the reset would leave every later test in this run reading
 * aliases nothing else in the suite declares.
 */
afterEach(function (): void {
    Relation::morphMap([], merge: false);
});

/**
 * The check the whole node rests on.
 *
 * The key comes from a variable, and a variable is filled by whatever the
 * scenario put there — including, one node earlier, something the visitor typed.
 * Without the resource's own access scopes, a visitor who names somebody else's
 * order number gets the conversation pointed at it, and the host then shows that
 * record to them because that is what the column says. So the link goes through
 * the same reachability rule as a write, and an unreachable key links to nothing
 * at all.
 */
it('refuses a record the conversation is not allowed to reach', function (): void {
    linkableResource(['scopes' => [['field' => 'owner_id', 'source' => 'conversation.contact_id']]]);

    $mine = DemoRecord::create(['name' => 'Mine', 'owner_id' => 7]);
    $theirs = DemoRecord::create(['name' => 'Theirs', 'owner_id' => 99]);

    $conversation = Conversation::factory()->create([
        'bot_id' => linkingBot((string) $theirs->getKey())->getKey(),
        'contact_type' => DemoRecord::class,
        'contact_id' => 7,
    ]);

    visitorSays($conversation, 'hello');

    expect($conversation->refresh()->subject_type)->toBeNull()
        ->and($conversation->subject_id)->toBeNull();

    $allowed = Conversation::factory()->create([
        'bot_id' => linkingBot((string) $mine->getKey())->getKey(),
        'contact_type' => DemoRecord::class,
        'contact_id' => 7,
    ]);

    visitorSays($allowed, 'hello');

    expect((string) $allowed->refresh()->subject_id)->toBe((string) $mine->getKey());
});

/**
 * A link already made is not quietly moved.
 *
 * What is there came from the channel, from the host, or from an earlier and
 * better-informed turn. A later turn repointing it is how a thread ends up filed
 * under the wrong customer, with nothing on any screen to say it moved — so the
 * author has to switch replacing on, node by node, and see themselves do it.
 */
it('leaves an existing link alone unless the node was told it may replace it', function (): void {
    linkableResource();

    $first = DemoRecord::create(['name' => 'A record']);
    $second = DemoRecord::create(['name' => 'A record']);

    $guarded = Conversation::factory()->create([
        'bot_id' => linkingBot((string) $second->getKey(), role: 'contact')->getKey(),
        'contact_type' => DemoRecord::class,
        'contact_id' => $first->getKey(),
    ]);

    visitorSays($guarded, 'hello');

    expect((string) $guarded->refresh()->contact_id)->toBe((string) $first->getKey());

    $replacing = Conversation::factory()->create([
        'bot_id' => linkingBot((string) $second->getKey(), role: 'contact', replace: true)->getKey(),
        'contact_type' => DemoRecord::class,
        'contact_id' => $first->getKey(),
    ]);

    visitorSays($replacing, 'hello');

    expect((string) $replacing->refresh()->contact_id)->toBe((string) $second->getKey());
});
