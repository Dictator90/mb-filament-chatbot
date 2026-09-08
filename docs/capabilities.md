---
title: Capabilities
layout: default
nav_order: 6
---

# Capabilities

A [data resource]({{ site.baseurl }}/data-resources) covers reading without
writing code. A capability covers everything a read cannot do — award the
points, cancel the booking, open the ticket, work out a price from rules that
live in the host application.

The host writes the closure once in a service provider, and from then on the
action is a node on the canvas that a scenario author picks from a list.

---

## The contract

`MB\FilamentChatbot\Capabilities\CapabilityAction` is a readonly value object.
Every parameter is required except the last.

```php
use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Capabilities\CapabilityAction;
use MB\FilamentChatbot\Enums\SideEffect;

new CapabilityAction(
    key: 'shop.open_ticket',
    version: '1',
    label: 'Open a support ticket',
    description: 'Creates a ticket for the visitor and returns its reference.',
    effect: SideEffect::Write,
    input: [
        Field::text('subject')->label('Subject')->required(),
        Field::textarea('body')->label('What happened')->rows(4),
        Field::select('priority')
            ->label('Priority')
            ->options(['normal' => 'Normal', 'high' => 'High'])
            ->default('normal'),
    ],
    requiresApproval: true,
    handler: fn (array $payload, StepContext $context): array => [
        'reference' => Ticket::open($payload)->reference,
    ],
    group: 'Support',
);
```

| Parameter | | |
| --- | --- | --- |
| `key` | `string` | Stable and namespaced by the host — `shop.open_ticket` |
| `version` | `string` | Moved **by hand** when the meaning of the action changes |
| `label` | `string` | What an author sees in the picker |
| `description` | `string` | One sentence about what it does |
| `effect` | `SideEffect` | `Read` or `Write` |
| `input` | `list<Field>` | The descriptors the call form is drawn from |
| `requiresApproval` | `bool` | Whether a confirmation must stand above it |
| `handler` | `Closure(array, StepContext): array` | What actually runs |
| `group` | `?string` | Optional grouping for the picker |

The constructor throws `InvalidArgumentException` on an empty `key` (a scenario
would have nothing to name it by) or an empty `version` (a released scenario
would have nothing to pin).

`reference()` returns `key@version` — the exact string a published scenario
pins. It is built in one place rather than at each call site because the editor,
the node's `pins()` and the release check all name the same thing, and three
spellings of it is how a release ends up comparing `shop.award_points@1` against
`shop.award_points:1` and reporting every action as changed.

`toArray()` is what the editor receives. **The handler is not in it and cannot
be:** closures do not survive `json_encode`, and an action's code has no
business travelling to a browser.

---

## Why the input uses `Field` descriptors

`Field` is the same class a node's own settings are described with, and reusing
it is what keeps a host action from being second-class.

The editor already draws every kind of field from those descriptors. An action
declaring `Field::number('points')->required()` gets the same control, the same
required marker and the same validation as a built-in node — with no panel
written in the editor's JavaScript, which a host cannot add to without forking
the package.

Describe the input any other way and every new action would arrive as a
key-value box with a free-text value, and the author would learn the field names
from documentation instead of from the screen.

That is not hypothetical — for a while it was exactly what the editor did. The
descriptors reached the browser and nothing drew them, so an author typed
`due_in_hours` into a key box, mistyped it, and the call refused mid-conversation
with the correct spelling printed in its own error message. Found by installing
the package into an application that had real actions to register. The node's
input is a `capability_input` field now: it reads the chosen action's descriptors
and draws them. An action whose package has since been removed falls back to the
key-value box, so a scenario naming one can still be read and repaired.

The factory methods: `text`, `textarea`, `number`, `select`, `toggle`, `list`,
`keyValue`, `variable`, `scenario`, `dataResource`, `capability`. Chainable
modifiers: `label`, `hint`, `placeholder`, `default`, `options`, `multiple`,
`required`, `min`, `max`, `rows`, `visibleWhen`.

---

## Registering

From a service provider's `boot()`:

