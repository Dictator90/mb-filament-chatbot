<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Bots\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use MB\FilamentChatbot\Channels\ChannelConnections;
use MB\FilamentChatbot\Channels\ChannelPolling;
use MB\FilamentChatbot\Channels\ChannelSetup;
use MB\FilamentChatbot\Filament\Resources\Bots\Pages\EditBot;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\ChannelConnection;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * The messengers this bot can be reached in, one inner tab each.
 *
 * **Per bot, not per installation.** A token identifies an account, and an
 * account answers as one bot: an agency running sales and support separately
 * has two Telegram bots, and settings held once per installation could express
 * only the first. That is why these live beside the bot's own name rather than
 * on a settings screen of their own.
 *
 * **The tabs are built from the registry**, not from a list written here. A
 * host that registers its own channel driver gets a tab for it with the fields
 * its provider asks for, without touching this class — and a channel that only
 * delivers, like the site widget, has nothing to set up and no tab.
 *
 * **State is loaded and saved by the page, not by a relationship.** Filament
 * can bind a group to a `hasOne`, but only to one named in advance, and the
 * whole point here is that the set of channels is open. The page fills
 * `channels.<key>.…` from the rows and writes them back
 * ({@see EditBot}).
 */
class BotChannels
{
    /**
     * @return array<int, Tab>
     */
    public static function tabs(): array
    {
        $tabs = [];

        foreach (self::drivers() as $key => $driver) {
            $tabs[] = Tab::make($key)
                ->label($driver->label())
                ->schema(self::fields($key, $driver));
        }

        return $tabs;
    }

    /**
     * The drivers that have something to set up, keyed by channel.
     *
     * @return array<string, ChannelSetup>
     */
    public static function drivers(): array
    {
        $drivers = [];

        foreach (app(ChatbotRegistry::class)->channels() as $key => $driver) {
            if ($driver instanceof ChannelSetup) {
                $drivers[$key] = $driver;
            }
        }

        return $drivers;
    }

    /**
     * What the form shows for one messenger.
     *
     * @return array<int, mixed>
     */
    protected static function fields(string $channel, ChannelSetup $driver): array
    {
        $fields = [
            Toggle::make("channels.{$channel}.is_active")
                ->label(__('chatbot::panel.bots.channels.is_active'))
                ->helperText(__('chatbot::panel.bots.channels.is_active_hint')),
        ];

        /*
         * Only where the driver can actually fetch. Offering the choice for a
         * provider with no polling API would let somebody switch a working
         * channel into a mode where nothing arrives and nothing says why.
         */
        if ($driver instanceof ChannelPolling) {
            $fields[] = Select::make("channels.{$channel}.delivery")
                ->label(__('chatbot::panel.bots.channels.delivery'))
                ->options([
                    ChannelConnection::DELIVERY_WEBHOOK => __('chatbot::panel.bots.channels.delivery_webhook'),
                    ChannelConnection::DELIVERY_POLL => __('chatbot::panel.bots.channels.delivery_poll'),
                ])
                ->default(ChannelConnection::DELIVERY_WEBHOOK)
                ->selectablePlaceholder(false)
                ->helperText(__('chatbot::panel.bots.channels.delivery_hint'));
        }

        foreach ($driver->credentialFields() as $name => $field) {
            $input = TextInput::make("channels.{$channel}.credentials.{$name}")
                ->label($field['label'])
                ->maxLength(255);

            if ($field['secret'] ?? false) {
                /*
                 * Revealable rather than write-only. A token that cannot be
                 * read back cannot be checked against the provider's dashboard
                 * either, and the person who may edit a bot is already the
                 * person who may read its transcripts.
                 */
                $input->password()->revealable();
            }

            if ($field['hint'] ?? null) {
                $input->helperText($field['hint']);
            }

            if ($field['required'] ?? false) {
                /*
                 * Required only while the channel is switched on. Half-filled
                 * credentials are the normal state of a messenger being set up,
                 * and refusing to save them would mean doing it in one sitting
                 * or not at all.
                 */
                $input->requiredIf("channels.{$channel}.is_active", true);
            }

            $fields[] = $input;
        }

        $fields[] = Text::make(fn (?Bot $record): string => self::summary($record, $channel))
            ->columnSpanFull();

        $fields[] = Actions::make([
            self::verifyAction($channel),
            self::installAction($channel),
        ])->columnSpanFull();

        return $fields;
    }

