<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Data;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use MB\FilamentChatbot\Enums\DataFilterOperator;
use MB\FilamentChatbot\Enums\DataQueryMode;
use MB\FilamentChatbot\Exceptions\DataQueryException;
use MB\FilamentChatbot\Models\DataResource;
use Throwable;

/**
 * Runs a declared read, and refuses everything that was not declared.
 *
 * The whole package's read access to the host application passes through this
 * one method, which is why every rule below is enforced here rather than by the
 * callers: a rule that lives in a call site is a rule the next call site has to
 * remember, and there will be more call sites — a bot step, a panel preview, a
 * tool exposed to a language model — each written on a different day.
 *
 * Nothing is repaired. An unknown field, an operator the field never listed and
 * a limit above the ceiling all end the query. The alternative in each case is
 * an answer that looks correct: a reply built on a column nobody approved, a
 * filter quietly widened, a partial result read as the whole set. A refusal is
 * seen by a developer; a silently repaired query is seen by a customer.
 */
class DataReader
{
    /**
     * @throws never
     */
    public function read(DataQuery $query, RuntimeContext $context): DataResult
    {
        if (config('chatbot.data_resources.enabled', true) !== true) {
            return DataResult::failed('Data resources are disabled.');
        }

        $resource = DataResource::query()
            ->where('key', $query->resource)
            ->where('is_active', true)
            ->first();

        if (! $resource instanceof DataResource) {
            return DataResult::failed("Data resource [{$query->resource}] is unknown or inactive.");
        }

        try {
            return $this->run($resource, $query, $context);
        } catch (DataQueryException $exception) {
            return DataResult::failed($exception->getMessage());
        } catch (Throwable $exception) {
            /*
             * A driver-level failure is reported as a refusal rather than allowed
             * to escape. A read for a chat reply must never take the surrounding
             * request down with it, and the scenario already has a branch for
             * "could not read" — this is that branch, not a new one.
             */
            return DataResult::failed('The data resource query could not be executed: '.$exception->getMessage());
        }
    }

    /**
     * @throws DataQueryException
     */
    protected function run(DataResource $resource, DataQuery $query, RuntimeContext $context): DataResult
    {
        $resource->assertAllowsMode($query->mode->value);

        $limit = $this->resolveLimit($resource, $query->limit);
        $select = $this->resolveSelect($resource, $query->select);

        if ($select === [] && $query->mode !== DataQueryMode::Count) {
            return DataResult::failed(
                "Data resource [{$resource->key}] declares no field that may be returned.",
            );
        }

        $builder = $this->baseQuery($resource);

        if ($builder === null) {
            return DataResult::failed("Data resource [{$resource->key}] points at a model that does not exist.");
        }

        $this->applyFilters($builder, $resource, $query->filters);
        $this->applyScopes($builder, $resource, $context);

        if ($query->mode === DataQueryMode::Count) {
            return DataResult::counted($builder->count());
        }

        $this->applySort($builder, $resource, $query->sort);

        /*
         * Only the approved columns are asked for, by name, in the SQL itself.
         *
         * The tempting shortcut is to fetch the model and serialise it, and it
         * is the simplest way there is to hand out a column nobody approved: the
         * host's `$appends` run on serialisation and put computed values in the
         * output that appear in no declaration and in no migration, `$casts` can
         * turn a column into a nested structure carrying more than the column
         * itself, and an eager-loaded relation arrives as a whole related row.
         * None of those are visible in the field list anyone reviewed. Naming the
         * columns means the answer can only ever contain what was declared, and
         * the row below is rebuilt from the declared names rather than from
         * whatever the model happens to be holding.
         */
        $builder->select($select);
        $builder->limit($query->mode === DataQueryMode::First ? 1 : $limit);

        $rows = [];

        foreach ($builder->get() as $record) {
            $rows[] = $this->flattenRecord($record, $select);
        }

        return DataResult::fromRows($rows);
    }

