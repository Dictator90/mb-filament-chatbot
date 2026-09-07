<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use MB\FilamentChatbot\Http\AttachmentResponse;
use MB\FilamentChatbot\Models\Attachment;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file from a conversation, handed to a member of staff.
 *
 * The desk's own door, separate from the widget's, because the two answer
 * different questions about who is asking. The visitor's is answered by a token
 * that owns exactly one thread; this one is answered by the panel's guard and
 * the conversation policy — an operator may read any conversation the host lets
 * them read, and the file is part of it.
 *
 * **The panel is named in the path and the guard is asked of it.** `auth()`
 * would resolve the application's default guard, which on any host that signs
 * staff in separately is not the one the operator is signed into: the page would
 * refuse a person who is plainly logged in, and only on that host. Same reasoning
 * as `WidgetPreviewController`, which this follows deliberately.
 */
class AttachmentController
{
    public function __invoke(string $panel, string $attachment): StreamedResponse|BinaryFileResponse
    {
        $found = Filament::getPanel($panel, isStrict: false);

        abort_if($found === null, 404);

        Filament::setCurrentPanel($found);

        $user = Filament::auth()->user();

        abort_if($user === null, 403);

        $file = Attachment::query()->where('uuid', $attachment)->first();

        abort_if($file === null, 404);

        $conversation = $file->conversation;

        abort_if($conversation === null, 404);

        /*
         * Asked of the conversation and not of the file. Reading a thread and
         * reading what was attached to it are one permission; asking twice, in
         * two vocabularies, is how a host ends up granting one and not the other
         * and being surprised by which.
         */
        abort_if(Gate::forUser($user)->denies('view', $conversation), 403);

        return AttachmentResponse::for($file);
    }
}
