<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Filament\Resources\DataResources\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use MB\FilamentChatbot\Data\HostModels;
use MB\FilamentChatbot\Enums\DataFilterOperator;
use MB\FilamentChatbot\Enums\DataQueryMode;

class DataResourceForm
{
    /**
     * A list of column names as a select's options.
     *
     * Keyed by the name itself rather than by position: the value stored is the
     * column name, and a positional key would rewrite every declaration the day
     * a migration adds a column in the middle.
     *
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private static function columnOptions(array $columns): array
    {
        return array_combine($columns, $columns) ?: [];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('chatbot::panel.data_resources.heading'))->schema([
                Grid::make(2)->schema([
                    /*
                     * The key is how a scenario step names this resource. It is
                     * typed once and left alone: renaming it does not break
                     * loudly, it makes every step pointing here fail to find
                     * anything, which the author reads as "the lookup returned
                     * nothing" rather than "the lookup is broken".
                     */
                    TextInput::make('key')
                        ->label(__('chatbot::panel.attributes.key'))
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText(__('chatbot::panel.data_resources.key_help')),
                    TextInput::make('label')
                        ->label(__('chatbot::panel.attributes.label'))
                        ->required()
                        ->maxLength(191),
                ]),

                /*
                 * Chosen from what the installation has, not typed.
                 *
                 * It was a text box, and `App\Models\Oder` saves perfectly:
                 * nothing fails until a conversation months later, as a refused
                 * read the visitor sees as a bot that stopped answering. The
                 * list is a convenience and never a boundary — what may be read
                 * is the declaration's own business, checked on every query —
                 * so a value already stored stays selectable even when
                 * discovery has not heard of it.
                 *
                 * Live, because the column and relation lists below are read
                 * off whatever is chosen here.
                 */
                Select::make('model')
                    ->label(__('chatbot::panel.data_resources.model'))
                    ->required()
                    ->searchable()
                    ->live()
                    ->options(fn (Get $get): array => HostModels::including(
                        HostModels::options(),
                        $get('model'),
                    ))
                    ->helperText(__('chatbot::panel.data_resources.model_help')),

                Textarea::make('description')
                    ->label(__('chatbot::panel.attributes.description'))
                    ->rows(2)
                    ->maxLength(2000)
                    ->helperText(__('chatbot::panel.data_resources.description_help')),

                Textarea::make('query_guidance')
                    ->label(__('chatbot::panel.data_resources.guidance'))
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText(__('chatbot::panel.data_resources.guidance_help')),

                Toggle::make('is_active')
                    ->label(__('chatbot::panel.attributes.is_active'))
                    ->default(true)
                    ->helperText(__('chatbot::panel.data_resources.is_active_help')),
            ]),

            Section::make(__('chatbot::panel.data_resources.asked.heading'))->schema([
                /*
                 * Modes are per resource rather than global. A resource that
                 * answers "how many unpaid invoices" and never lists them is a
                 * legitimate and far cheaper declaration than one that hands
                 * rows out — and it stops being one the moment List is on by
                 * default.
                 */
                Select::make('modes')
                    ->label(__('chatbot::panel.data_resources.asked.modes'))
                    ->multiple()
                    ->options(DataQueryMode::class)
                    ->default([DataQueryMode::List->value])
                    ->required()
                    ->helperText(__('chatbot::panel.data_resources.asked.modes_help')),

                Grid::make(2)->schema([
                    TextInput::make('default_limit')
                        ->label(__('chatbot::panel.data_resources.asked.default_limit'))
                        ->numeric()
                        ->minValue(1)
                        ->default(10)
                        ->required(),
                    /*
                     * Asking for more than this fails rather than being cut
                     * down to it. A trimmed result reads as the answer to the
                     * question that was asked, and a caller that cannot tell a
                     * full result from a truncated one will quote the
                     * truncated one as the truth.
                     */
                    TextInput::make('max_limit')
                        ->label(__('chatbot::panel.data_resources.asked.max_limit'))
                        ->numeric()
                        ->minValue(1)
                        ->default(100)
                        ->required()
                        ->helperText(__('chatbot::panel.data_resources.asked.max_limit_help')),
                ]),

                Grid::make(2)->schema([
                    TextInput::make('default_sort.field')
                        ->label(__('chatbot::panel.data_resources.asked.sort_field'))
                        ->maxLength(191)
                        ->helperText(__('chatbot::panel.data_resources.asked.sort_field_help')),
                    Select::make('default_sort.direction')
                        ->label(__('chatbot::panel.data_resources.asked.direction'))
                        ->options(fn (): array => [
                            'asc' => __('chatbot::panel.data_resources.asked.ascending'),
                            'desc' => __('chatbot::panel.data_resources.asked.descending'),
                        ])
                        ->default('asc'),
                ]),
            ]),

            Section::make(__('chatbot::panel.data_resources.fields.heading'))
                ->description(__('chatbot::panel.data_resources.fields.description'))
                ->schema([
                    Repeater::make('fields')
                        ->hiddenLabel()
                        ->addActionLabel(__('chatbot::panel.data_resources.fields.add'))
                        ->defaultItems(1)
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->schema([
                            Grid::make(2)->schema([
                                /*
                                 * The model's own columns, read from the
                                 * schema. A list somebody maintains is wrong
                                 * the first time a migration adds a column;
                                 * this one cannot be. An accessor or a virtual
                                 * column typed before is kept, because it is
                                 * legitimate and the schema does not know it.
                                 */
                                Select::make('name')
                                    ->label(__('chatbot::panel.data_resources.fields.column'))
                                    ->required()
                                    ->searchable()
                                    ->options(fn (Get $get): array => HostModels::including(
                                        self::columnOptions(HostModels::columns($get('../../model'))),
                                        $get('name'),
                                    )),
                                TextInput::make('label')
                                    ->label(__('chatbot::panel.attributes.label'))
                                    ->maxLength(191)
                                    ->helperText(__('chatbot::panel.data_resources.fields.label_help')),
                            ]),

                            /*
                             * Sensitivity is the whole statement, not a hint,
                             * so it is live: the three boxes below react to it
                             * the moment it is ticked.
                             */
                            Checkbox::make('sensitive')
                                ->label(__('chatbot::panel.attributes.sensitive'))
                                ->live()
                                ->helperText(__('chatbot::panel.data_resources.fields.sensitive_help')),

                            Grid::make(4)->schema([
                                /*
                                 * Greyed out for a sensitive field, because a
                                 * declaration that ticks both is contradicting
                                 * itself and the runtime resolves the
                                 * contradiction against returning. A form that
                                 * let the two be ticked together would promise
                                 * a column in the answer that never appears in
                                 * it, and the author would go looking for the
                                 * bug in the scenario.
                                 */
                                Checkbox::make('returned_by_default')
                                    ->label(__('chatbot::panel.data_resources.fields.returned'))
                                    ->disabled(fn (Get $get): bool => (bool) $get('sensitive'))
                                    ->helperText(fn (Get $get): ?string => $get('sensitive')
                                        ? __('chatbot::panel.data_resources.fields.returned_blocked')
                                        : null),
                                /*
                                 * Text search is greyed out for the same
                                 * reason and one more of its own: substring
                                 * matching is the one comparison whose cost
                                 * and whose reach are both chosen by whoever
                                 * is typing in the chat, and the runtime
                                 * strips textual operators from any field that
                                 * is not searchable regardless of what is
                                 * declared.
                                 */
                                Checkbox::make('searchable')
                                    ->label(__('chatbot::panel.data_resources.fields.searchable'))
                                    ->disabled(fn (Get $get): bool => (bool) $get('sensitive'))
                                    ->helperText(fn (Get $get): ?string => $get('sensitive')
                                        ? __('chatbot::panel.data_resources.fields.searchable_blocked')
                                        : null),
                                /*
                                 * Filtering on a sensitive field is greyed out
                                 * even though it never returns the value.
                                 * Equality on a hidden column is an oracle: a
                                 * count of 1 confirms the value without ever
                                 * handing it over, and the visitor picks the
                                 * value. Narrowing to one person's rows is
                                 * what the access scope below is for — it takes
                                 * its value from server state, which is the
                                 * difference that matters.
                                 */
                                Checkbox::make('filterable')
                                    ->label(__('chatbot::panel.data_resources.fields.filterable'))
                                    ->disabled(fn (Get $get): bool => (bool) $get('sensitive'))
                                    ->helperText(fn (Get $get): ?string => $get('sensitive')
                                        ? __('chatbot::panel.data_resources.fields.filterable_blocked')
                                        : null),
                                Checkbox::make('sortable')
                                    ->label(__('chatbot::panel.attributes.sortable')),
                            ]),
                        ])
                        ->columnSpanFull(),
                ]),

            Section::make(__('chatbot::panel.data_resources.scopes.heading'))
                ->description(__('chatbot::panel.data_resources.scopes.description'))
                ->schema([
                    Repeater::make('scopes')
                        ->hiddenLabel()
                        ->addActionLabel(__('chatbot::panel.data_resources.scopes.add'))
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state): ?string => $state['field'] ?? null)
                        ->schema([
                            /*
                             * The relation comes first because the column list
                             * below depends on it: a scope may narrow on this
                             * model's own column, or — for a child table with no
                             * owner column of its own — on a column of the model
                             * it belongs to.
                             *
                             * Offered from declared return types, and nothing is
                             * called to find them. Invoking every public method
                             * of a host model to see what comes back is how a
                             * form ends up charging a card because somebody
                             * named their method `pay()`.
                             */
                            Select::make('relation')
                                ->label(__('chatbot::panel.data_resources.scopes.relation'))
                                ->live()
                                ->searchable()
                                ->placeholder(__('chatbot::panel.data_resources.scopes.relation_none'))
                                ->options(fn (Get $get): array => HostModels::including(
                                    HostModels::relations($get('../../model')),
                                    $get('relation'),
                                ))
                                ->helperText(__('chatbot::panel.data_resources.scopes.relation_help')),

                            Grid::make(3)->schema([
                                Select::make('field')
                                    ->label(__('chatbot::panel.data_resources.scopes.column'))
                                    ->required()
                                    ->searchable()
                                    ->options(fn (Get $get): array => HostModels::including(
                                        self::columnOptions(filled($get('relation'))
                                            ? HostModels::relatedColumns($get('../../model'), $get('relation'))
                                            : HostModels::columns($get('../../model'))),
                                        $get('field'),
                                    )),
                                /*
                                 * A source that resolves to nothing refuses the
                                 * query rather than dropping the condition.
                                 * The failure this guards against is a typo:
                                 * `actor.tenant_id` where the host meant
                                 * `tenant.id` keeps working, it just stops
                                 * restricting anything, and one resource
                                 * quietly starts answering with the whole
                                 * table.
                                 */
                                TextInput::make('source')
                                    ->label(__('chatbot::panel.attributes.source'))
                                    ->required()
                                    ->maxLength(191)
                                    ->placeholder('actor.id')
                                    ->helperText(__('chatbot::panel.data_resources.scopes.source_help')),
                                Select::make('operator')
                                    ->label(__('chatbot::panel.attributes.operator'))
                                    ->options(DataFilterOperator::class)
                                    ->default(DataFilterOperator::Equals->value)
                                    ->required(),
                            ]),
                        ])
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
