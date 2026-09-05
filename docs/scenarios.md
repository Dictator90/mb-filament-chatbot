---
title: Scenarios
layout: default
nav_order: 3
---

# Scenarios

Three things, nested:

- A **bot** is what a channel talks to. A conversation belongs to one.
- A **scenario** is one graph of nodes, with a slug, a priority and a start node.
  A bot has many, and one conversation walks through several of them.
- A **node** is one step of the walk: a message, a question, a branch, a lookup.

Nothing about a scenario is compiled or generated. The canvas writes rows into
`chatbot_steps`, and the engine reads those rows at run time.

---

## Choosing the scenario that answers

`ScenarioPicker` runs once per turn, when the conversation is not already
standing inside a scenario. Candidates are the active scenarios of this bot,
with their steps eager-loaded — the picker asks every candidate about its start
node, and fetching steps per scenario would be one query per scenario on the
busiest path in the package.

The order is fixed:

1. **Keyword.** A start node with `trigger: keyword` whose `keywords` list
   matches the message.
2. **Greeting.** A start node with `trigger: greeting`, matched when this is the
   first incoming message of the conversation or when the message reads as a
   greeting.
3. **A model, if one is bound.** Only when the host has bound a
   `MB\FilamentChatbot\Bot\Routing\ScenarioRouter`. The package binds none, and
   the shipped `AiScenarioRouter` also returns `null` unless
   `chatbot.ai.routing.enabled` is true.
4. **Fallback.** A start node with `trigger: fallback`, which matches anything.

Preference is by trigger kind first and priority second. Ordering by priority
alone would let one high-priority fallback swallow every keyword in the
installation, and the author would have no way to see why from the canvas.

**Why the model sits exactly there.** Before the keywords it would spend a
request on every message the words already answer, and it would destroy the one
property an author can rely on — that what is written on the start node is what
routes. After the fallback it would never be asked at all, because a catch-all
matches everything by definition. It is also handed a list already narrowed to
scenarios this conversation may legitimately reach: a model has no way to know
that a scenario is for signed-in visitors on the site only, and a router allowed
to return one anyway would walk a Telegram visitor into it. A model naming anything
that was not offered has either invented it or named one deliberately withheld,
and both count as "did not choose".

**Only scenarios whose author wrote an invocation contract are offered.** The
contract — what the scenario is for, when it applies, when it does not, and a
few example messages — is the author saying this scenario may be chosen by a
model and on what grounds. Offering every active scenario instead would make the
model's list grow silently with the installation, and a scenario written for a
keyword nobody types would start catching live conversations.

Every failure of the router is silence rather than an error: no driver, no key,
a refusal, a timeout, an unreadable reply all return `null`, and the picker
carries on to its fallback exactly as it would have without the router. So
switching routing on can add a match and never take one away.

**Everything the picker reads is on the start node, not on the scenario row.**
`trigger`, `keywords`, `channels` and `audience` are settings of the `start`
node. Two places describing how a conversation begins would disagree on the
first edit, and the one an author can see is the canvas.

Two defaults worth knowing:

- An empty `channels` list means **every** channel. Empty meaning none would
  make a freshly drawn scenario silently unreachable, and the author would go
  looking in the keywords.
- Keywords match on **word boundaries**, never as substrings. A substring match
  makes the keyword `no` fire on "notebook" and `cancel` fire on "cancellation
  policy", and an author reading a list of perfectly sensible words has no way
  to see it coming. The same rule applies to `chatbot.bot.handoff_words`.

---

## One turn of the walk

`BotEngine::handle()` takes an incoming message and returns whether the bot took
the turn.

```
silent?  ──────────────────────────────► false, the bot says nothing
   │ no
asked for a human?  ───────────────────► hand off, true
   │ no
standing on a node?  ──► resume it with the answer
   │ no
pick a scenario ──► enter it ──► walk
```

Inside the walk, per node:

1. Resolve the node kind from the registry. **A kind nothing claims** — a
   package removed while its nodes stayed in a published scenario — hands the
   conversation to a person. There is no safe guess about what the node was
   meant to do, and a human is the one outcome that is never wrong.
2. If this is not a resume and `speaks()` is true, fill the node's body text
   with the conversation's variables and send it. This is why a node that asks
   something does not have to send its own question.
3. Call `run()`, or `resume($answer)` when the visitor is answering this node.
4. Record the outcome the node reported, if it reported one.
5. Act on the returned `StepFlow`.

**Nodes never move the conversation themselves.** They report what happened and
hand it back; the engine resolves the target, saves the conversation and writes
the journal. That split is what makes a node you wrote as safe as one that
shipped: a node cannot leave a visitor parked on a node that asked them nothing,
or pointing at a scenario that was deleted.