```php
use MB\FilamentChatbot\Facades\Chatbot;

public function boot(): void
{
    Chatbot::registerCapability(new CapabilityAction(...));

    // or several at once
    Chatbot::registerCapabilities([$award, $ticket, $quote]);
}
```

The registry is a container singleton, which is why host packages can register
into it during their own boot without a fresh instance quietly dropping every
one of them.

**A key that is already claimed is left alone** — and that is the opposite of
what `registerStep()` does, deliberately. A step type is a screen and a
behaviour, and replacing one is the documented way to reword a built-in node. An
action changes the host's own records: two packages claiming `billing.refund` is
not a customised built-in, it is one of them quietly performing the other's
refund, with the only trace being money moving while the journal still reads
successful. Registration order is manifest order, so a silent overwrite would
land one way on the developer's machine and the other way in production.

Replacing an action stays possible and has to be said out loud:

```php
Chatbot::forgetCapability('billing.refund');
Chatbot::registerCapability($ours);
```

`Chatbot::capability($key)` returns `null` for an unknown key rather than
throwing, for the same reason `step()` does: a saved node may name an action
whose package has since been removed, and both the editor and a running
conversation have to survive that — the first by drawing a defect on the node,
the second by taking its "could not" exit.

---

## The gateway

`CapabilityGateway::run()` is **the one place a host action is actually
called.** Nothing else in the package invokes a handler.

A node that called the closure itself would be a node that decides on its own
whether the input was checked, whether a thrown exception ends the conversation,
and whether a handler returning nonsense is a success — and the second node to
do it would decide differently. Those three answers are the same for every
action, so they are written once.

```php
$result = $gateway->run($action, $payload, $context);

$result->ok();     // bool
$result->data();   // array<string, mixed>, empty on failure
$result->error();  // string|null — the reason, for the trace
```

`CapabilityResult` keeps "it worked" apart from what came back. A bare array
would carry both answers at once and every caller would invent its own way of
asking: an empty array means the action did nothing, or it means the action
failed, depending on which action it was. A scenario branches on those two
differently — a failure takes the second exit and ends up in front of a person,
an empty success carries on. The reason travels with the failure because it is
the only thing anyone can act on afterwards; the visitor is told nothing about
it, and the trace is where "the action threw" is told apart from "the action
refused".

### A payload that does not fit is refused, never adjusted

Coercing a missing value to an empty string or a word to a zero is how an action
ends up awarding nought points, cancelling the booking whose id defaulted to
nothing, or writing an empty name over a real one — and every one of those
*succeeds*, so the trace says the action worked. A refusal takes the node's
second exit, which is a branch the author drew and can route to a person.

What is checked:

- **Required inputs must be present.** An empty string counts as absent, because
  that is what a text field submits when nobody typed in it and what
  `{{a_variable}}` substitutes to when the conversation never learned one. A
  required input satisfied by the empty string is a required input that is not
  enforced at all. `null` and `[]` count as absent too.
- **A `number` field must receive something numeric.**
- **A `select` field's value must be one of its option keys** — each of them,
  when the field is `multiple`.

The check reads the descriptors through `toArray()` rather than through getters
of their own: that array is what the editor draws the very same form from, so a
field the author was shown as required is the field checked here. Two sources
for one answer is how a form marks a value optional while the call refuses it as
missing.

**An empty option list is not treated as "nothing is allowed".** A select whose
options are computed at render time can legitimately arrive without them, and
refusing every value in that case would make an action fail for a reason nobody
could see in the editor, where the list is plainly there.

### A handler that throws fails this call and nothing else

The action belongs to the host and can do anything at all — reach a payment
gateway that is down, hit a unique index, dereference a record somebody deleted
a second ago. Letting that out would end the visitor's turn with a stack trace
and no reply, punishing them for the state of a system they cannot see. It is
caught, worded into the trace as `[key@version] threw Exception: message`, and
routed like any other failure.

### Anything but an array back is a failure too

A handler that returns `null` or `true` is a handler somebody wrote against a
signature they did not read, and treating it as an empty success would make the
node write nothing while its "done" branch carried on describing a result.

---

## The `action` node

