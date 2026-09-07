<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Pages;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use MB\FilamentChatbot\Filament\Concerns\HasChatbotNavigation;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Sandbox\SandboxRunner;

/**
 * Talking to a whole bot, the way a visitor does.
 *
 * The canvas has a sandbox of its own, and it answers a different question. That
 * one enters the scenario being drawn, whatever the routing would have done with
 * the words; this one says nothing about which scenario should answer and lets
 * the router decide — so the thing under test here is the routing itself, which
 * is invisible on a single canvas by construction.
 *
 * That is also why it is worth a screen. Routing is where an installation with
 * a dozen scenarios actually goes wrong: a keyword claimed by two of them, a
 * fallback with a priority high enough to swallow every keyword, a scenario left
 * switched off. Every one of those looks perfect on the canvas of the scenario
 * that was supposed to answer.
 *
 * Nothing here talks to the bot itself. The conversation is held by the real
 * engine through `SandboxRunner`, the trace is read from the run journal, and
 * the thread is hidden from the desk and from reports by the same global scope
 * that hides a quality rehearsal.
 */
class Sandbox extends Page
{
    use HasChatbotNavigation;

    /** @var view-string */
    protected string $view = 'chatbot::sandbox';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    /** Under `chatbot/`, for the reason `HasChatbotNavigation` sets out. */
    protected static ?string $slug = 'chatbot/sandbox';

    /**
     * The bot under test, in the address bar so that a link to this screen is a
     * link to the conversation somebody was having, not to an empty page.
     */
    #[Url(as: 'bot', except: null)]
    public ?int $botId = null;

    public string $said = '';

    public static function getNavigationLabel(): string
    {
        return __('chatbot::panel.sandbox.title');
    }

    /**
     * Below the screens that configure the bot: this is where somebody goes
     * after drawing something, not before.
     */
    protected static function chatbotNavigationOffset(): int
    {
        return 7;
    }

    /**
     * Whoever may look at scenarios may try them out.
     *
     * The question is asked of the panel's guard rather than of the application
     * default — `Filament::auth()`, never `auth()`. A panel may sign people in
     * on a guard of its own, and against such a host `auth()` answers null while
     * an operator is plainly looking at the page: the screen would simply not
     * appear in the menu, with nothing to say why.
     */
    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->can('viewAny', Scenario::class) === true;
    }

    public function getTitle(): string|Htmlable
    {
        return __('chatbot::panel.sandbox.title');
    }

    /**
     * Opens on a bot rather than on nothing.
     *
     * A screen whose every control is disabled until a select is touched reads
     * as broken, and the overwhelming majority of installations have exactly one
     * bot to choose from.
     */
    public function mount(): void
    {
        $this->botId ??= $this->bots->first()?->getKey();
    }

    /**
     * The bots somebody may try, switched-off ones included.
     *
     * A bot is switched off precisely when something about it is being fixed,
     * and refusing to let anyone talk to it then would take the sandbox away at
     * the moment it is wanted.
     *
     * @return Collection<int, Bot>
     */
    #[Computed]
    public function bots(): Collection
    {
        return Bot::query()->orderBy('name')->get();
    }

    /**
     * The sandbox thread, if this person has one open.
     *
     * Not opened here: a screen that wrote a conversation for everybody who
     * merely looked at it would fill the table with threads nobody spoke into.
     * The first message is what starts one.
     */
    #[Computed]
    public function conversation(): ?Conversation
    {
        $conversation = app(SandboxRunner::class)->find($this->owner());

        return $conversation !== null && (int) $conversation->bot_id === (int) $this->botId
            ? $conversation
            : null;
    }

    /**
     * @return list<array{from: string, body: string}>
     */
    #[Computed]
    public function transcript(): array
    {
        $conversation = $this->conversation;

        return $conversation === null ? [] : app(SandboxRunner::class)->transcript($conversation);
    }

    /**
     * The options the bot is waiting on, so an `ask` node can be answered here
     * the way a visitor answers it — by picking one, rather than by guessing the
     * wording of a button the screen never drew.
     *
     * @return list<array{label: string}>
     */
    #[Computed]
    public function choices(): array
    {
        $conversation = $this->conversation;

        return $conversation === null ? [] : app(SandboxRunner::class)->choices($conversation);
    }

    /**
     * @return list<array{seq: int, key: string, type: string, scenario: string, outcome: string, said: ?string}>
     */
    #[Computed]
    public function trace(): array
    {
        $conversation = $this->conversation;

        return $conversation === null ? [] : app(SandboxRunner::class)->trace($conversation);
    }

    /**
     * Where the conversation stands, or null before it has started.
     */
    #[Computed]
    public function conversationState(): ?string
    {
        $conversation = $this->conversation;

        return $conversation === null ? null : app(SandboxRunner::class)->state($conversation);
    }

    /**
     * Says the line to the bot and lets it answer.
     *
     * No scenario is pinned, so the router chooses exactly as it would for a
     * visitor. That is the whole point of this screen, and the reason the two
     * sandboxes are not one.
     */
    public function send(): void
    {
        $said = trim($this->said);

        if ($said === '') {
            return;
        }

        $runner = app(SandboxRunner::class);
        $conversation = $runner->open($this->owner(), $this->bot());

        $runner->say($conversation, $said);

        $this->said = '';

        $this->forgetConversation();
    }

    /**
     * Answers the pending question by picking one of its options.
     *
     * The label is sent as if it had been typed, because that is what the
     * channels do: a button in the widget posts its own text, and the `ask` node
     * matches on what came back. Answering here through any other route would
     * exercise a path no visitor takes, which is the one thing a sandbox must
     * not do.
     */
    public function choose(string $label): void
    {
        $this->said = $label;

        $this->send();
    }

    /**
     * Throws the conversation away so the next message starts from nothing.
     */
    public function restart(): void
    {
        app(SandboxRunner::class)->find($this->owner())?->delete();

        $this->said = '';

        $this->forgetConversation();
    }

    /**
     * A different bot is a different conversation, and mixing the two would have
     * one bot answering questions the other was asked.
     */
    public function updatedBotId(): void
    {
        $this->forgetConversation();
    }

    protected function bot(): ?Bot
    {
        return $this->botId === null ? null : Bot::query()->whereKey($this->botId)->first();
    }

    /**
     * One thread per person on this screen, kept apart from the one beside a
     * scenario's canvas: they are different questions, and sharing a thread
     * would have one answer the other's half-finished conversation.
     */
    protected function owner(): string
    {
        return SandboxRunner::ownerKey('sandbox', Filament::auth()->id());
    }

    /**
     * Clears the computed values that were true before this turn.
     *
     * Livewire caches a computed property for the rest of the request, and the
     * request that sends a message is the request that renders the answer — so
     * without this the screen redraws the conversation as it stood before the
     * bot spoke.
     */
    protected function forgetConversation(): void
    {
        unset($this->conversation, $this->transcript, $this->choices, $this->trace, $this->conversationState);
    }
}
