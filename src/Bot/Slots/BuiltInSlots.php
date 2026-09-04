<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Slots;

/**
 * The slots every installation gets without registering anything.
 *
 * Eight, and deliberately no more: a name, three ways to reach someone, what
 * they want, how soon, a reference they may already have, and who they work
 * for. Nothing here names an industry — there is no "destination" and no
 * "symptom" — because a travel agency and a clinic install the same package,
 * and a slot picker full of somebody else's vocabulary is worse than an empty
 * one. Everything past this list the host registers itself through the
 * registry, which is the whole reason the registry exists.
 *
 * The wording is a starting point, not a fixture. Each slot carries a default
 * question so that an author can assemble a working form before writing a
 * single line of copy, and every scenario may override it.
 */
final class BuiltInSlots
{
    /**
     * @return list<Slot>
     */
    public static function all(): array
    {
        return [
            Slot::text('name')
                ->label('Name')
                ->question('How should I address you?')
                ->group('contact'),

            Slot::phone('phone')
                ->label('Phone')
                ->question('What is the best number to reach you on?')
                ->group('contact'),

            Slot::email('email')
                ->label('Email')
                ->question('What is your email address?')
                ->group('contact'),

            Slot::choice('contact_preference', [
                'phone' => 'A phone call',
                'email' => 'Email',
                'messenger' => 'A messenger',
            ])
                ->label('Preferred way to reach you')
                ->question('How would you like us to get back to you?')
                ->group('contact'),

            Slot::text('company')
                ->label('Company')
                ->question('Which company are you with?')
                ->group('contact'),

            Slot::longText('request')
                ->label('Request')
                ->question('Tell me what you need')
                ->group('request'),

            Slot::choice('urgency', [
                'now' => 'Right now',
                'today' => 'Today',
                'this_week' => 'This week',
                'no_rush' => 'No rush',
            ])
                ->label('Urgency')
                ->question('How soon do you need this?')
                ->group('request'),

            /*
             * "Reference number" rather than a booking, an order or a case
             * number: whatever the host calls the thing people quote back at
             * it, this is the slot it lands in, and a name borrowed from one
             * industry would read as a mistake in every other one.
             */
            Slot::text('order_number')
                ->label('Reference number')
                ->question('Do you have a reference number for this?')
                ->group('request'),
        ];
    }
}
