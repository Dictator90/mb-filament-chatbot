<?php

declare(strict_types=1);

use MB\FilamentChatbot\Exceptions\DataQueryException;
use MB\FilamentChatbot\Models\DataResource;

/**
 * A data resource is the only thing standing between a chat message and the
 * application's own tables, and everything it gets wrong, it gets wrong quietly:
 * a trimmed limit still returns rows, a downgraded operator still returns rows,
 * a field nobody approved still returns a value. The reply looks the same in
 * every one of those cases, which is why these are worth a test and the shape of
 * the edit form is not.
 */
it('refuses a limit above the ceiling instead of trimming it', function (): void {
    $resource = DataResource::factory()->create([
        'default_limit' => 5,
        'max_limit' => 25,
    ]);

    expect(fn () => $resource->cappedLimit(26))->toThrow(DataQueryException::class)
        ->and($resource->cappedLimit(25))->toBe(25)
        ->and($resource->cappedLimit(3))->toBe(3)
        ->and($resource->cappedLimit(null))->toBe(5);
});

/**
 * A ceiling set below the declared default is a misconfiguration, and the safe
 * reading of it is the smaller number. Taking the default at face value would
 * quietly serve more rows than the maximum the resource advertises.
 */
it('keeps the fallback limit under the ceiling when the two disagree', function (): void {
    $resource = DataResource::factory()->create([
        'default_limit' => 50,
        'max_limit' => 10,
    ]);

    expect($resource->cappedLimit(null))->toBe(10);
});

it('refuses a field it never declared', function (): void {
    $resource = DataResource::factory()->forModel('Workbench\\App\\Models\\User', [
        ['name' => 'reference', 'filterable' => true, 'operators' => ['equals']],
    ])->create();

    expect($resource->allowsField('reference'))->toBeTrue()
        ->and($resource->allowsField('password'))->toBeFalse();

    expect(fn () => $resource->assertAllowsField('password'))
        ->toThrow(DataQueryException::class);
});

/**
 * The operator list is per field, not per resource: a reference number may be
 * matched exactly and a date may be compared, and swapping either rule onto the
 * other field returns rows the declaration never meant to expose.
 */
it('refuses an operator the field does not list', function (): void {
    $resource = DataResource::factory()->forModel('Workbench\\App\\Models\\User', [
        ['name' => 'reference', 'filterable' => true, 'operators' => ['equals']],
        ['name' => 'created_at', 'filterable' => true, 'operators' => ['gte', 'lte']],
    ])->create();

    expect($resource->allowsOperator('reference', 'equals'))->toBeTrue()
        ->and($resource->allowsOperator('created_at', 'gte'))->toBeTrue()
        ->and($resource->allowsOperator('reference', 'gte'))->toBeFalse();

    expect(fn () => $resource->assertAllowsOperator('reference', 'gte'))
        ->toThrow(DataQueryException::class);

    expect(fn () => $resource->assertAllowsOperator('password', 'equals'))
        ->toThrow(DataQueryException::class);
});

/**
 * Substring matching is granted by the searchable flag, not by listing the
 * operator: the value comes straight from a chat message, and a field nobody
 * marked as searchable has nobody who accepted that.
 */
it('withholds substring matching from a field nobody marked searchable', function (): void {
    $resource = DataResource::factory()->forModel('Workbench\\App\\Models\\User', [
        ['name' => 'title', 'filterable' => true, 'operators' => ['equals', 'contains']],
        ['name' => 'note', 'filterable' => true, 'searchable' => true, 'operators' => ['contains']],
    ])->create();

    expect($resource->allowsOperator('title', 'contains'))->toBeFalse()
        ->and($resource->allowsOperator('title', 'equals'))->toBeTrue()
        ->and($resource->allowsOperator('note', 'contains'))->toBeTrue();
});

it('refuses a query mode the resource does not allow', function (): void {
    $resource = DataResource::factory()->withModes(['count'])->create();

    expect($resource->allowsMode('count'))->toBeTrue()
        ->and($resource->allowsMode('list'))->toBeFalse();

    expect(fn () => $resource->assertAllowsMode('list'))
        ->toThrow(DataQueryException::class);
});

/**
 * A field marked both sensitive and returned by default is a declaration that
 * contradicts itself, and it is resolved against returning. One of the two flags
 * leaks a passport number when it wins, the other one only withholds a column.
 */
it('never returns a sensitive field, even when it is marked returned by default', function (): void {
    $resource = DataResource::factory()->forModel('Workbench\\App\\Models\\User', [
        ['name' => 'reference', 'returned_by_default' => true],
        ['name' => 'passport', 'returned_by_default' => true, 'sensitive' => true],
    ])->create();

    expect($resource->isSensitive('passport'))->toBeTrue()
        ->and($resource->returnableFields())->toBe(['reference'])
        ->and($resource->defaultSelect())->toBe(['reference']);

    expect(fn () => $resource->assertReturnable('passport'))
        ->toThrow(DataQueryException::class);
});

/**
 * The fallback when nothing is marked. One safe field, not the whole row: a
 * declaration that forgot to mark its defaults is the same declaration that
 * never decided which columns are fit to show, and reading that silence as
 * consent is how an unreviewed column reaches a customer.
 */
it('falls back to a single safe field when no default is marked', function (): void {
    $resource = DataResource::factory()->forModel('Workbench\\App\\Models\\User', [
        ['name' => 'passport', 'sensitive' => true],
        ['name' => 'reference'],
        ['name' => 'status'],
        ['name' => 'total'],
    ])->create();

    expect($resource->defaultSelect())->toBe(['reference'])
        ->and($resource->returnableFields())->toBe(['reference', 'status', 'total']);
});
