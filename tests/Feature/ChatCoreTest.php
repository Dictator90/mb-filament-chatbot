<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use Workbench\App\Models\DemoRecord;
use Workbench\App\Models\User;

/**
 * The morphs are the package's whole contract with the host: it stores what it
 * was given and hands it back untouched. Read the pair back wrong and the
 * operator opens a conversation attached to somebody else's record — nothing
 * throws, and there is no second place the right answer could come from.
 */
it('stores a host record on the contact and subject morphs and reads it back', function (): void {
    $record = DemoRecord::create(['name' => 'Acme Holdings']);

    $conversation = Conversation::factory()->create([
        'contact_type' => $record->getMorphClass(),
        'contact_id' => $record->getKey(),
        'subject_type' => $record->getMorphClass(),
        'subject_id' => $record->getKey(),
    ]);

    $fresh = Conversation::query()->findOrFail($conversation->getKey());

    expect($fresh->contact)->toBeInstanceOf(DemoRecord::class);
    expect($fresh->contact->name)->toBe('Acme Holdings');
    expect($fresh->subject)->toBeInstanceOf(DemoRecord::class);
    expect($fresh->subject->getKey())->toBe($record->getKey());
});

/**
 * The package must never resolve those morphs on its own account. The moment it
 * does, it has assumed the host's record carries a name, an e-mail, a status —
 * and it stops installing into applications whose records carry none of that.
 *
 * Two things prove it here at once: the conversation's own naming ignores a
 * linked record that clearly has a name, and a conversation whose host record
 * has been deleted still loads and still names itself. Were the package reaching
 * through the morph, the second case would be a null-property fatal on a chat
 * screen the operator can no longer open.
 */
it('never names a conversation from the record the host linked to it', function (): void {
    $record = DemoRecord::create(['name' => 'Acme Holdings']);

    $conversation = Conversation::factory()->anonymous()->create([
        'contact_type' => $record->getMorphClass(),
        'contact_id' => $record->getKey(),
    ]);

    expect($conversation->title())->toBe('Conversation #'.$conversation->getKey());

    $record->delete();

    $orphaned = Conversation::query()->findOrFail($conversation->getKey());

    expect($orphaned->contact)->toBeNull();
    expect($orphaned->contact_id)->toBe($record->getKey());
    expect($orphaned->title())->toBe('Conversation #'.$conversation->getKey());
});

/**
 * Webhooks retry. A delivery that timed out on our side arrives again with the
 * same provider id, and without the unique index the visitor would see their
 * own message twice and the bot would answer it twice. The scope of the index is
 * the point: the id is only unique inside one conversation, because two channels
 * — or two chats on the same channel — number their messages independently.
 */
it('refuses a repeated delivery of the same external message id', function (): void {
    $conversation = Conversation::factory()->create();

    Message::factory()->for($conversation)->create(['external_id' => 'tg-4211']);

    expect(fn () => Message::factory()->for($conversation)->create(['external_id' => 'tg-4211']))
        ->toThrow(QueryException::class);

    $elsewhere = Conversation::factory()->create();

    Message::factory()->for($elsewhere)->create(['external_id' => 'tg-4211']);

    expect(Message::query()->where('external_id', 'tg-4211')->count())->toBe(2);
});

/**
 * The unread list is what an operator works from. A thread missing from it is a
 * customer waiting with nobody aware of it, and a thread wrongly in it teaches
 * the operator to ignore the badge — both fail without a single error anywhere.
 */
it('lists only conversations whose incoming message is newer than the read mark', function (): void {
    $waiting = Conversation::factory()->create([
        'last_incoming_at' => now(),
        'agent_read_at' => now()->subMinute(),
    ]);

    $neverOpened = Conversation::factory()->create([
        'last_incoming_at' => now(),
        'agent_read_at' => null,
    ]);

    $answered = Conversation::factory()->create([
        'last_incoming_at' => now()->subMinute(),
        'agent_read_at' => now(),
    ]);

    $silent = Conversation::factory()->create([
        'last_incoming_at' => null,
        'agent_read_at' => null,
    ]);

    $unread = Conversation::query()->unread()->pluck('id')->all();

    expect($unread)->toContain($waiting->getKey());
    expect($unread)->toContain($neverOpened->getKey());
    expect($unread)->not->toContain($answered->getKey());
    expect($unread)->not->toContain($silent->getKey());

    expect($waiting->hasUnread())->toBeTrue();
    expect($answered->hasUnread())->toBeFalse();
    expect($silent->hasUnread())->toBeFalse();
});

/**
 * Each source in turn, because an empty one has to hand over rather than win.
 * A blank caption in the conversation list is not a cosmetic defect: the rows
 * are told apart by that caption, and an operator cannot pick the right one out
 * of a column of blanks.
 */
it('falls through to the next name source when the previous one is empty', function (): void {
    $named = Conversation::factory()->create(['display_name' => 'Maria K.']);

    $handle = Conversation::factory()->create([
        'display_name' => null,
        'username' => 'maria_k',
    ]);

    $phone = Conversation::factory()->create([
        'display_name' => null,
        'username' => null,
        'contact_phone' => '+79494157404',
    ]);

    $email = Conversation::factory()->create([
        'display_name' => null,
        'username' => null,
        'contact_phone' => null,
        'contact_email' => 'maria@example.test',
    ]);

    $blank = Conversation::factory()->anonymous()->create();

    expect($named->title())->toBe('Maria K.');
    expect($handle->title())->toBe('@maria_k');
    expect($phone->title())->toBe('+79494157404');
    expect($email->title())->toBe('maria@example.test');
    expect($blank->title())->toBe('Conversation #'.$blank->getKey());
});

/**
 * The operator is whoever the host says it is. A literal `App\Models\User` here
 * would work in every application that happens to use that name and fail on the
 * rest — and it fails at the moment a manager opens a conversation, not at
 * install time when someone could still do something about it.
 */
it('resolves the assignee against the model the host configured', function (): void {
    config()->set('chatbot.user_model', User::class);

    $operator = User::create([
        'name' => 'Operator',
        'email' => 'operator@example.test',
        'password' => 'secret-for-tests',
    ]);

    $conversation = Conversation::factory()->create(['assigned_to_id' => $operator->getKey()]);

    expect($conversation->assignedTo()->getRelated())->toBeInstanceOf(User::class);
    expect($conversation->assignedTo->getKey())->toBe($operator->getKey());
});
