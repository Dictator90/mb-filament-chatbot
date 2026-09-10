<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use MB\FilamentChatbot\Chat\OperatorReply;
use MB\FilamentChatbot\ChatbotPlugin;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Filament\Pages\ChatInbox;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use Workbench\App\Models\User;

/**
 * How many queries it takes to draw the list of conversations.
 *
 * The component is rendered once before counting. A cold render pays for the
 * session, the panel's own lookups and Livewire's boot, and those would drown
 * the difference the test is looking for.
 */
function inboxListQueries(int $conversations): int
{
    Conversation::factory()->count($conversations)->create(['last_message_at' => now()])
        ->each(fn (Conversation $conversation) => Message::factory()->for($conversation)->create());

    Livewire::test(ChatInbox::class)->assertOk();

    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    Livewire::test(ChatInbox::class)->assertOk();

    return $count;
}

/**
 * The reply is what silences the bot, and nothing on screen says so.
 *
 * `BotEngine` keeps quiet while a conversation has an owner or has been handed
 * off. If answering did not set both, the bot would carry on running its
 * scenario over the operator's reply — the customer gets two answers to one
 * question, from two different voices, and the transcript is the only place it
 * shows.
 */
it('stops the bot from answering over an operator who has replied', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->botRunning()->create();

    app(OperatorReply::class)->send($conversation, 'Let me check that for you.', $user);

    $conversation->refresh();

    expect($conversation->assigned_to_id)->toBe($user->getKey())
        ->and($conversation->bot_state)->toBe(BotState::HandedOff);

    expect(visitorSays($conversation, 'thanks'))->toBeFalse();
});

/**
 * The author is not decoration: reports count operator work by it, and the
 * engine's own silence rule reads it. An operator reply filed as anything else
 * looks like the bot answered.
 */
it('files an operator reply as outgoing, authored by the person who typed it', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create();

    $message = app(OperatorReply::class)->send($conversation, 'On its way.', $user);

    expect($message->author)->toBe(MessageAuthor::Operator)
        ->and($message->isIncoming())->toBeFalse()
        ->and($message->user_id)->toBe($user->getKey())
        ->and($message->body)->toBe('On its way.');
});

/**
 * An empty body would be delivered as a real message — a notification with
 * nothing in it — and would leave the operator looking at their own blank
 * bubble believing they had answered.
 */
it('refuses to send a reply with nothing in it', function (): void {
    $conversation = Conversation::factory()->create();

    expect(fn () => app(OperatorReply::class)->send($conversation, "  \n ", User::factory()->create()))
        ->toThrow(InvalidArgumentException::class);

    expect($conversation->messages()->count())->toBe(0);
});

/**
 * Helping out on a colleague's conversation must not quietly transfer it: the
 * owner is who the rest of the desk routes by, and a stolen one takes the
 * conversation off the colleague's own list without anybody being told.
 */
it('leaves a conversation with the colleague who already owns it', function (): void {
    $owner = User::factory()->create();
    $helper = User::factory()->create();
    $conversation = Conversation::factory()->create(['assigned_to_id' => $owner->getKey()]);

    app(OperatorReply::class)->send($conversation, 'Adding a note.', $helper);

    expect($conversation->refresh()->assigned_to_id)->toBe($owner->getKey());
});

/**
 * A page written and never added to the plugin leaves the suite green and the
 * menu short, so the list is read from the plugin rather than named here.
 */
it('registers the desk with the panel and opens it', function (): void {
    signIn();

    expect((new ChatbotPlugin)->pages())->toContain(ChatInbox::class);

    $this->get(ChatInbox::getUrl())->assertOk();
});

/**
 * Rehearsal threads written by the quality runner look exactly like real ones,
 * and an operator who opens one answers a customer who does not exist. The
 * global scope is what keeps them out; this proves the desk has not lifted it.
 */
it('keeps quality-check rehearsals off the desk', function (): void {
    signIn();

    $real = Conversation::factory()->create(['last_message_at' => now()]);
    $rehearsal = Conversation::factory()->create([
        'last_message_at' => now(),
        'meta' => [Conversation::QUALITY_META_KEY => 7],
    ]);

    $listed = Livewire::test(ChatInbox::class)->instance()->conversations->pluck('id');

    expect($listed)->toContain($real->getKey())
        ->and($listed)->not->toContain($rehearsal->getKey());
});

/**
 * "Unanswered" is a comparison of two timestamps, and getting it backwards is
 * invisible: the filter still returns conversations, just the wrong half — the
 * ones already dealt with, while the customer waiting since this morning is
 * hidden by the very filter meant to find them.
 */
it('shows only conversations whose last word came from the other side', function (): void {
    signIn();

    $waiting = Conversation::factory()->create([
        'last_message_at' => now(),
        'last_incoming_at' => now(),
        'agent_read_at' => null,
    ]);

    $answered = Conversation::factory()->create([
        'last_message_at' => now(),
        'last_incoming_at' => now()->subMinutes(10),
        'agent_read_at' => now(),
    ]);

    $listed = Livewire::test(ChatInbox::class)
        ->set('onlyUnread', true)
        ->instance()
        ->conversations
        ->pluck('id');

    expect($listed)->toContain($waiting->getKey())
        ->and($listed)->not->toContain($answered->getKey());
});

/**
 * Reading is what marking read means. Left to a button, the unread count would
 * measure who remembered to press it, and an operator would keep being sent
 * back to conversations they have already answered.
 */
it('marks a conversation read as soon as it is opened', function (): void {
    signIn();

    $conversation = Conversation::factory()->unread()->create();

    Livewire::test(ChatInbox::class)->call('selectConversation', $conversation->getKey());

    expect($conversation->refresh()->agent_read_at)->not->toBeNull()
        ->and($conversation->hasUnread())->toBeFalse();
});

