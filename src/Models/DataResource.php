<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MB\FilamentChatbot\Database\Factories\DataResourceFactory;
use MB\FilamentChatbot\Enums\DataFilterOperator;
use MB\FilamentChatbot\Exceptions\DataQueryException;

/**
 * One declared window onto a model of the host application.
 *
 * This class is the declaration and nothing else: it answers what is allowed and
 * refuses what is not. Building and running the query is a separate concern, and
 * the split is deliberate — the rules have to be readable, testable and quotable
 * on their own, without a database connection or a request behind them.
 *
 * Every question here is answered from the stored declaration, which is why the
 * normalising happens in one place. A descriptor may arrive as a list of arrays
 * or as a map keyed by field name, from a form, a seeder or a config file, and
 * every method downstream would otherwise carry its own guesswork about which
 * shape it received.
 *
 * @property string $key
 * @property string $label
 * @property string|null $model
 * @property string|null $connection
 * @property string|null $source_table
 * @property array<int, string> $modes
 * @property array<array-key, mixed> $fields
 * @property array<string, mixed>|null $default_sort
 * @property int $default_limit
 * @property int $max_limit
 * @property array<int, array<string, mixed>>|null $scopes
 * @property bool $is_writable
 * @property array<int, string>|null $write_fields
 * @property string|null $lock_column
 * @property bool $is_active
 */
class DataResource extends Model
{
    /** @use HasFactory<DataResourceFactory> */
    use HasFactory;

    protected $table = 'chatbot_data_resources';

    protected $guarded = [];

    /**
     * Normalised descriptors, memoised for the lifetime of the instance.
     *
     * A single reply asks the same resource about a dozen fields; re-parsing the
     * json for each of them is work with no possible new answer.
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $normalisedFields = null;

    protected function casts(): array
    {
        return [
            'modes' => 'array',
            'fields' => 'array',
            'default_sort' => 'array',
            'scopes' => 'array',
            'write_fields' => 'array',
            'default_limit' => 'integer',
            'max_limit' => 'integer',
            'is_writable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this resource is a table somewhere rather than a model here.
     *
     * The second kind exists because not every readable thing has a model: a
     * reporting replica, a legacy database, another service's schema on another
     * host. There is no class to introspect, so the table and its columns are
     * named by whoever declares it, and nothing about the declaration can be
     * checked against code that does not exist.
     *
     * **Read-only, and that is a rule rather than a limitation of the moment.**
     * A write needs a key column, a lock column and a model whose events the
     * host expects to fire; a foreign table offers none of the three, and a
     * package writing into somebody's reporting replica because a scenario said
     * so is the kind of thing found afterwards.
     */
    public function isTableBacked(): bool
    {
        return trim((string) $this->source_table) !== '';
    }

    /**
     * Field descriptors keyed by field name.
     *
     * Anything absent from a stored descriptor resolves to the closed value —
     * not returnable, not filterable, not searchable, not sortable — so a field
     * added to the list with nothing but a name grants no access at all. The
     * opposite default would make every future key the package adds retroactively
     * permissive on declarations written before it existed.
     *
     * @return array<string, array{name: string, label: string, returned_by_default: bool, filterable: bool, searchable: bool, sortable: bool, sensitive: bool, operators: array<int, string>}>
     */
    public function fieldDescriptors(): array
    {
        if ($this->normalisedFields !== null) {
            return $this->normalisedFields;
        }

        $descriptors = [];

        foreach ((array) $this->fields as $index => $declared) {
            $declared = is_array($declared) ? $declared : ['name' => $declared];

            $name = (string) ($declared['name'] ?? (is_string($index) ? $index : ''));

            if ($name === '') {
                continue;
            }

            $descriptors[$name] = $this->normaliseDescriptor($name, $declared);
        }

        return $this->normalisedFields = $descriptors;
    }

    /**
     * Fields that may ever leave the application.
     *
     * Sensitivity is checked here rather than by the caller because there is no
     * legitimate reading of a sensitive field that also returns it: the flag is
     * the whole statement.
     *
     * @return array<int, string>
     */
    public function returnableFields(): array
    {
        return array_values(array_keys(array_filter(
            $this->fieldDescriptors(),
            fn (array $descriptor): bool => $descriptor['sensitive'] === false,
        )));
    }

    /**
     * The fields returned when a query names none.
     *
     * When the declaration marks nothing as returned by default, this falls back
     * to the first safe field — one field, not the whole set. An empty selection
     * degrading into "everything" is precisely how a column nobody approved ends
     * up in a reply: the declaration that forgot to mark its defaults is the same
     * declaration that never thought about which columns are fit to show, and
     * reading silence as consent turns an oversight into a disclosure. One field
     * gives a visibly incomplete answer, which is a bug report; all of them give
     * a complete-looking answer, which is an incident.
     *
     * A sensitive field marked returned_by_default is a declaration contradicting
     * itself, and it is resolved against returning. Consistency is not worth an
     * argument here: one of the two flags leaks data when it is the one obeyed,
     * and the other one merely withholds it.
     *
     * @return array<int, string>
     */
    public function defaultSelect(): array
    {
        $marked = array_values(array_keys(array_filter(
            $this->fieldDescriptors(),
            fn (array $descriptor): bool => $descriptor['sensitive'] === false
                && $descriptor['returned_by_default'] === true,
        )));

        if ($marked !== []) {
            return $marked;
        }

        return array_slice($this->returnableFields(), 0, 1);
    }

    public function allowsField(string $field): bool
    {
        return array_key_exists($field, $this->fieldDescriptors());
    }

