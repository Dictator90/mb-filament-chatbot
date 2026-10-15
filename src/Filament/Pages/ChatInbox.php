<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Pages;

use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use MB\FilamentChatbot\Chat\AttachmentRefused;
use MB\FilamentChatbot\Chat\AttachmentStore;
use MB\FilamentChatbot\Chat\ContactCard;
use MB\FilamentChatbot\Chat\ConversationEraser;
use MB\FilamentChatbot\Chat\OperatorReply;
use MB\FilamentChatbot\Contracts\ContactDirectory;
use MB\FilamentChatbot\Contracts\ContactPicker;
use MB\FilamentChatbot\Contracts\ContactRegistrar;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\ConversationStatus;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Filament\Concerns\HasChatbotNavigation;
use MB\FilamentChatbot\Models\Attachment;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\QuickReply;
use UnitEnum;

/**
 * The desk: conversations on the left, the transcript and a reply box on the
 * right.
 *
 * This is the only screen in the package where a person talks to a person; the
 * other six configure the machinery. That difference decides almost everything
 * about it — one screen across every channel rather than a tab per channel,
 * because an operator's question is never "what came in from Telegram" but
 * "who is still waiting for me", and unread work split by channel gets walked
 * round in a circle with the quiet channel always waiting longest.
 *
 * New messages arrive by polling rather than by broadcasting. A package cannot
 * assume the host runs a websocket server, and one that required it would be
 * uninstallable on the shared hosting most small installations sit on. The poll
 * runs only while the tab is on screen, so a desk left open all day costs
 * nothing when nobody is looking at it.
 *
 * The reply box is plain markup with `wire:model` rather than a Filament field.
 * It has to read as a messenger's composer, and a form field brings its own
 * chrome — the wrapper needed to undo that would be larger than the markup.
 */
class ChatInbox extends Page
{
    use HasChatbotNavigation;
    use WithFileUploads;

    /** @var view-string */
    protected string $view = 'chatbot::chat-inbox';

