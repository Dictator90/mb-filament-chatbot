<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Chat;

use Illuminate\Support\Facades\Storage;
use MB\FilamentChatbot\Models\Attachment;
use MB\FilamentChatbot\Models\Conversation;
use Throwable;

/**
 * Removes a conversation and everything that was said in it.
 *
 * Rows go by themselves: messages, runs, run steps and attachment records all
 * hang off the conversation with `cascadeOnDelete`, so `$conversation->delete()`
 * takes the transcript with it. The files do not, and that is the whole reason
 * this class exists rather than a `delete()` call at the call site. An
 * attachment row is the only thing that knows where its bytes are; delete the
 * row and the photo somebody sent stays on the disk, reachable by anybody who
 * kept its address, with nothing left in the database to find it by. A screen
 * that offers to delete a conversation would then be telling the operator
 * something untrue about what it did.
 *
 * **Files first, rows after.** The order matters only when the second half
 * fails, and then it decides which kind of wreckage is left: a thread whose
 * images are gone, which an operator can see and delete again, or files nobody
 * can see and nobody will ever delete. The first is recoverable, the second is
 * personal data kept by accident.
 *
 * A file that will not delete is not allowed to stop the deletion. The usual
 * cause is a file already gone — removed by hand, lost in a disk migration —
 * and refusing to erase a conversation over bytes that are not there would
 * leave the only thing the operator asked for undone.
 */
final class ConversationEraser
{
    public function erase(Conversation $conversation): void
    {
        $this->eraseFiles($conversation);

        $conversation->delete();
    }

    private function eraseFiles(Conversation $conversation): void
    {
        Attachment::query()
            ->where('conversation_id', $conversation->getKey())
            ->get(['id', 'disk', 'path'])
            ->each(function (Attachment $attachment): void {
                $disk = trim((string) $attachment->disk);
                $path = trim((string) $attachment->path);

                if ($disk === '' || $path === '') {
                    return;
                }

                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable) {
                }
            });
    }
}
