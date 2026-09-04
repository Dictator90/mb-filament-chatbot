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
use MB\FilamentChatbot\Enums\DataFilterOperator;
use MB\FilamentChatbot\Enums\DataQueryMode;

class DataResourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Resource')->schema([
                Grid::make(2)->schema([
                    /*
                     * The key is how a scenario step names this resource. It is
                     * typed once and left alone: renaming it does not break
                     * loudly, it makes every step pointing here fail to find
                     * anything, which the author reads as "the lookup returned
                     * nothing" rather than "the lookup is broken".
                     */
                    TextInput::make('key')
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText('How a scenario step names this resource. Set once.'),
                    TextInput::make('label')
                        ->required()
                        ->maxLength(191),
                ]),

                TextInput::make('model')
                    ->label('Model class')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('App\\Models\\Order')
                    ->helperText('The fully qualified Eloquent model this resource reads. Global scopes the application puts on it stay in force.'),

                Textarea::make('description')
                    ->rows(2)
                    ->maxLength(2000)
                    ->helperText('For whoever maintains the declaration.'),

                Textarea::make('query_guidance')
                    ->label('Guidance for the model')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Read by the language model when it decides how to query this resource.'),

                Toggle::make('is_active')
                    ->default(true)
                    ->helperText('A switched-off resource answers nothing, as if it were not declared.'),
            ]),

            Section::make('What may be asked')->schema([
                /*
                 * Modes are per resource rather than global. A resource that
                 * answers "how many unpaid invoices" and never lists them is a
                 * legitimate and far cheaper declaration than one that hands
                 * rows out — and it stops being one the moment List is on by
                 * default.
                 */
                Select::make('modes')
                    ->label('Query shapes')
                    ->multiple()
                    ->options(DataQueryMode::class)
                    ->default([DataQueryMode::List->value])
                    ->required()
                    ->helperText('Anything not listed here is refused, not adapted.'),

                Grid::make(2)->schema([
                    TextInput::make('default_limit')
                        ->label('Rows when none asked for')
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
                        ->label('Hard ceiling')
                        ->numeric()
                        ->minValue(1)
                        ->default(100)
                        ->required()
                        ->helperText('A query asking for more is refused, never trimmed. The installation-wide ceiling still wins if it is lower.'),
                ]),

                Grid::make(2)->schema([
                    TextInput::make('default_sort.field')
                        ->label('Default sort field')
                        ->maxLength(191)
                        ->helperText('Must be a field marked sortable below, or it is ignored.'),
                    Select::make('default_sort.direction')
                        ->label('Direction')
                        ->options(['asc' => 'Ascending', 'desc' => 'Descending'])
                        ->default('asc'),
                ]),
            ]),

            Section::make('Fields')
                ->description('A field listed with nothing ticked grants no access at all — every box left unticked resolves to the closed value.')
                ->schema([
                    Repeater::make('fields')
                        ->hiddenLabel()
                        ->addActionLabel('Add a field')
                        ->defaultItems(1)
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->schema([
                            Grid::make(2)->schema([
                                TextInput::make('name')
                                    ->label('Column')
                                    ->required()
                                    ->maxLength(191),
                                TextInput::make('label')
                                    ->maxLength(191)
                                    ->helperText('Shown to the model in place of the column name.'),
                            ]),

                            /*
                             * Sensitivity is the whole statement, not a hint,
                             * so it is live: the three boxes below react to it
                             * the moment it is ticked.
                             */
                            Checkbox::make('sensitive')
                                ->live()
                                ->helperText('Never leaves the application, whatever else is ticked.'),

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
                                    ->label('Returned by default')
                                    ->disabled(fn (Get $get): bool => (bool) $get('sensitive'))
                                    ->helperText(fn (Get $get): ?string => $get('sensitive')
                                        ? 'Unavailable: a sensitive field is never returned.'
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
                                    ->label('Text search')
                                    ->disabled(fn (Get $get): bool => (bool) $get('sensitive'))
                                    ->helperText(fn (Get $get): ?string => $get('sensitive')
                                        ? 'Unavailable: matching text against a hidden value reveals it.'
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
                                    ->label('Filterable')
                                    ->disabled(fn (Get $get): bool => (bool) $get('sensitive'))
                                    ->helperText(fn (Get $get): ?string => $get('sensitive')
                                        ? 'Unavailable: use an access scope. Equality on a hidden field confirms its value without returning it.'
                                        : null),
                                Checkbox::make('sortable'),
                            ]),
                        ])
                        ->columnSpanFull(),
                ]),

            Section::make('Access scope')
                ->description('Applied to every query of this resource, whatever the mode or the filters. The value is fetched from server state at query time and is never shown to the model or to the scenario author — a scope that can be read is a scope that can be argued with.')
                ->schema([
                    Repeater::make('scopes')
                        ->hiddenLabel()
                        ->addActionLabel('Add a condition')
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state): ?string => $state['field'] ?? null)
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('field')
                                    ->label('Column')
                                    ->required()
                                    ->maxLength(191),
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
                                    ->required()
                                    ->maxLength(191)
                                    ->placeholder('actor.id')
                                    ->helperText('actor.*, tenant.* or conversation.*. An unresolvable source refuses the query.'),
                                Select::make('operator')
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
