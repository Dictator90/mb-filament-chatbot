<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Chat;

use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Channels\InboundMessage;
use MB\FilamentChatbot\Contracts\ContactDirectory;
use MB\FilamentChatbot\Models\Conversation;

/**
 * The directory an installation gets before it writes one: it recognises nobody
 * and says nothing.
 *
 * Bound by default so that every call site can ask the question unconditionally.
 * The alternative — a nullable dependency and an `if` at each of them — is the
 * shape where the third call site forgets the check, and it forgets it in the
 * inbound path, where the failure is a customer's message that did not arrive.
 *
 * Recognising nobody is also the right default rather than a placeholder. A
 * package that guessed which of the host's records a stranger might be would be
 * filing conversations under real customers on the strength of a phone number
 * typed into a public chat box.
 */
final class NoContactDirectory implements ContactDirectory
{
    public function identify(Conversation $conversation, InboundMessage $message): ?Model
    {
        return null;
    }

    public function describe(Conversation $conversation): ?ContactCard
    {
        return null;
    }
}
