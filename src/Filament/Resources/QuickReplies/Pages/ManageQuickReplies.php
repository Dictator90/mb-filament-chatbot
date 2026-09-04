<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\QuickReplies\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use MB\FilamentChatbot\Filament\Resources\QuickReplies\QuickReplyResource;

class ManageQuickReplies extends ManageRecords
{
    protected static string $resource = QuickReplyResource::class;

    public function getSubheading(): string
    {
        return 'Personal replies are yours alone; shared ones every operator sees. Both appear beside the reply box in the chat.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
