---
title: Installation
layout: default
nav_order: 2
---

# Installation

## Requirements

| | |
| --- | --- |
| PHP | 8.3 or newer |
| Laravel | 11, 12 or 13 |
| Filament | 5 |

The package requires `illuminate/bus`, `illuminate/queue`, `illuminate/http`,
`illuminate/database`, `illuminate/support` and `illuminate/contracts` — the
pieces it actually uses — rather than `laravel/framework` as a whole, so it can
be installed into an application that has trimmed its dependencies.

Two things are suggestions rather than requirements, and the distinction is
deliberate:

- `defstudio/telegraph` (`^1.72`) — needed only by the Telegram channel driver.
- `laravel/ai` (`^0.11`) — enables the `laravel-ai` gateway driver, and with it
  Anthropic, Gemini, Bedrock and tool calling.

`laravel/ai` is not required because requiring it would make every installation
of this package inherit its constraints: it is still 0.x with a moving provider
surface, it pulls in `aws/aws-sdk-php` outright, and its `illuminate/json-schema`
constraint raises the Laravel floor to 12.62. An application that only ever
talks to one OpenAI-compatible endpoint would pay all of that for nothing, and
would break whenever a 0.x release did.

---

## Install

```bash
composer require mb4it/filament-chatbot
php artisan migrate
```

`ChatbotServiceProvider` is discovered from `composer.json`, so there is nothing
to add to `bootstrap/providers.php`. It registers a second provider —
`ChannelsServiceProvider` — from inside its own `register()` rather than listing
it for discovery as well. Two discovered providers are two things that can be
half-installed, and the half-installed state here is a package whose screens all
work and whose messages never leave the building, with nothing anywhere saying
which half is missing.

Migrations are loaded from the package and do not have to be published. Thirteen
tables are created, all prefixed `chatbot_`:

```
chatbot_bots               chatbot_runs
chatbot_scenarios          chatbot_run_steps
chatbot_steps              chatbot_knowledge_entries
chatbot_scenario_versions  chatbot_quick_replies
chatbot_conversations      chatbot_data_resources
chatbot_messages           chatbot_quality_tests
                           chatbot_budget_periods
```

If you need to edit them, publish them with `--tag=chatbot-migrations`. They go
through Laravel's `publishesMigrations()`, which stamps the copies with a fresh
timestamp; published under their package filenames they would sort in front of
migrations your application already has and try to add foreign keys to tables
that do not exist yet.

---

## Add the plugin to a panel

```php
use Filament\Panel;
use MB\FilamentChatbot\ChatbotPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugins([
        ChatbotPlugin::make(),
    ]);
}
```

Everything the package adds to a panel lives in the plugin, and nothing panel-
shaped is registered by the service provider. That split exists because an
application may run several panels — a staff desk and a partner portal — and
only one of them should carry customer conversations. Register the screens in
the provider and they appear in every panel the application has, with no way to
opt out short of forking the package.

The plugin registers six resources:

| Resource | What it manages |
| --- | --- |
| `BotResource` | Bots: the personality a conversation is answered by |
| `WidgetResource` | Widgets: where a bot is embedded, and how it looks there |
| `ScenarioResource` | Scenarios and the canvas editor |
| `KnowledgeEntryResource` | Knowledge base topics |
| `QuickReplyResource` | Canned replies for operators |
| `DataResourceResource` | Declared read access to your models |
| `RunResource` | The journal of what the bot did, turn by turn |

Two pages come with them and are registered the same way: the **chat desk**,
where a person answers, and the **sandbox**, where a scenario can be tried
before anybody meets it.

They are listed by hand rather than discovered. Filament's `discoverResources()`
derives a namespace from a directory, which works for an application's own
`app/Filament` tree and not for a vendor package — the host would have to know
the vendor path. Listing them also means a host that wants six of the seven can
extend `ChatbotPlugin` and override `resources()`.

