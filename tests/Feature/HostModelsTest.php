<?php

declare(strict_types=1);

use MB\FilamentChatbot\Data\HostModels;
use Workbench\App\Models\DemoLine;
use Workbench\App\Models\DemoRecord;

/**
 * What the resource form offers instead of three empty text boxes.
 *
 * A data resource is a statement about a class name and a set of column names,
 * and every one of them used to be typed by hand into a field that accepted
 * anything. `App\Models\Oder` saves perfectly; it fails months later, in a
 * conversation, as a refused read the visitor reads as a bot that has stopped
 * answering.
 */

/**
 * Relations are read from declared return types, and nothing is called to find
 * them.
 *
 * This is the assertion the whole class is shaped around. Discovering relations
 * by invoking public methods and looking at what comes back means invoking every
 * public method of a host model — and on a real one the equivalent of
 * `chargeTheCard()` sends the e-mail, charges the card or cancels the booking.
 * The demo model throws from that method precisely so this test says it out
 * loud rather than a customer saying it later.
 */
it('finds relations without calling anything', function (): void {
    expect(HostModels::relations(DemoRecord::class))->toBe(['lines' => 'lines'])
        ->and(HostModels::relations(DemoLine::class))->toBe(['record' => 'record']);
});

/**
 * Columns come from the schema, because a list somebody maintains is wrong the
 * first time a migration adds a column.
 */
it('reads a model\'s columns off the schema, and a related model\'s through the relation', function (): void {
    expect(HostModels::columns(DemoRecord::class))->toContain('owner_id', 'secret_code')
        ->and(HostModels::relatedColumns(DemoLine::class, 'record'))->toContain('owner_id')
        ->and(HostModels::relatedColumns(DemoLine::class, 'nonsense'))->toBe([]);
});

/**
 * A stored value the list has never heard of stays selectable.
 *
 * The one thing these dropdowns must never do is quietly forget what a resource
 * already says. Dropped from the options, the value blanks on the next save —
 * and a resource whose model or column silently emptied is a refused read the
 * day after somebody opened the form to fix a label.
 */
it('keeps a stored value that discovery does not know about', function (): void {
    $options = HostModels::including(['a' => 'A'], 'App\\Models\\Elsewhere');

    expect($options)->toHaveKey('App\\Models\\Elsewhere')
        ->and($options)->toHaveKey('a')
        ->and(HostModels::including(['a' => 'A'], null))->toBe(['a' => 'A']);
});

/**
 * A host whose models are not in `app/Models` names them, and gets exactly
 * those. Anything in the list that is not an Eloquent model is dropped rather
 * than offered — a class name in a dropdown that cannot be queried is a
 * declaration that fails at the first conversation.
 */
it('offers the models the installation names, and only those', function (): void {
    config()->set('chatbot.data_resources.models', [DemoRecord::class, 'App\\Nothing\\At\\All']);

    expect(HostModels::options())->toBe([DemoRecord::class => 'DemoRecord']);
});
