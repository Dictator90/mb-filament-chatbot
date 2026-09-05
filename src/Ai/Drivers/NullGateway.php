<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai\Drivers;

use MB\FilamentChatbot\Ai\AiFailure;
use MB\FilamentChatbot\Ai\AiGateway;
use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\AiReply;

/**
 * The gateway the package ships with: it declines, always.
 *
 * This is not a stub waiting to be replaced — it is the state every
 * installation starts in and many stay in, and the suite runs against it from
 * end to end for that reason. A scenario that only makes sense once somebody
 * has pasted an API key is a scenario that was broken on the day it was drawn,
 * and the only way to keep finding that out early is to make "no model" the
 * default a package is tested in.
 *
 * It is also where an unknown driver name lands ({@see AiGatewayFactory}),
 * so a typo in configuration degrades the bot instead of taking the panel down.
 */
final class NullGateway implements AiGateway
{
    public function name(): string
    {
        return 'null';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function ask(AiPrompt $prompt): AiReply
    {
        return AiReply::declined(AiFailure::NotConfigured);
    }
}
