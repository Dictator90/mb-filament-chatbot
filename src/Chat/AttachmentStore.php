<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Chat;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MB\FilamentChatbot\Models\Attachment;
use MB\FilamentChatbot\Models\Message;

/**
 * The one place a file enters a conversation.
 *
 * Both ends upload — the visitor from a page this application does not control,
 * the operator from the desk — and every rule below has to hold for both. Two
 * copies of them would eventually be one copy with the type check and one copy
 * with the size check, and the missing half would be discovered by whoever
 * uploaded the thing it was supposed to stop.
 */
class AttachmentStore
{
    /**
     * The ceiling, in bytes, from `chatbot.widget.max_attachment_kb`.
     *
     * Read on every call rather than held, so a host raising it in a running
     * process is obeyed and so a test can lower it and see the refusal. The key
     * lives under the widget because that is where the number was first written
     * down; it governs the desk as well — see the note beside it in the config.
     */
    public function maxBytes(): int
    {
        return $this->maxKilobytes() * 1024;
    }

    public function maxKilobytes(): int
    {
        return max(1, (int) config('chatbot.widget.max_attachment_kb', 10240));
    }

    /**
     * @return list<string>
     */
    public function allowedMimes(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $mime): string => mb_strtolower(trim((string) $mime)),
            (array) config('chatbot.attachments.mimes', []),
        ), static fn (string $mime): bool => $mime !== ''));
    }

    public function disk(): string
    {
        return (string) config('chatbot.attachments.disk', 'local');
    }

    /**
     * The type of a file as its bytes report it, lower-cased.
     *
     * `getMimeType()` and never `getClientMimeType()`. The second is a header the
     * uploader wrote, so trusting it means a `.php` announced as `image/png`
     * passes the allow-list — and the extension is no better, since the uploader
     * chose that too. This one asks the file.
     */
    public function detectedMime(UploadedFile $file): string
    {
        return mb_strtolower((string) $file->getMimeType());
    }

    /**
     * Why this file would be refused, or null.
     *
     * Separate from `store()` so a caller can turn a refusal into a sentence next
     * to the box the person is looking at, without a message having been written
     * first. `store()` asks the same question again rather than trusting that
     * somebody did: a second caller that forgets is a file on disk nobody
     * checked.
     */
    public function refusalFor(UploadedFile $file): ?AttachmentRefused
    {
        if ($file->getSize() > $this->maxBytes()) {
            return AttachmentRefused::tooLarge($this->maxKilobytes());
        }

        $mime = $this->detectedMime($file);

        if (! in_array($mime, $this->allowedMimes(), true)) {
            return AttachmentRefused::wrongType($mime === '' ? 'unknown' : $mime);
        }

        return null;
    }

    /**
     * Writes the bytes and records where they went.
     *
     * **The name on disk has nothing to do with the name that arrived.** The sent
     * one is a string from a stranger's machine — it can be a path, it can be a
     * second extension, it can collide with the file somebody else uploaded a
     * second earlier — and it is kept only to be shown. What is written is a
     * random name plus the extension the *detected* type implies, so the
     * uploader chooses neither where the file lands nor what it is called there.
     *
     * @throws AttachmentRefused
     */
    public function store(Message $message, UploadedFile $file): Attachment
    {
        $refusal = $this->refusalFor($file);

        if ($refusal !== null) {
            throw $refusal;
        }

        $mime = $this->detectedMime($file);
        $disk = $this->disk();

        $directory = trim((string) config('chatbot.attachments.path', 'chatbot/attachments'), '/')
            .'/'.$message->conversation_id;

        $path = Storage::disk($disk)->putFileAs(
            $directory,
            $file,
            Str::random(40).$this->extensionFor($file),
        );

        return Attachment::create([
            'uuid' => (string) Str::uuid(),
            'message_id' => $message->getKey(),
            'conversation_id' => $message->conversation_id,
            'disk' => $disk,
            'path' => (string) $path,
            'name' => $this->displayName($file),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
        ]);
    }

    /**
     * The extension the detected type implies, with a leading dot, or nothing.
     *
     * Derived from the type rather than taken from the sent name, so a file whose
     * bytes are a PNG cannot be stored as `.phtml` because that is what it was
     * called. Nothing depends on the extension being right — the download route
     * sends the stored type — so an unknown one costs a file with no suffix and
     * not a wrong one.
     */
    protected function extensionFor(UploadedFile $file): string
    {
        $guessed = trim((string) $file->guessExtension());

        return $guessed === '' ? '' : '.'.mb_strtolower($guessed);
    }

    /**
     * What the transcript prints beside the file.
     *
     * The sent name with its directories taken off and its length cut, because
     * it is shown to an operator and to the other end of the conversation. It is
     * escaped where it is drawn, not here: a name mangled on the way in is a name
     * nobody can match against the file on their own machine.
     */
    protected function displayName(UploadedFile $file): string
    {
        $name = trim(str_replace(['\\', '/'], ' ', (string) $file->getClientOriginalName()));

        return $name === '' ? 'file' : mb_substr($name, 0, 200);
    }
}
