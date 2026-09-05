---
title: Operations
layout: default
nav_order: 11
---

# Operations

Two commands, both written to be run by a person after installing and by a
pipeline before every release. Plus the two things that decide what a running
installation is allowed to spend and who is allowed to see it.

---

## `chatbot:doctor`

```bash
php artisan chatbot:doctor
php artisan chatbot:doctor --check=widget --check=queue
php artisan chatbot:doctor --strict
```

It looks for the class of fault nobody notices until a customer does. An empty
widget origin list, an unpublished asset bundle, a scenario whose node kind no
longer exists: each of them looks fine on the screen that reports success and
breaks the one that matters.

### Exit codes

| Worst finding | Exit | With `--strict` |
| --- | --- | --- |
| pass / skip | 0 | 0 |
| warn | 0 | **1** |
| fail | 1 | 1 |

`FAIL` returns 1, so a pipeline can gate on it. **Warnings do not fail the run by
default**, and that is the decision that keeps the command in the pipeline: a
queue on the sync driver is wrong in production and perfectly reasonable on a
laptop, and a command that refused to pass on a laptop would be turned off
everywhere within a week. `--strict` is for the pipeline that wants to hold the
higher line.

`--check` restricts the run by key. Asking for keys that match nothing is an
error, not an empty pass — a pipeline naming a check that has been renamed
should hear about it rather than report success.

### Fail, warn and skip mean different things

`Fail` and `Warn` are separated by one question: **does the installation work.** A
queue running synchronously makes pauses feel wrong; a widget with no allowed
origins does not answer at all. Only the second may stop a deployment. Collapse
them and you either block releases over a preference or let a dead widget
through — after which nobody looks at the report again.

**`Skipped` is not a pass.** A check whose subject is not installed has proved
nothing, and printing PASS for it would be a green line about something that was
never looked at.

Every finding carries advice and the subjects it is about, printed under the
message. The advice is the half that gets read: "the queue is synchronous" tells
somebody a fact about their configuration, while naming the setting turns it into
an action, and the person reading is usually deploying and did not write any of
this. Naming subjects is the difference between a warning somebody acts on and a
warning somebody rediscovers by opening scenarios one at a time — which is also
why they are printed under the sentence rather than inside it: a finding about
eleven scenarios is one line to read and eleven to act on.

### What it checks

Order matters, and the list starts with the schema: nothing below it can say
anything useful when its tables are missing, and a report opening with six SQL
exceptions buries the one line explaining all of them.

**`schema` — Database schema.** Are the package's tables there. The table list is
written out rather than derived from migration filenames: a published copy of a
migration keeps the table and loses the filename, so reading the directory would
report a healthy installation as broken on exactly the hosts that followed the
publishing instructions.

**`step-types` — Node kinds.** Nodes of a kind nothing in this installation
provides — a package removed, or a scenario exported from an installation that
had one. The node is kept, because the editor draws it as a defect rather than
deleting somebody's work, but a conversation reaching it is handed to a person
and the panel gives no hint that a whole branch has become a dead end. **A
failure**, because there is no reading of this under which the installation is
doing what its author drew: deploying over it ships a scenario whose behaviour
changed without anyone editing it. Scenarios are named rather than nodes, because
the first thing anybody does with the report is open one.

**`scenarios` — Scenarios.** The three ways a live scenario can be wrong without
anybody noticing. All warnings, deliberately: a half-drawn scenario is one
somebody is still working on, and refusing the release of an unrelated change
over it would make the doctor the thing that gets skipped. The blocking-defect
check runs *the same code the publish button runs* rather than a lighter version
— two implementations of "is this scenario sound" disagreeing is how an author
ends up with a green report and a publish that says no.

**`bots` — Bots.** Active bots with nothing to say. A bot with no active scenario
is not switched off: it accepts messages, opens conversations and answers none of
them, so the visitor writes into a widget that works and gets silence — which
reads as the company ignoring them. It happens after a scenario is deactivated to
fix something and never switched back on. Scenarios attached to no bot count for
every bot, because that is how the runtime picks them.

