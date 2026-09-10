<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\Widgets\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Str;
use MB\FilamentChatbot\Channels\WidgetFeatures;
use MB\FilamentChatbot\Channels\WidgetLead;
use MB\FilamentChatbot\Channels\WidgetTheme;
use MB\FilamentChatbot\Http\WidgetEmbed;
use MB\FilamentChatbot\Models\Bot;

/**
 * Everything one placement of a bot decides: where it is embedded, what colour
 * it is, and what it says.
 *
 * Tabbed for the ordinary reason — there are five distinct questions here and a
 * flat column would run to two screens — but the split follows who asks each
 * one. Whoever sets the widget up answers Basics and Domains; whoever owns the
 * look answers Appearance and Wording; the embed code is for the person who
 * maintains the customer's page and never opens this panel at all.
 *
 * The preview sits outside the tabs on purpose. It is the answer to every one
 * of them, and a preview that vanished when somebody switched to Wording would
 * be a preview nobody trusted to be current.
 */
class WidgetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Tabs::make('widget')
                ->columnSpan(2)
                ->tabs([
                    Tab::make('basics')
                        ->label(__('chatbot::panel.site_widgets.tabs.basics'))
                        ->schema(self::basics()),
                    Tab::make('appearance')
                        ->label(__('chatbot::panel.site_widgets.tabs.appearance'))
                        ->schema(self::appearance()),
                    Tab::make('wording')
                        ->label(__('chatbot::panel.site_widgets.tabs.wording'))
                        ->schema(self::wording()),
                    Tab::make('sites')
                        ->label(__('chatbot::panel.site_widgets.tabs.sites'))
                        ->schema(self::sites()),
                    Tab::make('embed')
                        ->label(__('chatbot::panel.site_widgets.tabs.embed'))
                        ->schema(self::embed()),
                ]),

            Section::make(__('chatbot::panel.site_widgets.preview.heading'))
                ->description(__('chatbot::panel.site_widgets.preview.description'))
                ->columnSpan(1)
                ->schema([
                    WidgetPreview::make(),
                ]),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected static function basics(): array
    {
        return [
            Section::make(__('chatbot::panel.site_widgets.identity.heading'))->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label(__('chatbot::panel.attributes.name'))
                        ->required()
                        ->maxLength(191)
                        ->live(onBlur: true)
                        /*
                         * Fills the key only while it is still empty.
                         *
                         * Deriving it on every rename would rewrite the key of a
                         * widget already embedded on somebody else's page, and
                         * that page is not ours to fix: the tag would go on
                         * naming a widget that no longer exists, the endpoint
                         * would answer with no widget, and the chat would simply
                         * stop opening — with nothing logged here and nothing to
                         * see there. Renaming a widget is an everyday thing;
                         * changing what a customer's page points at is not.
                         */
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                            if (blank($get('key'))) {
                                $set('key', Str::slug($state ?? ''));
                            }
                        }),

                    TextInput::make('key')
                        ->label(__('chatbot::panel.attributes.key'))
                        ->required()
                        ->maxLength(64)
                        ->unique(ignoreRecord: true)
                        ->live(onBlur: true)
                        ->helperText(__('chatbot::panel.site_widgets.identity.key')),
                ]),

                /*
                 * Empty is a real answer here, not an unfinished one, so it is
                 * said in words. A widget with no bot opens the conversation and
                 * hands it straight to a person — which is how small
                 * installations run on purpose — and a placeholder reading
                 * "none" would have been read as "not configured yet".
                 */
                Select::make('bot_id')
                    ->label(__('chatbot::panel.site_widgets.identity.bot'))
                    ->relationship('bot', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder(__('chatbot::panel.site_widgets.identity.bot_placeholder'))
                    ->helperText(__('chatbot::panel.site_widgets.identity.bot_help'))
                    ->getOptionLabelFromRecordUsing(fn (Bot $record): string => $record->is_active
                        ? (string) $record->name
                        : __('chatbot::panel.site_widgets.identity.bot_inactive', ['name' => $record->name])),

                Toggle::make('is_active')
                    ->label(__('chatbot::panel.attributes.is_active'))
                    ->default(true)
                    ->live()
                    ->helperText(__('chatbot::panel.site_widgets.identity.is_active')),
            ]),

            /*
             * On Basics rather than on Appearance, and that is the same reason
             * these are not kept in `theme`. Whether this placement takes files
             * from strangers is a question about what it does, not about how it
             * looks; filed with the colours it would be found only by somebody
             * who had come to change the colours.
             *
             * The defaults are read from the value object rather than written
             * here, so a new widget and one saved before either switch existed
             * answer the same way. A second copy of "attachments off" would part
             * company with the first the day either moved, leaving a form that
             * shows one answer while the endpoint enforces the other.
             */
            Section::make(__('chatbot::panel.site_widgets.features.heading'))
                ->description(__('chatbot::panel.site_widgets.features.description'))
                ->schema([
                    Grid::make(2)->schema([
                        Toggle::make('features.emoji')
                            ->label(__('chatbot::panel.site_widgets.features.emoji'))
                            ->default(WidgetFeatures::defaults()['emoji'])
                            ->helperText(__('chatbot::panel.site_widgets.features.emoji_help'))
                            ->live(),
                        Toggle::make('features.attachments')
                            ->label(__('chatbot::panel.site_widgets.features.attachments'))
                            ->default(WidgetFeatures::defaults()['attachments'])
                            ->helperText(__('chatbot::panel.site_widgets.features.attachments_help'))
                            ->live(),
                        Toggle::make('features.receipts')
                            ->label(__('chatbot::panel.site_widgets.features.receipts'))
                            ->default(WidgetFeatures::defaults()['receipts'])
                            ->helperText(__('chatbot::panel.site_widgets.features.receipts_help'))
                            ->live(),
                        Toggle::make('features.replies')
                            ->label(__('chatbot::panel.site_widgets.features.replies'))
                            ->default(WidgetFeatures::defaults()['replies'])
                            ->helperText(__('chatbot::panel.site_widgets.features.replies_help'))
                            ->live(),
                    ]),
                ]),

            /*
             * The cookie names this placement may read off the page it sits on,
             * empty until somebody fills it in on purpose.
             *
             * A list rather than a switch, because a switch would say "read the
             * page's cookies" — and an embed on a customer's site that reads
             * every cookie there is is not a support widget, whatever the panel
             * calls it. Naming each one keeps the answer to "what does this
             * script collect?" on one screen, in words the person who put it
             * there chose.
             */
            /*
             * The opening form, off by default and off on purpose.
             *
             * A form in front of a chat box costs conversations — a proportion
             * of the people who would have asked simply do not — and that trade
             * belongs to the installation. What it collects is a claim and never
             * an identity: somebody typed a name into a box on a public page.
             * The help text says so, because the mistake it prevents is a
             * scenario built on "we have a phone number, so we know who this
             * is", which greets a first-time visitor with "good to see you
             * again" and an empty list of their orders.
             */
            Section::make(__('chatbot::panel.site_widgets.lead.heading'))
                ->description(__('chatbot::panel.site_widgets.lead.description'))
                ->schema([
                    Toggle::make('lead.enabled')
                        ->label(__('chatbot::panel.site_widgets.lead.enabled'))
                        ->helperText(__('chatbot::panel.site_widgets.lead.enabled_help'))
                        ->live(),

                    CheckboxList::make('lead.fields')
                        ->label(__('chatbot::panel.site_widgets.lead.fields'))
                        ->options(self::leadFields())
                        ->visible(fn (Get $get): bool => (bool) $get('lead.enabled'))
                        ->live(),

                    CheckboxList::make('lead.required')
                        ->label(__('chatbot::panel.site_widgets.lead.required'))
                        ->helperText(__('chatbot::panel.site_widgets.lead.required_help'))
                        /*
                         * Offered from what is being asked rather than from the
                         * whole list: a field marked required and not asked for
                         * is a rule that fires on nothing, and the author has no
                         * way to see that from the screen.
                         */
                        ->options(fn (Get $get): array => array_intersect_key(
                            self::leadFields(),
                            array_flip((array) $get('lead.fields')),
                        ))
                        ->visible(fn (Get $get): bool => (bool) $get('lead.enabled') && (array) $get('lead.fields') !== []),
                ]),

            Section::make(__('chatbot::panel.site_widgets.cookies.heading'))
                ->description(__('chatbot::panel.site_widgets.cookies.description'))
                ->schema([
                    TagsInput::make('cookies')
                        ->label(__('chatbot::panel.site_widgets.cookies.names'))
                        ->placeholder('utm_campaign')
                        ->helperText(__('chatbot::panel.site_widgets.cookies.help'))
                        ->nestedRecursiveRules(['string', 'max:64', 'regex:/^[A-Za-z0-9_.\-]+$/']),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function appearance(): array
    {
        $defaults = self::themeDefaults();

        return [
            Section::make(__('chatbot::panel.site_widgets.colours.heading'))
                ->description(__('chatbot::panel.site_widgets.colours.description'))
                ->schema([
                    Grid::make(2)->schema(array_map(
                        static fn (string $name): ColorPicker => ColorPicker::make("theme.{$name}")
                            ->label(__("chatbot::panel.site_widgets.colours.{$name}"))
                            ->hex()
                            ->default($defaults[$name])
                            ->live(onBlur: true),
                        ['accent', 'on_accent', 'surface', 'ink', 'muted', 'line', 'agent', 'agent_ink'],
                    )),
                ]),

            Section::make(__('chatbot::panel.site_widgets.shape.heading'))->schema([
                Grid::make(3)->schema([
                    TextInput::make('theme.radius')
                        ->label(__('chatbot::panel.site_widgets.shape.radius'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(28)
                        ->suffix('px')
                        ->default($defaults['radius'])
                        ->live(onBlur: true),
                    TextInput::make('theme.bubble_radius')
                        ->label(__('chatbot::panel.site_widgets.shape.bubble_radius'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(24)
                        ->suffix('px')
                        ->default($defaults['bubble_radius'])
                        ->live(onBlur: true),
                    TextInput::make('theme.width')
                        ->label(__('chatbot::panel.site_widgets.shape.width'))
                        ->numeric()
                        ->minValue(280)
                        ->maxValue(520)
                        ->suffix('px')
                        ->default($defaults['width'])
                        ->live(onBlur: true),
                ]),

                Grid::make(3)->schema([
                    Select::make('theme.bubbles')
                        ->label(__('chatbot::panel.site_widgets.shape.bubbles'))
                        ->options(self::choices('bubbles', WidgetTheme::STYLES))
                        ->default($defaults['bubbles'])
                        ->selectablePlaceholder(false)
                        ->live(),
                    Select::make('theme.launcher')
                        ->label(__('chatbot::panel.site_widgets.shape.launcher'))
                        ->options(self::choices('launcher', WidgetTheme::LAUNCHERS))
                        ->default($defaults['launcher'])
                        ->selectablePlaceholder(false)
                        ->live(),
                    Select::make('theme.position')
                        ->label(__('chatbot::panel.site_widgets.shape.position'))
                        ->options(self::choices('position', WidgetTheme::POSITIONS))
                        ->default($defaults['position'])
                        ->selectablePlaceholder(false)
                        ->live(),
                    Select::make('theme.composer')
                        ->label(__('chatbot::panel.site_widgets.shape.composer'))
                        ->options(self::choices('composer', WidgetTheme::COMPOSERS))
                        ->default($defaults['composer'])
                        ->helperText(__('chatbot::panel.site_widgets.shape.composer_help'))
                        ->selectablePlaceholder(false)
                        ->live(),
                ]),
            ]),

            Section::make(__('chatbot::panel.site_widgets.face.heading'))
                ->description(__('chatbot::panel.site_widgets.face.description'))
                ->schema([
                    Grid::make(2)->schema([
                        /*
                         * An absolute https address, never a path. The widget
                         * runs on the customer's domain, where a relative path
                         * resolves against *their* server and quietly lands on
                         * their 404 page — a broken picture nobody here can see.
                         */
                        TextInput::make('theme.avatar')
                            ->label(__('chatbot::panel.site_widgets.face.avatar'))
                            ->url()
                            ->maxLength(2048)
                            ->placeholder('https://example.com/bot.png')
                            ->helperText(__('chatbot::panel.site_widgets.face.avatar_help'))
                            ->live(onBlur: true),
                        /*
                         * The name in full, not the two letters.
                         *
                         * The field used to cap at two characters, and that cap
                         * outlived the widget it was written for: the initials
                         * are now taken from the first letter of each word, so
                         * two characters is a name of one word cut in half —
                         * «Мир на ладони» could not be typed at all, and
                         * whatever was typed instead was drawn on every page the
                         * widget stands on.
                         */
                        TextInput::make('theme.avatar_label')
                            ->label(__('chatbot::panel.site_widgets.face.avatar_label'))
                            ->maxLength(191)
                            ->helperText(__('chatbot::panel.site_widgets.face.avatar_label_help'))
                            ->live(onBlur: true),
                    ]),
                ]),

            /*
             * The three switches that add something to the chrome, gathered
             * rather than scattered among the colours. Each of them is a
             * sentence the widget says about itself on somebody else's page —
             * how long an answer takes, who is speaking, that this circle is a
             * chat — and read together they are a decision about how much the
             * widget claims. Filed under the corner radii they would be three
             * unrelated checkboxes.
             */
            Section::make(__('chatbot::panel.site_widgets.chrome.heading'))
                ->description(__('chatbot::panel.site_widgets.chrome.description'))
                ->schema([
                    Grid::make(3)->schema([
                        Toggle::make('theme.status')
                            ->label(__('chatbot::panel.site_widgets.chrome.status'))
                            ->default($defaults['status'])
                            ->helperText(__('chatbot::panel.site_widgets.chrome.status_help'))
                            ->live(),
                        Toggle::make('theme.authors')
                            ->label(__('chatbot::panel.site_widgets.chrome.authors'))
                            ->default($defaults['authors'])
                            ->helperText(__('chatbot::panel.site_widgets.chrome.authors_help'))
                            ->live(),
                        Toggle::make('theme.teaser')
                            ->label(__('chatbot::panel.site_widgets.chrome.teaser'))
                            ->default($defaults['teaser'])
                            ->helperText(__('chatbot::panel.site_widgets.chrome.teaser_help'))
                            ->live(),
                    ]),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function wording(): array
    {
        return [
            Section::make(__('chatbot::panel.site_widgets.wording.heading'))
                ->description(__('chatbot::panel.site_widgets.wording.description'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('labels.title')
                            ->label(__('chatbot::panel.site_widgets.wording.title'))
                            ->maxLength(191)
                            ->live(onBlur: true),
                        TextInput::make('labels.launcher')
                            ->label(__('chatbot::panel.site_widgets.wording.launcher'))
                            ->maxLength(191)
                            ->live(onBlur: true),
                        TextInput::make('labels.placeholder')
                            ->label(__('chatbot::panel.site_widgets.wording.placeholder'))
                            ->maxLength(191)
                            ->live(onBlur: true),
                        TextInput::make('labels.send')
                            ->label(__('chatbot::panel.site_widgets.wording.send'))
                            ->maxLength(191)
                            ->live(onBlur: true),
                        TextInput::make('labels.close')
                            ->label(__('chatbot::panel.site_widgets.wording.close'))
                            ->maxLength(191)
                            ->live(onBlur: true),
                    ]),
                ]),

            Section::make(__('chatbot::panel.site_widgets.greeting.heading'))->schema([
                Textarea::make('greeting')
                    ->label(__('chatbot::panel.attributes.greeting'))
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText(__('chatbot::panel.site_widgets.greeting.help'))
                    ->live(onBlur: true),
            ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function sites(): array
    {
        return [
            Section::make(__('chatbot::panel.site_widgets.sites.heading'))
                /*
                 * The description says "narrows" and never "blocks", and the
                 * difference is the whole field. Two lists decide where a tag
                 * works: the installation's own, which fails closed and admits
                 * nobody while it is empty, and this one, which only ever takes
                 * sites away from it. Read the other way round — empty means
                 * nowhere — every widget in every installation would go dark the
                 * first time somebody saved this form without filling it in.
                 */
                ->description(__('chatbot::panel.site_widgets.sites.description'))
                ->schema([
                    TagsInput::make('allowed_origins')
                        ->label(__('chatbot::panel.attributes.allowed_origins'))
                        ->placeholder('https://example.com')
                        ->live(onBlur: true)
                        ->helperText(__('chatbot::panel.site_widgets.sites.help')),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function embed(): array
    {
        return [
            /*
             * The tag, with this installation's own address and this widget's
             * own key in it.
             *
             * Whoever owns the site is not the person who can look either up.
             * Left to the documentation they paste the example — a script on
             * app.example.com naming a widget called `demo` — onto a live page,
             * and the chat never loads, with nothing logged here because nothing
             * ever arrives.
             *
             * Shown only when a copied tag would genuinely open a conversation.
             * A snippet handed over while the widget is off, or while no origin
             * is allowed, is a promise kept by a 403 on somebody else's page: it
             * installs cleanly, looks right in the source, and fails where
             * neither of us is watching.
             */
            Section::make(__('chatbot::panel.site_widgets.embed.heading'))->schema([
                /*
                 * Wrapped normally, never `pre-wrap`.
                 *
                 * Filament prints the value inside its own indented template, so
                 * under `pre-wrap` a newline and twelve spaces of that template
                 * arrive on screen ahead of the tag and eight more behind it —
                 * an empty first line and a stray indent that look like part of
                 * what you are being told to paste. The snippet is one line and
                 * has no whitespace worth keeping, so keeping none is right.
                 */
                Text::make(fn (Get $get): string => WidgetEmbed::snippet((string) $get('key')))
                    ->key('widget_snippet')
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(['style' => 'word-break: break-all;'])
                    ->copyable()
                    ->copyMessage(__('chatbot::panel.site_widgets.embed.copied'))
                    ->tooltip(__('chatbot::panel.site_widgets.embed.copy_tooltip'))
                    ->visible(fn (Get $get): bool => self::isEmbeddable($get)),

                /*
                 * The origins are reported for this widget and not for the
                 * installation. Naming only the configured list would answer a
                 * different question from the one a request is judged by, and be
                 * believed: somebody restricts a widget to one site, reads a
                 * list of five here, and pastes the tag onto a page where it
                 * will not answer.
                 */
                Text::make(function (Get $get): string {
                    $origins = WidgetEmbed::allowedOrigins($get('allowed_origins') ?? []);

                    return __('chatbot::panel.site_widgets.embed.hint', [
                        'origins' => $origins === []
                            ? __('chatbot::panel.site_widgets.embed.hint_none')
                            : implode(', ', $origins).'.',
                    ]);
                })
                    ->key('widget_snippet_hint')
                    ->visible(fn (Get $get): bool => self::isEmbeddable($get)),

                Text::make(fn (Get $get): string => self::embedObstacle($get))
                    ->key('widget_snippet_blocked')
                    ->color('warning')
                    ->visible(fn (Get $get): bool => ! self::isEmbeddable($get)),
            ]),
        ];
    }

    /**
     * Whether a tag copied out of this form right now would work.
     */
    protected static function isEmbeddable(Get $get): bool
    {
        return (bool) $get('is_active')
            && filled($get('key'))
            && WidgetEmbed::isServable();
    }

    /**
     * Names the one thing standing between this widget and a working embed.
     *
     * One reason at a time, nearest first. A list of everything that could be
     * wrong reads as a wall, and the reader only ever has to fix the first of
     * them before the next is worth knowing about.
     */
    protected static function embedObstacle(Get $get): string
    {
        if (! $get('is_active')) {
            return __('chatbot::panel.site_widgets.embed.blocked_inactive');
        }

        if (blank($get('key'))) {
            return __('chatbot::panel.site_widgets.embed.blocked_no_key');
        }

        if (! config('chatbot.widget.enabled', true)) {
            return __('chatbot::panel.site_widgets.embed.blocked_disabled');
        }

        return __('chatbot::panel.site_widgets.embed.blocked_no_origins');
    }

    /**
     * The shipped defaults, taken from the theme itself rather than repeated.
     *
     * A second copy of "#4f46e5" in this file would be a second answer to what
     * indigo the widget is, and the two would part company the first time
     * either moved — leaving a form that opens showing one colour and saves
     * another.
     *
     * @return array<string, mixed>
     */
    protected static function themeDefaults(): array
    {
        return WidgetTheme::make()->toArray();
    }

    /**
     * The fields an opening form may ask for, read from the value object rather
     * than listed here.
     *
     * A second copy would part company with the first the day either moved, and
     * the symptom is a checkbox in the panel for a field the endpoint drops in
     * silence — a placement that appears to collect a phone number and does not.
     *
     * @return array<string, string>
     */
    protected static function leadFields(): array
    {
        $options = [];

        foreach (WidgetLead::FIELDS as $field) {
            $options[$field] = __("chatbot::panel.site_widgets.lead.field_options.{$field}");
        }

        return $options;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    protected static function choices(string $group, array $values): array
    {
        $options = [];

        foreach ($values as $value) {
            $options[$value] = __("chatbot::panel.site_widgets.shape.{$group}_options.{$value}");
        }

        return $options;
    }
}