/**
 * Handing back needs both halves: the engine stays silent while a conversation
 * has an owner *or* is marked handed off. Doing one and not the other leaves the
 * screen claiming the bot is answering while the visitor talks to nobody.
 */
it('gives a conversation back to the bot, owner and all', function (): void {
    $user = User::factory()->create();
    signIn($user);

    $bot = Bot::factory()->create();
    $conversation = Conversation::factory()->handedOff()->create([
        'bot_id' => $bot->getKey(),
        'assigned_to_id' => $user->getKey(),
        'last_message_at' => now(),
    ]);

    Livewire::test(ChatInbox::class)
        ->call('selectConversation', $conversation->getKey())
        ->callAction('returnToBot');

    $conversation->refresh();

    expect($conversation->assigned_to_id)->toBeNull()
        ->and($conversation->bot_state)->toBe(BotState::Active)
        ->and($conversation->botIsSilent())->toBeFalse();
});

/**
 * The badge is counted per person and held in cache for a few seconds. With a
 * key that does not name the viewer, the first person to open the panel hands
 * their own number — including conversations assigned to them personally — to
 * everybody else for the life of the entry.
 */
it('counts the unread badge for the person looking at it', function (): void {
    $mine = User::factory()->create();
    $colleague = User::factory()->create();

    Conversation::factory()->unread()->create([
        'assigned_to_id' => $mine->getKey(),
        'last_message_at' => now(),
    ]);

    signIn($mine);
    expect(ChatInbox::getNavigationBadge())->toBe('1');

    signIn($colleague);
    expect(ChatInbox::getNavigationBadge())->toBeNull();
});

/**
 * The circle beside each conversation always has something in it.
 *
 * It is decoration with one hard requirement: never empty. A rail of twenty
 * threads is scanned by the shape and the letters rather than read, and an empty
 * circle is not a quiet default — it reads as a hole in the page, which is how
 * it gets reported.
 *
 * The cases below are the ones the desk actually meets. A thread from the
 * website has no name at all until somebody types one; one from Telegram has a
 * handle with an `@` in front of it, and one from the widget's form may have
 * only a phone number — take the first two characters blindly and every row
 * from a channel shows the same two.
 */
it('always has something to draw in the circle beside a conversation', function (string $field, ?string $value, string $expected): void {
    $conversation = Conversation::factory()->create([
        'display_name' => null,
        'username' => null,
        'contact_phone' => null,
        'contact_email' => null,
        $field => $value,
    ]);

    expect($conversation->initials())->toBe($expected);
})->with([
    'a full name' => ['display_name', 'Мария Доронина', 'МД'],
    'one name' => ['display_name', 'Мария', 'МА'],
    'a handle' => ['username', 'mdoronina', 'MD'],
    'a phone number' => ['contact_phone', '+79495551122', '79'],
    'an e-mail' => ['contact_email', 'maria@example.test', 'MA'],
]);

/**
 * A name that is nothing but punctuation still leaves something in the circle.
 *
 * It is not a hypothetical: the leading `@` and `+` are stripped because every
 * handle and every phone number carries one, and a visitor whose name in the
 * channel is «+» or «@» is left with nothing at all after that. An empty circle
 * is not a quiet default — it is a hole in the rail, and it will be reported as
 * a broken page rather than as a person who did not give their name.
 */
it('leaves something in the circle when the name is only punctuation', function (): void {
    $conversation = Conversation::factory()->create([
        'display_name' => '+ @',
        'username' => null,
        'contact_phone' => null,
        'contact_email' => null,
    ]);

    expect($conversation->initials())->toBe('?');
});

/**
 * The line a visitor quoted is drawn at the desk as well as in the widget.
 *
 * For some replies the quote *is* the content: "this one", against a message
 * from an hour ago. Without it on this screen the operator reads a two-word
 * answer with nothing to attach it to, answers the most recent question instead,
 * and there is no sign anywhere that the visitor had pointed at a different one.
 *
 * Counted rather than looked for, and that is the whole difficulty of testing
 * this screen: the quoted message is in the thread anyway, so `assertSee` of its
 * text passes whether or not anything quoted it — and the page carries its own
 * stylesheet, so `assertSeeHtml` of the class name passes on the rule that
 * styles it. Twice on the page means once as a message and once as a quote.
 */
it('shows the operator which message a visitor was answering', function (): void {
    signIn();

    $conversation = Conversation::factory()->create(['last_message_at' => now()]);

    $quoted = Message::factory()->for($conversation)->create([
        'author' => MessageAuthor::Operator,
        'body' => 'Confirmed for the fourth of June',
    ]);

    Message::factory()->for($conversation)->create([
        'author' => MessageAuthor::Visitor,
        'body' => 'which fourth?',
        'reply_to_id' => $quoted->getKey(),
    ]);

    $html = Livewire::test(ChatInbox::class)
        ->call('selectConversation', $conversation->getKey())
        ->html();

    expect(substr_count($html, 'Confirmed for the fourth of June'))->toBe(2);
});

/**
 * The property, not a ceiling: twenty conversations must cost what two cost.
 *
 * A ceiling on the number of queries catches a change of decoration; this
 * catches the thing that actually breaks — an eager load dropped from the list
 * query, after which every row fetches its own last message to print one line of
 * preview, and nothing on screen looks any different.
 */
it('draws twenty conversations for the price of two', function (): void {
    signIn();

    $few = inboxListQueries(2);
    $many = inboxListQueries(20);

    expect($many)->toBe($few);
});