See the [node reference]({{ site.baseurl }}/nodes#action) for the full entry.
The parts that matter here:

| Setting | |
| --- | --- |
| `capability` | The registered key. Required |
| `input` | Name/value pairs. Values may contain `{{variables}}` |
| `into` | Variable prefix for what the action returned |

Variables are substituted **before** the gateway sees the payload. The gateway
is what tells a good payload from a bad one and it has to judge the values that
will genuinely be passed: filled afterwards, an input that looked present as
`{{booking_id}}` would arrive at the handler as an empty string whenever the
conversation never learned one — through a check that had already said the
payload was complete.

What the action returns is spread over prefixed variables — `award_balance`,
`ticket_reference` — the same convention `query`, `choose` and `http` write
records under, so an author who has read one message template can write the
next. A returned value that is not a scalar is rendered as JSON rather than
dropped: a name that quietly does not exist prints as a gap in a sentence, and
the author has nothing to go on.

The node reports issues while the scenario is being edited:

| Severity | When |
| --- | --- |
| blocking | No action chosen — the node could only ever take its second exit |
| blocking | No action registered under that key — it would fail on every conversation |
| notice | The action changes data — check a confirmation stands above it |

The picker appends "(changes data)" to writing actions. The label alone is not
enough on a list that mixes reads and writes: an author choosing between "Cancel
booking" and "Check cancellation fee" cannot tell from those words which one
cannot be taken back.

---

## Writing requires a confirmation above it

**This is checked when a scenario is published, not when the action runs.**

`ScenarioGraph` walks forward from the start node carrying an approved flag. An
action node whose capability has `effect: SideEffect::Write` **or**
`requiresApproval: true`, reached with that flag unset, is a blocking defect:

> This node changes data and can be reached without a confirmation node above
> it. Add one on every path that leads here.

Only the **approving** exit of an [`approval`
node]({{ site.baseurl }}/nodes#approval) carries the flag forward. The refusing
exit means the person said no, and a write placed there is exactly the mistake
the rule is for. A confirmation is recognised by class as well as by key, so a
host subclassing `ApprovalStep` does not find every write below it refusing to
publish.

`requiresApproval` is how a host says "ask first" about something the effect
flag alone cannot express — reading a customer's documents out loud, for
instance. It counts as a write for this rule even when `effect` is `Read`;
overruling it would make the flag decorative.

**Why the gateway does not ask this question.** A gateway is handed one call and
one conversation. It cannot see the graph, and answering from what it can see
would mean answering wrongly. The check belongs to the node's `issues()` and to
the release of a version, where the graph is in hand and the fix is still cheap
— which is the last moment that defect costs nothing.

One caveat about where to look: the gateway's own docblock describes the
`requiresApproval` flag as enforced there. In the shipped code the flag is read
only by `ScenarioGraph::writes()`, at publish time; `CapabilityGateway::run()`
checks the payload and nothing else. The publish-time rule is the enforcement
point.

---

## Why there is no hash of the handler's source

A well-known commercial builder pins the code of the handler into the released
contract. The result is that renaming a local variable inside the method breaks
a live scenario: the hash moves, the pin no longer matches, and a conversation
that was working stops on a version mismatch nobody changed the meaning of.

**Refactoring is not a release.**

What a scenario pins instead is `key@version`, and the version is moved by the
person who changed what the action *means* — a new required input, a different
unit, points that now cost money. That decision cannot be derived from the bytes
of a method, and pretending it can only teaches people to bump a number they do
not understand.

The pin does carry the version rather than the key alone, and that half matters.
Pinned by key only, an action whose meaning was deliberately changed would slide
under a released scenario with nothing to show for it, and the first sign would
be the wrong thing happening to a customer. With the version in the pin, a
release can say which actions moved beneath it.

When the node is saved against a key that is not registered at all, the pin
becomes `key@?` — a released version that names an action nobody provides, said
out loud rather than dropped.

---

## Next

- [Data resources]({{ site.baseurl }}/data-resources) — reading, declared rather than coded.
- [Node reference]({{ site.baseurl }}/nodes#action) — the `action` node in full.
- [Scenarios]({{ site.baseurl }}/scenarios#versions-and-publishing) — pins, fingerprints and what refuses a release.
