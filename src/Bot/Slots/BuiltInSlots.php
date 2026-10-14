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
            Slot::make('name', SlotKind::Name)
                ->label(static fn (): string => __('chatbot::nodes.slots.name.label'))
                ->question(static fn (): string => __('chatbot::nodes.slots.name.question'))
                ->group('contact'),

            Slot::phone('phone')
                ->label(static fn (): string => __('chatbot::nodes.slots.phone.label'))
                ->question(static fn (): string => __('chatbot::nodes.slots.phone.question'))
                ->group('contact'),

            Slot::email('email')
                ->label(static fn (): string => __('chatbot::nodes.slots.email.label'))
                ->question(static fn (): string => __('chatbot::nodes.slots.email.question'))
                ->group('contact'),

            Slot::choice(
                'contact_preference',
                static fn (): array => (array) __('chatbot::nodes.slots.contact_preference.options'),
            )
                ->label(static fn (): string => __('chatbot::nodes.slots.contact_preference.label'))
                ->question(static fn (): string => __('chatbot::nodes.slots.contact_preference.question'))
                ->group('contact'),

            Slot::text('company')
                ->label(static fn (): string => __('chatbot::nodes.slots.company.label'))
                ->question(static fn (): string => __('chatbot::nodes.slots.company.question'))
                ->group('contact'),

            Slot::longText('request')
                ->label(static fn (): string => __('chatbot::nodes.slots.request.label'))
                ->question(static fn (): string => __('chatbot::nodes.slots.request.question'))
                ->group('request'),

            Slot::choice(
                'urgency',
                static fn (): array => (array) __('chatbot::nodes.slots.urgency.options'),
            )
                ->label(static fn (): string => __('chatbot::nodes.slots.urgency.label'))
                ->question(static fn (): string => __('chatbot::nodes.slots.urgency.question'))
                ->group('request'),

            /*
             * "Reference number" rather than a booking, an order or a case
             * number: whatever the host calls the thing people quote back at
             * it, this is the slot it lands in, and a name borrowed from one
             * industry would read as a mistake in every other one.
             */
            Slot::text('order_number')
                ->label(static fn (): string => __('chatbot::nodes.slots.order_number.label'))
                ->question(static fn (): string => __('chatbot::nodes.slots.order_number.question'))
                ->group('request'),
        ];
    }
}
