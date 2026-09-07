<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file inside one message.
 *
 * The row is the only thing that knows where the bytes are. The disk and the
 * path are stored rather than derived, because a host that moves attachments to
 * another disk next year must not thereby lose everything filed before the move
 * — a path rebuilt from today's configuration would point at nothing for every
 * older row, and the failure would be a transcript full of broken links rather
 * than an error anybody sees.
 */
class Attachment extends Model
{
    /**
     * The types a browser may be told to render in place.
     *
     * Raster images only, and the omissions are the point. `image/svg+xml` is an
     * image that carries script and runs it against this application's own
     * origin the moment a browser is asked to display it, and a PDF opened
     * inline runs in the viewer with the page's own address in the bar. Anything
     * not in this list is sent as a download, which is a file the browser saves
     * rather than a document it executes.
     *
     * @var list<string>
     */
    public const INLINE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    protected $table = 'chatbot_attachments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * Whether the transcript should show the picture rather than the name.
     *
     * Answered from the detected type and from nothing else. A file called
     * `holiday.jpg` that turns out to be a zip is drawn as a zip, because what
     * decides is what the bytes are.
     */
    public function isImage(): bool
    {
        return in_array($this->mime, self::INLINE_MIMES, true);
    }
}
