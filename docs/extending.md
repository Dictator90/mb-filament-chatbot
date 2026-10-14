---
title: Extending
layout: default
nav_order: 7
---

# Extending

The package ships no knowledge of any business. A travel agency needs a node
that picks one of your bookings; a help desk needs one that escalates to the
on-call engineer; neither belongs in a package the other one installs. Both
register their own instead, and the registry — `MB\FilamentChatbot\Registry\ChatbotRegistry`,
a container singleton behind the `Chatbot` facade — is the whole of that
surface.

Everything below is registered from a host service provider's `boot()`. The
registry is a singleton, so registrations accumulate across your application,
its own packages and your tests.

```php
use MB\FilamentChatbot\Facades\Chatbot;

public function boot(): void
{
    Chatbot::registerStep(PickBookingStep::class);
    Chatbot::registerSlot(Slot::text('destination')->group('request'));
    Chatbot::registerCondition(HasUnpaidInvoiceCondition::class);
}
```

The facade proxies the registry object, so `app(ChatbotRegistry::class)->registerStep(...)`
is the same call. Use whichever reads better in your provider.

---

## A note on keys, before anything else

Every extension point is addressed by a string key, and that key is what a saved
scenario stores. It is not a label and not an implementation detail: renaming it
orphans every node, branch or action of that kind in every published version of
every scenario, and an orphaned node cannot be routed through.

So: **prefix your keys.** `crm.pick_booking`, not `booking`. `crm.has_debt`, not
`has_debt`. The package reserves the unprefixed namespace for itself, and a
package you install next year that claims `booking` would otherwise take the
name out from under a scenario that is already live. The registry does not
enforce this — enforcing it would break the one legitimate case, replacing a
built-in — but everything that goes wrong without it goes wrong silently and in
production.

---

## Your own node

A node is one class. `key()`, `label()` and `run()` are required; everything
else has a default describing an ordinary one-output node that does something
and moves on.

```php
<?php

declare(strict_types=1);

namespace App\Chatbot\Steps;

use App\Models\Booking;
use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;

/**
 * Offers the visitor their own bookings and remembers which one they picked.
 */
final class PickBookingStep extends StepType
{
    public static function key(): string
    {
        return 'crm.pick_booking';
    }

    public function label(): string
    {
        return 'Pick a booking';
    }

    public function icon(): string
    {
        return 'heroicon-o-ticket';
    }

    public function color(): string
    {
        return '#0ea5e9';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::Pair;
    }

    /** @return array{0: string, 1: string} */
    public function pairLabels(): array
    {
        return ['picked', 'nothing to pick'];
    }

    public function speaks(): bool
    {
        return true;
    }

    public function waitsForReply(): bool
    {
        return true;
    }

    /** @return list<Field> */
    public function fields(): array
    {
        return [
            Field::variable('store_in')
                ->label('Remember the booking under')
                ->default('booking_reference')
                ->required(),

            Field::number('limit')
                ->label('How many to offer')
                ->default(5)
                ->min(1)
                ->max(10)
                ->hint('A list longer than this reads as a search result, not a choice.'),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $bookings = Booking::query()
            ->where('contact_id', $context->conversation->contact_id)
            ->latest()
            ->limit((int) $context->setting('limit', 5))
            ->get();

        if ($bookings->isEmpty()) {
            return StepResult::second()->traced(RunStep::EMPTY_RESULT, ['reason' => 'no bookings']);
        }

        $context->runtime->say($this->question($bookings));

        return StepResult::wait()
            ->traced(RunStep::WAITING, ['offered' => $bookings->count()])
            ->spoken();
    }

    public function resume(StepContext $context, string $answer): StepResult
    {
        $reference = $this->match($answer);

        if ($reference === null) {
            return StepResult::second()->traced(RunStep::UNKNOWN, ['answer' => $answer]);
        }

        $context->runtime->store(
            (string) $context->setting('store_in', 'booking_reference'),
            $reference,
        );

        return StepResult::next()->traced(RunStep::ANSWERED, ['value' => $reference]);
    }
}
```

Then, in a provider:

```php
Chatbot::registerStep(PickBookingStep::class);
```