    /**
     * The emoji the composer offers, grouped by what they are for.
     *
     * Short and closed, and it stays that way. A full palette is a searchable,
     * paginated, skin-toned, keyboard-navigable thing with a data file behind
     * it — a separate product — and this list covers what an operator actually
     * reaches for between two sentences. Anything else is typed, pasted or
     * picked from the operating system's own picker, which is a keystroke away
     * on every platform this panel runs on.
     *
     * The widget carries its own copy rather than reading this one. It runs on
     * somebody else's page with no way to call PHP, and giving it a fourth
     * endpoint to fetch thirty characters would cost a round trip on every page
     * load for a list that has never changed.
     *
     * @var array<string, list<string>>
     */
    public const EMOJI = [
        'faces' => ['🙂', '😀', '😅', '😉', '😍', '🤔', '😐', '😢', '😮', '😴'],
        'gestures' => ['👍', '👎', '👌', '🙏', '👏', '🤝', '💪', '❤️', '🔥', '✨'],
        'marks' => ['✅', '❌', '⚠️', '📎', '📅', '⏰', '💬', '📞', '🎉', '🎁'],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    /**
     * Under `chatbot/`, for the reason `HasChatbotNavigation` sets out — and
     * this is the screen that found it. An application with its own `ChatInbox`
     * page lost it to this one the moment the plugin was registered.
     */
    protected static ?string $slug = 'chatbot/inbox';

    /**
     * How many conversations the list may hold.
     *
     * A ceiling rather than a pager, because the list is read from the top and
     * scrolled, never paged through: whatever is below the eightieth row is
     * found by searching, not by clicking "next".
     */
    private const LIST_LIMIT = 80;

    /**
     * Everything that shapes the list lives in the address bar.
     *
     * Not for the sake of shareable links — for coming back. An operator opens
     * a linked record, a report, another tab, and returns; state kept only in
     * the component is rebuilt from defaults on that return, so the filter they
     * set two minutes ago is silently gone and the conversation they were
     * reading is not on screen. They do not read that as "the page reset", they
     * read it as "my conversation disappeared".
     */
    #[Url(as: 'conversation', except: null)]
    public ?int $conversationId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'open')]
    public string $statusFilter = 'open';

    #[Url(as: 'channel', except: null)]
    public ?string $channelFilter = null;

    /** Whose conversations to show: `mine`, `unassigned` or `all`. */
    #[Url(as: 'who', except: 'all')]
    public string $ownerFilter = 'all';

    #[Url(as: 'unread', except: false)]
    public bool $onlyUnread = false;

    public string $composerBody = '';

    /**
     * The file waiting to go out with the next reply, if any.
     *
     * Untyped on purpose. Livewire puts a `TemporaryUploadedFile` here once an
     * upload has landed and a plain string on it while one is still arriving,
     * and a typed property turns that intermediate state into a `TypeError`
     * thrown from inside Livewire's own hydration — where nothing on screen
     * names this page, this property, or the file somebody was uploading.
     *
     * @var TemporaryUploadedFile|null
     */
    public $attachment = null;

    public static function getNavigationLabel(): string
    {
        return __('chatbot::panel.inbox.title');
    }

    /**
     * Top level in the menu, unlike the six screens that configure the bot.
     *
     * Those are visited on purpose; this one is run to. A conversation inside a
     * collapsed group announces itself only through a count on the group header
     * and takes two clicks to reach — and the whole value of an unread badge is
     * that the answer is one click away.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return null;
    }

    /**
     * Ahead of the configured block, so the desk sits above the machinery even
     * where a host has pushed the chatbot screens far down its own menu.
     */
    protected static function chatbotNavigationOffset(): int
    {
        return -1;
    }

    public function getTitle(): string|Htmlable
    {
        return __('chatbot::panel.inbox.title');
    }

    /**
     * No heading and no breadcrumbs on purpose: the screen fills the window, and
     * a title bar would take a centimetre of height off the transcript to repeat
     * the word already highlighted in the menu.
     */
    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    /**
     * Who is looking, asked of the panel rather than of the application.
     *
     * `Filament::auth()` and not `auth()` — here and everywhere else on this
     * screen. A panel may sign people in on a guard of its own, and hosts
     * commonly do: staff against one table, customers against another. Against
     * such a host `auth()` resolves the *application default* guard, which
     * nobody signed into, and answers `null` while an operator is plainly
     * looking at the page.
     *
     * Every consequence of getting this wrong is silent. This method answers
     * false and the desk simply is not in the menu; the unread badge counts a
     * guest; "mine" matches nothing; a reply is filed with no author. Nothing
     * throws, and a suite that signs in with `actingAs($user, $guard)` cannot
     * see it either, because that helper also makes the panel's guard the
     * default — see `signIn()` in `tests/Pest.php`.
     */
    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->can('viewAny', Conversation::class) === true;
    }

    /**
     * How much is waiting for this operator.
     *
     * Counted as "unanswered and not somebody else's": a conversation a
     * colleague has taken is not this person's work, and a badge that counted
     * the whole desk would show every operator the same number and mean nothing
     * to any of them.
     *
     * The sidebar renders this on every page of the panel, so the count is held
     * for a few seconds. The key carries the viewer's id, and that is not
     * tidiness: with a shared key the first person to open the panel would hand
     * their own number to everyone else for the life of the entry — including
     * the count of conversations they had personally been assigned.
     */
    public static function getNavigationBadge(): ?string
    {
        $userId = Filament::auth()->id();

        /*
         * "Waiting for me" is defined once, on the model. It is asked here and
         * again by the dashboard tile, and two copies of a definition made of
         * three clauses drift the moment one of them is refined — leaving a
         * badge and a tile that disagree about the same desk, with nothing on
         * either screen to say which is right.
         */
        $unread = Cache::remember(
            'chatbot.inbox.badge.'.($userId ?? 'guest'),
            now()->addSeconds(15),
            fn (): int => Conversation::query()->waitingFor($userId)->count(),
        );

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('chatbot::panel.inbox.badge_tooltip');
    }

    public function mount(): void
    {
        $this->normaliseFilters();

        $this->activeConversation?->markReadByAgent();
    }

    /**
     * The list: unanswered first, then whatever spoke last.
     *
     * `lastMessage` and `bot` are eager-loaded by name because every row shows
     * an excerpt and a bot tag. Fetched per row instead, eighty conversations
     * would cost a hundred and sixty extra queries to draw one column of text.
     *
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        return Conversation::query()
            ->with(['lastMessage', 'bot'])
            ->when($this->statusFilter === 'open', fn (Builder $query): Builder => $query->open())
            ->when(
                $this->statusFilter === 'closed',
                fn (Builder $query): Builder => $query->where('status', ConversationStatus::Closed),
            )
            ->when($this->onlyUnread, fn (Builder $query): Builder => $query->unread())
            ->when(
                $this->ownerFilter === 'mine',
                fn (Builder $query): Builder => $query->where('assigned_to_id', Filament::auth()->id()),
            )
            ->when(
                $this->ownerFilter === 'unassigned',
                fn (Builder $query): Builder => $query->whereNull('assigned_to_id'),
            )
            ->when(
                $this->channelFilter !== null,
                fn (Builder $query): Builder => $query->forChannel((string) $this->channelFilter),
            )
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $scoped): Builder => $scoped
                    ->where('display_name', 'like', '%'.$this->search.'%')
                    ->orWhere('username', 'like', '%'.$this->search.'%')
                    ->orWhere('contact_phone', 'like', '%'.$this->search.'%')
                    ->orWhere('contact_email', 'like', '%'.$this->search.'%'),
            ))
            /*
             * A conversation nobody has said anything in is not work. One is
             * created the moment a widget opens, and it stays empty when the
             * visitor closes the tab without typing.
             */
            ->whereNotNull('last_message_at')
            /*
             * Unanswered to the top, and only within those the freshest first.
             *
             * Sorting by time alone is fine until there are more conversations
             * than fit on screen: from then on the question asked this morning
             * and never answered sits below a column of "thanks!", and that
             * question is the entire job. The condition repeats `hasUnread()`
             * word for word, because the order and the unread dot have to agree
             * — disagreeing, neither can be trusted.
             */
            ->orderByRaw(
                'CASE WHEN last_incoming_at IS NOT NULL '
                .'AND (agent_read_at IS NULL OR agent_read_at < last_incoming_at) THEN 0 ELSE 1 END',
            )
            ->orderByDesc('last_message_at')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    public function listIsCapped(): bool
    {
        return $this->conversations->count() === self::LIST_LIMIT;
    }

    /**
     * The open conversation, fetched through the model rather than picked out of
     * the list: a conversation reached by a link may be filtered out of the list
     * it was opened from, and closing the filter must not close the transcript.
     */
    #[Computed]
    public function activeConversation(): ?Conversation
    {
        if ($this->conversationId === null) {
            return null;
        }

        return Conversation::query()->with('bot')->find($this->conversationId);
    }

    /**
     * The transcript, latest lines first and then turned back the right way up.
     *
     * The limit has to bite at the recent end. Taken from the top, a
     * conversation older than the limit would show its first fifty lines
     * forever — the operator would open it and read a greeting from March while
     * the question they are answering was never on screen.
     *
     * @return Collection<int, Message>
     */
    #[Computed]
    public function thread(): Collection
    {
        $conversation = $this->activeConversation;

        if (! $conversation instanceof Conversation) {
            return new Collection;
        }

        return $conversation->messages()
            /*
             * The files are eager-loaded rather than asked for per bubble. Almost
             * every message has none, so this is one query for the transcript
             * where a relation touched in the view would be fifty.
             */
            /*
             * The quoted line travels with the message for the same reason. A
             * thread where every second reply answers a particular message would
             * otherwise be twenty-five extra queries, and the quote is drawn
             * inside the bubble where nothing looks expensive.
             */
            ->with(['attachments', 'replyTo'])
            ->reorder()
            ->latest('id')
            ->limit((int) config('chatbot.chat.history_limit', 50))
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * The phrases this operator may drop in: the shared ones plus their own.
     *
     * @return Collection<int, QuickReply>
     */
    #[Computed]
    public function quickReplies(): Collection
    {
        return QuickReply::query()
            ->active()
            ->availableTo(Filament::auth()->id())
            ->orderBy('sort')
            ->get();
    }

    /**
     * Opens a conversation and marks it read.
     *
     * Reading is what marking read means. Left to a button, the unread count
     * would measure who remembered to press it rather than what is unanswered,
     * and an operator would see their own dealt-with conversations at the top of
     * the list all day.
     */
    public function selectConversation(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->composerBody = '';
        $this->attachment = null;
        $this->resetValidation();

        $this->forgetThread();

        $this->activeConversation?->markReadByAgent();
    }

    public function clearSelection(): void
    {
        $this->conversationId = null;
        $this->composerBody = '';
        $this->attachment = null;
        $this->resetValidation();

        $this->forgetThread();
    }

    public function updatedStatusFilter(): void
    {
        $this->normaliseFilters();
        $this->forgetThread();
    }

    public function updatedOwnerFilter(): void
    {
        $this->normaliseFilters();
        $this->forgetThread();
    }

    public function updatedSearch(): void
    {
        $this->forgetThread();
    }

    public function updatedChannelFilter(): void
    {
        $this->forgetThread();
    }

    public function updatedOnlyUnread(): void
    {
        $this->forgetThread();
    }

    /**
     * Re-reads the list and the transcript. Called by the poll in the view.
     *
     * The read mark is refreshed here too: a message that arrives while the
     * operator is looking straight at the conversation has been read, and
     * counting it as unread would pile up a badge in front of somebody who is
     * reading every word of it.
     */
    public function poll(): void
    {
        $this->forgetThread();

        $this->activeConversation?->markReadByAgent();
    }

    /**
     * Sends what is in the box.
     *
     * The refusal to send an empty message is repeated from `OperatorReply`
     * rather than caught from it, because the two refusals are different things:
     * the service throws to stop a caller doing something senseless, and this
     * one has to put a sentence under the box while leaving the draft alone.
     */
    public function sendReply(): void
    {
        $conversation = $this->activeConversation;

        if (! $conversation instanceof Conversation) {
            return;
        }

        $this->authorize('update', $conversation);

        $file = $this->attachment instanceof UploadedFile ? $this->attachment : null;

        $body = trim($this->composerBody);
        $limit = (int) config('chatbot.chat.max_message_length', 4000);

        if ($body === '' && $file === null) {
            $this->addError('composerBody', __('chatbot::panel.inbox.empty_body'));

            return;
        }

        if (mb_strlen($body) > $limit) {
            $this->addError('composerBody', __('chatbot::panel.inbox.too_long', ['limit' => $limit]));

            return;
        }

        /*
         * The file is judged before the reply is written, not after.
         *
         * Writing first and refusing second would send the customer a message
         * and then tell the operator their file was rejected — and nothing
         * recalls a message a channel has already accepted, so the operator
         * would be left explaining the half that did arrive.
         */
        if ($file !== null && ! $this->attachmentIsAcceptable($file)) {
            return;
        }

        try {
            $message = app(OperatorReply::class)->send(
                $conversation,
                /*
                 * A file with no sentence under it goes out named. `OperatorReply`
                 * refuses an empty body — an empty bubble is a notification with
                 * nothing in it — and inventing a placeholder here would write the
                 * panel's language into a row the visitor reads.
                 */
                $body === '' ? $this->attachmentName($file) : $body,
                Filament::auth()->user(),
            );
        } catch (InvalidArgumentException) {
            $this->addError('composerBody', __('chatbot::panel.inbox.empty_body'));

            return;
        }

        if ($file !== null) {
            app(AttachmentStore::class)->store($message, $file);
        }

        $this->composerBody = '';
        $this->attachment = null;
        $this->resetValidation();
        $this->forgetThread();
    }

    /**
     * Takes the file off the reply without sending anything.
     */
    public function clearAttachment(): void
    {
        $this->attachment = null;
        $this->resetValidation('attachment');
    }

    /**
     * Says no to a file the store would refuse, in words, under the box.
     *
     * The same two questions `AttachmentStore` asks, asked here so the answer
     * can be a sentence rather than an exception. `store()` asks them again
     * afterwards, which is not duplication but the guarantee: this method is
     * about telling somebody, and that one is about what reaches the disk.
     */
    protected function attachmentIsAcceptable(UploadedFile $file): bool
    {
        $store = app(AttachmentStore::class);
        $refusal = $store->refusalFor($file);

        if ($refusal === null) {
            return true;
        }

        $this->addError('attachment', $refusal->reason === AttachmentRefused::TOO_LARGE
            ? __('chatbot::panel.inbox.attachment_too_large', ['limit' => $store->maxKilobytes()])
            : __('chatbot::panel.inbox.attachment_wrong_type'));

        return false;
    }

    /**
     * The name of the file on the reply, as the operator's own machine spelled
     * it. Empty when nothing is attached, which is what the composer draws.
     */
    public function attachmentName(mixed $file = null): string
    {
        $file ??= $this->attachment;

        return $file instanceof UploadedFile
            ? trim((string) $file->getClientOriginalName())
            : '';
    }

    /**
     * Where the desk fetches one attached file from.
     *
     * The panel is named in the address because the route is outside the panel's
     * own path and has to know whose guard to ask — see
     * `src/Http/Controllers/AttachmentController.php`. Taken from the panel
     * currently rendering rather than from a setting, so a host running the desk
     * in two panels gets a working link in both.
     */
    public function attachmentUrl(Attachment $attachment): string
    {
        return route('chatbot.attachment', [
            'panel' => Filament::getCurrentPanel()?->getId() ?? Filament::getDefaultPanel()->getId(),
            'attachment' => $attachment->uuid,
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function emojiGroups(): array
    {
        return self::EMOJI;
    }

    /**
     * Drops a stock phrase into the box.
     *
     * Appended rather than substituted: operators greet people in their own
     * words and reach for the stock phrase after, and replacing the draft would
     * cost them that sentence every time.
     */
    public function applyQuickReply(int $replyId): void
    {
        $reply = $this->quickReplies->firstWhere('id', $replyId);

        if ($reply === null) {
            return;
        }

        $current = rtrim($this->composerBody);

        $this->composerBody = $current === ''
            ? (string) $reply->body
            : $current."\n".$reply->body;
    }

    /**
     * Take the conversation, or put it back on the desk.
     *
     * One action with two labels rather than two buttons: they are the same
     * decision from opposite sides, and a pair of them would ask the operator to
     * work out which one is currently live.
     *
     * `authorize()` is spelled out because Filament actions do not consult a
     * policy on their own. A header or row action with no authorisation is
     * visible to everybody who reached the page, whatever the policy says.
     */
    public function assignmentAction(): Action
    {
        return Action::make('assignment')
            ->label(fn (): string => $this->isMine()
                ? __('chatbot::panel.inbox.release')
                : __('chatbot::panel.inbox.take'))
            ->icon(fn (): Heroicon => $this->isMine() ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedHandRaised)
            ->color('gray')
            /*
                Значком и с подсказкой, а не словом на кнопке.

                Четыре подписанные кнопки занимали в шапке разговора больше
                места, чем имя человека, с которым разговаривают: на телефоне
                они переносились в три ряда и забирали четверть экрана.
                Подпись не пропадает — `iconButton()` оставляет её
                в `aria-label`, а `tooltip()` показывает при наведении.
            */
            ->iconButton()
            ->tooltip(fn (): string => $this->isMine()
                ? __('chatbot::panel.inbox.release')
                : __('chatbot::panel.inbox.take'))
            ->authorize(fn (): bool => $this->canUpdateActive())
            ->action(function (): void {
                $conversation = $this->activeConversation;

                if (! $conversation instanceof Conversation) {
                    return;
                }

                $conversation->forceFill([
                    'assigned_to_id' => $this->isMine() ? null : Filament::auth()->id(),
                ])->save();

                $this->forgetThread();
            });
    }

    /**
     * Close the conversation, or open it again.
     *
     * Closing is housekeeping — the row keeps its history and the next incoming
     * message brings it back — so it asks for no confirmation.
     */
    public function statusAction(): Action
    {
        return Action::make('status')
            ->label(fn (): string => $this->isOpen()
                ? __('chatbot::panel.inbox.close')
                : __('chatbot::panel.inbox.reopen'))
            ->icon(fn (): Heroicon => $this->isOpen() ? Heroicon::OutlinedArchiveBox : Heroicon::OutlinedArchiveBoxArrowDown)
            ->color('gray')
            ->iconButton()
            ->tooltip(fn (): string => $this->isOpen()
                ? __('chatbot::panel.inbox.close')
                : __('chatbot::panel.inbox.reopen'))
            ->authorize(fn (): bool => $this->canUpdateActive())
            ->action(function (): void {
                $conversation = $this->activeConversation;

                if (! $conversation instanceof Conversation) {
                    return;
                }

                $conversation->forceFill([
                    'status' => $this->isOpen() ? ConversationStatus::Closed : ConversationStatus::Open,
                ])->save();

                $this->forgetThread();
            });
    }

    /**
     * Hands the conversation back to the bot.
     *
     * Both halves are needed and neither is enough alone: the engine stays
     * silent while a conversation has an owner *or* is marked handed off, so
     * releasing it without resuming the bot, or resuming the bot while still
     * holding the conversation, leaves the visitor talking to nobody — and the
     * screen would say the bot was answering.
     *
     * Confirmed, because it is the one action here that puts a customer back in
     * front of a machine.
     */
    public function returnToBotAction(): Action
    {
        return Action::make('returnToBot')
            ->label(__('chatbot::panel.inbox.return_to_bot'))
            ->icon(Heroicon::OutlinedCpuChip)
            ->color('gray')
            ->iconButton()
            ->tooltip(__('chatbot::panel.inbox.return_to_bot'))
            ->requiresConfirmation()
            ->modalDescription(__('chatbot::panel.inbox.return_to_bot_description'))
            ->authorize(fn (): bool => $this->canUpdateActive())
            ->action(function (): void {
                $conversation = $this->activeConversation;

                if (! $conversation instanceof Conversation) {
                    return;
                }

                $conversation->forceFill([
                    'assigned_to_id' => null,
                    'bot_state' => BotState::Active,
                    'bot_handed_off_at' => null,
                ])->save();

                $this->forgetThread();
            });
    }

    /**
     * Files the person in this thread in the host's own directory.
     *
     * The thread already holds everything the card is made of — the name the
     * visitor gave, the phone number they typed — and before this the operator
     * had to retype it on another screen, which is how one customer ends up in
     * the directory twice under two spellings of the same name.
     *
     * The host decides what gets created and whether anything does at all; this
     * only asks. A refusal is reported as a warning rather than an error,
     * because the usual reason is a rule the host is enforcing on purpose — a
     * setting switched off, or the same person found and linked instead.
     */
    public function createContactAction(): Action
    {
        return Action::make('createContact')
            ->label(__('chatbot::panel.inbox.create_contact'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->iconButton()
            ->tooltip(__('chatbot::panel.inbox.create_contact'))
            ->authorize(fn (): bool => $this->canUpdateActive())
            ->action(function (): void {
                $conversation = $this->activeConversation;
                $registrar = $this->contactRegistrar();

                if (! $conversation instanceof Conversation || $registrar === null) {
                    return;
                }

                $record = $registrar->register($conversation);

                $this->forgetThread();

                $notification = Notification::make();

                $record === null
                    ? $notification->body(__('chatbot::panel.inbox.create_contact_refused'))->warning()->send()
                    : $notification->body(__('chatbot::panel.inbox.create_contact_done'))->success()->send();
            });
    }

    /**
     * Points the thread at a different record, chosen by the operator.
     *
     * The link is made automatically where it can be — by a recognised phone
     * number, by a signed identity — and automatic recognition is wrong often
     * enough to matter: a number belongs to a husband, a colleague, or whoever
     * had it before. Until this existed the operator's only choices were to
     * answer a customer under somebody else's history or to leave the thread
     * mislinked, and both end with the wrong card being updated.
     *
     * The search is the host's: the package cannot query a table it has never
     * heard of, and the value it hands back is the one the host gave out.
     */
    public function linkContactAction(): Action
    {
        return Action::make('linkContact')
            /*
             * Подпись зависит от того, есть ли уже карточка: «привязать
             * к другому» на разговоре, не привязанном ни к кому, обещает
             * замену там, где замещать нечего.
             */
            ->label(fn (): string => $this->activeConversation?->contact_id === null
                ? __('chatbot::panel.inbox.link_contact_empty')
                : __('chatbot::panel.inbox.link_contact'))
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->size(Size::ExtraSmall)
            ->iconButton()
            ->tooltip(fn (): string => $this->activeConversation?->contact_id === null
                ? __('chatbot::panel.inbox.link_contact_empty')
                : __('chatbot::panel.inbox.link_contact'))
            ->authorize(fn (): bool => $this->canUpdateActive())
            ->schema([
                Select::make('contact')
                    ->label(__('chatbot::panel.inbox.link_contact_field'))
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search): array => $this->contactPicker()?->search($search) ?? [])
                    ->getOptionLabelUsing(fn (string $value): ?string => $this->contactPicker()?->label($value)),
            ])
            ->action(function (array $data): void {
                $conversation = $this->activeConversation;
                $picker = $this->contactPicker();

                if (! $conversation instanceof Conversation || $picker === null) {
                    return;
                }

                $record = $picker->attach($conversation, (string) $data['contact']);

                $this->forgetThread();

                $notification = Notification::make();

                $record === null
                    ? $notification->body(__('chatbot::panel.inbox.link_contact_gone'))->warning()->send()
                    : $notification->body(__('chatbot::panel.inbox.link_contact_done'))->success()->send();
            });
    }

    /**
     * Takes the link off, leaving the thread filed under nobody.
     *
     * The honest answer when the link is wrong and the right record is not
     * there to pick: a thread pointing at the wrong person is worse than one
     * pointing at nobody, because everything an operator reads above it — the
     * history, the name, the card — is then about somebody else.
     *
     * Confirmed because what it removes is not visible from here: the link may
     * be the only thing connecting a year of conversations to a customer, and
     * putting it back needs the operator to know which record it was.
     */
    public function unlinkContactAction(): Action
    {
        return Action::make('unlinkContact')
            ->label(__('chatbot::panel.inbox.unlink_contact'))
            ->icon(Heroicon::OutlinedLinkSlash)
            ->color('gray')
            ->size(Size::ExtraSmall)
            ->iconButton()
            ->tooltip(__('chatbot::panel.inbox.unlink_contact'))
            ->requiresConfirmation()
            ->modalDescription(__('chatbot::panel.inbox.unlink_contact_description'))
            ->authorize(fn (): bool => $this->canUpdateActive())
            ->action(function (): void {
                $conversation = $this->activeConversation;

                if (! $conversation instanceof Conversation) {
                    return;
                }

                $conversation->forceFill([
                    'contact_type' => null,
                    'contact_id' => null,
                ])->save();

                $this->forgetThread();

                Notification::make()
                    ->body(__('chatbot::panel.inbox.unlink_contact_done'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Deletes the conversation and everything that was said in it.
     *
     * The one action here that cannot be undone, and the only one that removes
     * rather than changes. It exists because a transcript is personal data: a
     * customer who asks for their messages to be deleted, a thread opened by
     * mistake with somebody's passport photo in it, a rehearsal that reached a
     * real chat — all of them had to be cleared out of the database by hand
     * before this, which in practice meant they stayed.
     *
     * Files go with the rows ({@see ConversationEraser}), which is the half an
     * operator cannot check: an attachment whose row is gone is a photo on the
     * disk that nothing in the application can find any more, and nobody will
     * ever delete.
     *
     * Asks the policy for `delete` rather than `update`. Everybody who answers
     * the chat may write in it; removing it is a different question, and a host
     * that wants to keep it with the owner says so with `chatbot.delete`.
     */
    public function deleteConversationAction(): Action
    {
        return Action::make('deleteConversation')
            ->label(__('chatbot::panel.inbox.delete_conversation'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->size(Size::ExtraSmall)
            ->iconButton()
            ->tooltip(__('chatbot::panel.inbox.delete_conversation'))
            ->requiresConfirmation()
            ->modalHeading(__('chatbot::panel.inbox.delete_conversation'))
            ->modalDescription(__('chatbot::panel.inbox.delete_conversation_description'))
            ->modalSubmitActionLabel(__('chatbot::panel.inbox.delete_conversation_confirm'))
            ->authorize(fn (): bool => $this->canDeleteConversation())
            ->action(function (): void {
                $conversation = $this->activeConversation;

                if (! $conversation instanceof Conversation) {
                    return;
                }

                app(ConversationEraser::class)->erase($conversation);

                $this->conversationId = null;
                $this->forgetThread();

                Notification::make()
                    ->body(__('chatbot::panel.inbox.delete_conversation_done'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Whether there is a link to take off.
     */
    public function canUnlinkContact(): bool
    {
        return $this->activeConversation?->contact_id !== null;
    }

    /**
     * Whether a thread can be re-pointed at all.
     *
     * Only that a picker is bound: unlike filing somebody new, this is useful
     * precisely when the thread already has a contact — that is the case it was
     * written for.
     */
    public function canLinkContact(): bool
    {
        return $this->activeConversation instanceof Conversation && $this->contactPicker() !== null;
    }

    private function contactPicker(): ?ContactPicker
    {
        return app()->bound(ContactPicker::class)
            ? app(ContactPicker::class)
            : null;
    }

    /**
     * Whether that button has anything to do.
     *
     * Three conditions, and the thread already having a contact is the one that
     * matters: offering "file this person" on a conversation that is already
     * filed is how the same customer gets a second card.
     */
    public function canCreateContact(): bool
    {
        $conversation = $this->activeConversation;
        $registrar = $this->contactRegistrar();

        if (! $conversation instanceof Conversation || $registrar === null) {
            return false;
        }

        return $conversation->contact_id === null && $registrar->canRegister($conversation);
    }

    /**
     * The host's registrar, or nothing at all.
     *
     * Resolved rather than injected because binding it is optional: an
     * installation that has not bound one gets no button, which is the right
     * answer for a helpdesk whose contacts are employees and are created
     * nowhere near a chat.
     */
    private function contactRegistrar(): ?ContactRegistrar
    {
        return app()->bound(ContactRegistrar::class)
            ? app(ContactRegistrar::class)
            : null;
    }

    public function isOpen(): bool
    {
        return $this->activeConversation?->status === ConversationStatus::Open;
    }

    public function isMine(): bool
    {
        return $this->activeConversation?->assigned_to_id === Filament::auth()->id()
            && $this->activeConversation?->assigned_to_id !== null;
    }

    public function botIsAnswering(): bool
    {
        return $this->activeConversation?->bot_state === BotState::Active;
    }

    /**
     * What the application says about the person this thread belongs to.
     *
     * Asked once per render and never cached across them: an operator who
     * corrects a customer's name in another tab must not go on reading the old
     * one here for the rest of the day.
     *
     * An empty card is treated as no card. A directory that answered with a
     * blank name would otherwise draw a line with nothing on it, which reads as
     * a broken screen rather than as a thread nobody has identified.
     */
    public function contactCard(): ?ContactCard
    {
        $conversation = $this->activeConversation;

        if ($conversation === null) {
            return null;
        }

        $card = app(ContactDirectory::class)->describe($conversation);

        return $card?->filled() === true ? $card : null;
    }

    /**
     * Whether the bot could be handed this conversation back.
     *
     * A conversation with no bot behind it has nothing to hand back to, and
     * offering the action there promises an answer that will never come.
     */
    public function canReturnToBot(): bool
    {
        return $this->activeConversation?->bot_id !== null
            && ! $this->botIsAnswering();
    }

    public function maxLength(): int
    {
        return (int) config('chatbot.chat.max_message_length', 4000);
    }

    /**
     * Who owns a row, said without loading the host's user record.
     *
     * The package has no idea whether the host's users have a name, an e-mail or
     * anything else worth printing, and asking each row for its assignee would
     * be a query per row on top of that. What the operator needs from the list
     * is only whether this one is theirs, somebody's or nobody's.
     */
    public function ownerLabel(Conversation $conversation): ?string
    {
        return match (true) {
            $conversation->assigned_to_id === null => __('chatbot::panel.inbox.owner_free'),
            $conversation->assigned_to_id === Filament::auth()->id() => __('chatbot::panel.inbox.owner_mine'),
            default => __('chatbot::panel.inbox.owner_taken'),
        };
    }

    /**
     * The preview line, prefixed with who said it.
     *
     * The prefix is not politeness. Without it an operator reads the bot's last
     * sentence as their own reply, decides the conversation has been dealt with,
     * and it stays unanswered.
     */
    public function listPreview(Conversation $conversation): string
    {
        $message = $conversation->lastMessage;

        if ($message === null) {
            return '—';
        }

        $excerpt = str((string) $message->body)->squish()->limit(70)->value();

        return match ($message->author) {
            MessageAuthor::Operator => __('chatbot::panel.inbox.preview_you', ['excerpt' => $excerpt]),
            MessageAuthor::Bot => __('chatbot::panel.inbox.preview_bot', ['excerpt' => $excerpt]),
            MessageAuthor::System => __('chatbot::panel.inbox.preview_system', ['excerpt' => $excerpt]),
            default => $excerpt,
        };
    }

    /**
     * Who is speaking in the transcript.
     *
     * The bot and an operator both write outgoing messages, and telling them
     * apart is the whole reason the author is stored separately from the
     * direction: an operator reading a bot's promise as a colleague's would
     * repeat it or contradict it.
     */
    public function authorLabel(Message $message, Conversation $conversation): string
    {
        return match ($message->author) {
            MessageAuthor::Visitor => $conversation->title(),
            MessageAuthor::Bot => $conversation->bot?->name ?? __('chatbot::panel.inbox.author_bot'),
            MessageAuthor::Operator => __('chatbot::panel.inbox.author_operator'),
            default => __('chatbot::panel.inbox.author_system'),
        };
    }

    /**
     * Today by the clock, yesterday by name, older by date. A full date on a
     * conversation from this morning takes the width of the row and answers a
     * question nobody asked.
     *
     * `CarbonInterface`, never `Illuminate\Support\Carbon`. An application that
     * has called `Date::use(CarbonImmutable::class)` — an ordinary line, and one
     * this package has no say in — gets `Carbon\CarbonImmutable` out of every
     * date cast, which is not an instance of Laravel's Carbon. Typed against the
     * concrete class this method throws a `TypeError` and takes the whole desk
     * down with it, on a host where nothing is wrong.
     */
    public function listTimestamp(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '';
        }

        return match (true) {
            $at->isToday() => $at->format('H:i'),
            $at->isYesterday() => __('chatbot::panel.inbox.yesterday'),
            $at->isCurrentYear() => $at->format('d M'),
            default => $at->format('d M Y'),
        };
    }

    /**
     * The day a run of messages belongs to, for the divider above it.
     *
     * Without it a transcript is a column of times: `14:02` under `09:41` under
     * `18:20`, and nothing anywhere says whether those are three moments of one
     * afternoon or three months of a conversation nobody answered. An operator
     * apologising for a two-minute wait on a message from Tuesday is how it
     * goes wrong, and the bubble they read looked entirely normal.
     *
     * Today and yesterday are spelled out. A date is what somebody reads when
     * they have to work out how long ago it was, and for the two days that come
     * up most nobody should have to.
     *
     * `CarbonInterface` for the reason `listTimestamp()` gives above it.
     */
    public function dayLabel(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '';
        }

        return match (true) {
            /*
             * Its own pair of keys rather than the list's. There the word sits
             * inside a row as a timestamp and is lower case; here it is a
             * heading over a day's messages, and Russian capitalises the two
             * differently from English — one string could only be wrong in one
             * of the four places.
             */
            $at->isToday() => __('chatbot::panel.inbox.day_today'),
            $at->isYesterday() => __('chatbot::panel.inbox.day_yesterday'),
            $at->isCurrentYear() => $at->translatedFormat('j F'),
            default => $at->translatedFormat('j F Y'),
        };
    }

    /**
     * Channels worth offering: the ones conversations actually arrived on.
     *
     * Read from the table rather than from the registry of drivers, because a
     * channel that is configured and has never been used only ever gives an
     * empty list, and one that was switched off yesterday still has yesterday's
     * conversations in it.
     *
     * @return array<string, string>
     */
    public function channelOptions(): array
    {
        return Conversation::query()
            ->distinct()
            ->orderBy('channel')
            ->pluck('channel')
            ->mapWithKeys(fn (string $channel): array => [$channel => str($channel)->headline()->value()])
            ->all();
    }

    /**
     * How many are on screen and how many of those are unanswered.
     */
    public function listSummary(): string
    {
        $total = $this->conversations->count();
        $unread = $this->conversations
            ->filter(fn (Conversation $conversation): bool => $conversation->hasUnread())
            ->count();

        $line = trans_choice('chatbot::panel.inbox.summary', $total, ['count' => $total]);

        return $unread === 0
            ? $line
            : $line.' · '.__('chatbot::panel.inbox.summary_waiting', ['count' => $unread]);
    }

    private function canUpdateActive(): bool
    {
        $conversation = $this->activeConversation;

        return $conversation instanceof Conversation
            && Filament::auth()->user()?->can('update', $conversation) === true;
    }

    /**
     * Whether this operator may delete the open conversation.
     *
     * Public because the view has to ask it. An action embedded in markup draws
     * itself whatever its own authorisation says — a forbidden one comes out as
     * a greyed-out button rather than as nothing — and a disabled "delete
     * conversation" on the desk of somebody who may not delete it reads as a
     * screen that is broken.
     */
    public function canDeleteConversation(): bool
    {
        $conversation = $this->activeConversation;

        return $conversation instanceof Conversation
            && Filament::auth()->user()?->can('delete', $conversation) === true;
    }

    private function normaliseFilters(): void
    {
        if (! in_array($this->statusFilter, ['open', 'closed', 'all'], true)) {
            $this->statusFilter = 'open';
        }

        if (! in_array($this->ownerFilter, ['mine', 'unassigned', 'all'], true)) {
            $this->ownerFilter = 'all';
        }
    }

    /**
     * Drops the memoised properties together.
     *
     * One method rather than an `unset` at each call site: forgetting one of the
     * four shows a list where the conversation has already jumped to the top
     * beside a transcript that does not yet contain the message that moved it.
     */
    private function forgetThread(): void
    {
        unset($this->conversations, $this->activeConversation, $this->thread, $this->quickReplies);
    }
}
