# Filament Chatbot

Customer chat and a visual chatbot builder for any Filament panel.

Conversations from the web widget and messaging channels, a scenario editor on a
canvas, a knowledge base, and answers from an AI provider when you want them —
all of it usable without the package knowing anything about your business.

> Status: in development. The API below is what exists today; sections marked
> *planned* are on the roadmap and not shipped yet.

**[Full documentation](https://dictator90.github.io/mb-filament-chatbot/)** —
installation, every node, data resources, capabilities, extending, operations.
It is built from `docs/` on the default branch, so a change in behaviour and the
page describing it arrive in the same commit. To publish it, point GitHub Pages
at *Deploy from a branch* → `main` → `/docs`; no workflow is needed.

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13
- Filament 5

## Install

```bash
composer require mb4it/filament-chatbot
php artisan migrate
```

Add the plugin to the panel that should carry the chat screens:

```php
use MB\FilamentChatbot\ChatbotPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugins([
        ChatbotPlugin::make(),
    ]);
}
```

Publish the configuration if you want to change the defaults:

```bash
php artisan vendor:publish --tag=chatbot-config
```

## The idea

Most chatbot packages either hard-code a domain — orders, bookings, tickets — or
give you a scripting language and call it flexibility. This one has a small,
fixed vocabulary of nodes and **three ways to teach it about your application**,
in increasing order of effort:

1. **Data resources** — declare a model, its approved fields and a ceiling, and
   the bot can read your records. No code.
2. **Capabilities** — register an action with an input schema and a handler, and
   a scenario can invoke it.
3. **Step types** — write a node class when the first two do not fit.

Nothing in the package references your models. A conversation points at your
records through nullable morphs it never dereferences, and the operator model
comes from your auth configuration.

## Scenarios

A scenario is a graph of nodes. The engine walks it one turn at a time: a node
says what it did, the engine decides where the conversation goes and writes the
journal. That split is what makes a node you wrote as safe as one that shipped.

Built-in nodes today:

| Node | What it does |
| --- | --- |
| `start` | What starts the scenario and who it is for |
| `say` | Send a message and move on |
| `ask` | Ask with options; each option names its next node |
| `collect` | Ask one thing and remember the answer |
| `form` | Ask for several things, skipping what is already known |
| `keyword` | Branch on words in what the visitor already wrote |
| `condition` | Branch on what is already known |
| `set` | Remember a value without asking |
| `delay` | Pause, on a queue, not in the request |
| `jump` | Continue in another scenario and do not come back |
| `handoff` | Fetch a human; the bot falls silent |
| `close` | Say goodbye; the next message reopens the conversation |

| `call` / `result` | Run another scenario and come back with what it returned |
| `query` | Read a data resource into variables |
| `choose` | Offer the visitor their own records and remember the one they pick |
| `approval` | An explicit yes or no before anything irreversible |
| `action` | Invoke a capability the host registered |
| `http` | Call an allow-listed endpoint |
| `transform` | A fixed set of deterministic string and date operations |
| `for_each` | Loop over a list, with a required finite bound |
| `answer` | Answer from the knowledge base, or take the "do not know" branch |
| `ai` | Answer with a model, accepted only when it cites what it was given |
| `ai_task` | Extract values from text into variables |

`call` and `result` are the pair worth knowing about. Shared work — identifying
the visitor, collecting a phone number — is written once as its own scenario and
called from everywhere, instead of being copied into each one and drifting.

Twenty-five kinds in all, `mutate` among them: a bot can write one row of a data
resource, but only where the resource was declared writable, only into the
columns it named, and only with an approval node above it on the branch —
publishing refuses a graph where a write is reachable without one.

The full reference, with every setting of every node, is in
[the documentation](https://dictator90.github.io/mb-filament-chatbot/nodes).

## Writing your own node

A node is one class. Only `key()`, `label()` and `run()` are required; the
settings panel in the editor is drawn from `fields()`, so a node needs no
JavaScript and no rebuild.

```php
use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepResult;
use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Enums\StepOutputs;
use MB\FilamentChatbot\Models\RunStep;

final class AwardPointsStep extends StepType
{
    public static function key(): string
    {
        return 'shop.award_points';
    }

    public function label(): string
    {
        return 'Award loyalty points';
    }

    public function icon(): string
    {
        return 'heroicon-o-gift';
    }

    public function outputs(): StepOutputs
    {
        return StepOutputs::Pair;
    }

    public function pairLabels(): array
    {
        return ['awarded', 'could not'];
    }

    public function fields(): array
    {
        return [
            Field::number('points')->label('Points')->default(50)->min(1)->max(10000),
            Field::variable('into')->label('Remember the new balance as'),
        ];
    }

    public function run(StepContext $context): StepResult
    {
        $customer = $context->conversation->contact;

        if ($customer === null) {
            return StepResult::second()->traced(RunStep::FAILED, ['reason' => 'nobody to award']);
        }

        $balance = $customer->award((int) $context->setting('points', 0));

        $context->runtime->store((string) $context->setting('into', 'points'), (string) $balance);

        return StepResult::next()->traced(RunStep::PASSED, ['balance' => $balance]);
    }
}
```

Register it from a service provider:

```php
use MB\FilamentChatbot\Facades\Chatbot;

public function boot(): void
{
    Chatbot::registerStep(AwardPointsStep::class);
}
```

Prefix your keys. `shop.award_points` cannot be taken out from under a published
scenario by a later package, and `award_points` can.

### A node that waits

A node is entered twice when it asks something: `run()` asks and returns
`StepResult::wait()`, and `resume()` receives the answer on the next turn.

```php
public function run(StepContext $context): StepResult
{
    return StepResult::wait()->traced(RunStep::WAITING);
}

public function resume(StepContext $context, string $answer): StepResult
{
    $context->runtime->store('order_number', $answer);

    return StepResult::next()->traced(RunStep::ANSWERED);
}
```

The engine sends the node's own message before `run()` when `speaks()` is true,
so a waiting node does not have to send its own question.

## Capabilities

A data resource covers reading. Anything else your application should be able to
do from a conversation — award points, open a ticket, cancel something — is a
capability: a key, a schema for its input, and a handler.

```php
use MB\FilamentChatbot\Capabilities\CapabilityAction;
use MB\FilamentChatbot\Enums\SideEffect;
use MB\FilamentChatbot\Facades\Chatbot;

Chatbot::registerCapability(new CapabilityAction(
    key: 'shop.open_ticket',
    version: '1',
    label: 'Open a support ticket',
    description: 'Creates a ticket and returns its reference.',
    effect: SideEffect::Write,
    input: [
        Field::text('subject')->label('Subject')->required(),
        Field::textarea('body')->label('What happened'),
    ],
    requiresApproval: true,
    handler: fn (array $payload, StepContext $context): array => [
        'reference' => Ticket::open($payload)->reference,
    ],
));
```

The input is described with the same `Field` descriptors a node uses, so the
call is drawn by the same generic panel. A capability that writes is only
reachable from behind an approval node — the scenario refuses to publish
otherwise, which is the last moment that defect is still cheap.

There is no hash of your handler's source in the contract. A well-known
commercial package does that, and the result is that refactoring a method breaks
a live scenario. You change the `version` when you change the meaning.

## Slots

Slots are the things a bot can ask for. They exist so that "the visitor's phone
number" means the same thing in every scenario: the same question, the same
parsing, the same key in the stored answers.

The package ships the eight any business needs. Register your own:

```php
use MB\FilamentChatbot\Bot\Slots\Slot;
use MB\FilamentChatbot\Facades\Chatbot;

Chatbot::registerSlot(
    Slot::date('appointment_at')
        ->label('Appointment date')
        ->question('Which day suits you?')
        ->group('booking'),
);
```

Answers are parsed by kind, not required in a format: a date slot accepts
"tomorrow", "3.10" and an ISO string, and a choice slot accepts the option's
label, its key, or the number the visitor typed because their channel had no
buttons.

## Conditions

A `condition` node branches on something already known. The package supplies
what it owns — the channel, whether the visitor is known, the time, what has
been collected. Anything about your records is yours to add:

```php
use MB\FilamentChatbot\Bot\Conditions\ConditionField;
use MB\FilamentChatbot\Bot\Conditions\ConditionKind;

final class HasUnpaidInvoice extends ConditionField
{
    public static function key(): string
    {
        return 'shop.has_unpaid_invoice';
    }

    public function label(): string
    {
        return 'Has an unpaid invoice';
    }

    public function kind(): ConditionKind
    {
        return ConditionKind::Flag;
    }

    public function evaluate(Conversation $conversation, string $operator, mixed $value, Runtime $runtime): bool
    {
        return $conversation->contact?->invoices()->unpaid()->exists() ?? false;
    }
}
```

## The site widget

One script tag on any page:

```html
<script src="https://app.example.com/chatbot/widget.js" async
        data-bot="support"
        data-title="Support"
        data-color="#4f46e5"></script>
```

Vanilla, unbundled, no dependencies — it runs next to whatever the site already
loads, and it can be read by whoever has to approve putting it there.

Two things it does not do, both on purpose. It never trusts the page it runs on:
who the visitor is comes from a signature your application issued, and
everything else is decoration. And it will not answer a page whose origin is not
in `chatbot.widget.origins` — an empty list blocks every site rather than
allowing all of them, because the reverse default turns one forgotten setting
into an endpoint anyone can embed and bill to your model budget.

## AI

AI is optional and off by default, and every scenario has to work without it: an
unset key, a provider outage and an exhausted budget all take the same "could
not" branch.

Drivers:

- `null` — the shipped default.
- `openai` — any OpenAI-compatible endpoint, over Laravel's HTTP client and
  nothing else. OpenRouter, Ollama, LM Studio, Azure, DeepSeek, a local vLLM.
- `laravel-ai` — used when `laravel/ai` is installed, for Anthropic, Gemini,
  Bedrock and tool calling. A suggestion rather than a requirement: it is still
  0.x, it requires `aws/aws-sdk-php` outright, and requiring it would raise this
  package's Laravel floor for every installation, including ones that only ever
  talk to a single endpoint.
- Your own, registered like anything else.

## Try it

```bash
php artisan chatbot:demo-data
php artisan chatbot:demo "my printer is broken" "Alex" "alex@example.test" "it will not switch on" "today"
```

The first seeds one bot, six scenarios covering every kind of node, ten
knowledge topics and a few operator canned replies. The second holds that
conversation and prints the transcript together with the run journal — which is
the fastest way to see that the package is wired into *your* application, with
your providers and your database, rather than into a test case.

Then open the panel and look at a scenario on the canvas.

## Testing

The package develops against a bare Filament application in `workbench/`, which
contains no chatbot code at all — only the registration a host would write. If a
screen needs something the workbench does not have, that is something a host
would have hit on day one.

```bash
composer test
composer serve   # the workbench at /admin
```

## Licence

MIT.
