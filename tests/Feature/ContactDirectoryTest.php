<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Channels\InboundMessage;
use MB\FilamentChatbot\Channels\MessageRouter;
use MB\FilamentChatbot\Chat\ContactCard;
use MB\FilamentChatbot\Contracts\ContactDirectory;
use MB\FilamentChatbot\Models\Conversation;
use Workbench\App\Models\DemoRecord;

/**
 * A directory that recognises one phone number and nothing else.
 */
function directoryFor(?DemoRecord $record, bool $explodes = false): ContactDirectory
{
    return new class($record, $explodes) implements ContactDirectory
    {
        public function __construct(private readonly ?DemoRecord $record, private readonly bool $explodes) {}

        public function identify(Conversation $conversation, InboundMessage $message): ?Model
        {
            if ($this->explodes) {
                throw new RuntimeException('The customer database is down.');
            }

            return $conversation->contact_phone === '+70000000000' ? $this->record : null;
        }

        public function describe(Conversation $conversation): ?ContactCard
        {
            return new ContactCard('Anna Petrova', 'client since 2019', '/clients/1');
        }
    };
}

function saysHello(string $phone = ''): ?Conversation
{
    app(MessageRouter::class)->receive(new InboundMessage(
        channel: 'site',
        externalConversationId: 'thread-1',
        externalMessageId: uniqid('m', true),
        body: 'hello',
        phone: $phone ?: null,
    ));

    return Conversation::query()->where('external_id', 'thread-1')->first();
}

/**
 * A stranger who turns out to be a customer gets filed under them.
 *
 * Without this the morph is written only by a scenario node or a signed
 * identity, so a customer of eight years who opens the chat on a public page is
 * a stranger to the desk — and every operator answering them starts by searching
 * the CRM for a record the application could have named itself.
 */
it('links a conversation to the record the application recognises', function (): void {
    $record = DemoRecord::create(['name' => 'Anna Petrova']);
    app()->instance(ContactDirectory::class, directoryFor($record));

    $conversation = saysHello('+70000000000');

    expect($conversation?->contact_type)->toBe(DemoRecord::class)
        ->and((string) $conversation?->contact_id)->toBe((string) $record->getKey());
});

/**
 * Recognising nobody is the ordinary answer, not a failure. Most people who open
 * a chat on a public page are strangers, and a thread that stayed unlinked has
 * to keep working exactly as it always did.
 */
it('leaves a conversation unlinked when nobody is recognised', function (): void {
    app()->instance(ContactDirectory::class, directoryFor(DemoRecord::create(['name' => 'Anna'])));

    $conversation = saysHello('+79999999999');

    expect($conversation?->contact_type)->toBeNull()
        ->and($conversation?->contact_id)->toBeNull();
});

/**
 * A link already made is never overruled by a lookup.
 *
 * What is there came from a signed identity, from an operator or from a
 * scenario, and each knows more than a match on something typed into a chat box.
 * Letting the directory win would refile a thread under the wrong customer on
 * the strength of a mistyped digit — and nothing on any screen would say it had
 * moved.
 */
it('never repoints a conversation that is already linked', function (): void {
    $first = DemoRecord::create(['name' => 'The right one']);
    $second = DemoRecord::create(['name' => 'The lookup\'s guess']);

    app()->instance(ContactDirectory::class, directoryFor($second));

    Conversation::factory()->create([
        'channel' => 'site',
        'external_id' => 'thread-1',
        'contact_type' => DemoRecord::class,
        'contact_id' => $first->getKey(),
    ]);

    $conversation = saysHello('+70000000000');

    expect((string) $conversation?->contact_id)->toBe((string) $first->getKey());
});

/**
 * A directory that falls over does not cost the customer their message.
 *
 * It runs inside the transaction that stores what was said, so an exception
 * escaping would roll that message back and answer the channel with a failure —
 * after which the webhook redelivers, and it throws again. The contract asks for
 * a directory that never throws; this is the guarantee behind the request, and
 * the assertion is that the message survived, not that the lookup did.
 */
it('stores the message even when the directory throws', function (): void {
    app()->instance(ContactDirectory::class, directoryFor(null, explodes: true));

    $conversation = saysHello('+70000000000');

    expect($conversation)->not->toBeNull()
        ->and($conversation?->messages()->count())->toBe(1)
        ->and($conversation?->contact_type)->toBeNull();
});

/**
 * Out of the box the package recognises nobody and says nothing about anybody.
 *
 * The default is a decision rather than a placeholder: a package that guessed
 * which of the host's records a stranger might be would file conversations under
 * real customers on the strength of a phone number typed into a public chat box.
 */
it('recognises nobody until a host says otherwise', function (): void {
    DemoRecord::create(['name' => 'Anna Petrova']);

    $conversation = saysHello('+70000000000');

    expect($conversation?->contact_type)->toBeNull()
        ->and(app(ContactDirectory::class)->describe($conversation))->toBeNull();
});