`StepFlow` is a closed set, and it stays closed — every case already exists in
the walker, so adding a node kind never means touching the engine.

| Flow | Constructor | What the engine does |
| --- | --- | --- |
| `Next` | `StepResult::next()` | Follow the node's own next output |
| `Second` | `StepResult::second()` | Follow the second output of a two-output node |
| `Goto` | `StepResult::goto($key)` | Continue at a named node; `null` ends the branch |
| `Wait` | `StepResult::wait()` | Park on this node until the visitor replies |
| `Delay` | `StepResult::delay($seconds)` | Park, and queue the continuation |
| `Jump` | `StepResult::jump($slug)` | Continue in another scenario, never return |
| `Call` | `StepResult::call($slug)` | Run another scenario and come back |
| `Returns` | `StepResult::returns($value)` | End the called scenario with a value |
| `Handoff` | `StepResult::handoff()` | Fetch a human; the bot falls silent |
| `Close` | `StepResult::close()` | Close the conversation |

The scenario is a local variable inside the walk that may change: a jump
replaces it, a call pushes the current one onto `conversation.bot_stack` and a
return pops it back. Recursing per scenario instead would make the step budget
per scenario rather than per turn, and a chain of five scenarios could quietly
walk two hundred nodes for one message.

---

## A node that waits is entered twice

The two entries are nothing alike. `run()` asks; `resume()` reads the reply.

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

Folding the two into one `run()` with a flag was the alternative, and it goes
wrong in one specific way: a node that asked, and was then re-entered by a
resumed delay or a re-sent webhook, cannot tell "they answered" from "we are
here again", so it asks the same question a second time.

`waitsForReply()` describes the kind of node for the editor. It is not what the
engine acts on — the engine acts on `StepResult::wait()`. A node that asks
conditionally, like `form` asking only for what it does not already know, must
answer `false` and return `wait()` on the turns where it does ask; the kind of
node alone cannot tell the editor what happened this time.

---

## When the bot says nothing

`handle()` returns `false` without walking anything in three cases:

- `chatbot.bot.enabled` is `false`.
- The conversation's `bot_state` is `HandedOff`.
- The conversation has an `assigned_to_id`.

The third is the one worth stating out loud. **Assignment counts even without an
explicit handover.** An operator opening a conversation and taking it is the
same statement as pressing the handover button, and a bot answering over them
would be answering a person who is already typing. A bot that keeps talking over
an operator is worse than no bot.

Separately, before any scenario runs, the message is checked against
`chatbot.bot.handoff_words` (`operator`, `human`, `manager`, `agent` by
default). A visitor asking for a human has already decided the bot is not
helping, and walking them through three more nodes first only confirms it.

---

## The limits on a turn

Two guards, both from `config('chatbot.bot')`, and neither is a tuning knob:

| Setting | Default | What it stops |
| --- | --- | --- |
| `max_steps_per_turn` | 40 | A scenario walking in a circle |
| `max_jumps` | 5 | A chain of jumps and calls with no bottom |

Exceeding either closes the run — `RunOutcome::StepLimit` for the first,
`RunOutcome::Failed` for the second — and hands the conversation to a person.
Without them the walk holds the request open until PHP gives up, and the visitor
sees nothing at all, not even an error.

---

## A branch that just stops

An output nobody connected is not an error, it is the most common unfinished
thing on a canvas. When the walk runs out of nodes the run is closed as
`RunOutcome::DeadEnd`, recorded as its own outcome rather than as a success:
a scenario that quietly stops halfway looks fine in the editor and looks broken
to the visitor.

What happens next depends on whether the bot said anything this turn. If it did,
the visitor has an answer and the silence reads as the end of the exchange. If
it did not, they wrote to a bot and got nothing at all, and the only decent
response is a person.

`DeadEnd` is deliberately not merged into `HandedOff`, even though the engine
does hand over. Recording it as a handover would be truthful about the
conversation and a lie about the design: it would hide the single most common
authoring mistake behind an outcome that looks intentional, and nobody would
ever learn about the dangling branch.

---

## Calling a scenario and coming back

`jump` and `call` both continue in another scenario. The difference is the
return, and it is the whole reason the pair exists.

**`jump`** replaces the current scenario. `conversation.bot_scenario_id` moves
to the target, the walk restarts at the target's start node, and nothing is
remembered about where it came from. The run keeps writing the same trace —
every trace row carries its own scenario slug for that reason, because splitting
the journal at each jump would cut the one thing it exists for, the path a
person actually walked, into fragments nobody can reassemble.