That is the whole integration. There is no JavaScript to write and nothing to
rebuild — see [the settings form](#the-settings-form-is-declared-not-drawn)
below.

### run() must not move the conversation

`run()` says what happened and which way it went. The engine resolves the
target, writes the journal and saves the conversation. A step that saved its own
step key would be one crash away from parking a visitor on a node that never
asked them anything.

`StepResult` is the vocabulary:

| Returned | Means |
| --- | --- |
| `next()` | follow the node's own single output |
| `second()` | take the second exit — the one that means "could not" |
| `goto(?string $stepKey)` | continue at a named node; `null` legitimately ends the branch |
| `wait()` | stop until the visitor replies |
| `delay(int $seconds)` | resume at the next output after a pause |
| `handoff()` | give the thread to a person |
| `close()` | end the conversation |
| `jump($slug)` / `call($slug)` / `returns($value)` | leave for another scenario, or come back from one |

Chain `->traced(RunStep::…, [...])` onto any of them, and `->spoken()` when the
node said something. Neither is decoration. A journal that says only "step ran"
answers half the question people bring to it — the step may well have run and
written nothing, because the answer did not parse — and a turn that walked ten
nodes without speaking means the visitor is looking at an empty chat.

Every two-output node routes failure through `second()`, and the labels differ
only in wording: nothing found, the model declined, the form gave up, nothing
matched. They all lead to a person, and four arrows out of one node would say
the same thing four times.

### resume() is a separate method on purpose

A waiting node is entered twice, and the two entries are nothing alike: the
first asks, the second reads a reply. The obvious alternative — one `run()` with
a flag — goes wrong in one specific way. A node that asked, and was then
re-entered by a resumed delay or a re-sent webhook, cannot tell "they answered"
from "we are here again", and asks the same question a second time.

The default `resume()` returns `next()`, which is right for nodes that never
wait. **A node that ever returns `wait()` must override it.**

`waitsForReply()` is the node's declaration to the editor and the graph checker,
not to the engine. A node that only sometimes asks — a form that asks for what
it does not already know — should answer `false` and return `wait()` on the
turns where it did ask. The engine cannot tell from the kind of node alone, only
from what happened this time.

### The settings form is declared, not drawn

The scenario editor is a React canvas. If a node's settings panel had to be
written inside that bundle, a host could not add a node at all without forking
the package and rebuilding its JavaScript. So nodes describe their settings with
`Field` descriptors and one generic renderer draws every one of them:

```php
use MB\FilamentChatbot\Bot\Fields\Field;

Field::text('label');
Field::textarea('body')->rows(4);
Field::number('attempts')->min(1)->max(5)->default(2);
Field::select('tone')->options(['formal' => 'Formal', 'casual' => 'Casual']);
Field::toggle('quote_the_answer')->default(true);
Field::list('keywords');
Field::keyValue('headers');
Field::variable('store_in');        // picks from the variables known at this node
Field::scenario('target');          // picks from the scenarios of the same bot
Field::dataResource('resource');    // picks from your declared data resources
Field::capability('action');        // picks from the capabilities you registered
```

Modifiers: `label()`, `hint()`, `placeholder()`, `default()`, `options()`,
`multiple()`, `required()`, `min()`, `max()`, `rows()`, and
`visibleWhen($otherField, ...$values)`.

Two constraints worth knowing before you write one.

**Nothing in a descriptor may hold a value `json_encode` cannot carry.**
Descriptors travel to the browser as plain arrays inside the editor's
configuration; an `options()` closure is resolved on the server before the page
renders, precisely so the editor never has to call back for a list.

**Conditional visibility is declarative for the same reason.** `visibleWhen()`
is evaluated in the browser while the person types. A node that hid its own
fields in PHP would need a round trip on every keystroke.

Write a `hint()` for anything whose effect is not visible on the canvas. The
person editing a scenario is usually not the person who installed the package,
and a bare label like "attempts" tells them nothing about what happens on the
third one.

`defaults()` is derived from the descriptors, so a freshly dropped node already
carries every `default()` you declared. There is nothing to duplicate.

### issues() — refuse to publish while it is still cheap

`issues()` runs against the draft, not against a live conversation, so it can
afford to be strict. Anything it returns is drawn on the node in the editor and
can block publication — which is the last moment the defect costs nothing.

```php
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/** @return list<array{severity: string, message: string}> */
public function issues(Step $step, Scenario $scenario): array
{
    if ((string) $step->setting('store_in', '') === '') {
        return [[
            'severity' => 'error',
            'message' => 'This node picks a booking but never says where to remember it.',
        ]];
    }

    return [];
}
```

If your node can change the host's records rather than only read them, implement
`MB\FilamentChatbot\Contracts\HasSideEffect` as well:

```php
use MB\FilamentChatbot\Contracts\HasSideEffect;
use MB\FilamentChatbot\Enums\SideEffect;

public function sideEffect(Step $step): SideEffect
{
    return SideEffect::Writes;
}
```

The graph checker refuses to publish a scenario that writes without a
confirmation step above it, and it will not guess: a host node called
`sync_order` reads, a node called `confirm` charges a card, and no naming rule
tells them apart. A kind that says nothing is treated as a read. The answer takes
the `Step` and not just the kind because one kind covers many nodes — a node that
runs a host capability writes or reads depending on which capability was chosen
in its settings, and a per-kind answer would mark every one of them as dangerous.
A warning on every node is a warning nobody reads.

### pins() — freeze what a released version depended on

```php
/** @return array<string, list<string>> */
public function pins(Step $step): array
{
    return ['capabilities' => ['crm.cancel_booking@2']];
}
```

Without pins, a released scenario changes behaviour silently when somebody edits
a data resource or bumps a host action it uses. With them the difference is
visible and the release can say what moved underneath it.

### Cosmetics, and one that is not

`icon()`, `color()`, `nextTypes()` and `isBasic()` are how your node behaves on
the canvas. `isBasic()` deserves a moment: a palette with twenty-six buttons in
one row reads as a settings list rather than a choice, and the seven nodes every
scenario is actually built from get lost in it. Say `true` only if yours is one
of those for your installation.

`nextTypes()` is knowledge about scenarios, not about the screen, which is why
it lives on the node. An empty canvas asks "where do I start"; a canvas with a
node selected asks "what next", and answering that with the full list of kinds
is not answering it.

### Instances are shared and stateless

The registry resolves one instance per key from the container and runs every
node of that kind, in every conversation, through it. Anything a particular node
needs comes from the step row; anything a particular conversation needs comes
from the context. A property set during `run()` is a property the next visitor
reads.

`StepContext` is passed as one object rather than as five arguments so that later
versions can add to it — a data-resource reader, a capability gateway — without
breaking the signature of every step you have written. It carries
`conversation`, `scenario`, `step`, `runtime` and `said`, plus
`setting($name, $default)` and `filled($name)` — the latter with `{{variable}}`
placeholders already substituted.

---

## Your own condition

What a conversation can be asked about depends entirely on the business. The
package knows about the conversation itself — its channel, whether the person
has written before, what the bot has collected, what time it is. Whether they
have an unpaid invoice is your question.

```php
<?php

declare(strict_types=1);

namespace App\Chatbot\Conditions;

use App\Models\Invoice;
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;
use MB\FilamentChatbot\Bot\Conditions\Concerns\ComparesValues;
use MB\FilamentChatbot\Bot\Runtime;
use MB\FilamentChatbot\Models\Conversation;

final class OverdueBalanceCondition extends ConditionField
{
    use ComparesValues;

    public static function key(): string
    {
        return 'crm.overdue_balance';
    }

    public function label(): string
    {
        return 'Overdue balance';
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Number;
    }

    public function hint(): ?string
    {
        return 'An amount, in your account currency.';
    }

    public function evaluate(
        Conversation $conversation,
        string $operator,
        mixed $value,
        Runtime $runtime,
    ): bool {
        $contactId = $conversation->contact_id;

        if ($contactId === null) {
            return $this->compare($operator, null, $value);
        }

        $balance = Invoice::query()
            ->where('contact_id', $contactId)
            ->overdue()
            ->sum('amount');

        return $this->compare($operator, (string) $balance, $value);
    }
}
```

```php
Chatbot::registerCondition(OverdueBalanceCondition::class);
```

**Conditions are read-only by contract.** A condition that wrote anything would
run a different number of times depending on how the scenario branches, and an
author reading the canvas has no way to see that.

`kind()` decides which operators the editor offers and whether it draws a value
box at all — see [Slots and conditions]({{ site.baseurl }}/slots-and-conditions)
for the table. Kinds exist rather than a free operator list per field because an
author choosing "is the visitor known" should never be offered "greater than",
and a field author should not have to remember to exclude it.

Use the `ComparesValues` trait rather than writing your own comparison. "Equals"
has to mean the same thing on a channel, on a collected answer and on your own
field. The moment a second field writes its own comparison the two drift — one
trims, the other does not — and an author who moved a branch from one field to
another finds it stops matching for no visible reason.

### ReceivesName, for a condition that is really a family

"A collected variable" is not one condition but as many as the bot has ever
collected, and the author has to say which. `needsName()` tells the editor to
ask; `ReceivesName` is how the answer reaches your field.

```php
use MB\FilamentChatbot\Bot\Conditions\ReceivesName;

final class CustomFieldCondition extends ConditionField implements ReceivesName
{
    private ?string $name = null;

    public function needsName(): bool
    {
        return true;
    }

    public function withName(?string $name): static
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    // ...
}
```

Two things here are not stylistic. The name arrives through an interface instead
of a fifth parameter on `evaluate()` because every condition anyone has written
implements that signature — a new parameter breaks all of them, to carry
something the overwhelming majority never look at. And `withName()` returns a
**copy** because the registry hands one instance to every conversation: a setter
would leave one node's variable name on the shared object for the next node, the
next conversation and the next visitor to read.

---

## Your own slot

A slot is one thing the bot can ask for and remember. Slots exist so that "the
visitor's phone number" means the same thing in every scenario of an
installation — the same question, the same parsing, the same name in the stored
answers. Free-form variables cannot give that: one scenario writes `phone`,
another `tel`, and the host code reading them silently finds nothing.

```php
use MB\FilamentChatbot\Bot\Slots\Slot;

Chatbot::registerSlots([
    Slot::text('destination')
        ->label('Destination')
        ->question('Where would you like to go?')
        ->group('request'),

    Slot::dateRange('travel_dates')
        ->label('Travel dates')
        ->question('Which dates are you looking at?')
        ->group('request'),

    Slot::choice('board', [
        'bb' => 'Breakfast',
        'hb' => 'Half board',
        'ai' => 'All inclusive',
    ])
        ->label('Board basis')
        ->question('Which board basis suits you?')
        ->group('request'),
]);
```

The constructors are `text`, `longText`, `phone`, `email`, `number`, `money`,
`date`, `dateRange` and `choice`; `Slot::make($key, SlotKind::…)` covers the
general case.

**The kind decides parsing, not presentation.** People do not answer a question
about dates with a date — they write "next Friday", "the 3rd", "in two weeks" —
and a slot that only accepted an ISO date would reject almost every real answer
and hand the conversation to a person over a formatting problem. What each kind
accepts, and what it does with an answer it cannot read, is on
[Slots and conditions]({{ site.baseurl }}/slots-and-conditions).

`question()` is a default, not a fixture: a scenario may override it, because
the same slot is asked differently after a complaint than after a greeting.
Having a default at all is what lets a form ask for five things before anyone
has written a line of copy.

`group()` sorts the editor's picker — "contact", "request", "billing". `hint()`
is shown to the author, never to the visitor.

---

## Your own variable source

A source is a named group of values a message template may use. The package
supplies what it owns — what the visitor just said, which channel they came
from, what the bot has collected. Everything about your records comes from here.

```php
<?php

declare(strict_types=1);

namespace App\Chatbot\Variables;

use App\Models\Contact;
use MB\FilamentChatbot\Bot\Variables\VariableSource;
use MB\FilamentChatbot\Models\Conversation;

final class ContactVariables implements VariableSource
{
    public function key(): string
    {
        return 'crm';
    }

    public function label(): string
    {
        return 'Customer';
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return [
            'manager' => 'Account manager',
            'loyalty_tier' => 'Loyalty tier',
        ];
    }

    public function value(Conversation $conversation, string $field): ?string
    {
        $contact = $conversation->contact;

        if (! $contact instanceof Contact) {
            return null;
        }

        return match ($field) {
            'manager' => $contact->manager?->name,
            'loyalty_tier' => $contact->loyalty_tier,
            default => null,
        };
    }

    /** @return list<string> */
    public function availableAfter(): array
    {
        return [];
    }
}
```

```php
Chatbot::registerVariableSource(new ContactVariables);
```

An author then writes `{{crm.manager}}`. The prefix from `key()` is what keeps
two sources from colliding over a name as ordinary as "name".

**Sources are a closed list per installation, not an open door to any column.**
A template names a variable and gets a string; it cannot ask for a field nobody
approved. That is what keeps a bot from reading an internal note or a payment
total out loud because somebody typed the wrong word into a message.

Three details that decide behaviour:

**Null and an empty string are different answers.** A template renders null as a
gap; a scenario asking "is it filled" needs to tell "we never found out" from
"they answered with nothing".

**Collected answers win over sources.** An author who dropped a `name` node and
wrote `{{name}}` on the line below is asking for what they just collected. Were
a source to win, installing a package that happens to publish a field of the same
name would silently change what published scenarios say.

**An ambiguous bare name resolves to nothing.** If two sources both offer
`manager`, `{{manager}}` renders empty rather than picking whichever registered
first — registration order is an accident of composer's dependency graph, and a
sentence that changes meaning when an unrelated package is installed is not
something anybody would think to test. The editor's picker therefore lists source
fields only under their prefix.

`availableAfter()` names the node kinds after which your variables start
resolving. Empty means always. A source that only fills in once a record has
been chosen says so here, and the editor stops offering its fields to nodes
placed above that choice — where they would render as empty gaps in a sentence
the visitor still receives.

Values are never re-expanded. A collected answer containing `{{crm.manager}}`
stays as those characters, because almost every collected answer was typed by
the visitor, and the one place they control the input is the one place a
template engine must not be recursive.

---

## Your own channel

`ChannelDriver` is an interface rather than a base class, because the two
shipped channels share nothing at all: the site widget delivers by doing nothing
and letting the browser ask, Telegram delivers by calling somebody else's HTTP
API. A shared parent would have to be empty, and an empty parent hosts are told
to extend is a promise the package cannot keep once a third channel arrives with
a third shape.

```php
<?php

declare(strict_types=1);

namespace App\Chatbot\Channels;

use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Channels\ChannelDriver;
use MB\FilamentChatbot\Channels\DeliveryResult;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use Throwable;

final class WhatsAppDriver implements ChannelDriver
{
    public function key(): string
    {
        return 'whatsapp';
    }

    public function label(): string
    {
        return 'WhatsApp';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.whatsapp.token'));
    }

    public function supportsButtons(): bool
    {
        return false;
    }

    public function sendsOutward(): bool
    {
        return true;
    }

    public function send(Conversation $conversation, Message $message): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::failed('No WhatsApp token is configured.', retryable: false);
        }

        try {
            $response = Http::withToken(config('services.whatsapp.token'))
                ->timeout(10)
                ->post(config('services.whatsapp.endpoint'), [
                    'to' => $conversation->external_id,
                    'body' => $message->body,
                ]);
        } catch (Throwable $e) {
            return DeliveryResult::failed($e->getMessage());
        }

        if ($response->failed()) {
            return DeliveryResult::failed(
                'WhatsApp refused the message: '.$response->status(),
                retryable: $response->serverError(),
            );
        }

        return DeliveryResult::sent($response->json('id'));
    }
}
```

```php
app(ChatbotRegistry::class)->registerChannel(new WhatsAppDriver);
```

`key()` is written into `chatbot_conversations.channel` for every thread the
channel opens, which is why it is a plain string on both sides: adding WhatsApp
must not require forking the package to widen an enum. It also means the key may
never change once an installation has traffic — renaming it orphans every thread
that came in through it, and orphaned threads cannot be answered.

**`isConfigured()` is separate from being registered because the two fail
differently.** An unregistered channel is a mistake in your provider; a
registered one without credentials is an installation that has not finished
being set up, and it must be able to say so on the settings screen instead of
only in a failed message hours later. `chatbot:doctor` asks this question too.

**`send()` never throws and never decides whether the message exists.** The
message row is written before delivery is attempted and keeps existing whatever
comes back: the transcript is what was said, and a provider outage must not be
able to erase a sentence the bot already committed to. Failing to deliver is an
ordinary outcome of talking to somebody else's server, not a defect, so it comes
back as a `DeliveryResult` that lands on the message row where an operator can
see it. An exception would either bubble out of an unrelated request or be
swallowed by a catch that had to guess which throwables meant "the provider said
no".

`DeliveryResult::failed($reason, retryable: false)` is worth using precisely.
A missing driver never becomes present by waiting, and retrying it only fills
the queue with work guaranteed to fail while delaying the reason reaching the
operator. The external id on `sent()` is optional because not every channel has
one — the site widget hands out no identifiers at all, and inventing a
placeholder would make the column unusable for the channels that do return one.

**`sendsOutward()` answers "does a reply here have to be carried anywhere?" —
and for almost every channel the answer is yes.** Say `false` only for a channel
the reader comes to, the way the site widget polls this application: the message
is already where it needs to be the moment the row exists, and the outbox queues
nothing for it. Say `true` if you are unsure. The wrong `true` costs a job that
does nothing; the wrong `false` costs a message nobody ever sends, with nothing
on the row to say so.

The reason it exists is a real installation whose worker had stopped. Its queue
held thirty-seven jobs, every one of them a site message that needed no sending
— and the delivery that had actually failed was somewhere in the middle of them.
A channel that is switched off or has no driver at all is still queued, because
the job is where that reason gets written on the message row.

### Why a channel without buttons is still fully usable

Answer `supportsButtons(): false` and the outgoing text carries the options as a
numbered list instead — that transformation lives in `ChannelText`. The slot
parser and the `Ask` node both accept a bare number as an answer, matching an
option by its position in the list as well as by its label or key.

That is not a convenience. Only the site widget can draw buttons; in a
messenger, over email or read out on the phone, the bot lists the options and
the person replies "2". Accepting the label alone would leave the bot working in
exactly one channel and stuck in every other one.

---

## Your own AI driver

`AiGateway` has three methods, and the small surface is the design. Everything
the package asks a model to do — answer from your own material, pull a few
values out of a sentence, choose between scenarios — is one question with one
answer. Streaming, tool calling and provider-side conversation state were left
out deliberately: each would tie the package to one provider's idea of a
session, and a driver that could not express it would have to fake it.

```php
<?php

declare(strict_types=1);

namespace App\Chatbot\Ai;

use MB\FilamentChatbot\Ai\AiFailure;
use MB\FilamentChatbot\Ai\AiGateway;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\AiReply;

final class AnthropicGateway implements AiGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function isAvailable(): bool
    {
        return filled($this->config['key'] ?? null);
    }

    public function ask(AiPrompt $prompt): AiReply
    {
        if (! $this->isAvailable()) {
            return AiReply::declined(AiFailure::NotConfigured);
        }

        // ... call the provider, and return AiReply::declined(AiFailure::Unavailable)
        // rather than letting anything escape.
    }
}
```

Registered on the factory, from a provider:

```php
use MB\FilamentChatbot\Ai\AiGatewayFactory;

app(AiGatewayFactory::class)->extend(
    'anthropic',
    fn (array $config): AiGateway => new AnthropicGateway($config),
);
```

The array handed to your closure is `config('chatbot.ai.drivers.anthropic')`, so
your driver is configured exactly like a built-in one, and it appears in a
node's provider picker alongside them — the picker is read from configuration
plus the extended names, because an author has no way to know which is which.

**A gateway never throws.** Not for a missing key, not for a refused request,
not for a socket that closed halfway. Every one of those returns
`AiReply::declined()` carrying an `AiFailure`, because on the other side of the
call is a node with a second exit drawn on the canvas and a scenario that has to
keep talking. An exception would leave the visitor's turn ending in nothing.

**`isAvailable()` is answered from settings alone** — a key, an address, an
installed class — and never by calling the provider. A liveness check would put
a network round trip in front of every node that might use a model, including
the ones that end up not needing it.

An unknown driver name in configuration yields the null gateway rather than an
exception, for the same reason: the name comes from an environment variable, so
getting it wrong is a typo, and a typo must not be able to take down the panel,
the queue worker and every conversation at once.

[More on the AI layer]({{ site.baseurl }}/ai), including what a prompt carries
and why an answer is only accepted when the model names its sources.

### Your own knowledge source

If the model should answer from something other than the built-in knowledge
base — a help desk, a manual, a product catalogue — bind `KnowledgeSource`:

```php
use MB\FilamentChatbot\Ai\KnowledgeSource;

$this->app->bind(KnowledgeSource::class, HelpDeskArticles::class);
```

```php
final class HelpDeskArticles implements KnowledgeSource
{
    /** @return list<array{title: string, text: string, key: string}> */
    public function candidates(?int $botId, ?string $said, int $limit): array
    {
        // best first; a null $botId means material shared by every bot
    }
}
```

The contract is narrower than the knowledge base's own search on purpose. The
model layer needs one thing — a handful of labelled fragments that might answer
this message — and binding the nodes to how topics happen to be looked up today
(keywords now, an index later, embeddings after that) would make every one of
those changes a change to the nodes.

`key` is what the model is asked to name back, so it has to be stable within the
turn and meaningful to whoever reads the run journal: an article slug, not a row
offset. The default binding is `NullKnowledgeSource` — no material, so every
node needing some takes its "could not" exit. A package that answered from
nothing would be a package that invents answers.

---

## Your own installation check

Half of what breaks a chat installation is outside the package: a credential you
store your own way, a queue worker on a machine nobody restarted, an endpoint
your own driver talks to. A check registered here gets you one answer to "is
this deployment healthy" instead of two.

```php
<?php

declare(strict_types=1);

namespace App\Chatbot\Doctor;

use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Console\Doctor\DoctorCheck;
use MB\FilamentChatbot\Console\Doctor\DoctorFinding;

final class WhatsAppCheck extends DoctorCheck
{
    public static function key(): string
    {
        return 'crm.whatsapp';
    }

    public function title(): string
    {
        return 'WhatsApp gateway';
    }

    /** @return list<DoctorFinding> */
    public function run(): array
    {
        if (blank(config('services.whatsapp.token'))) {
            return [DoctorFinding::fail(
                'No WhatsApp token is configured.',
                'Set WHATSAPP_TOKEN. Until then, replies to WhatsApp threads fail silently for the visitor.',
            )];
        }

        $ok = Http::withToken(config('services.whatsapp.token'))
            ->timeout(5)
            ->get(config('services.whatsapp.endpoint').'/health')
            ->successful();

        return [$ok
            ? DoctorFinding::pass('The WhatsApp gateway answered.')
            : DoctorFinding::warn(
                'The WhatsApp gateway did not answer.',
                'Outgoing messages will queue and retry. Check the gateway before the next release.',
            )];
    }
}
```

```php
Chatbot::registerDoctorCheck(WhatsAppCheck::class);
```

`DoctorCheck` is an abstract class rather than an interface so that you inherit
the part that is easy to get wrong: `safely()` catches whatever your `run()`
throws and reports it as a failure in its own words. A check that threw would
take the whole report down, and the one line that mattered would be somewhere
above a stack trace nobody scrolled to. Checks are resolved from the container,
so ask for what you need in the constructor.

Write the advice. It is the half that gets read — "the queue is synchronous"
tells somebody a fact about their configuration; naming the setting to change
turns the fact into an action, and the person reading it is usually deploying at
the time and did not write any of this. Name subjects too, in the third argument:
the difference between a warning somebody acts on and a warning somebody
rediscovers by opening scenarios one at a time.

Choose `fail` over `warn` on one question only: **does the installation work.**
A queue running synchronously makes pauses feel wrong; a widget with no allowed
origins does not answer at all. Only the second may stop a deployment. See
[Operations]({{ site.baseurl }}/operations) for exit codes and `--strict`.

---

## Telling the package who this person is

The package stores who a conversation belongs to and what it is about, and never
looks at either.

`chatbot_conversations` carries two nullable morphs — `contact_type` /
`contact_id` and `subject_type` / `subject_id` — exposed as ordinary
relationships:

```php
$conversation->contact()->associate($customer);
$conversation->subject()->associate($booking);
$conversation->identified_at = now();
$conversation->save();
```

`contact` is whoever you say this person is. `subject` is whatever you say the
conversation is about: an order, a ticket, a booking. Both are stored and
returned, never inspected — which is why nothing in the package references your
models, and why the same package fits a clinic and a travel agency.

Two consequences worth planning around.

**Resolve them yourself, deliberately.** `Conversation::title()` never falls
through to `contact` for a name, because that would load a host model the
package knows nothing about — one query per row in the conversation list, for a
class that may not even expose a name. Override `title()` if you want your own
naming, and eager-load the morph where you need it.

**`identified_at` is not "we know their phone number".** A phone number may have
been typed into the widget's form by anybody, and there is nothing here to check
it against. This timestamp is your application saying it authenticated the
visitor, and `isIdentified()` reads exactly that. The difference matters because
linking a thread to a real record is decided on this answer — and everything
arriving on an `InboundMessage` (display name, phone, e-mail, username) is
recorded as a claim, never used to decide who somebody is.

The operator model — the member of staff answering — comes from your auth
configuration rather than a literal, which is also why `assigned_to_id` carries
no foreign key: there is no fixed table to constrain it against.

### Filing somebody who is not in the directory yet

`ContactDirectory` can only recognise a record that already exists. A visitor
who writes for the first time, gives a name and a phone number and is handed to
a person exists nowhere afterwards — so the next time they write the bot greets
a stranger, and the operator retypes what the transcript already says.

Bind `ContactRegistrar` and the desk grows a **File this person** button:

```php
use MB\FilamentChatbot\Contracts\ContactRegistrar;

$this->app->bind(ContactRegistrar::class, CustomerRegistrar::class);
```

```php
public function canRegister(Conversation $conversation): bool;
public function register(Conversation $conversation): ?Model;
```

Binding it is optional, and without it there is no button: deciding that a chat
visitor deserves a row in the customer directory is the host's call and
sometimes the law's. The threshold is yours as well — the package asks
`canRegister()` and offers the button only on a yes, so "a name they gave
themselves and a way of reaching them", or "only while the agency's setting is
on", are rules you state once, in your own code. Returning `null` from
`register()` means *not created* rather than *failed*: the operator is told
plainly and nothing is logged as an error.

A scenario can file the same person without an operator, through a capability of
your own that calls the same registrar, and the two together are the usual
arrangement: the common path files people by itself, and the button catches the
conversations that never reached that node.

---

## What happens when you claim a key the package already uses

The registry answers this differently for capabilities than for everything else,
and the difference is not an oversight. It is which way the damage runs.

### Steps, slots, conditions, channels and doctor checks: last registration wins

`registerStep()`, `registerSlot()`, `registerCondition()`, `registerChannel()`
and `registerDoctorCheck()` all replace whatever held the key.

Replacement is allowed on purpose. Overriding a built-in node is the cheapest
way to change one detail of it — the wording it sends, the limit it enforces —
without forking the package, and forbidding it would only push you into copying
the whole class anyway. The same argument holds for a driver: a driver is a
transport, not an action on your records, and swapping the Telegram driver for
one routing through a company gateway is an ordinary deployment decision whose
worst failure is visible on the message row.

That raises an obvious question — if last registration wins, and provider boot
order comes from the composer manifest, does your override survive? Yes,
because **the package's own registrations skip a key that is already claimed.**
Every built-in list is registered with a `hasStep()` / `slot()` / `condition()` /
`hasChannel()` guard first. Doctor checks reach the same result by a different
route: the built-in list is assembled first and host checks are folded in
afterwards, keyed by key, so a host check claiming a built-in key is the one
that survives.

Both directions therefore agree: whoever registers a key other than this package
owns it, whether they got there first or arrive later and overwrite. Without the
guard, a host redefining `phone` with its own label and question would see it
honoured on installations where its provider boots later and silently reverted
on the others — working on the developer's machine and not in production, over
nothing but manifest order.

### Capabilities: the taken key is left alone

`registerCapability()` returns without doing anything if the key already exists.

An action changes your own records. Two packages claiming `billing.refund` is
not a customised built-in; it is one of them quietly performing the other's
refund, and the only trace is money moving while the journal still reads
successful. Registration order is manifest order, so a silent overwrite would
land one way locally and the other way in production.

Replacing an action stays possible and has to be said out loud:

```php
Chatbot::forgetCapability('billing.refund');
Chatbot::registerCapability($myRefund);
```

`forgetStep($key)` and `forgetChannel($key)` exist too, for removing a built-in
entirely rather than replacing it — an installation with no knowledge base has
no use for the node that reads from it, and an unusable node on the palette is a
support ticket waiting to happen.

### An unknown key is not an error

`step()`, `condition()`, `capability()` and `channel()` all answer `null` for a
key nothing claims, and null is an ordinary answer rather than a failure. A
scenario may well hold nodes of a kind whose package has since been removed, and
the editor has to open it: the node is drawn as a problem and the author decides
what to do. Throwing would let one uninstalled package take down the whole
scenario screen.

At runtime the same rule holds with different consequences, and each subsystem
absorbs it where it can be seen:

- an unrecognised **condition** evaluates to `false`, so the branch falls
  through to "otherwise" and the conversation continues — while the run journal
  records which condition was unrecognised and the editor marks the node. It
  fails loudly to the person who can fix it and quietly to the person who
  cannot;
- a missing **capability** takes the node's "could not" exit;
- a missing **channel** turns into a failed message carrying the reason, because
  a reply disappearing without trace is the one outcome that must not happen.
