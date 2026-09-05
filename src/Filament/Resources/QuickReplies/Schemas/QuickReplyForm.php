<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\QuickReplies\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class QuickReplyForm
{
    /**
     * The option standing for an empty owner.
     *
     * A separate string rather than an empty one: an empty value in a Filament
     * select reads as "nothing chosen", and "shared with everyone" would then
     * look like a field the operator forgot to fill in.
     */
    private const SHARED = 'shared';

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(2)->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(191)
                    ->helperText('What the operator sees in the picker. Never shown to the visitor.'),
                TextInput::make('shortcut')
                    ->maxLength(64)
                    ->prefix('/')
                    ->helperText('Type it in the reply box and press Tab.'),
            ]),

            Textarea::make('body')
                ->required()
                ->rows(5)
                ->maxLength((int) config('chatbot.chat.max_message_length', 4000))
                ->helperText('Dropped into the reply box, where it can still be edited before sending.'),

            Grid::make(2)->schema([
                /*
                 * Bound straight to user_id rather than to an invented
                 * is_shared flag translated in page hooks: a shared reply is
                 * precisely a reply with no owner, and a second name for the
                 * same fact is how a form and a table start disagreeing.
                 *
                 * Two options rather than a list of people, because a reply
                 * cannot be handed to a colleague — a list of staff would offer
                 * something that does not exist.
                 */
                Select::make('user_id')
                    ->label('Whose')
                    ->options(fn (): array => [
                        (string) Filament::auth()->id() => 'Mine — only I see it',
                        self::SHARED => 'Shared with everyone',
                    ])
                    ->default(fn (): string => (string) Filament::auth()->id())
                    ->selectablePlaceholder(false)
                    ->required()
                    ->formatStateUsing(fn (mixed $state): string => $state === null
                        ? self::SHARED
                        : (string) $state)
                    ->dehydrateStateUsing(fn (?string $state): ?int => $state === self::SHARED
                        ? null
                        : (int) $state),
                TextInput::make('sort')
                    ->label('Order')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]),

            Toggle::make('is_active')
                ->label('Offered in the chat')
                ->default(true),
        ]);
    }
}
