<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Chat;

use RuntimeException;

/**
 * A file the store would not take.
 *
 * Carries a reason key rather than a sentence, because the two callers say it in
 * different places: the widget endpoint answers a validation error to a browser
 * on somebody else's domain, and the desk puts a line under the reply box. A
 * message baked in here would be in the application's language on both, which is
 * wrong for the first — the visitor reads the site's language, not the panel's.
 */
final class AttachmentRefused extends RuntimeException
{
    public const TOO_LARGE = 'too_large';

    public const WRONG_TYPE = 'wrong_type';

    public const NOT_ALLOWED = 'not_allowed';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function tooLarge(int $limitKb): self
    {
        return new self(self::TOO_LARGE, "The file is larger than the {$limitKb} KB this installation accepts.");
    }

    public static function wrongType(string $mime): self
    {
        return new self(self::WRONG_TYPE, "Files of type [{$mime}] are not accepted.");
    }

    public static function notAllowed(): self
    {
        return new self(self::NOT_ALLOWED, 'This widget does not accept attachments.');
    }
}
