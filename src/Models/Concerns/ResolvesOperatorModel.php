<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Finds the class that stands for a member of staff in the host application.
 *
 * The package cannot name that class. Hosts call it `User`, `Employee`,
 * `Admin`, or keep panel users in a table of their own on a second connection,
 * and any of those would break a hard-coded `App\Models\User`. The lookup is
 * therefore configuration first, the default auth provider second — which is
 * what almost every application actually wants, so `chatbot.user_model` stays
 * null until an installation genuinely differs.
 *
 * The same resolution has to serve the conversation's assignee and the message's
 * author. Written out twice it would eventually be changed once, and a host with
 * a custom operator model would find half its chat screens pointing at the wrong
 * table.
 */
trait ResolvesOperatorModel
{
    /**
     * @return class-string<Model>
     */
    public static function operatorModel(): string
    {
        /** @var class-string<Model>|null $configured */
        $configured = config('chatbot.user_model');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        /** @var class-string<Model>|null $provider */
        $provider = config('auth.providers.users.model');

        if (is_string($provider) && $provider !== '') {
            return $provider;
        }

        return Authenticatable::class;
    }
}
