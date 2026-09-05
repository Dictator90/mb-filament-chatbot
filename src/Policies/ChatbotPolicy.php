<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Authorisation for the package's screens, deferred to the host.
 *
 * The package invents no permission system and ships no roles. It asks a
 * question — "may this person manage scenarios?" — and if the application has
 * an opinion, that opinion decides. If it has none, the answer is yes.
 *
 * That default is the important part, and it is chosen rather than lazy. A
 * package that denied by default would be unusable the moment it was installed:
 * the screens would appear in the menu and refuse to open, with nothing on
 * screen to say which ability was missing. A package that invented its own
 * permission names would collide with whatever the host already uses, and every
 * installation would need a bridge. Asking a question the host may ignore costs
 * nothing on day one and tightens the moment somebody defines the gate.
 *
 * The abilities are `chatbot.view.<subject>` and `chatbot.manage.<subject>`,
 * with `chatbot.view` and `chatbot.manage` as fallbacks — so an application can
 * be as coarse or as fine as it likes without the package caring which.
 */
abstract class ChatbotPolicy
{
    /**
     * The thing this policy speaks for: `scenarios`, `knowledge`, `runs`.
     */
    abstract protected function subject(): string;

    public function viewAny(mixed $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(mixed $user, Model $record): bool
    {
        return $this->allows($user, 'view');
    }

    public function create(mixed $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function update(mixed $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }

    public function delete(mixed $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }

    public function deleteAny(mixed $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function restore(mixed $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }

    public function forceDelete(mixed $user, Model $record): bool
    {
        return $this->allows($user, 'manage');
    }

    /**
     * Asks the host, most specific question first, and allows when it has none.
     *
     * The specific ability is tried before the broad one so that an application
     * granting `chatbot.manage` wholesale still works, while one that wants to
     * let support staff edit the knowledge base but not the scenarios can say
     * exactly that.
     *
     * A defined gate is obeyed even when it denies — including for a user the
     * package would otherwise have allowed. Silently overruling a host's "no"
     * is the one thing a package must never do here.
     */
    protected function allows(mixed $user, string $verb): bool
    {
        foreach (["chatbot.{$verb}.{$this->subject()}", "chatbot.{$verb}"] as $ability) {
            if (Gate::has($ability)) {
                return Gate::forUser($user)->allows($ability);
            }
        }

        return true;
    }
}