**`call`** pushes a frame — the calling scenario's slug, the calling node's key,
and the variable named in the node's `into` setting — onto
`conversation.bot_stack`, then enters the target. When the called scenario
reaches a `result` node, the frame is popped, the returned value is stored in
`into` if one was named, and the walk continues at the node **after** the call.

Shared work — identifying the visitor, collecting a phone number — is written
once as its own scenario and called from everywhere, instead of being copied
into each one and drifting apart.

Two behaviours worth knowing:

- **A `result` with nothing to return to closes the conversation** rather than
  failing. A scenario may be run on its own as well as called, and the same
  terminal node has to work either way.
- A call to a scenario that does not exist, or one that exhausts `max_jumps`,
  takes the call node's **second** output instead of failing the run. A jump in
  the same situation does fail — there is no second output to take.

---

## Versions and publishing

A scenario is edited continuously and answers customers continuously, and
without a release those are the same act: a half-drawn branch saved at five
o'clock is what the bot says at five o'clock and one. A version is the line
between the two.

`ScenarioVersions::publish()` freezes the graph into `chatbot_scenario_versions`
and points `scenario.live_version_id` at the snapshot.

### The fingerprint

`fingerprint()` hashes what the scenario **says and does**: its slug, name,
active flag, priority, invocation contract, and for every node the key, type,
body, options, config and next key. Steps are sorted by key, and every array is
key-sorted before encoding.

Coordinates, sort order, ids and timestamps are all left out, and that is the
entire point of computing the hash by hand instead of hashing the graph payload.
Dragging a node changes its position and nothing else; include the position and
the panel announces unpublished changes after somebody tidied the canvas, until
"there are changes to publish" means nothing and gets published blind.

The canonicalisation matters for the same reason: a JSON object remembers the
order its keys were written in, so a settings bag saved with the limit before
the resource one day and after it the next is the same configuration and a
different hash — a change nobody made.

A scenario that has never been published always reports unpublished changes,
however empty it is. It is running as a draft, which means every edit reaches
customers the moment it is saved, and that is the state the panel most needs to
say out loud.

### Pins

`pins()` collects what the scenario leans on from **outside** itself, by asking
each node kind's `pins()` method: the data resources it reads, the capabilities
it invokes, the scenarios it calls. They are frozen into the release.

Without them, a scenario that starts behaving differently cannot be told from
one that was changed. The data resource, the host action and the called scenario
all live outside this scenario and are edited by other people on other days;
with no pins the version history says "nothing changed here" while the bot's
answers move underneath it.

### The two rules that refuse a release

`publish()` collects `ScenarioGraph::issues()` and throws
`ScenarioNotPublishable` if any of them are `blocking`. Blocking defects refuse
the release rather than being reported and released anyway, because this is the
last moment any of them is cheap: a transition into a deleted node costs nothing
to fix while the author is looking at the canvas, and the same defect found from
a live conversation costs a customer who was told nothing and arrives days later
as "the bot ignored me", without the node's name.

The two that are about behaviour rather than about a broken drawing:

**A write reachable without a confirmation above it.** The checker walks forward
from the start node carrying a flag, and a node that changes data while the flag
is unset is named in the refusal: *"This node changes data and can be reached
without a confirmation node above it. Add one on every path that leads here."*
Only the **approving** exit of a confirmation carries the flag forward — the
refusing exit means the person said no, and a write placed there is exactly the
mistake the rule is for. A node counts as writing either because its kind
declares `SideEffect::Write` through `HasSideEffect`, or because it pins a
capability whose `effect` is `Write` **or** whose `requiresApproval` is true. A
confirmation is recognised by class as well as by key, so a host that subclasses
`ApprovalStep` does not find every write below it refusing to publish.

**A loop with no finite bound.** `for_each` refuses to publish without a
`max_items` between 1 and its ceiling: *"This loop has no limit. Without one the
conversation walks the whole list and may never reach its next answer."* A limit
above the ceiling is refused rather than clamped down to it — a clamp would let a
scenario be published with 5000 written on the node and walk a hundred entries,
while the author went on reading the number they typed.

The other blocking issues are structural: an exit pointing at a node that does
not exist, a node of an unknown kind, no start node at all, or more than one
start node (which of them runs is not something an author can decide from the
canvas).

**Unreachable nodes are a notice, not a blocker.** A branch is unreachable for
the whole time it is being built, and a checker that refused to publish because
of it would be wrong on every ordinary afternoon.

The editor checks an unsaved draft with `issuesForDraft()`, which builds step
rows it never writes and runs them through exactly the same `issues()` code. A
lighter check written for the editor would answer a slightly different question
from the one that refuses a release, and the two disagreeing is how an author
ends up with a canvas showing no problems and a publish button that says no.

### Rolling back

