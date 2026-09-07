<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Policies;

use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\BudgetPeriod;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\DataResource;
use MB\FilamentChatbot\Models\KnowledgeEntry;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\QualityTest;
use MB\FilamentChatbot\Models\QuickReply;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\ScenarioVersion;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Models\Widget;

/**
 * Which policy speaks for which model.
 *
 * A map rather than eight `Gate::policy()` calls in the provider, so that the
 * list is a thing a test can walk. A model added later and left out of it would
 * otherwise be authorised by nothing at all — and the failure is invisible,
 * because Laravel treats a model with no policy as "no opinion" and Filament
 * shows the screen.
 *
 * @return array<class-string, class-string<ChatbotPolicy>>
 */
final class Policies
{
    /**
     * @return array<class-string, class-string<ChatbotPolicy>>
     */
    public static function map(): array
    {
        return [
            Bot::class => BotPolicy::class,
            Widget::class => WidgetPolicy::class,
            BudgetPeriod::class => BudgetPeriodPolicy::class,
            Scenario::class => ScenarioPolicy::class,
            Step::class => StepPolicy::class,
            ScenarioVersion::class => ScenarioVersionPolicy::class,
            Message::class => MessagePolicy::class,
            RunStep::class => RunStepPolicy::class,
            KnowledgeEntry::class => KnowledgeEntryPolicy::class,
            QuickReply::class => QuickReplyPolicy::class,
            DataResource::class => DataResourcePolicy::class,
            Run::class => RunPolicy::class,
            Conversation::class => ConversationPolicy::class,
            QualityTest::class => QualityTestPolicy::class,
        ];
    }
}
