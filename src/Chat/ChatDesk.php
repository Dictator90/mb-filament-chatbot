<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Chat;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use MB\FilamentChatbot\Contracts\OperatorAudience;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Policies\ChatbotPolicy;
use Throwable;

/**
 * The audience the package ships: whoever has the conversation, or the desk.
 *
 * **An assigned thread rings its assignee and nobody else.** It has an owner,
 * and calling four people towards one reply is how a team learns to ignore its
 * own notifications.
 *
 * **An unassigned thread rings everybody who may open the desk**, and that is
 * the half that had to be decided rather than deferred. An enquiry from a
 * website arrives with nobody responsible for it; ringing nobody means it lies
 * there until somebody happens to look, which is exactly the silence the bell
 * exists to end. The same choice was made in the application this package grew
 * out of, and the reason has not changed.
 *
 * Two filters keep "everybody" honest, and both are needed:
 *
 * - **panel access**, when the model answers `FilamentUser`. Plenty of
 *   applications keep customers in the same table as staff, and a bell that
 *   went to the whole table would tell ten thousand people that somebody wrote
 *   to the shop.
 * - **the same permission that opens the desk**. A person who cannot read the
 *   conversation has no use for a notification about it, and the package
 *   already asks that question everywhere else
 *   ({@see ChatbotPolicy}).
 *
 * Past `max_recipients` the answer is nobody, deliberately. A count that high
 * means the operator model is not a staff table — the two filters above found
 * nothing to narrow — and the useful failure there is a quiet bell that
 * `chatbot:doctor` names, not one notification per user per enquiry. The host
 * fixes it by pointing `chatbot.user_model` at its staff, or by binding
 * {@see OperatorAudience} to its own rota.
 */
final class ChatDesk implements OperatorAudience
{
    /**
     * @return iterable<object>
     */
    public function for(Conversation $conversation): iterable
    {
        $operator = $conversation->assignedTo;

        if ($operator !== null) {
            return [$operator];
        }

        if (config('chatbot.notifications.unassigned', true) !== true) {
            return [];
        }

        return $this->desk();
    }

    /**
     * @return list<object>
     */
    private function desk(): array
    {
        $limit = (int) config('chatbot.notifications.max_recipients', 100);

        /** @var class-string<Model> $model */
        $model = Conversation::operatorModel();

        $candidates = $model::query()->limit($limit + 1)->get();

        if ($candidates->count() > $limit) {
            return [];
        }

        $panel = $this->panel();

        return $candidates
            ->filter(fn (Model $user): bool => $this->opensTheDesk($user, $panel))
            ->values()
            ->all();
    }

    /**
     * The panel to ask about, or nothing when there is no answering it.
     *
     * The bell rings from places that have no panel around them — a channel
     * webhook, a queued continuation, a console command — and asking Filament
     * for one there can throw. Resolved once and passed down rather than asked
     * per user, because the failure is a property of the request and not of the
     * person: unresolved, the panel filter is skipped entirely and the
     * permission below still decides. Filtering everybody out instead would
     * turn "no panel in this request" into a silent bell.
     */
    private function panel(): ?Panel
    {
        try {
            return Filament::getCurrentOrDefaultPanel();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Both questions are asked defensively because both run inside the path
     * that stores a customer's message.
     *
     * `canAccessPanel()` is host code and may look at a tenant, a subscription,
     * a remote directory; the policy behind `viewAny` is host code too. A
     * throwing answer means "not this person", never "lose the message" — the
     * bell's own catch would otherwise swallow the whole audience because one
     * user's row is odd.
     */
    private function opensTheDesk(Model $user, ?Panel $panel): bool
    {
        try {
            if ($panel !== null && $user instanceof FilamentUser && ! $user->canAccessPanel($panel)) {
                return false;
            }

            return Gate::forUser($user)->allows('viewAny', Conversation::class);
        } catch (Throwable) {
            return false;
        }
    }
}
