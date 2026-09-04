# Filament Chatbot

Customer chat and a visual chatbot builder for any Filament panel.

Conversations from the web widget and messaging channels, a scenario editor on a
canvas, a knowledge base, and answers from an AI provider when you want them —
all of it usable without the package knowing anything about your business.

> Status: in development. The API below is what exists today; sections marked
> *planned* are on the roadmap and not shipped yet.

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
2. **Capabilities** *(planned)* — register an action with an input and output
   schema, and a scenario can invoke it.
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

Planned: `call`/`result` (a scenario you can call and return from), `query`,
`choose` and `mutate` (data resources), `action` (capabilities), `http`,
`transform`, `forEach`, `approval`, `answer` and `ai`.

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
