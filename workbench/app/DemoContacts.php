<?php

declare(strict_types=1);

namespace Workbench\App;

use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Channels\InboundMessage;
use MB\FilamentChatbot\Chat\ContactCard;
use MB\FilamentChatbot\Contracts\ContactDirectory;
use MB\FilamentChatbot\Models\Conversation;
use Workbench\App\Models\DemoRecord;

/**
 * A host's contact directory, as the workbench can show one.
 *
 * Without it the desk has a state nobody can look at: a thread linked to a
 * record draws a second line under the name — who this is, plus the buttons
 * that relink and unlink — and the package's own sandbox never rendered it.
 * The line is where the desk is tightest on a phone, so "how does it look when
 * a contact is attached" was a question that could only be answered on somebody
 * else's installation.
 *
 * Recognising is deliberately dumb: the first demo record stands for everyone.
 * This is a showroom, not an identity service — a host writes the real one, and
 * what it must and must not do is in `docs/operator-desk.md`.
 */
final class DemoContacts implements ContactDirectory
{
    public function identify(Conversation $conversation, InboundMessage $message): ?Model
    {
        return null;
    }

    public function describe(Conversation $conversation): ?ContactCard
    {
        $record = DemoRecord::query()->first();

        if ($record === null) {
            return null;
        }

        return new ContactCard(
            name: (string) $record->name,
            note: 'постоянный клиент',
            url: null,
        );
    }
}