`rollBack()` writes the old snapshot over the scenario and publishes it again as
a **new** version. It does not move the live pointer backwards and delete what
came after.

History is what an incident is reconstructed from. The question afterwards is
always "what were people being told between two and four", and a rollback that
erased the version in between answers it with nothing. Numbers only ever climb,
so "version seven" means the same thing tomorrow as it does today — and the
number comes from the highest this scenario has ever had, not from how many rows
exist, so a deleted version cannot hand its number to the next release.

The release check runs on the way through. Rolling back is still publishing, and
a snapshot drawn when a host package was installed can no longer be run without
it; putting it live unchecked would replace one broken bot with another.

---

## The run journal

Two tables. A `chatbot_runs` row is one pass of the bot through a conversation,
from entering a scenario to handing over, closing or breaking off. A run
survives a change of scenario — a jump keeps writing the same trace.

`chatbot_run_steps` rows are appended and never updated, which is why they carry
`occurred_at` and no `created_at`: a second answer to the same question can drift
from the first when a delayed step is written from a queue.

Rows are buffered in memory and written in one insert at the end of the turn.
A turn can walk dozens of nodes, and an insert per node would put the journal's
cost on the visitor's reply. The journal is for us, and it must never be the
reason a person waits.

### Run outcomes

| Outcome | |
| --- | --- |
| `running` | Still going. The one value with an empty `ended_at` |
| `handed_off` | A human was called in |
| `closed` | Reached a node that closes the conversation |
| `dead_end` | The branch ran out; nobody was called and nothing asked |
| `step_limit` | Hit the per-turn step limit, almost always a loop |
| `lost` | The node the visitor stood on disappeared mid-conversation |
| `failed` | The engine threw. The run is still closed |

`isOpen()` is decided by `ended_at` rather than by the outcome. "Running" and an
empty `ended_at` are the same state written down twice, and the one to trust is
the one an index can check — a run left with a stale outcome after a crash would
otherwise read as open forever.

A failed run is closed rather than left open, because an open run reads as "in
progress" forever and a crashing scenario would look like a working one in every
report.

### Step outcomes

Constants on `RunStep`, not an enum. There are two dozen of them, nearly every
one meaning "this branch of the engine fired", and an unfamiliar value has to
stay readable: a host registers its own nodes with their own outcomes, and a
strict cast would let one old row stop the whole journal from opening. `step_type`
is uncast for the same reason.

| Constant | Value | Means |
| --- | --- | --- |
| `RunStep::PASSED` | `passed` | Did its piece and left through its only exit |
| `RunStep::SAID` | `said` | Sent a reply |
| `RunStep::WAITING` | `waiting` | Asked, and stopped the walk |
| `RunStep::ANSWERED` | `answered` | The reply matched, and the walk went on |
| `RunStep::UNKNOWN` | `unknown` | Nothing matched, and the bot asked again |
| `RunStep::BRANCHED` | `branched` | A split by words or condition chose a branch |
| `RunStep::JUMPED` | `jumped` | Moved to another scenario, same run |
| `RunStep::CALLED` | `called` | Entered a sub-scenario, expecting to come back |
| `RunStep::RETURNED` | `returned` | Came back from a sub-scenario |
| `RunStep::DELAYED` | `delayed` | Continuation scheduled as a delayed job |
| `RunStep::QUERIED` | `queried` | A data resource was read |
| `RunStep::EMPTY_RESULT` | `empty_result` | The query matched nothing |
| `RunStep::APPROVED` | `approved` | A confirmation came back yes |
| `RunStep::DECLINED` | `declined` | A confirmation came back no |
| `RunStep::HANDED_OFF` | `handed_off` | A human was called in |
| `RunStep::CLOSED` | `closed` | The conversation was closed |
| `RunStep::FAILED` | `failed` | The node itself failed |

`UNKNOWN` is the most valuable row in the table and the one thing a transcript
cannot be read to reveal: "did not understand" and "the visitor never replied"
look identical in the messages. `FAILED` is deliberately distinct from
`EMPTY_RESULT` and `DECLINED` — those two are answers, while `FAILED` means there
was no answer at all, and it is fixed by settings or by the other system rather
than by the scenario.

There is no "answer not understood" **run** outcome. Failing to match makes the
bot ask again and the conversation continues, so that is the outcome of a node,
not of a run; merging the two would close runs at the exact moment they are
getting started.

---

## Next

- [Node reference]({{ site.baseurl }}/nodes) — every kind of node, its settings and its outputs.
- [Data resources]({{ site.baseurl }}/data-resources) — what `query` and `choose` read from.
- [Capabilities]({{ site.baseurl }}/capabilities) — what `action` invokes.
