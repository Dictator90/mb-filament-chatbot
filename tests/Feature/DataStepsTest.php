<?php

declare(strict_types=1);

use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepFlow;
use MB\FilamentChatbot\Bot\Steps\Types\ApprovalStep;
use MB\FilamentChatbot\Bot\Steps\Types\ChooseStep;
use MB\FilamentChatbot\Bot\Steps\Types\QueryStep;
use MB\FilamentChatbot\Enums\DataQueryMode;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\DataResource;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Tests\Support\RecordingRuntime;
use Workbench\App\Models\DemoRecord;

/**
 * The three nodes that read the host's records, and the ways they go wrong in
 * silence.
 *
 * None of the failures below throws or shows up in a transcript as a fault. A
 * record printed as one unreadable line, a permission refusal worded to the
 * visitor as "you have no bookings", a pick that resolves to a different record
 * than the one on screen, a hesitant answer taken as consent to cancel an
 * order, a customer id typed into the chat that widens a query — every one of
 * them produces a conversation that reads as though it worked.
 */

/**
 * A resource over the workbench model, declared the narrow way.
 *
 * `secret_code` is here because a sensitive column is the only way to prove a
 * node never spreads one into the conversation's variables: without one in the
 * declaration the assertion would hold for a node that copies everything it is
 * given.
 *
 * @param  array<array-key, mixed>  $overrides
 */
