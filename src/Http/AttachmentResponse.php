<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Http;

use Illuminate\Support\Facades\Storage;
use MB\FilamentChatbot\Models\Attachment;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * How an attached file leaves the building, once somebody is allowed it.
 *
 * One place for both doors — the visitor's widget and the operator's desk —
 * because the headers below are the whole of the defence that survives after
 * authorisation has said yes, and a second copy of them would be the copy
 * without `nosniff`.
 *
 * Three rules, and each of them stops something specific:
 *
 * The type sent is the one detected from the bytes when the file arrived, never
 * the one the uploader announced. Sending back what they claimed would let an
 * uploader decide how their own file is interpreted in everybody else's browser.
 *
 * `X-Content-Type-Options: nosniff` goes with it, because a browser that is
 * allowed to disagree with the declared type will happily read a `text/plain`
 * upload as HTML and run the script inside it — on this application's origin,
 * which is where the panel's session lives.
 *
 * Only pictures are shown in place. Everything else is sent as a download, so a
 * PDF or a document is a file the reader saves rather than a document the
 * browser opens with the panel's address in the bar. Which types count as
 * pictures is a short list on the model, and it does not include SVG.
 */
final class AttachmentResponse
{
    public static function for(Attachment $attachment): StreamedResponse|BinaryFileResponse
    {
        $disposition = $attachment->isImage()
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        return Storage::disk((string) $attachment->disk)->response(
            (string) $attachment->path,
            (string) $attachment->name,
            [
                'Content-Type' => (string) $attachment->mime,
                'X-Content-Type-Options' => 'nosniff',
                /*
                 * Never cached by anything in between. The URL is the same for
                 * everybody who may read the file and the permission is not, so
                 * a shared cache holding one answer would hand a passport scan
                 * to the next person who asked for the same address.
                 */
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            $disposition,
        );
    }
}