    /**
     * Whether a field accepts an operator.
     *
     * An undeclared field answers false rather than throwing, so that a caller
     * building a form can ask without guarding. The throwing counterparts below
     * are what a query runner uses.
     */
    public function allowsOperator(string $field, string $operator): bool
    {
        $descriptor = $this->fieldDescriptors()[$field] ?? null;

        return $descriptor !== null && in_array($operator, $descriptor['operators'], true);
    }

    public function allowsMode(string $mode): bool
    {
        return in_array($mode, array_map(
            static fn (mixed $declared): string => (string) $declared,
            (array) $this->modes,
        ), true);
    }

    /**
     * An undeclared field counts as sensitive.
     *
     * The question is only ever asked to decide whether something may be shown,
     * and "we have never heard of this column" is not a reason to show it.
     */
    public function isSensitive(string $field): bool
    {
        $descriptor = $this->fieldDescriptors()[$field] ?? null;

        return $descriptor === null || $descriptor['sensitive'] === true;
    }

    /**
     * The row limit for one query, or a refusal.
     *
     * Asking for more than the ceiling fails instead of being trimmed. Trimming
     * reads as an answer to the question that was asked — "there are 40 matches"
     * when the caller asked for 500 and the ceiling is 40 — and a caller that
     * cannot tell a full result from a truncated one will report the truncated
     * one as the truth.
     *
     * A missing or non-positive request is a different case: it asks for nothing
     * in particular rather than for more than allowed, so it takes the declared
     * default, itself held under the ceiling in case the two were configured
     * out of step.
     */
    public function cappedLimit(?int $requested): int
    {
        $maximum = max(1, (int) $this->max_limit);
        $default = min(max(1, (int) $this->default_limit), $maximum);

        if ($requested === null || $requested < 1) {
            return $default;
        }

        if ($requested > $maximum) {
            throw DataQueryException::limitTooHigh((string) $this->key, $requested, $maximum);
        }

        return $requested;
    }

    /**
     * @throws DataQueryException
     */
    public function assertAllowsField(string $field): void
    {
        if (! $this->allowsField($field)) {
            throw DataQueryException::unknownField((string) $this->key, $field);
        }
    }

    /**
     * @throws DataQueryException
     */
    public function assertAllowsOperator(string $field, string $operator): void
    {
        $this->assertAllowsField($field);

        if (! $this->allowsOperator($field, $operator)) {
            throw DataQueryException::operatorNotAllowed((string) $this->key, $field, $operator);
        }
    }

    /**
     * @throws DataQueryException
     */
    public function assertAllowsMode(string $mode): void
    {
        if (! $this->allowsMode($mode)) {
            throw DataQueryException::modeNotAllowed((string) $this->key, $mode);
        }
    }

    /**
     * @throws DataQueryException
     */
    public function assertReturnable(string $field): void
    {
        $this->assertAllowsField($field);

        if ($this->isSensitive($field)) {
            throw DataQueryException::sensitiveField((string) $this->key, $field);
        }
    }

    /**
     * Turns one stored descriptor into the shape the rest of the package reads.
     *
     * Two rules are applied while normalising rather than at query time, because
     * a rule enforced at the point of use is a rule every future call site has to
     * remember:
     *
     * A field with no operator list and a filterable flag gets equality only. The
     * tempting alternative — every operator the type could support — hands out
     * range and substring matching to declarations that asked for a lookup.
     *
     * Substring operators are stripped from any field not marked searchable, even
     * when the declaration lists them. Text matching is the one comparison whose
     * cost and whose blast radius are both chosen by whoever is typing in the
     * chat, and searchable is where somebody accepted that.
     *
     * @param  array<string, mixed>  $declared
     * @return array{name: string, label: string, returned_by_default: bool, filterable: bool, searchable: bool, sortable: bool, sensitive: bool, operators: array<int, string>}
     */
    protected function normaliseDescriptor(string $name, array $declared): array
    {
        $sensitive = (bool) ($declared['sensitive'] ?? false);
        $searchable = (bool) ($declared['searchable'] ?? false);

        $operators = array_values(array_filter(
            array_map(
                static fn (mixed $operator): ?string => $operator instanceof DataFilterOperator
                    ? $operator->value
                    : (is_string($operator) ? $operator : null),
                (array) ($declared['operators'] ?? []),
            ),
            static fn (?string $operator): bool => $operator !== null
                && DataFilterOperator::tryFrom($operator) !== null,
        ));

        $filterable = (bool) ($declared['filterable'] ?? $operators !== []);

        if (! $filterable) {
            $operators = [];
        } elseif ($operators === []) {
            $operators = [DataFilterOperator::Equals->value];
        }

        if (! $searchable) {
            $textual = array_map(
                static fn (DataFilterOperator $operator): string => $operator->value,
                DataFilterOperator::textual(),
            );

            $operators = array_values(array_diff($operators, $textual));
        }

        return [
            'name' => $name,
            'label' => (string) ($declared['label'] ?? $name),
            'returned_by_default' => (bool) ($declared['returned_by_default'] ?? false),
            'filterable' => $filterable,
            'searchable' => $searchable,
            'sortable' => (bool) ($declared['sortable'] ?? false),
            'sensitive' => $sensitive,
            'operators' => array_values(array_unique($operators)),
        ];
    }

    /**
     * Drops the memoised descriptors whenever the raw declaration is replaced.
     *
     * Without this, a resource edited and re-read inside one request — a form
     * saving and then rendering, a test rewriting its fixture — would keep
     * answering from the field list it had before the change, and the stale
     * answer is the permissive one.
     *
     * @param  string  $key
     * @param  mixed  $value
     */
    public function setAttribute($key, $value): mixed
    {
        if ($key === 'fields') {
            $this->normalisedFields = null;
        }

        return parent::setAttribute($key, $value);
    }

    protected static function newFactory(): Factory
    {
        return DataResourceFactory::new();
    }
}
