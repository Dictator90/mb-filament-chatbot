<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;
use Throwable;

/**
 * What the host application actually has: its models, their columns, their
 * relations.
 *
 * Written for the resource form, and the reason it exists is that the form used
 * to be three free-text boxes. A data resource is a statement about a class name
 * and a set of column names, and every one of them was typed by hand into a
 * field that accepted anything: `App\Models\Oder`, `custmer_id`, `oders`. None
 * of those fail on save. They fail months later, mid-conversation, as a refused
 * read the visitor sees as a bot that has stopped answering — and the one place
 * the mistake is visible is a run journal nobody opens until somebody complains.
 *
 * **Discovery is a convenience, never a boundary.** Nothing here decides what
 * may be read; that is the declaration's own business, checked by the reader on
 * every query. A model missing from the list costs an author a dropdown, not
 * access — which is why an unlisted value that is already stored is always kept
 * rather than silently dropped, and why a host whose models live somewhere
 * unusual loses nothing but the convenience.
 */
final class HostModels
{
    /** @var array<string, array<class-string<Model>, string>>|null */
    private static ?array $discovered = null;

    /** @var array<string, list<string>> */
    private static array $columns = [];

    /**
     * Every model this installation offers, as class name to short name.
     *
     * A host that names its own list in `chatbot.data_resources.models` gets
     * exactly that list; otherwise the conventional `app/Models` is read. The
     * config is not a security setting — see the class docblock — it is for the
     * applications whose models are not where Laravel puts them, and for the
     * ones that would rather offer three models than ninety.
     *
     * @return array<class-string<Model>, string>
     */
    public static function options(): array
    {
        $declared = (array) config('chatbot.data_resources.models', []);

        if ($declared !== []) {
            return self::labelled(array_values(array_filter(
                array_map(static fn (mixed $model): string => (string) $model, $declared),
                static fn (string $model): bool => is_subclass_of($model, Model::class),
            )));
        }

        return self::$discovered['app'] ??= self::labelled(self::scan());
    }

    /**
     * The database connections this installation has configured.
     *
     * Read from the host's own `config/database.php`, because that is where
     * credentials for another host already live and a package holding a second
     * copy of them is a package that will hold a stale one.
     *
     * @return array<string, string>
     */
    public static function connections(): array
    {
        $names = array_keys((array) config('database.connections', []));

        sort($names);

        return array_combine($names, $names) ?: [];
    }

    /**
     * The columns of a table on a named connection.
     *
     * Asked of the connection rather than typed by hand, even for a foreign
     * database: Laravel can introspect anything it can connect to, and a list
     * somebody maintains is wrong the first time that schema changes. An
     * unreachable connection answers with nothing, and the form falls back to
     * what is stored — which is what keeps a resource editable on a machine
     * where the reporting database is not attached.
     *
     * @return list<string>
     */
    public static function tableColumns(?string $connection, ?string $table): array
    {
        $table = trim((string) $table);

        if ($table === '') {
            return [];
        }

        try {
            return Schema::connection(trim((string) $connection) ?: null)->getColumnListing($table);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The tables a connection has, for the same reason as the columns.
     *
     * @return array<string, string>
     */
    public static function tables(?string $connection): array
    {
        try {
            $names = array_map(
                static fn (array $table): string => (string) ($table['name'] ?? ''),
                Schema::connection(trim((string) $connection) ?: null)->getTables(),
            );
        } catch (Throwable) {
            return [];
        }

        $names = array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
        sort($names);

        return array_combine($names, $names) ?: [];
    }

    /**
     * The columns of a model's table, or nothing when it cannot be asked.
     *
     * Read from the schema rather than from a list somebody maintains, because
     * the list somebody maintains is wrong the first time a migration adds a
     * column. A model whose class is gone, whose table does not exist, or whose
     * connection is unreachable answers with an empty list: the form falls back
     * to what is stored, which is the behaviour that keeps a resource editable
     * on a machine where the reporting database is not attached.
     *
     * @return list<string>
     */
    public static function columns(?string $model): array
    {
        $model = trim((string) $model);

        if ($model === '' || ! is_subclass_of($model, Model::class)) {
            return [];
        }

        return self::$columns[$model] ??= self::listColumns($model);
    }

    /**
     * The relations a model declares, by name.
     *
     * Read from return types alone, and nothing is called to find them. A method
     * has to be invoked before it can say what it returns, and invoking every
     * public method of a host model to see what comes back is how a form ends up
     * charging a card because somebody called their method `pay()`. A relation
     * without a declared return type is simply not offered; the author types it,
     * exactly as before.
     *
     * @return array<string, string>
     */
    public static function relations(?string $model): array
    {
        $model = trim((string) $model);

        if ($model === '' || ! is_subclass_of($model, Model::class)) {
            return [];
        }

        $relations = [];

        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfParameters() > 0 || $method->isStatic()) {
                continue;
            }

            $type = $method->getReturnType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if (! is_subclass_of($type->getName(), Relation::class)) {
                continue;
            }

            $relations[$method->getName()] = $method->getName();
        }

        ksort($relations);

        return $relations;
    }

    /**
     * The columns of the model on the far side of a relation.
     *
     * The relation *is* called here, and only here, and only after the reflection
     * above has established that its declared return type is a relation. That is
     * the difference between calling a method because it is named plausibly and
     * calling one the class has stated is a relation.
     *
     * @return list<string>
     */
    public static function relatedColumns(?string $model, ?string $relation): array
    {
        $relation = trim((string) $relation);

        if ($relation === '' || ! array_key_exists($relation, self::relations($model))) {
            return [];
        }

        try {
            /** @var Model $instance */
            $instance = new $model;

            return self::columns($instance->{$relation}()->getRelated()::class);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Keeps a stored value selectable even when discovery has not heard of it.
     *
     * The one thing this must never do is quietly forget what a resource already
     * says. A select that dropped an unlisted value would blank the field on the
     * next save, and a resource whose model or column silently emptied is a
     * refused read the day after somebody opened the form to fix a label.
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    public static function including(array $options, mixed $current): array
    {
        $current = trim((string) $current);

        if ($current === '' || array_key_exists($current, $options)) {
            return $options;
        }

        return [$current => $current] + $options;
    }

    /**
     * @param  list<class-string<Model>>  $models
     * @return array<class-string<Model>, string>
     */
    private static function labelled(array $models): array
    {
        sort($models);

        $labelled = [];

        foreach ($models as $model) {
            $labelled[$model] = class_basename($model);
        }

        return $labelled;
    }

    /**
     * @return list<class-string<Model>>
     */
    private static function scan(): array
    {
        $directory = app_path('Models');

        if (! is_dir($directory)) {
            return [];
        }

        $namespace = app()->getNamespace().'Models\\';
        $found = [];

        foreach (File::allFiles($directory) as $file) {
            /** @var SplFileInfo $file */
            $class = $namespace.str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($file->getPathname(), $directory.DIRECTORY_SEPARATOR),
            );

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $found[] = $class;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function listColumns(string $model): array
    {
        try {
            /** @var Model $instance */
            $instance = new $model;

            return Schema::connection($instance->getConnectionName())->getColumnListing($instance->getTable());
        } catch (Throwable) {
            return [];
        }
    }
}
