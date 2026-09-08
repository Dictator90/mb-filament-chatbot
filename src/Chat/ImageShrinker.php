<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Chat;

use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use MB\FilamentChatbot\Models\Attachment;
use Throwable;

/**
 * Cuts a photograph sent to the chat down to something worth keeping.
 *
 * **The file itself is shrunk, rather than a thumbnail made beside it.** A
 * customer sends a picture from their phone: twelve megapixels and five
 * megabytes for one photograph of a room. It is looked at in a panel the width
 * of a hand, and nothing above about fifteen hundred pixels is ever seen — not
 * on the screen and not in anything printed from it. A thumbnail beside the
 * original does not save that space, it doubles it: a conversation has no expiry
 * and the original is never looked at again.
 *
 * Fifteen hundred on the long side is the size at which a flight number is still
 * readable in a photograph of a ticket and a document number in a scan. Smaller,
 * and an attachment stops being a document.
 *
 * **Proportions are kept.** The frame is fitted inside the square rather than
 * cropped to it: a scan of a passport is a long rectangle, and a square crop
 * would take half of it away.
 *
 * **Shrinking is optional at every step, and every failure leaves the file
 * alone.** No GD extension, a driver that is not local, an unreadable format, a
 * disk that will not answer — all of them mean "keep it as it was". Losing a
 * customer's attachment because it could not be re-encoded is worse than keeping
 * the extra megabytes, and both are worse than an upload that fails.
 */
final class ImageShrinker
{
    /**
     * Written against GD and nothing else, deliberately.
     *
     * A package that required Imagick or an image library would be one more
     * thing to install before a chat box works, and the image library most hosts
     * already have is a PHP extension nobody had to choose. Where it is missing
     * this class does nothing, which is the same behaviour as a host that never
     * turned shrinking on.
     */
    public function available(): bool
    {
        return function_exists('imagecreatetruecolor')
            && function_exists('imagescale')
            && (bool) config('chatbot.attachments.shrink_images', true);
    }

    public function maxSide(): int
    {
        return max(100, (int) config('chatbot.attachments.max_image_side', 1500));
    }

    /**
     * Rewrites the stored file in place and corrects the recorded size.
     *
     * In place, after the file has landed, rather than on the stream before it
     * is written: attachments arrive by three different roads — the site widget,
     * a channel, an operator at the desk — and the one point they have in common
     * is a row that already exists.
     */
    public function shrink(?Attachment $attachment): void
    {
        if ($attachment === null || ! $this->shrinkable($attachment)) {
            return;
        }

        try {
            $path = Storage::disk($attachment->disk)->path($attachment->path);
            $size = @getimagesize($path);

            if ($size === false || max((int) $size[0], (int) $size[1]) <= $this->maxSide()) {
                return;
            }

            $image = $this->read($path, (string) $attachment->mime);

            if ($image === null) {
                return;
            }

            $scaled = imagescale($image, ...$this->fit((int) $size[0], (int) $size[1]));

            imagedestroy($image);

            if ($scaled === false) {
                return;
            }

            $written = $this->write($scaled, $path, (string) $attachment->mime);

            imagedestroy($scaled);

            if (! $written) {
                return;
            }

            clearstatcache(true, $path);

            $attachment->forceFill(['size' => (int) filesize($path)])->save();
        } catch (Throwable $error) {
            /*
             * Logged rather than swallowed in silence, and only warned about.
             * The customer's file is on disk and their message went through; what
             * failed is an optimisation. A host that never looks at the log loses
             * nothing but space, and one that does gets told which format its
             * GD build cannot re-encode.
             */
            Log::warning('Could not shrink a chat attachment.', [
                'attachment' => $attachment->getKey(),
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * The disk has to be local, because there is no path on this machine for a
     * file that lives in a bucket.
     *
     * Asked here rather than by the callers: moving the store to object storage
     * must not mean editing all three places an attachment arrives.
     */
    private function shrinkable(Attachment $attachment): bool
    {
        if (! $this->available() || ! str_starts_with((string) $attachment->mime, 'image/')) {
            return false;
        }

        return config('filesystems.disks.'.$attachment->disk.'.driver') === 'local';
    }

    /**
     * The new size, long side capped, proportions kept.
     *
     * `imagescale` takes a width and a height and stretches to fit them, so the
     * second one has to be computed rather than left to the library: passing the
     * cap for both is how a photograph of a receipt comes back square.
     *
     * @return array{0: int, 1: int}
     */
    private function fit(int $width, int $height): array
    {
        $side = $this->maxSide();

        return $width >= $height
            ? [$side, max(1, (int) round($height * $side / $width))]
            : [max(1, (int) round($width * $side / $height)), $side];
    }

    private function read(string $path, string $mime): ?GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            /*
             * GIF is deliberately absent. An animated one re-encodes to a single
             * still frame, and a customer who sent a short screen recording as a
             * GIF would find one motionless picture of it — a silent loss of
             * what they were trying to show.
             */
            default => false,
        };

        return $image instanceof GdImage ? $image : null;
    }

    private function write(GdImage $image, string $path, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => function_exists('imagejpeg') && @imagejpeg($image, $path, 82),
            /*
             * PNG keeps its transparency, so the alpha channel is saved rather
             * than flattened: a screenshot with a rounded corner would otherwise
             * come back with a black one.
             */
            'image/png' => function_exists('imagepng')
                && imagealphablending($image, false)
                && imagesavealpha($image, true)
                && @imagepng($image, $path, 6),
            'image/webp' => function_exists('imagewebp') && @imagewebp($image, $path, 82),
            default => false,
        };
    }
}
