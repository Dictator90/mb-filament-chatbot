<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Facades;

use Illuminate\Support\Facades\Facade;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * The registration surface a host application writes against.
 *
 * A facade over the registry rather than the registry itself, because this is
 * the one class hosts type by name in their service providers, and it should
 * read that way:
 *
 *     Chatbot::registerStep(PickBookingStep::class);
 *
 * @method static ChatbotRegistry registerStep(class-string<\MB\FilamentChatbot\Bot\Steps\StepType> $step)
 * @method static ChatbotRegistry registerSteps(iterable $steps)
 * @method static ChatbotRegistry forgetStep(string $key)
 * @method static \MB\FilamentChatbot\Bot\Steps\StepType|null step(string $key)
 * @method static array steps()
 * @method static array stepClasses()
 * @method static bool hasStep(string $key)
 * @method static ChatbotRegistry registerSlot(\MB\FilamentChatbot\Bot\Slots\Slot $slot)
 * @method static ChatbotRegistry registerSlots(iterable $slots)
 * @method static \MB\FilamentChatbot\Bot\Slots\Slot|null slot(string $key)
 * @method static array slots()
 *
 * @see ChatbotRegistry
 */
class Chatbot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ChatbotRegistry::class;
    }
}