    /**
     * The row ceiling is the smaller of the two that were set.
     *
     * A resource declaring `max_limit` 500 does not get 500 when the installation
     * settled on 100. The installation-wide number is the one an administrator
     * chose for the whole application, and a resource row is edited far more
     * often and by more people — a form in the panel, a config file, a seeder in
     * a package the host installed. If the resource could raise its own ceiling,
     * the application-wide setting would not be a limit at all, only a default
     * that any declaration is free to ignore, and the first person to type a
     * large number into a form would be the one who decided how much data may
     * leave in one reply.
     *
     * @throws DataQueryException
     */
    protected function resolveLimit(DataResource $resource, ?int $requested): int
    {
        $ceiling = max(1, min(
            max(1, (int) $resource->max_limit),
            max(1, (int) config('chatbot.data_resources.max_limit', 100)),
        ));

        if ($requested !== null && $requested > 0 && $requested > $ceiling) {
            throw DataQueryException::limitTooHigh((string) $resource->key, $requested, $ceiling);
        }

        if ($requested !== null && $requested > 0) {
            return $requested;
        }

        return min($resource->cappedLimit(null), $ceiling);
    }

    /**
     * The columns that may leave, checked one by one.
     *
     * A sensitive field is refused here even when it was named explicitly, and
     * especially then: an explicit request is not a stronger claim than the
     * declaration, it is the exact case the declaration was written to answer.
     *
     * @param  array<int, string>  $requested
     * @return array<int, string>
     *
     * @throws DataQueryException
     */
    protected function resolveSelect(DataResource $resource, array $requested): array
    {
        if ($requested === []) {
            return $resource->defaultSelect();
        }

        foreach ($requested as $field) {
            $resource->assertReturnable($field);
        }

        return array_values(array_unique($requested));
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $builder
     * @param  list<array{field: string, operator: string, value: mixed}>  $filters
     *
     * @throws DataQueryException
     */
    protected function applyFilters(EloquentBuilder|QueryBuilder $builder, DataResource $resource, array $filters): void
    {
        foreach ($filters as $filter) {
            $field = $filter['field'];

            $resource->assertAllowsField($field);

            /*
             * A sensitive field may be filtered on by a scope and never by the
             * query, and the difference is not squeamishness about symmetry.
             *
             * An equality filter on a hidden column is an oracle: the value never
             * comes back, but the answer does. "Count records where passport =
             * 4517 123456" returning 1 confirms the passport number without ever
             * printing it, and a few hundred of those confirm it digit by digit.
             * The `sensitive` flag would then mean "not printed" rather than "not
             * exposed", which is not what anybody ticking it believed it meant.
             *
             * A scope is the opposite case by construction: its value is fetched
             * from the server-side context, so the person asking cannot choose it
             * and learns nothing from the result they did not already know.
             */
            if ($resource->isSensitive($field)) {
                throw DataQueryException::sensitiveField((string) $resource->key, $field);
            }

            $resource->assertAllowsOperator($field, $filter['operator']);

            $operator = DataFilterOperator::from($filter['operator']);

            $this->compare($builder, $field, $operator, $filter['value']);
        }
    }

    /**
     * The declaration's own conditions, applied to every query without exception.
     *
     * Last, and unconditionally. There is no query shape, no mode and no filter
     * combination that skips this loop, because the scope is the only thing
     * standing between "show me my orders" and "show me the orders".
     *
     * Values come from the runtime context and nowhere else — see RuntimeContext
     * for why that boundary is the one that matters. A source that resolves to
     * null ends the query. Dropping the condition instead would mean a mistyped
     * source name silently converts a per-customer resource into a full table
     * read, with every row it returns looking exactly like a correct answer.
     *
     * **A scope may name a relation, and in a normalised schema it usually has
     * to.** A booking belongs to a customer through its order and carries no
     * customer column of its own; without this such a resource could not be
     * scoped at all, leaving two options — a table nobody can reach, or one
     * declared unscoped, which is the whole table. The relation name is
     * declared in the same reviewed file as the model class and trusted exactly
     * as far: a name Eloquent does not know throws, the read is refused, and a
     * refusal is the safe end of that mistake.
     *
     * @param  EloquentBuilder<Model>|QueryBuilder  $builder
     *
     * @throws DataQueryException
     */
    protected function applyScopes(EloquentBuilder|QueryBuilder $builder, DataResource $resource, RuntimeContext $context): void
    {
        foreach ((array) $resource->scopes as $scope) {
            if (! is_array($scope)) {
                continue;
            }

            $field = (string) ($scope['field'] ?? '');

            if ($field === '') {
                throw DataQueryException::unknownField((string) $resource->key, '');
            }

            $operator = DataFilterOperator::tryFrom((string) ($scope['operator'] ?? DataFilterOperator::Equals->value));

            if ($operator === null) {
                throw DataQueryException::operatorNotAllowed(
                    (string) $resource->key,
                    $field,
                    (string) ($scope['operator'] ?? ''),
                );
            }

            $relation = trim((string) ($scope['relation'] ?? ''));

            if (in_array($operator, DataFilterOperator::valueless(), true)) {
                $this->restrict($builder, $relation, $field, $operator, null);

                continue;
            }

            $source = (string) ($scope['source'] ?? '');
            $value = $context->resolve($source);

            if ($value === null) {
                throw DataQueryException::unknownField(
                    (string) $resource->key,
                    "{$field} (access scope source [{$source}] resolved to nothing)",
                );
            }

            $this->restrict($builder, $relation, $field, $operator, $value);
        }
    }

    /**
     * One scope condition, on this table or on a related one.
     *
     * `whereHas` and not a join: a join multiplies rows when the relation is a
     * has-many, and a limit applied afterwards would then return five copies of
     * two records — a wrong answer that reads as a short one.
     *
     * @param  EloquentBuilder<Model>|QueryBuilder  $builder
     */
    protected function restrict(
        EloquentBuilder|QueryBuilder $builder,
        string $relation,
        string $field,
        DataFilterOperator $operator,
        mixed $value,
    ): void {
        if ($relation === '') {
            $this->compare($builder, $field, $operator, $value);

            return;
        }

        /*
         * A table has no relations to reach through, and a scope that cannot be
         * applied must stop the read rather than be skipped. Skipped, the query
         * runs unrestricted and every row it returns looks like a correct
         * answer — which is the failure this whole class is built against.
         */
        if (! $builder instanceof EloquentBuilder) {
            throw DataQueryException::unknownField(
                $field,
                "{$field} (a scope through relation [{$relation}] needs a model, and this resource is a table)",
            );
        }

        $builder->whereHas($relation, function (EloquentBuilder $related) use ($field, $operator, $value): void {
            $this->compare($related, $field, $operator, $value);
        });
    }

    /**
     * Sorting falls back to the declaration rather than to whatever the database
     * feels like returning.
     *
     * An unsortable field is dropped instead of refused: unlike a filter or a
     * field selection, a sort order cannot widen what is returned, only reorder
     * it, and a query that fails because somebody sorted by the wrong column
     * would be a refusal with no security question behind it. What it must not
     * do is sort by an undeclared column anyway — that is an index scan chosen
     * by the visitor.
     *
     * @param  EloquentBuilder<Model>|QueryBuilder  $builder
     * @param  list<array{field: string, direction: string}>  $sort
     */
    protected function applySort(EloquentBuilder|QueryBuilder $builder, DataResource $resource, array $sort): void
    {
        $descriptors = $resource->fieldDescriptors();
        $applied = false;

        foreach ($sort as $entry) {
            $descriptor = $descriptors[$entry['field']] ?? null;

            if ($descriptor === null || $descriptor['sortable'] !== true) {
                continue;
            }

            $builder->orderBy($entry['field'], $entry['direction'] === 'desc' ? 'desc' : 'asc');
            $applied = true;
        }

        if ($applied) {
            return;
        }

        foreach ($this->declaredSort($resource) as $entry) {
            $builder->orderBy($entry['field'], $entry['direction']);
        }
    }

    /**
     * @return list<array{field: string, direction: string}>
     */
    protected function declaredSort(DataResource $resource): array
    {
        $declared = (array) $resource->default_sort;

        if ($declared === []) {
            return [];
        }

        $entries = array_key_exists('field', $declared) ? [$declared] : $declared;
        $descriptors = $resource->fieldDescriptors();
        $sort = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $field = (string) ($entry['field'] ?? '');

            if ($field === '' || ! array_key_exists($field, $descriptors)) {
                continue;
            }

            $sort[] = [
                'field' => $field,
                'direction' => ($entry['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            ];
        }

        return $sort;
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $builder
     */
    protected function compare(EloquentBuilder|QueryBuilder $builder, string $field, DataFilterOperator $operator, mixed $value): void
    {
        match ($operator) {
            DataFilterOperator::Equals => $builder->where($field, '=', $value),
            DataFilterOperator::NotEquals => $builder->where($field, '!=', $value),
            DataFilterOperator::In => $builder->whereIn($field, $this->listValue($value)),
            DataFilterOperator::NotIn => $builder->whereNotIn($field, $this->listValue($value)),
            DataFilterOperator::LessThan => $builder->where($field, '<', $value),
            DataFilterOperator::LessOrEqual => $builder->where($field, '<=', $value),
            DataFilterOperator::GreaterThan => $builder->where($field, '>', $value),
            DataFilterOperator::GreaterOrEqual => $builder->where($field, '>=', $value),
            DataFilterOperator::Contains => $builder->where($field, 'like', '%'.$this->escapeLike($value).'%'),
            DataFilterOperator::StartsWith => $builder->where($field, 'like', $this->escapeLike($value).'%'),
            DataFilterOperator::Between => $builder->whereBetween($field, $this->pairValue($value)),
            DataFilterOperator::IsNull => $builder->whereNull($field),
            DataFilterOperator::IsNotNull => $builder->whereNotNull($field),
        };
    }

    /**
     * Neutralises the wildcards before the value is bound.
     *
     * A lone `%` typed into the chat would otherwise turn a lookup into "match
     * every row" — a full scan the visitor chose, over a result set nobody
     * restricted, returned as though it were an answer to their question. `_` is
     * the same problem one character at a time, which is worse: it still looks
     * like a plausible search.
     *
     * The escaping neutralises them rather than making them match literally.
     * Doing the second properly needs an `ESCAPE` clause whose spelling differs
     * between drivers, and the price of not having it is that searching for a
     * literal per-cent sign finds nothing, which is a bad search result rather
     * than a disclosure.
     */
    protected function escapeLike(mixed $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $this->stringable($value));
    }

    /**
     * @return array<int, mixed>
     */
    protected function listValue(mixed $value): array
    {
        return array_values(array_filter(
            is_array($value) ? $value : [$value],
            static fn (mixed $item): bool => is_scalar($item) || $item === null,
        ));
    }

    /**
     * @return array<int, mixed>
     */
    protected function pairValue(mixed $value): array
    {
        $list = $this->listValue($value);

        return [$list[0] ?? null, $list[1] ?? null];
    }

    protected function stringable(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Instantiates the host model named in the declaration.
     *
     * Global scopes the host put on it are deliberately left in place. They are
     * the host's own rules about which rows exist — soft deletes, tenancy, an
     * archive filter — and this package has no standing to decide that a bot
     * reply is the one context where they do not apply.
     */
    protected function resolveModel(DataResource $resource): ?Model
    {
        $class = (string) $resource->model;

        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return new $class;
    }

    /**
     * The query this resource starts from: a model's, or a table's.
     *
     * A resource is one or the other. With a table named it is a table on a
     * configured connection — a reporting replica, a legacy database, another
     * service's schema — where there is no class to introspect and nothing to
     * check the declaration against; without one it is a model of this
     * application, as every resource was before.
     *
     * Everything after this point treats the two alike. Filters, the access
     * scope, the sort, the ceiling and the named column list are all query
     * builder work, and the one thing that differs — a scope reaching through a
     * relation — refuses on a table, because a table has no relations to reach
     * through.
     */
    protected function baseQuery(DataResource $resource): EloquentBuilder|QueryBuilder|null
    {
        if ($resource->isTableBacked()) {
            return DB::connection($resource->connection ?: null)->table((string) $resource->source_table);
        }

        $model = $this->resolveModel($resource);

        if ($model === null) {
            return null;
        }

        /*
         * A model may be read on another connection too, and that is a
         * different case from a table: the class, its casts and its column list
         * are this application's, only the rows live elsewhere. Setting it here
         * rather than on the class keeps the model itself untouched — the same
         * model is still read normally everywhere else in the host.
         */
        if (filled($resource->connection)) {
            $model->setConnection((string) $resource->connection);
        }

        return $model->newQuery();
    }

    /**
     * Rebuilds one row from the declared field names.
     *
     * Built from the select list rather than from the model's attributes, so a
     * column that arrived for some other reason — a driver returning the key, a
     * cast producing an extra attribute — cannot appear in the output. Values are
     * reduced to scalars: an object or an array leaving here is either a cast
     * result carrying more than the column, or a relation, and neither is
     * something the declaration described.
     *
     * @param  array<int, string>  $select
     * @return array<string, scalar|null>
     */
    protected function flattenRecord(object $record, array $select): array
    {
        $row = [];

        foreach ($select as $field) {
            /*
             * A table-backed read comes back as a plain object rather than a
             * model, so the value is read off the property. Same rule either
             * way: the row is built from the declared names, and a column that
             * arrived for some other reason has nowhere to appear.
             */
            $row[$field] = $this->scalarise(
                $record instanceof Model ? $record->getAttribute($field) : ($record->{$field} ?? null),
            );
        }

        return $row;
    }

    protected function scalarise(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return null;
    }
}