**`queue` — Queue.** Whether anything here needs a queue that is not there. A
delay node dispatches its continuation with a delay, and on the sync driver that
delay is a sleep inside the request answering the visitor: the widget hangs for
the length of the pause, and past the web server's timeout it never answers at
all — while the author sees a bot that stopped replying and goes looking at the
scenario. A warning, and it counts pause nodes rather than reading the setting
alone, because sync is only a problem once it meets one.

**`assets` — Published assets.** Are the canvas files where the browser will ask
for them. This is the failure that looks like nothing: the scenario screen
renders, the layout is correct, and where the diagram should be there is an empty
rectangle, with the only evidence a 404 in a console nobody has open. It happens
on every deployment that forgets `filament:assets`, and again after an upgrade
because the published copy is the old bundle and nothing on the page says so. The
check asks Filament for the assets it has registered rather than testing a
hard-coded path, so it keeps working if a bundle is renamed or the assets
directory moves.

**`widget` — Site widget.** An empty origin list on an enabled widget is a
**failure**, and that severity is the reason the check exists: an empty list
blocks every site — the deliberate, safe default — so a widget switched on and
never given an origin is switched on and mute, and the only symptom is a widget
that does not open, which reads as broken code rather than an unfilled setting. A
wildcard in production is the opposite mistake and only a warning: it works, and
it lets any site embed this installation's widget and bill its model budget —
which somebody may have decided on purpose for a network of sites, so it is said
out loud rather than refused.

**`http` — Outbound HTTP.** Scenarios that call out, against the list of hosts
they may call. The allow-list starts empty on purpose — an HTTP node that could
reach anything the moment it was drawn would give a scenario author the
application's network access — and the cost of that safety is exactly this
situation: a scenario built and tested against a live endpoint, deployed to a
host whose config was never edited, where every call is refused. A **failure**,
because the scenario is already drawn: an author decided that call was necessary,
and an installation that cannot make it is running a scenario that cannot do its
job.

**`ai` — AI gateways.** Can each bot meant to use a model actually reach one. An
unreachable gateway does not break a bot, it flattens it: every AI node takes its
"could not" exit, the scenario keeps working, and the answers get quietly worse.
That is why an outage is a branch rather than an error — and why it has to be
said out loud here, because nothing else will: a missing API key looks exactly
like a bot whose scenarios were written badly. A warning, because the
installation works.

### Adding your own

