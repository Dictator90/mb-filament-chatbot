---
title: AI
layout: default
nav_order: 9
---

# AI

**A scenario must remain useful with the model switched off.** That is not
advice, it is the shape of every class on this page. An unset key, an
uninstalled library, a provider outage, a refusal, an unreadable answer and an
exhausted budget all come back as the same thing — a decline — and every node
that can use a model has one exit for all of them. The package ships with the
null driver as its default, so the state you install into is the state a
provider outage produces, which means the behaviour you tested is the behaviour
you get on a bad day.

Six failure reasons, one branch. Give them separate exits and every scenario in
the installation grows six arrows leading to the same apology. They are told
apart in the run journal instead, because that is where the difference matters:
"nobody configured a key" is fixed in settings and "the provider refused" is
fixed with the provider, and a single "failed" would send somebody looking in
the wrong place.

`AiFailure` is that list: `not_configured`, `unavailable`, `refused`,
`unparseable`, `budget_exhausted`, `timeout`.

---

## Drivers

Configured under `chatbot.ai`:

```php
'ai' => [
    'default' => env('CHATBOT_AI_DRIVER', 'null'),

    'drivers' => [
        'null' => [],

        'openai' => [
            'base_url' => env('CHATBOT_AI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('CHATBOT_AI_API_KEY'),
            'model' => env('CHATBOT_AI_MODEL', 'gpt-4o-mini'),
            'timeout' => 20,
            'temperature' => 0.2,
            'max_tokens' => 600,
            'price_per_million_input' => env('CHATBOT_AI_PRICE_INPUT', 0),
            'price_per_million_output' => env('CHATBOT_AI_PRICE_OUTPUT', 0),
            'proxy' => env('CHATBOT_AI_PROXY'),
        ],

        'anthropic' => [
            'base_url' => env('CHATBOT_ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'api_key' => env('CHATBOT_ANTHROPIC_API_KEY'),
            'model' => env('CHATBOT_ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            'timeout' => 30,
            'temperature' => 0.2,
            'max_tokens' => 600,
            'price_per_million_input' => env('CHATBOT_ANTHROPIC_PRICE_INPUT', 0),
            'price_per_million_output' => env('CHATBOT_ANTHROPIC_PRICE_OUTPUT', 0),
            'proxy' => env('CHATBOT_ANTHROPIC_PROXY'),
        ],

        'laravel-ai' => [
            'provider' => env('CHATBOT_LARAVEL_AI_PROVIDER'),
            'model' => env('CHATBOT_LARAVEL_AI_MODEL'),
            'temperature' => 0.2,
            'max_tokens' => 600,
        ],
    ],
],
```

**`null`** — the default. Declines everything with `not_configured`. Everything
that never needed a model keeps working.

**`openai`** — any endpoint speaking the OpenAI chat-completions shape. Point
`base_url` at a local runner, a gateway or a compatible provider; the driver has
no opinion about who is on the other end.

**`anthropic`** — Anthropic's own Messages API, not its OpenAI-compatible
endpoint. Three things differ where it matters: the instruction is a `system`
field of its own rather than a message with a role, the answer arrives as a list
of content blocks, and the token counts have different names. A compatibility
layer would hide all three behind somebody else's second priority, and this
request is four fields. There is no `response_format` in that API, so JSON is
asked for in words and read back with the same fence-stripping decoder every
driver here uses.

**`proxy`** on either driver routes its calls through a tunnel —
`socks5://127.0.0.1:1080`, `http://user:pass@host:port` — with
`chatbot.ai.proxy` as the installation-wide fallback. The case is a network that
cannot reach the provider at all: it fails as a silent timeout, which on screen
reads as the model being slow rather than as a route that is closed.

