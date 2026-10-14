<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use MB\FilamentChatbot\Contracts\ContactPicker;
use MB\FilamentChatbot\Contracts\ContactRegistrar;
use MB\FilamentChatbot\Filament\Pages\ChatInbox;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use Workbench\App\Models\User;

/**
 * A host that files people, standing in for the application's own directory.
 */
function registrar(bool $enough = true, bool $creates = true): ContactRegistrar
{
    return new class($enough, $creates) implements ContactRegistrar
    {
        public function __construct(
            private readonly bool $enough,
            private readonly bool $creates,
        ) {}

        public function canRegister(Conversation $conversation): bool
        {
            return $this->enough;
        }

        public function register(Conversation $conversation): ?Model
        {
            if (! $this->creates) {
                return null;
            }

            $record = User::factory()->create();

            $conversation->forceFill([
                'contact_type' => $record->getMorphClass(),
                'contact_id' => $record->getKey(),
            ])->save();

            return $record;
        }
    };
}

function deskFor(Conversation $conversation)
{
    signIn();

    Message::factory()->for($conversation)->create();

    return Livewire::test(ChatInbox::class)->call('selectConversation', $conversation->getKey());
}

/**
 * The operator files the person without retyping what the thread already says.
 *
 * Before this the only way into the directory was a scenario node, so a visitor
 * who arrived by any other path — pressed "call a person" straight away, or was
 * taken over mid-question — existed nowhere afterwards. The operator retyped
 * the name and the number into another screen, which is how one customer ends
 * up filed twice under two spellings.
 */
it('files the person in the thread and links the conversation', function (): void {
    app()->instance(ContactRegistrar::class, registrar());

    $conversation = Conversation::factory()->create(['last_message_at' => now()]);

    deskFor($conversation)->callAction('createContact');

    expect($conversation->refresh()->contact_id)->not->toBeNull();
});

/**
 * An installation that files nobody is offered nothing.
 *
 * Binding the registrar is optional on purpose — a helpdesk whose contacts are
 * employees creates them nowhere near a chat — and a button that calls nothing
 * is worse than no button.
 */
it('offers nothing when the host files nobody', function (): void {
    $conversation = Conversation::factory()->create(['last_message_at' => now()]);

    signIn();
    Message::factory()->for($conversation)->create();

    $desk = Livewire::test(ChatInbox::class)->call('selectConversation', $conversation->getKey());

    expect($desk->instance()->canCreateContact())->toBeFalse();
});

/**
 * A thread already filed is not offered again.
 *
 * The second card is the thing this prevents, and it is the failure nobody
 * notices: both cards look right, and the customer's history is split between
 * them for ever after.
 */
it('does not offer to file a conversation that already has a contact', function (): void {
    app()->instance(ContactRegistrar::class, registrar());

    $contact = User::factory()->create();

    $conversation = Conversation::factory()->create([
        'last_message_at' => now(),
        'contact_type' => $contact->getMorphClass(),
        'contact_id' => $contact->getKey(),
    ]);

    expect(deskFor($conversation)->instance()->canCreateContact())->toBeFalse();
});

/**
 * Too little known is the host's answer, not this package's guess.
 *
 * What counts as enough — a name the person gave themselves, a way of reaching
 * them, a setting the agency can switch off — is a question only the host can
 * answer, and asking it here would mean two thresholds disagreeing.
 */
it('leaves the threshold to the host', function (): void {
    app()->instance(ContactRegistrar::class, registrar(enough: false));

    $conversation = Conversation::factory()->create(['last_message_at' => now()]);

    expect(deskFor($conversation)->instance()->canCreateContact())->toBeFalse();
});

/**
 * A refusal is reported, not swallowed.
 *
 * The host may decline for a reason of its own — a setting, a duplicate it
 * linked instead — and an operator who pressed a button and saw nothing happen
 * presses it again.
 */