Where they appear in the menu comes from configuration:

```php
'panel' => [
    'navigation_group' => 'Chatbot',
    'navigation_sort' => 30,
],
```

---

## Publish the configuration

```bash
php artisan vendor:publish --tag=chatbot-config
```

The config file is merged from the package, so the application runs without
publishing it, and the two settings the widget cannot answer anybody without are
both environment variables — no publishing needed:

```
CHATBOT_WIDGET_ORIGINS=https://shop.example.com
CHATBOT_WIDGET_SECRET=…   # only if you sign your visitors in
```

Publish the file when you need to change something else.

Other publish tags:

| Tag | What it copies |
| --- | --- |
| `chatbot-config` | `config/chatbot.php` |
| `chatbot-migrations` | The thirteen migrations, re-timestamped |
| `chatbot-views` | `resources/views/vendor/chatbot` |
| `chatbot-lang` | `lang/vendor/chatbot` |

---

## Publish the assets

```bash
php artisan filament:assets
```

**This is not optional, and skipping it produces the failure that looks like
nothing.** The scenario screen renders, the layout is correct, and where the
diagram should be there is an empty rectangle. The only evidence is a 404 in a
browser console nobody has open, so the report that arrives says "the editor is
broken" and the search starts in the graph code.

The canvas bundle ships pre-built in the package's `dist/` directory — a package
cannot ask a host to run a bundler, so the build happens upstream and the result
is committed. Both files are registered with `loadedOnRequest()`: the bundle is
most of a megabyte, because React and a graph library are not small, and it is
useful on exactly one screen. Registered normally every person opening any page
of the panel would download it in order to draw nothing.

The same command has to be run again after every upgrade of the package. The
published copy is a copy; nothing about the page tells you it is the old bundle.
`chatbot:doctor` has a check for exactly this — it asks Filament for the assets
it has registered and looks for each one's public path, so it keeps working if
the package renames a bundle or the application moves its assets directory.

---

## The queue

Two things in the package are jobs, and both of them behave badly on the `sync`
driver:

**Channel delivery.** A message leaving for Telegram is dispatched, never sent
inside the request that produced it. A provider answers in about a second when
it is healthy and not at all when it is not, and the request waiting on that
belongs to a visitor watching their own message fail to appear. Retries are
capped by `chatbot.channels.delivery.tries` (3 by default), and failures that
cannot repeat — a channel with no driver, missing credentials — are not retried
at all, because waiting cannot make a driver appear and the retry only delays
the reason reaching an operator.

**The `delay` node.** It dispatches its own continuation with a delay. On `sync`
that delay is a `sleep` inside the request that is answering the visitor: the
widget hangs for the length of the pause, and on anything longer than the web
server's timeout it never answers at all. What the author sees is a bot that
stopped replying, so they go and read the scenario.

Point `QUEUE_CONNECTION` at a real driver and run a worker before either of
those is in play. `chatbot:doctor` warns rather than fails here, and counts
nodes rather than reading the setting alone — `sync` with no pause nodes and no
outbound channels is a perfectly reasonable laptop, and a check that failed on a
laptop would be switched off everywhere within a week.

---

## The first five minutes

Three commands, in this order. All of them are registered only when the
application is running in the console.

### `php artisan chatbot:demo-data`

Seeds one bot with six scenarios, ten knowledge topics and four operator quick
replies. The scenarios are chosen to cover the node kinds that are hard to
reason about from a description — a call that returns, a branch on working
hours, a fallback — rather than to demonstrate one of each. The whole set is
written in a single transaction, because a scenario is only useful together with
the ones it jumps into.

```bash
php artisan chatbot:demo-data
php artisan chatbot:demo-data --bot=demo --fresh
```

| Option | |
| --- | --- |
| `--bot=` | Slug of the demo bot, `demo` by default |
| `--fresh` | Remove the demo data first |
| `--force` | Allow this in production |