The two prices are what your account pays per million tokens, in whole units of
whatever currency it is billed in. No endpoint of this shape reports a price, and
nobody but the account holder knows one — the same request costs one thing on
OpenAI, another through a reseller and nothing at all against a model on your own
machine. Left at zero, tokens are still counted and the
[money ceiling]({{ site.baseurl }}/operations#budgets) simply never fires, which
is visible and fixable; a price the package guessed would be wrong on every
invoice and would look authoritative while it was.

**`laravel-ai`** — used only when `laravel/ai` is installed.

**Your own** — registered on the factory with `extend()`, configured out of the
same `chatbot.ai.drivers` array so it is set up exactly like a built-in one and
appears in a node's provider picker alongside them. See
[Extending]({{ site.baseurl }}/extending#your-own-ai-driver).

An unknown driver name yields the null gateway rather than an exception. The
name comes from a config file or an environment variable, so getting it wrong is
a typo, and a typo must not be able to take down the panel, the queue worker and
every conversation at once. Declined instead of thrown, the mistake shows up in
a run journal full of "no model is configured" — where it can be read.

### Why `laravel/ai` is a suggestion and not a requirement

Requiring it would be a real cost on every installation. It is still 0.x with a
provider surface that moves between releases; it pulls in `aws/aws-sdk-php`
outright; and its own constraints raise this package's floor to a recent
Laravel 12 patch. An installation that only ever posts to one OpenAI-compatible
endpoint would carry all of that to use none of it, and would break on the day a
0.x release does.

So it stays in `suggest`, and a driver whose library is absent simply reports
itself unavailable — the same branch as an unset key or an outage. A hard
require would have turned "somebody chose a driver they did not install" into a
fatal on a customer's first message.

The driver also *probes* for the library's entry points rather than writing
against one release: method names in a 0.x package are not a contract, and a
chain hard-coded today throws on the host's next `composer update`, inside a
conversation. Nothing found and nothing thrown both come back as a decline.

### What a gateway may and may not do

`AiGateway` has three methods — `name()`, `isAvailable()`, `ask()` — and the
small surface is the design. Everything the package asks a model to do is one
question with one answer, and a contract shaped like that can be satisfied by an
HTTP endpoint, by `laravel/ai`, by your own client, or by nothing at all.
Streaming, tool calling and provider-side conversation state were left out
deliberately: each would tie the package to one provider's idea of a session,
and a driver that could not express it would have to fake it.

**A gateway never throws.** Not for a missing key, not for a refused request,
not for a socket that closed halfway. On the other side of the call is a node
with a second exit drawn on the canvas and a scenario that has to keep talking.

**`isAvailable()` is answered from settings alone** — a key, an address, an
installed class — never by calling the provider. A liveness check would put a
network round trip in front of every node that might use a model, including the
ones that end up not needing it.

### What a prompt carries

`AiPrompt` is a value object with four parts rather than one string, because
every provider worth supporting treats them differently: the instruction belongs
in a system role, the question in a user one, the context has to be labelled so
the model can name which fragment it used, and the schema decides whether the
answer is asked for as prose or as JSON. Flattened ahead of the driver, all four
arrive as an undifferentiated wall of text and each driver has to guess the seams
back out.

**Context fragments carry a key, and the key is the point** — see the next
section.

---

## The rule that keeps a model honest

**An answer is accepted only if the model names sources from the ones it was
given.**

The model is handed labelled fragments and asked to say which it used. The
caller intersects that claim with the keys it passed in; if nothing survives the
intersection, the answer is discarded and the node takes its "could not" exit.

What this prevents is the failure that does not look like one. A model that
invents a fragment produces an answer that sounds exactly like a real one —
fluent, in the company's voice, and wrong. Refusing it costs a "could not"
branch. Accepting it costs a customer being told the wrong refund policy by the
company itself, believing it, and quoting it back.

The check is only possible because fragments are keyed. Without keys it degrades
into matching sentences, which is exactly the check that cannot be made
reliably. That is also why context is numbered by key rather than by position: a
positional number is meaningless the moment the candidate list changes between
two turns.

An empty answer is refused on the same path.

---

## The three nodes

### `ai` — Answer with a model

Two exits: **answered** and **could not**.

It looks up material for the visitor's message through `KnowledgeSource`, hands
the model the candidates plus an instruction, and speaks the reply — but only
after the source check above.

Settings: which provider (blank means whatever the installation is set to), the
instruction, and how many articles to offer (default 5, capped at 20 — more
material means a better chance of an answer and a larger, slower, costlier
request).

**Why this is a node of its own rather than a switch inside the knowledge
answer.** While the model lived inside that node, the canvas showed one box for
two completely different things: a paragraph the company wrote and approved, and
a sentence composed on the spot by somebody else's program. Whoever drew the
scenario could not see which was which, and neither could the reviewer. Worse,
the choice was made once for the whole bot by a setting — so a company wanting a
model for opening hours and a fixed text for cancellation terms had no way to say
so. Two boxes, and the choice is made where it belongs: on the canvas, per
branch, by the person accountable for the wording.

The second exit is taken far more often than a demo suggests — no driver, no
key, the library missing, the provider down or refusing, the budget spent, the
answer unreadable, no material found, or an answer naming no material. A
scenario drawn without that exit connected goes silent the first time a provider
hiccups, and the editor warns about exactly that while the scenario is still
open.

The node has no body text of its own. What the visitor reads is composed by the
model, and a body box would put a fixed sentence in front of it whose effect an
author could not see until a real conversation ran.

### `ai_task` — Extract with a model

Two exits: **extracted** and **could not**. Settings: the instruction, what to
read from (defaults to the message just received), the list of value names to
expect, what to remember them as, and the provider.

It pulls named values out of what somebody wrote. "We are two adults and a child,
flying from Rostov around the tenth of June" holds four values no pattern
reliably finds; the node turns them into variables the rest of the scenario can
branch on, ask about and send. With `into` set to `trip` and fields `adults`,
`city`, `month`, the values land as `trip_adults`, `trip_city`, `trip_month`.

**What it may not do is decide anything.** It does not choose the next node, it
does not call a host action, it does not compose a word the visitor will read.
That is a boundary, not a missing feature. A model allowed to choose the route
takes a different one on Tuesday for the same sentence it saw on Monday, and the
author asked by a customer why the conversation went that way has no answer and
no way to get one. Extraction is checkable: the values land in named variables,
the journal shows them, and the branching next to it is a condition somebody
wrote and can read back. Keep those apart and a scenario stays explainable with a
model in it.

**Nothing is written on the failure path.** Partial or invented values would be
worse than no extraction at all: the conversation would carry on confidently
against a passenger count nobody said.

### `ai_agent` — A conversation led by a model

Two exits: **answered** and **needs a person**. The model reads the thread, may
use the tools this node offers, and answers in its own words. The settings are
in the [node reference]({{ site.baseurl }}/nodes); what follows is why they are
shaped that way.

**A driver has to opt in.** Tool calling and conversation state were left out of
`AiGateway` on purpose, and that reasoning still holds for a contract a host may
satisfy with an HTTP endpoint or nothing at all. What changed is that some
drivers *can* express them, so there is a second contract —
`AiConversationGateway`, one method, `converse(AiTurn): AiReply` — and the
built-in `anthropic` driver implements it. A driver that does not is not broken:
the node takes its second exit and the journal names the driver.

**The agent cannot reach a provider around the budget.** It is the most
expensive caller in the package — every round is a billed request carrying the
window and the whole tool catalogue — so `BudgetedGateway` implements the
conversation contract too, reserves against the same month's ceiling, and is the
only way in. A driver that cannot converse is declined by the wrapper rather
than called.

**What the model may do is a list on the canvas, not a sentence in the prompt.**
Capabilities are how a host teaches the bot to do things, and some of them cancel
bookings or move money. A model handed the whole registry is one confidently
worded customer message away from using any of them — and the conversation that
did it reads, in the transcript, like an ordinary request politely granted. The
author names the actions per node; a tool that was not named does not exist, and
one the model invents is refused and reported back to it so it can recover within
the turn.

**Three tools belong to the package**, because all three are about the
conversation rather than the application:

| Tool | What it is for |
| --- | --- |
| `search_knowledge` | Answer from the company's own material instead of from what the model knows about the world |
| `remember` | Write a value where the scenario, the templates and the desk can read it — into the slots the author named |
| `call_human` | Say this needs a person. A model with no way to ask improvises instead, which is how a bot promises a refund |
| `pass_on` | Say its part is finished, which is what takes the node's first exit — offered only when that exit leads somewhere |

And reading is a tool per allow-listed data resource, `read_<resource>`. That is
how an agent answers "what have I got booked" without a second road to the same
columns: the scopes the resource declared narrow the rows to this conversation's
own person, resolved from server state the model is never shown; the sensitive
fields are dropped by the reader; and no filters are offered, because a model
composing them invents field names and each invention costs a refused round.

**Memory is a window, a summary and a line that can be drawn under both.** A
thread with a regular customer runs to hundreds of messages: sending them costs
money every turn, pushes the company's own instruction further from the model's
attention, and is eventually refused for length. So the model sees the last few
exchanges verbatim, plus a running summary of what fell out of the window —
rewritten once per windowful rather than per turn, because a summary call is a
second billed request. When a scenario finishes, the host draws a line
(`ConversationMemory::closeWindow()`): nothing is deleted, the transcript is the
record, but the model stops re-opening a request that was closed three weeks ago.
`forget()` drops the summary as well, for "забудьте, что я говорил" and for a
thread relinked to somebody else.

Everything leaving goes through the redactor, the visitor's own words included —
the same rule as a single question, and it matters more here, because an agent is
shown far more of a transcript than an answer node ever is.

**A scripted form is still the better place for a required interview.** The
twelve questions an agency needs are a list somebody is accountable for; a model
asked to collect them will get eleven and improvise the twelfth. What works is
the agent for the conversation — greeting, questions about anything, reading a
booking, working out what the person actually wants — with `remember` writing
what it learns into the same slots a form would have filled.

---

## Where the material comes from

`MB\FilamentChatbot\Ai\KnowledgeSource` is the one thing the model layer asks
for: a handful of labelled fragments that might answer this message, best first.

```php
/** @return list<array{title: string, text: string, key: string}> */
public function candidates(?int $botId, ?string $said, int $limit): array;
```

It is narrower than the knowledge base's own search on purpose. Binding the
nodes to how topics happen to be looked up today — keywords now, an index later,
embeddings after that — would make every one of those changes a change to the
nodes, while none of them changes what a prompt needs. It also makes the model
layer testable with no knowledge base at all, and lets you feed the model from a
help desk, a manual or a catalogue instead.

The `key` is what the model is asked to name back, so it has to be stable within
the turn and meaningful in a run journal: an article slug, not a row offset.

The package binds `KnowledgeBaseSource`. The default when nothing is bound is
`NullKnowledgeSource` — no material, so every node needing some takes its "could
not" exit. A package that answered from nothing would be a package that invents
answers.

---

## AI scenario routing

Off by default:

```php
'routing' => [
    'enabled' => false,
    'instruction' => '...',
    'candidates' => 12,
],
```

When a message reaches the bot and no scenario's keywords claim it, a model can
be asked which scenario fits. Four rules govern it, and each is there because the
obvious version is worse.

**It runs last, never first.** Routing by words is free, instant, works with the
provider unreachable, and is visible on the canvas — an author can read the words
off the start node and know why a message landed where it did. A model asked
first would take that away from every conversation, including the ones a single
keyword already answered correctly, and would put a network round trip in front
of the busiest path in the package.

**Every failure is silence, not an error.** No driver, no key, a refusal, a
timeout, an unreadable reply: all return null and the picker carries on to its
fallback exactly as it would have without the router. Switching this on can add a
match; it can never take one away.

**The choice is from the offered list only.** A model naming a scenario that was
not offered has invented it — or has named one deliberately withheld because it
does not cover this channel or this audience — and both count as "did not
choose". Looking the name up globally would let a hallucination route a customer
into a scenario nobody made reachable.

**A scenario with no invocation contract is never offered.**

### The invocation contract

Stored on the scenario as `invocation`, with four parts:

| Key | Meaning |
| --- | --- |
| `purpose` | what this scenario is for — **required**; without it the scenario is not offered |
| `useWhen` | the conditions under which it applies |
| `doNotUseWhen` | a string or a list of exclusions |
| `examples` | a string or a list of example messages |

The contract is the author saying, out loud, that this scenario may be chosen by
a model and on what grounds. Offering every active scenario instead would make
the model's list grow silently with the installation, and a scenario written for
a keyword nobody types would start catching live conversations.

The offered list is capped (12 by default) because it is sent on every unmatched
message and paid for per token: an installation with two hundred scenarios would
otherwise send two hundred contracts to decide one message, and the request would
be slower and more expensive than the conversation it is routing. The scenario's
slug is the fragment key, so the model names back something this side can verify
against what it sent — a title could be shared by two scenarios.

---

## The knowledge base

The `answer` node replies from recorded topics. No model is involved; this is the
company's own text.

### Matching: rare roots decide

Words are cut to roots and scored, and **a root is worth what it is rare.**

Counting matched words equally is the obvious approach and it fails on the only
questions people actually ask. "Where is my refund for this order" contains
"order", which half a shop's knowledge base also contains, and "refund", which
exactly one topic contains. Counted flat, the two topics tie and the answer is a
coin toss between the refund policy and the order tracker. Weighting each root by
one over the number of topics carrying it makes the rare word decide — which is
what it was doing in the sentence.

Frequencies are counted over the topics *this bot* may use, not over a global
table: a word is common or rare relative to the population a question has to pick
from. The sum is divided by the number of roots in the question, not in the
topic — dividing by the topic's size would penalise a thorough topic with many
keywords, while dividing by the question's size is what makes questions of
different lengths comparable against one threshold.

The stemmer is a blunt five-character truncation, and a real one would be worse
here. A proper stemmer needs a dictionary and a rule set per language, and this
package is installed by people whose visitors write in languages nobody on this
side has thought about; one shipping rules for English only is not neutral, it
stems one language well and leaves every other raw, so the same knowledge base
behaves differently depending on which language a topic happens to be written in,
with nothing on any screen saying so. Truncation is equally crude everywhere,
never invents a root a person could not predict, and when it groups two unrelated
words the fix is writing a keyword rather than learning a rule set. Stop words
are the genuinely language-specific part, so they live in
`chatbot.knowledge.stop_words` and English ships as the default.

### Two thresholds, and the second one matters more

```php
'knowledge' => [
    'enabled' => true,
    'candidates' => 5,
    'min_score' => 0.2,
    'min_gap' => 0.25,
    'stop_words' => [ /* ... */ ],
],
```

`min_score` is the floor: below it, nothing matched well enough.

`min_gap` is the interesting one. If the runner-up is within 25% of the winner's
score, the finder answers "I do not know" rather than picking. Two topics fitting
a question equally well do not mean the base has two right answers — they mean
the question was not the question we think it was, because the words that would
have separated the topics are missing from it. Picking either produces a
confident reply about something the person did not ask, and that is the worst
failure a knowledge base has, because it reads as an answer: the visitor believes
it, quotes it back, and nobody looks at the base again. "I do not know" routes to
a person and costs one message.

The gap is relative to the winner's own score, so it means the same thing for a
strong match and a weak one.

The verdict carries the runners-up even though nothing acts on them. The
complaint a knowledge base actually receives is "it answered the wrong thing" or
"it said it did not know", and neither can be investigated from the winner alone:
the useful fact is which topic came second and by how little. By the time anybody
asks, the visitor's exact wording is gone.

### Topic modes, and what `answer` does with each

The mode is a property of the topic, not of the answer text, because the same
question asked twice deserves the same treatment: a topic that must never be
answered from a stored paragraph has to stay unanswerable even after somebody
fills the answer field in.

**`Answer`** — send the recorded text as written. The node takes its first exit.

**`Verify`** — sent exactly like `Answer`, and flagged in the run journal
instead. For topics right most of the time and expensive when they are not:
anything seasonal, anything an operator changes without touching the knowledge
base. Holding these back would empty the base of everything seasonal, and
prefixing the reply with a warning teaches visitors to distrust the bot on the
topics it is right about most of the time. The flag is for the person reading the
journal, who is the one who can check.

**`Handoff`** — never reply; take the second exit, traced as withheld. **The text
is never sent, and there is deliberately no setting that would let it be.** These
topics are refunds, prices, deadlines and house rules — the answers a customer
repeats back as a promise the company then has to keep. A sentence that was true
last quarter is still stored, still matches the question perfectly, and is now a
commitment nobody made. Withholding costs one handover; sending costs an argument
the company loses. Because these entries exist to *stop* an answer, they stay
useful even with an empty answer field.

**`Scenario`** — do not answer; jump to the named scenario. "Where is my order" is
a lookup wearing a question's clothes, and the useful reply is a form, not a
paragraph. A topic in this mode with no slug takes the second exit and is traced
as a fault rather than as "not understood" — nothing about the visitor's wording
caused it, and it is fixed in the knowledge base and nowhere else.

**`Urgent`** — hand to a person immediately. Separate from `Handoff` because the
trigger differs: `Handoff` is a topic the bot may not speak about, `Urgent` is a
topic that may not wait in a queue. It routes to a person directly rather than
through the second exit, because the second exit is an ordinary "I do not know"
that an author is free to wire into another question or a polite goodbye — and
urgent topics exist to cut that short.

The node's second exit therefore means "no answer was given" for every reason at
once: no topic matched, two matched equally, the topic was withheld, or the topic
carried no text. They are told apart in the trace rather than by extra arrows,
because an author wires all of them to the same place: a person.

A `fallback` setting covers the one case worth separating — a topic matched but
its answer field is empty. Left blank, the node takes its second exit instead.

Like the `ai` node, `answer` declares no body text of its own: every word it
sends comes from the topic. Claiming otherwise would make the editor draw a
message box whose contents get sent ahead of the real answer, so the visitor
reads two replies to one question — the first usually blank, with nothing on the
canvas explaining where the empty line came from.