function stepResource(array $overrides = []): DataResource
{
    return DataResource::factory()->create(array_merge([
        'key' => 'records',
        'model' => DemoRecord::class,
        'modes' => [
            DataQueryMode::List->value,
            DataQueryMode::First->value,
            DataQueryMode::Count->value,
        ],
        'fields' => [
            ['name' => 'id', 'returned_by_default' => true, 'filterable' => true, 'sortable' => true, 'operators' => ['equals']],
            ['name' => 'name', 'returned_by_default' => true, 'filterable' => true, 'operators' => ['equals']],
            ['name' => 'status', 'returned_by_default' => true, 'filterable' => true, 'operators' => ['equals']],
            ['name' => 'owner_id', 'filterable' => true, 'operators' => ['equals']],
            ['name' => 'secret_code', 'sensitive' => true, 'filterable' => true, 'operators' => ['equals']],
        ],
        'default_sort' => ['field' => 'id', 'direction' => 'asc'],
        'default_limit' => 10,
        'max_limit' => 100,
        'scopes' => null,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $config
 */
function dataStep(string $type, array $config, string $key = 'node'): Step
{
    return Step::factory()->make([
        'key' => $key,
        'type' => $type,
        'body' => null,
        'config' => $config,
    ]);
}

/**
 * The conversation is saved because these nodes keep state on it — what was
 * offered, how many unclear answers came back — while the scenario stays
 * unsaved, since none of them reads it.
 */
function dataContext(Step $step, RecordingRuntime $runtime, ?Conversation $conversation = null): StepContext
{
    return new StepContext(
        $conversation ?? Conversation::factory()->create(),
        new Scenario,
        $step,
        $runtime,
    );
}

/**
 * A record read into one variable per field is the difference between a message
 * that says "your booking Acme is open" and one that says "your booking 1, Acme,
 * open is open". The sensitive column is the other half of the same assertion:
 * whatever the node spreads out, it spreads out only what the declaration lets
 * leave.
 */
it('spreads a single record over one variable per field and never among them a sensitive column', function (): void {
    stepResource();
    DemoRecord::create(['name' => 'Acme', 'status' => 'open', 'secret_code' => 'S-1']);

    $runtime = new RecordingRuntime;

    $result = app(QueryStep::class)->run(dataContext(dataStep('query', [
        'resource' => 'records',
        'mode' => DataQueryMode::First->value,
        'filters' => ['name' => 'Acme'],
        'into' => 'booking',
    ]), $runtime));

    expect($result->flow)->toBe(StepFlow::Next)
        ->and($result->outcome)->toBe(RunStep::QUERIED)
        ->and($runtime->stored['booking_name'])->toBe('Acme')
        ->and($runtime->stored['booking_status'])->toBe('open')
        ->and($runtime->stored)->not->toHaveKey('booking_secret_code')
        ->and($runtime->stored)->not->toContain('S-1');
});

/**
 * Both branches leave by the same exit, so the trace is the only place the two
 * are told apart — and they need telling apart: an empty result is an answer the
 * scenario carries on from, while a refusal is a misconfiguration that nobody
 * will ever be told about if it reads as "nothing found".
 */
it('tells a refused read from an empty one in the trace, though both take the second exit', function (): void {
    stepResource();
    DemoRecord::create(['name' => 'Acme', 'status' => 'open']);

    $refused = app(QueryStep::class)->run(dataContext(dataStep('query', [
        'resource' => 'no-such-resource',
        'mode' => DataQueryMode::First->value,
        'into' => 'booking',
    ]), new RecordingRuntime));

    $empty = app(QueryStep::class)->run(dataContext(dataStep('query', [
        'resource' => 'records',
        'mode' => DataQueryMode::First->value,
        'filters' => ['name' => 'Globex'],
        'into' => 'booking',
    ]), new RecordingRuntime));

    expect($refused->flow)->toBe(StepFlow::Second)
        ->and($refused->outcome)->toBe(RunStep::FAILED)
        ->and($refused->trace['reason'])->toContain('no-such-resource')
        ->and($empty->flow)->toBe(StepFlow::Second)
        ->and($empty->outcome)->toBe(RunStep::EMPTY_RESULT);
});

/**
 * The reference number to look up arrived in the conversation a few nodes
 * earlier, so a filter value that is not substituted matches nothing — and
 * "nothing" is exactly what an unmatched lookup looks like when it works.
 */
it('substitutes a variable into a filter value', function (): void {
    stepResource();
    DemoRecord::create(['name' => 'Acme', 'status' => 'open']);
    DemoRecord::create(['name' => 'Globex', 'status' => 'closed']);

    $runtime = new RecordingRuntime;
    $runtime->store('reference', 'Globex');

    $result = app(QueryStep::class)->run(dataContext(dataStep('query', [
        'resource' => 'records',
        'mode' => DataQueryMode::First->value,
        'filters' => ['name' => '{{reference}}'],
        'into' => 'booking',
    ]), $runtime));

    expect($result->flow)->toBe(StepFlow::Next)
        ->and($runtime->stored['booking_name'])->toBe('Globex');
});

/**
 * A visitor cannot be asked which of their records they mean when they have
 * none: the question would be a dead end with a prompt on it, and they would
 * answer it.
 */
it('asks nothing at all when there is nothing to choose from', function (): void {
    stepResource();

    $runtime = new RecordingRuntime;

    $result = app(ChooseStep::class)->run(dataContext(dataStep('choose', [
        'resource' => 'records',
        'label' => '{{name}}',
        'into' => 'booking',
    ]), $runtime));

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->outcome)->toBe(RunStep::EMPTY_RESULT)
        ->and($runtime->said)->toBe([]);
});

/**
 * Three ways the same person answers the same list, depending on the channel
 * they are on: a number where the bot drew a numbered list, the label where it
 * drew buttons, and the last option when none of it is what they meant.
 */
it('takes the record a number names, the record a label names, and none of them for the last option', function (): void {
    stepResource();
    DemoRecord::create(['name' => 'Acme', 'status' => 'open']);
    DemoRecord::create(['name' => 'Globex', 'status' => 'closed']);

    $conversation = Conversation::factory()->create();
    $step = dataStep('choose', [
        'resource' => 'records',
        'label' => '{{name}} ({{status}})',
        'into' => 'booking',
        'none_label' => 'Not in the list',
    ]);
    $node = app(ChooseStep::class);

    $runtime = new RecordingRuntime;
    $node->run(dataContext($step, $runtime, $conversation));

    expect($runtime->said[0])->toContain('1. Acme (open)')
        ->and($runtime->said[0])->toContain('2. Globex (closed)')
        ->and($runtime->said[0])->toContain('3. Not in the list');

    $byNumber = $node->resume(dataContext($step, $runtime, $conversation), '2');

    expect($byNumber->flow)->toBe(StepFlow::Next)
        ->and($byNumber->outcome)->toBe(RunStep::ANSWERED)
        ->and($runtime->stored['booking_name'])->toBe('Globex');

    $node->run(dataContext($step, $runtime, $conversation));
    $byLabel = $node->resume(dataContext($step, $runtime, $conversation), 'Acme (open)');

    expect($byLabel->flow)->toBe(StepFlow::Next)
        ->and($runtime->stored['booking_name'])->toBe('Acme');

    $node->run(dataContext($step, $runtime, $conversation));
    $none = $node->resume(dataContext($step, $runtime, $conversation), 'Not in the list');

    expect($none->flow)->toBe(StepFlow::Second)
        ->and($none->outcome)->toBe(RunStep::BRANCHED);
});

/**
 * Between the question and the answer the records move: one is cancelled,
 * another is created, a status changes and the order with it. The visitor chose
 * the second line they were shown, so re-reading the list on the way back hands
 * the scenario a record that person never saw — and every message after it is
 * about the wrong booking, worded with complete confidence.
 */
it('returns the record it showed, not whatever stands in that position afterwards', function (): void {
    stepResource();
    $shown = DemoRecord::create(['name' => 'Acme', 'status' => 'open']);
    DemoRecord::create(['name' => 'Globex', 'status' => 'open']);

    $conversation = Conversation::factory()->create();
    $step = dataStep('choose', [
        'resource' => 'records',
        'label' => '{{name}}',
        'into' => 'booking',
    ]);
    $node = app(ChooseStep::class);
    $runtime = new RecordingRuntime;

    $node->run(dataContext($step, $runtime, $conversation));

    $shown->update(['name' => 'Renamed after the question', 'status' => 'closed']);
    DemoRecord::create(['name' => 'Arrived after the question', 'status' => 'open']);

    $result = $node->resume(dataContext($step, $runtime, $conversation), '1');

    expect($result->flow)->toBe(StepFlow::Next)
        ->and($runtime->stored['booking_name'])->toBe('Acme')
        ->and($runtime->stored['booking_status'])->toBe('open');
});

/**
 * The one that matters. An answer the node cannot read is a refusal, and it
 * stays a refusal when the attempts run out — because the node stands in front
 * of something irreversible, and "probably yes" performed on behalf of somebody
 * who was hesitating cannot be undone by the next message.
 */
it('treats an answer it cannot read as a refusal once the attempts are spent, never as consent', function (): void {
    $conversation = Conversation::factory()->create();
    $step = dataStep('approval', ['yes_label' => 'Yes', 'no_label' => 'No', 'attempts' => 2]);
    $node = app(ApprovalStep::class);
    $runtime = new RecordingRuntime;

    $node->run(dataContext($step, $runtime, $conversation));

    $first = $node->resume(dataContext($step, $runtime, $conversation), 'hmm, I am not sure');

    expect($first->flow)->toBe(StepFlow::Wait)
        ->and($first->outcome)->toBe(RunStep::UNKNOWN);

    $second = $node->resume(dataContext($step, $runtime, $conversation), 'well?');

    expect($second->flow)->toBe(StepFlow::Second)
        ->and($second->outcome)->toBe(RunStep::DECLINED);
});

/**
 * The three shapes a yes and a no arrive in: the word, the position in the
 * numbered list the node itself printed, and the label an author chose.
 */
it('reads a yes and a no as a word, as a number and as the label it printed', function (): void {
    $step = dataStep('approval', ['yes_label' => 'Cancel it', 'no_label' => 'Keep it', 'attempts' => 2]);
    $node = app(ApprovalStep::class);

    $word = $node->resume(dataContext($step, new RecordingRuntime), 'yes');
    $number = $node->resume(dataContext($step, new RecordingRuntime), '2');
    $label = $node->resume(dataContext($step, new RecordingRuntime), 'Cancel it');

    expect($word->flow)->toBe(StepFlow::Next)
        ->and($word->outcome)->toBe(RunStep::APPROVED)
        ->and($number->flow)->toBe(StepFlow::Second)
        ->and($number->outcome)->toBe(RunStep::DECLINED)
        ->and($label->flow)->toBe(StepFlow::Next)
        ->and($label->outcome)->toBe(RunStep::APPROVED);
});

/**
 * The whole reason the runtime context is built from the conversation and from
 * nothing else.
 *
 * Here the visitor has supplied somebody else's owner id — which is all the
 * answers bag ever holds, their own words under a friendly name — and the node
 * passes it into a filter. A filter may carry it: it narrows within the scope.
 * The scope itself comes from the conversation, so the read stays inside this
 * visitor's records and returns nothing rather than returning the other
 * person's row, which would have looked like a perfectly good answer.
 */
it('does not let a value the visitor supplied reach past the access scope', function (): void {
    stepResource(['scopes' => [
        ['field' => 'owner_id', 'operator' => 'equals', 'source' => 'conversation.contact_id'],
    ]]);

    DemoRecord::create(['name' => 'Mine', 'status' => 'open', 'owner_id' => 7]);
    DemoRecord::create(['name' => 'Somebody else', 'status' => 'open', 'owner_id' => 9]);

    $conversation = Conversation::factory()->create(['contact_id' => 7, 'contact_type' => 'demo']);

    $runtime = new RecordingRuntime;
    $runtime->store('owner_id', '9');

    $forged = app(QueryStep::class)->run(dataContext(dataStep('query', [
        'resource' => 'records',
        'mode' => DataQueryMode::First->value,
        'filters' => ['owner_id' => '{{owner_id}}'],
        'into' => 'booking',
    ]), $runtime, $conversation));

    expect($forged->flow)->toBe(StepFlow::Second)
        ->and($forged->outcome)->toBe(RunStep::EMPTY_RESULT)
        ->and($runtime->stored)->not->toHaveKey('booking_name');

    $own = new RecordingRuntime;

    app(QueryStep::class)->run(dataContext(dataStep('query', [
        'resource' => 'records',
        'mode' => DataQueryMode::First->value,
        'into' => 'booking',
    ]), $own, $conversation));

    expect($own->stored['booking_name'])->toBe('Mine');

    /*
     * The same node, the same filter, the same forged value — and a
     * conversation that genuinely belongs to owner 9. It returns the row, which
     * is what makes the refusal above an act of the access scope rather than a
     * query that was broken all along and would have found nothing either way.
     */
    $theirs = new RecordingRuntime;
    $theirs->store('owner_id', '9');

    app(QueryStep::class)->run(dataContext(dataStep('query', [
        'resource' => 'records',
        'mode' => DataQueryMode::First->value,
        'filters' => ['owner_id' => '{{owner_id}}'],
        'into' => 'booking',
    ]), $theirs, Conversation::factory()->create(['contact_id' => 9, 'contact_type' => 'demo'])));

    expect($theirs->stored['booking_name'])->toBe('Somebody else');
});