it('says so when the host declines to file anybody', function (): void {
    app()->instance(ContactRegistrar::class, registrar(creates: false));

    $conversation = Conversation::factory()->create(['last_message_at' => now()]);

    deskFor($conversation)->callAction('createContact')->assertNotified();

    expect($conversation->refresh()->contact_id)->toBeNull();
});

/**
 * Хозяин, умеющий искать и привязывать записи вручную.
 */
function picker(bool $resolves = true): ContactPicker
{
    return new class($resolves) implements ContactPicker
    {
        public function __construct(private readonly bool $resolves) {}

        public function search(string $term): array
        {
            return ['7' => 'Нина Петрова · +7 999 000-00-00'];
        }

        public function label(string $value): ?string
        {
            return $value === '7' ? 'Нина Петрова · +7 999 000-00-00' : null;
        }

        public function attach(Conversation $conversation, string $value): ?Model
        {
            if (! $this->resolves) {
                return null;
            }

            $record = User::factory()->create();

            $conversation->forceFill([
                'contact_type' => $record->getMorphClass(),
                'contact_id' => $record->getKey(),
            ])->save();

            return $record;
        }
    };
}

/**
 * Разговор, привязанный не к тому человеку, оператор перевешивает сам.
 *
 * Узнавание ошибается обыденно: номер принадлежит мужу, коллеге или тому, у
 * кого он был раньше. Пока перепривязки не было, у оператора оставались два
 * плохих выхода — отвечать клиенту под чужой историей или потерять связь
 * вместе с историей, ради которой разговор и открывали.
 */
it('перевешивает разговор на выбранную карточку', function (): void {
    app()->instance(ContactPicker::class, picker());

    $wrong = User::factory()->create();

    $conversation = Conversation::factory()->create([
        'last_message_at' => now(),
        'contact_type' => $wrong->getMorphClass(),
        'contact_id' => $wrong->getKey(),
    ]);

    deskFor($conversation)->callAction('linkContact', ['contact' => '7']);

    expect($conversation->refresh()->contact_id)->not->toBe($wrong->getKey());
});

/**
 * Без привязанного искателя кнопки нет вовсе: пакет не умеет искать в чужом
 * справочнике и не должен делать вид, что умеет.
 */
it('не предлагает перепривязку, когда хозяин не умеет искать', function (): void {
    $conversation = Conversation::factory()->create(['last_message_at' => now()]);

    expect(deskFor($conversation)->instance()->canLinkContact())->toBeFalse();
});

/**
 * Запись, исчезнувшая между поиском и выбором, не роняет разговор и не молчит.
 */
it('говорит, когда выбранной карточки уже нет', function (): void {
    app()->instance(ContactPicker::class, picker(resolves: false));

    $conversation = Conversation::factory()->create(['last_message_at' => now()]);

    deskFor($conversation)->callAction('linkContact', ['contact' => '7'])->assertNotified();

    expect($conversation->refresh()->contact_id)->toBeNull();
});

/**
 * Снять неверную привязку можно, не подбирая взамен верную.
 *
 * Разговор, указывающий не на того человека, хуже разговора, не указывающего
 * ни на кого: всё, что оператор читает над ним — история, имя, карточка, —
 * тогда про кого-то другого. А нужной записи может и не быть под рукой.
 */
it('снимает привязку разговора', function (): void {
    $wrong = User::factory()->create();

    $conversation = Conversation::factory()->create([
        'last_message_at' => now(),
        'contact_type' => $wrong->getMorphClass(),
        'contact_id' => $wrong->getKey(),
    ]);

    deskFor($conversation)->callAction('unlinkContact');

    expect($conversation->refresh()->contact_id)->toBeNull()
        ->and($conversation->contact_type)->toBeNull();
});

/**
 * На разговоре без карточки отвязывать нечего, и кнопки там нет.
 */
it('не предлагает отвязку там, где привязки нет', function (): void {
    $conversation = Conversation::factory()->create(['last_message_at' => now()]);

    expect(deskFor($conversation)->instance()->canUnlinkContact())->toBeFalse();
});