    /**
     * The address the provider delivers to, and what it last said.
     *
     * Shown as text rather than a disabled input because it is not a setting:
     * it is generated when the connection is first saved and never edited. The
     * sentence before it is the one a person needs while setting up — an
     * address that does not exist yet is the usual reason a webhook refuses.
     */
    protected static function summary(?Bot $record, string $channel): string
    {
        $connection = self::connection($record, $channel);

        if ($connection === null) {
            return __('chatbot::panel.bots.channels.unsaved');
        }

        $lines = $connection->delivery === ChannelConnection::DELIVERY_POLL
            ? [__('chatbot::panel.bots.channels.polled')]
            : [__('chatbot::panel.bots.channels.address').': '.self::webhookUrl($connection)];

        if ($connection->verified_at !== null) {
            $lines[] = __('chatbot::panel.bots.channels.verified', [
                'moment' => $connection->verified_at->diffForHumans(),
            ]);
        }

        if (filled($connection->last_error)) {
            $lines[] = __('chatbot::panel.bots.channels.last_error', ['reason' => $connection->last_error]);
        }

        if ($connection->last_event_at !== null) {
            $lines[] = __('chatbot::panel.bots.channels.last_event', [
                'moment' => $connection->last_event_at->diffForHumans(),
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * Asks the provider whether the saved credentials work.
     *
     * Disabled until the connection exists, because there is nothing to ask
     * about: the fields on screen are unsaved text, and checking them would
     * mean a second copy of the save logic that could disagree with the first.
     */
    protected static function verifyAction(string $channel): Action
    {
        return Action::make("verify_{$channel}")
            ->label(__('chatbot::panel.bots.channels.verify'))
            ->disabled(fn (?Bot $record): bool => self::connection($record, $channel) === null)
            ->action(function (?Bot $record) use ($channel): void {
                $connection = self::connection($record, $channel);
                $driver = self::drivers()[$channel] ?? null;

                if ($connection === null || $driver === null) {
                    return;
                }

                app(ChannelConnections::class)->forget();

                $status = $driver->verify($connection->refresh());

                $connection->forceFill([
                    'verified_at' => $status->ok ? now() : $connection->verified_at,
                    'last_error' => $status->ok ? null : $status->message,
                ])->save();

                $notification = Notification::make()->body($status->message);

                $status->ok ? $notification->success()->send() : $notification->danger()->send();
            });
    }

    /**
     * Points the provider at this connection's address, where it can be done.
     *
     * A provider with no API for it answers with instructions instead of a
     * failure — WhatsApp and VK are both registered by a person in somebody
     * else's dashboard, and reporting that as an error would send them looking
     * for a fault that is not there.
     */
    protected static function installAction(string $channel): Action
    {
        return Action::make("install_{$channel}")
            ->label(__('chatbot::panel.bots.channels.install'))
            ->disabled(fn (?Bot $record): bool => self::connection($record, $channel) === null)
            ->action(function (?Bot $record) use ($channel): void {
                $connection = self::connection($record, $channel);
                $driver = self::drivers()[$channel] ?? null;

                if ($connection === null || $driver === null) {
                    return;
                }

                app(ChannelConnections::class)->forget();

                $connection = $connection->refresh();
                $status = $driver->installWebhook($connection, self::webhookUrl($connection));

                $notification = Notification::make()
                    ->body($status->message)
                    ->persistent();

                match (true) {
                    $status->manual => $notification->info()->send(),
                    $status->ok => $notification->success()->send(),
                    default => $notification->danger()->send(),
                };
            });
    }

    /**
     * Reads what is saved rather than what is on screen.
     *
     * Deliberate: the actions beside the fields act on the provider, and acting
     * on half-typed credentials would report a verdict about something that was
     * never saved.
     */
    protected static function connection(?Bot $record, string $channel): ?ChannelConnection
    {
        if ($record === null || $record->getKey() === null) {
            return null;
        }

        return ChannelConnection::query()
            ->where('bot_id', $record->getKey())
            ->where('channel', $channel)
            ->first();
    }

    public static function webhookUrl(ChannelConnection $connection): string
    {
        return route('chatbot.webhook', [
            'channel' => $connection->channel,
            'connection' => $connection->public_id,
        ]);
    }

    /**
     * The saved connections, in the shape the form fields are named after.
     *
     * Channels with no row yet still get an entry, so that a messenger which
     * has never been set up draws empty fields rather than nothing at all.
     *
     * @return array<string, array{is_active: bool, delivery: string, credentials: array<string, string>}>
     */
    public static function state(Bot $bot): array
    {
        $saved = ChannelConnection::query()->where('bot_id', $bot->getKey())->get()->keyBy('channel');
        $state = [];

        foreach (self::drivers() as $key => $driver) {
            $connection = $saved->get($key);
            $credentials = [];

            foreach (array_keys($driver->credentialFields()) as $name) {
                $credentials[$name] = $connection?->credential($name) ?? '';
            }

            $state[$key] = [
                'is_active' => (bool) ($connection?->is_active ?? false),
                'delivery' => $connection?->delivery ?? ChannelConnection::DELIVERY_WEBHOOK,
                'credentials' => $credentials,
            ];
        }

        return $state;
    }

    /**
     * Writes the tabs back, one row per messenger that has anything in it.
     *
     * **A channel nobody filled in gets no row.** The alternative — a row per
     * registered driver for every bot — would fill the table with empty
     * connections, each with its own public address that answers 404 and its
     * own entry on the readiness screen saying it is not configured.
     *
     * An existing row is updated rather than replaced, because its `public_id`
     * is already registered on the provider's side: recreating it would point
     * a live webhook at an address that no longer exists, and nothing would say
     * so until a customer wrote.
     *
     * @param  array<string, array{is_active?: bool, delivery?: string, credentials?: array<string, string>}>  $state
     */
    public static function persist(Bot $bot, array $state): void
    {
        foreach (self::drivers() as $key => $driver) {
            $input = $state[$key] ?? [];
            $isActive = (bool) ($input['is_active'] ?? false);

            /*
             * Anything but a driver that polls, asked for in so many words,
             * means the webhook. The column is read by a command that runs
             * unattended, and a value it does not recognise would be a channel
             * that is neither delivered to nor fetched from.
             */
            $delivery = $driver instanceof ChannelPolling
                && ($input['delivery'] ?? null) === ChannelConnection::DELIVERY_POLL
                    ? ChannelConnection::DELIVERY_POLL
                    : ChannelConnection::DELIVERY_WEBHOOK;

            $credentials = [];

            foreach (array_keys($driver->credentialFields()) as $name) {
                $value = trim((string) ($input['credentials'][$name] ?? ''));

                if ($value !== '') {
                    $credentials[$name] = $value;
                }
            }

            $connection = ChannelConnection::query()
                ->where('bot_id', $bot->getKey())
                ->where('channel', $key)
                ->first();

            if ($connection === null && $credentials === [] && ! $isActive) {
                continue;
            }

            if ($connection === null) {
                ChannelConnection::query()->create([
                    'bot_id' => $bot->getKey(),
                    'channel' => $key,
                    'is_active' => $isActive,
                    'delivery' => $delivery,
                    'credentials' => $credentials,
                ]);

                continue;
            }

            $connection->forceFill([
                'is_active' => $isActive,
                'delivery' => $delivery,
                'credentials' => $credentials,
            ])->save();
        }

        app(ChannelConnections::class)->forget();
    }
}