It refuses to run in production without `--force`, and the guard is not
ceremony. This command does not write sample rows into a table nobody reads — a
scenario is live the moment it is saved. A fallback scenario called "Fallback"
starts answering real customers on the next message, competing with the
installation's own fallback for everything that reaches it, and the demo's
cheerful "Order ABC-123 left the warehouse" is a statement about somebody's
actual order. Deleting the rows afterwards does not undo it: the conversations
already happened.

### `php artisan chatbot:demo`

Holds a scripted conversation with the seeded bot and prints the transcript
together with the run journal.

```bash
php artisan chatbot:demo "my printer is broken" "Alex" "alex@example.test" "it will not switch on" "today"
php artisan chatbot:demo --bot=demo "where is my order" "ABC-123"
```

Run without arguments it walks the support scenario, which is the one that calls
another scenario and comes back — a script that only said hello would print a
perfectly good transcript on a package whose call stack was broken.

This proves something the package's own test suite cannot. The suite drives the
engine through a test case; this drives it through **your** application, with
your service providers, your registry and your database, which is where a
missing registration or a mis-ordered boot actually shows up. It writes a real
conversation into the inbox, so it too refuses production without `--force`.

### `php artisan chatbot:doctor`

Checks the installation and says what is wrong with it.

```bash
php artisan chatbot:doctor
php artisan chatbot:doctor --check=assets --check=widget
php artisan chatbot:doctor --strict
```

| Option | |
| --- | --- |
| `--check=` | Run only these checks, by key. Repeatable |
| `--strict` | Treat warnings as failures too |

Ten checks ship, in this order: `schema`, `step-types`, `scenarios`, `bots`,
`data-resources`, `queue`, `assets`, `widget`, `http`, `ai`. The schema
check runs first because everything after it reads from those tables, and a
report that opens with six exceptions about missing SQL tables buries the one
line that explains all of them.

A `FAIL` exits with 1, so the command belongs in a deployment pipeline as well
as in a terminal. Warnings do not fail the run unless you pass `--strict`. The
faults it looks for are the ones nobody notices until a customer does: an empty
widget origin list (the widget is switched on and mute, which reads as a broken
widget rather than an unfilled setting), an unpublished asset bundle, a scenario
whose node kind no longer exists.

A host can add its own check; it registers into the same registry every other
extension point uses, and a check claiming a built-in key replaces it. See
[Operations]({{ site.baseurl }}/operations).

---

## What to configure before going live

The defaults are chosen so that a forgotten setting fails closed. That means
several of them do nothing useful until you fill them in, which is intentional
and is the opposite of a bug:

- **`chatbot.widget.origins`** (from `CHATBOT_WIDGET_ORIGINS`) is empty, and an
  empty list blocks every site rather than allowing all of them. The reverse
  default would turn one forgotten setting into an endpoint any site can embed
  and bill to your model budget.
  Origins are matched whole and exactly — scheme, host and port together — so
  `https://shop.example.com` does not admit the plaintext version of the same
  site, and a request arriving with no `Origin` header at all is refused.
- **`chatbot.http.allowed_domains`** is empty, and the `http` node refuses to
  run until a domain is listed — in every environment, testing included.
- **`chatbot.channels.telegram.secret_token`** is what actually authenticates
  the webhook; the URL is public and its only other protection is that nobody
  has guessed it. An empty secret refuses every update rather than accepting
  every update.
- **`chatbot.ai.default`** is `null`, the driver that answers nothing. Every
  scenario has to stay useful in that state — an unset key, a provider outage
  and an exhausted budget all take the same "could not" branch — so the package
  ships in the state that proves it.

---

## Next

- [Scenarios]({{ site.baseurl }}/scenarios) — how a conversation is walked, and what publishing means.
- [Node reference]({{ site.baseurl }}/nodes) — every kind of node and what it branches on.
- [Data resources]({{ site.baseurl }}/data-resources) — letting the bot read your records.