Half of what breaks a chat installation is outside the package. A registered
check appears in the same report — see
[Extending]({{ site.baseurl }}/extending#your-own-installation-check). A host
check claiming a built-in key replaces it, matching how step types behave.

---

## Quality checks

A saved quality check is a rehearsal: a scripted set of visitor lines and a set
of expectations about what the bot does with them.

**The engine under test is the one that answers customers**, driven exactly the
way a channel drives it. A runner that walked the graph itself would be a second
implementation of the bot, and the interesting bugs — a router picking the wrong
scenario, a node waiting when it should branch — live precisely in the part such
a runner would have reimplemented instead of exercised.

**A named scenario is the subject of the check, not a forced entry point.** The
router still chooses. Entering the named scenario by hand would let a check stay
green while every real visitor saying the same words is routed somewhere else
entirely — which is the failure most worth catching.

### Expectations

| Key | Meaning |
| --- | --- |
| `says` | phrases that must appear in what the bot said — a string or a list |
| `never_says` | phrases that must not appear |
| `ends_with` | how the conversation must end: `closed`, `handed_off`, `waiting` or `finished` |
| `visits` | node keys the run must pass through |
| `collected` | variable names mapped to the values expected in them |

`says` and `never_says` match case-insensitively against everything the bot said
across the whole rehearsal, joined together — so an expectation does not have to
guess which message a phrase lands in. Both accept a single string or a list,
because a single phrase is what most checks want and being made to wrap it in an
array is the kind of friction that stops checks being written.

`collected` compares loosely: an expectation written as the number `3` and an
answer stored as `"3"` are the same answer, and a check failing on the difference
would be reporting on storage rather than on the bot.

**Every expectation is evaluated, never stopping at the first mismatch.** A check
reporting one at a time turns a wrong branch into as many runs as it has
consequences, and the person fixing it reads the least informative of them
first.

### `Failed` and `Broken` are not the same result

**`Failed`** — the bot ran and said something other than what was expected. Fix
the bot, or the expectation.

**`Broken`** ("could not run") — the check could not happen at all: the scenario
was deleted, a node kind nothing provides, a data resource switched off, the
engine threw. Fix the check or what it points at.

Recording the second as a failure buries the cause under a red badge that invites
somebody to go looking for a bug in a conversation that never ran, and it makes a
broken suite look like a broken bot right up until the day both are ignored. It
prints yellow rather than red for the same reason: a wall of red teaches the
reader that the suite is red, and the one line saying the scenario was deleted
scrolls past with the rest.

### The rehearsal conversation is hidden

Each run gets its own conversation, marked in `meta` and hidden by a **global
scope** named `real` on `Conversation`.

A global scope rather than a `real()` scope each screen remembers to call,
because "each screen" is not a list anybody holds: the inbox, the unread badge,
the returning-visitor condition and the widget's own lookup all ask this table,
and every one of them was written before quality checks existed. A scope that has
to be added is the scope that will be missing from the next caller — and the
symptom is an operator opening a thread with a customer who never wrote,
answering it, and finding nobody there.

It buys a second thing: silence. `Message::conversation` is scoped too, so the
outbox finds no thread behind a rehearsal's reply and cannot deliver it to a real
chat. The runner lifts the scope by name, which is the only place that may.

Reusing a customer's thread would put words in their transcript; leaving the
rehearsal visible would put a thread that never happened in the operator's inbox.

### `chatbot:quality`

```bash
php artisan chatbot:quality
php artisan chatbot:quality --bot=support --required
php artisan chatbot:quality --check="refund path" --check=17
php artisan chatbot:quality --all
```

| Situation | Exit |
| --- | --- |
| everything required passed | 0 |
| a required check failed **or** could not run | 1 |
| only non-required checks failed | 0 (reported as a warning) |
| no checks saved at all, no filters | 0 (warning) |
| a filter matched nothing | 1 |

**Only a required check can fail the run.** The distinction is not politeness: a
suite grows by people adding the conversation they were curious about that week,
and half of those describe a wording somebody is still arguing over. A command
refusing a release over any of them would be taken out of the pipeline within a
fortnight, taking the checks that matter with it. Required is the small set
somebody agreed must never break.

**A required check that could not run also fails.** It has proved nothing, and
"could not run" is what a check looks like the day the scenario it guards is
deleted — exactly the release it exists to stop.

Switched-off checks are excluded unless `--all`: a check is switched off because
it describes a conversation that no longer applies, and running it anyway reports
a failure whose fix is to delete it.

An empty selection is answered differently depending on who emptied it. An
installation with no checks is a normal installation, and a command that failed
on it would fail every pipeline the day the package is installed. A selection
emptied by a filter is the opposite: somebody asked for a named check and it is
not there, and passing silently would let a pipeline report success for a check
that has been deleted.

`--bot` accepts a slug as well as an id, because a slug is what a pipeline can
hard-code: ids differ between the staging database and the production one, and a
pipeline pinned to one runs the wrong bot's checks on the other without ever
reporting an error.

---

## Budgets

```php
'budgets' => [
    'enabled' => false,
    'monthly_tokens' => null,
    'monthly_cost' => null,
    'tool_calls_per_turn' => 5,
    'seconds_per_turn' => 90,
],
```

A bot may carry its own `monthly_token_budget` and `monthly_cost_budget`, which
take precedence over the installation's.

### Reservation happens before the call, not after

The obvious implementation — add up what was spent, compare, then call the
provider — is wrong in a way that only shows up under load. Two conversations
arriving in the same second both read the same remaining balance, both decide
there is room, and both spend it; the month ends over budget with a ledger saying
it never was.

So the estimate is **written first, and the decision is the write itself**: one
conditional `UPDATE` that adds the estimate to the period row only if the row
still has room for it, with the database reporting whether anything changed.
Nothing is read into PHP, compared there and written back, because that sequence
is not atomic however short it is. The second of two simultaneous callers sees
the first caller's estimate already in the column, which is the entire point.

Afterwards the estimate is replaced by the real figures **in the same statement**,
so the row is never briefly counted twice — a concurrent reservation reading it
mid-settle would otherwise refuse a call there was room for. The call counter is
not touched again: it was raised at reservation, and raising it twice would count
one request as two, which is the first number that stops making sense when you
are trying to spot an oversized prompt by the ratio of calls to tokens.

If the call never happens — the provider refused, the request timed out, the
scenario took another branch — **the estimate is given back.** Left in place, a
run of provider errors would exhaust a budget without a single answer being
generated, and the panel would show a month spent on nothing.

### Tokens are reserved; cost is only checked

Cost is checked as "has the month already reached its ceiling" rather than
reserved, because at the moment of reserving nobody knows what the call will
cost: the price depends on how long the answer turns out to be. Tokens are the
quantity that can honestly be estimated in advance. The cost ceiling therefore
stops the month one call later than the token one would — and reserving a made-up
price would be a more precise-looking number that is wrong every time.

### Running out

A denied reservation comes back as `AiFailure::BudgetExhausted`, which is the
same branch as an outage, a refusal or an unset key: the node takes its "could
not" exit and the scenario keeps talking. The reason is in the run journal. This
is the practical payoff of
[the one-branch rule]({{ site.baseurl }}/ai) — exhausting a budget degrades the
bot rather than breaking it.

### With no budget configured, spending is still recorded

The estimate is written even when nothing limits it, and that is deliberate.
**Accounting is not the same feature as limiting.** The first question anybody
asks after switching AI on is what it cost, and an installation that only counted
once a limit existed would answer that with nothing for the entire period before
somebody thought to set one.

Switching budgets off clears both limits rather than skipping the guard: usage
keeps being recorded, only the refusal stops.

`null` means unlimited and `0` means zero, and they are not blurred together. A
bot budgeted at zero has been switched off deliberately, and reading that as
"fall back to the installation default" would turn a deliberate stop into normal
spending.

The period is a calendar month **on the application's own clock**, not UTC and
not the visitor's: a budget is set by the people who pay it, and they mean their
own calendar.

---

## Authorisation

**The package invents no permission system and ships no roles.** It asks your
application a question, and if you have an opinion that opinion decides. If you
have none, the answer is yes.

The abilities are:

```
chatbot.view.<subject>      chatbot.manage.<subject>
chatbot.view               chatbot.manage
```

The specific ability is tried first, then the broad one, so an application
granting `chatbot.manage` wholesale still works while one that wants support
staff editing the knowledge base but not the scenarios can say exactly that.

```php
Gate::define('chatbot.manage.scenarios', fn ($user) => $user->hasRole('bot-author'));
Gate::define('chatbot.view', fn ($user) => $user->hasRole('support'));
```

### Why the default is "allowed"

It is a choice, not laziness.

A package that denied by default would be unusable the moment it was installed:
the screens would appear in the menu and refuse to open, with nothing on screen
to say which ability was missing. A package that invented its own permission
names would collide with whatever you already use, and every installation would
need a bridge. Asking a question you may ignore costs nothing on day one and
tightens the moment you define the gate.

**A defined gate is obeyed even when it denies** — including for a user the
package would otherwise have allowed. Silently overruling a host's "no" is the
one thing a package must never do here.

### The subjects

| Subject | Covers |
| --- | --- |
| `bots` | Bot |
| `scenarios` | Scenario, Step, ScenarioVersion |
| `conversations` | Conversation, Message |
| `runs` | Run, RunStep |
| `knowledge` | KnowledgeEntry |
| `quick_replies` | QuickReply |
| `data_resources` | DataResource |
| `quality_checks` | QualityTest |
| `budgets` | BudgetPeriod |

Steps and versions share the scenarios subject because they are parts of a
scenario rather than things anybody manages separately; messages share
conversations for the same reason. Data resources get their own because deciding
what the bot may read out of your database is a different decision from drawing a
conversation.

The mapping lives in one array — `MB\FilamentChatbot\Policies\Policies::map()` —
rather than in scattered `Gate::policy()` calls, so that a test can walk it. A
model added later and left out would be authorised by nothing at all, and the
failure is invisible: Laravel treats a model with no policy as "no opinion" and
Filament shows the screen.
