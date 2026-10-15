---
title: Node reference
layout: default
nav_order: 4
---

# Node reference

Twenty-seven node kinds ship with the package. Each one is a class under
`MB\FilamentChatbot\Bot\Steps\Types`, registered by the service provider under a
key that is stable forever: that string is what a saved graph refers to, and
renaming it orphans every node of that kind in every published version.

## Reading the tables

**Outputs** is the node's `StepOutputs` shape, which is what the canvas draws
handles from:

| Shape | |
| --- | --- |
| `None` | A terminal node. Anything drawn after it would be unreachable |
| `Single` | One exit, stored in `next_key` |
| `Options` | One exit per authored option |
| `OptionsElse` | One exit per authored option, plus a fallback |
| `Pair` | Exactly two exits, both named by the engine |

A `Pair` is not a special case of `OptionsElse`, however similar the pictures
look. An option is something a person creates and labels; a pair's two exits
mean "it worked" and "it did not" — outcomes the engine decides and the author
only routes. Let a pair be edited as options and the third click produces a node
with four branches, three of which the engine can never reach: the node runs,
one arrow is never taken, and the author spends the afternoon asking why.

**Speaks** means the engine sends the node's own body text before running it.
It is false for every node whose message comes from somewhere else — a knowledge
article, a model's reply, a generated list. Saying true for those would make the
editor offer a text box that goes nowhere while the bot sends an empty line
ahead of the real answer.

**Trace** lists the `RunStep` outcomes the node writes into the run journal.

---

# Conversation

## `start`

Where a scenario begins, and on what.

| | |
| --- | --- |
| Class | `StartStep` |
| Outputs | `Single` |
| Speaks | no |
| Waits | no |
| Trace | `passed` |

**Why it exists.** What launches a scenario is a node rather than a page of
scenario settings. As a setting it lived off the canvas: the drawing began with
an arrow coming from nowhere, and nobody reading it could say which words made
the bot speak at all, or whether it answered strangers as well as customers —
they had to open a form somewhere else and hold both in their head. Worse, a
scenario has one beginning and two places were describing it, the settings page
and the node the editor drew to anchor the arrow; the first edit that touched
only one of them left a graph that said one thing and a bot that did another.

The node does nothing when the walk reaches it. By then the decision it
describes has already been made — the engine consulted these settings to pick
this scenario — so it passes straight through.

| Setting | |
| --- | --- |
| `trigger` | `greeting`, `keyword`, `fallback` or `call`. Default `keyword` |
| `keywords` | List of words, matched on whole words. Only with `trigger: keyword` |
| `channels` | Channels this scenario answers on. **Empty means every channel** |
| `audience` | `any`, `known`, `anonymous`. Default `any` |
| `familiarity` | `any`, `new`, `returning`. Default `any` |

The channel options are filled in by the editor from the registered channel
drivers rather than listed in the class: which channels exist is a property of
the installation and changes while the panel is running, and a Telegram bot
connected on Tuesday has to appear in the list without a package release.

The start node never sends its own text. A greeting is a message like any other
and belongs in a `say` node after it, where an author can see it, reword it and
route around it for a returning visitor.

## `say`

Sends one message and carries on.

| | |
| --- | --- |
| Class | `SayStep` |
| Outputs | `Single` |
| Speaks | yes |
| Settings | none |
| Trace | `said` |

**Why it has no settings.** Its text is the step's own `body` column, the same
one every speaking node uses. Given a field of its own, the text would be stored
in two places for the same node — `body` for the nodes the engine sends
automatically, a setting for this one — and the editor's preview, the version
diff and the message the visitor receives would each be free to read a different
one.

It does not send anything itself either. The engine sends the body of any node
whose `speaks()` is true before running it, so a `say` node that also said its
body would send it twice.

## `ask`

Asks a question with ready answers, and routes on the one that comes back.

| | |
| --- | --- |
| Class | `AskStep` |
| Outputs | `OptionsElse` |
| Speaks | yes |
| Waits | yes |
| Trace | `waiting`, `branched`, `answered`, `unknown` |

Each option names its own next node, so the branch is drawn on the canvas rather
than hidden in a condition somewhere below the question.

| Setting | |
| --- | --- |
| `retry` | Sent once with the options listed again when nothing matched |

**The fallback exit is what keeps this node from becoming a trap.** Without one,
a visitor who typed a sentence instead of tapping a button got the same question
with the same buttons back, every time, for as long as they kept writing — the
bot had nowhere else to go, and the only way out of the loop was to guess the
word "operator". So: nothing matched and a fallback exists, the conversation
leaves through it rather than asking again.

Answers are matched in three passes, in this order: exact labels, then ordinals,
then prefixes. A prefix tried per option instead would let an early "No" swallow
an answer meant for a later "November" — and the option that won would depend on
the order the author happened to drag the buttons into. Ordinals are matched
because not every channel draws buttons: e-mail, SMS and the plainest webhooks
receive the options as a numbered list, so "2" is a real answer there.

## `collect`

Asks for exactly one thing and remembers the answer.

| | |
| --- | --- |
| Class | `CollectStep` |
| Outputs | `Single` |
| Speaks | yes |
| Waits | yes |
| Trace | `waiting`, `answered`, `failed` |

| Setting | |
| --- | --- |
| `slot` | Which registered slot to ask for. Required |
| `into` | Variable to store it under. Empty means the slot's own key |
| `attempts` | Attempts before giving up. Default 2, between 1 and 5 |

**It always asks, even when the answer is already known** — that is the whole
difference between this node and `form`. Use it where the question is the point
("what is the new address?") and the form where the answers are.

**It has one output and no way to fail.** After the allowed attempts it stores
what the visitor literally wrote and carries on: a person who has typed their
phone number twice and been told twice that it is not a phone number will not
type it a third time, and an operator reading "call me on the shop line"
understands it perfectly. A scenario that genuinely cannot continue without a
parsed value wants `form`, which has a branch for giving up.

If the node's body text is empty, the slot's own default question is sent
instead — that is what the slot's question was written for.

Attempts are counted against the **node**, not the slot. The same slot can be
asked for twice in one scenario — a phone number at the start and again after a
correction — and counting by slot would carry the earlier failures into the
later question, giving the visitor one attempt where the author wrote two.

## `form`

Collects several things at once, asking only for what is missing.

| | |
| --- | --- |
| Class | `FormStep` |
| Outputs | `Pair` — `collected` / `could not` |
| Speaks | yes |
| Waits | conditionally |
| Trace | `waiting`, `answered`, `failed` |

| Setting | |
| --- | --- |
| `slots` | The slots to collect, in order. Required |
| `attempts` | Attempts per question. Default 2, between 1 and 5 |

**`slots` takes two shapes.** The settings panel writes plain keys —
`["name", "phone"]` — and that is all a graph drawn on the canvas ever holds. A
graph that arrives any other way, from a host's seeder or an import, may say
`[{"slot": "phone", "required": true}]` instead, which is the richer thing an
author means and has no way to draw. Both are read.

`required: false` changes what happens when the attempts run out: the form
stops asking for that one and carries on instead of taking the "could not"
exit. An author marks a slot optional because the conversation is worth having
without it — a budget the visitor has not decided is the case — and fetching a
person over the one answer that did not matter is not what that branch is for.
A slot given up on is not asked again later in the same form.

An optional slot can also be declined outright. One that is not a choice is
asked with a single "Skip" button, and a whole answer from
`chatbot::bot.form.skip_words` ("don't know", «не скажу») skips it at once
instead of spending an attempt — re-asking someone who has just said they would
rather not name a budget is an argument, not a question. Only complete answers
match: "don't know where, somewhere warm" is an answer.

In a messenger a pressed button arrives as its position rather than its text —
callback data is capped at 64 bytes, which a label routinely exceeds — so the
form remembers the buttons it drew and translates the position back before
reading the answer. That is what makes "Skip" work outside the widget, and what
keeps `<slot>_said` holding «Всё включено» rather than "3". A number outside
the list stays a number.

A skip outlives the form: it is stored as `<slot>_skipped`, and no later form
in the conversation asks that slot again. A scenario that loops the visitor
back through its forms to correct one answer would otherwise re-ask everything
they had declined, once per correction. Clear `<slot>_skipped` to ask again on
purpose.

**The node that saves scenarios from becoming forms.** Five things to find out
would otherwise be five `collect` nodes in a row, and half of them would ask for
something already known — a phone number that arrived with the widget's contact
form, a name that came from the visitor's account. Being asked again for
something you have already given is the single most recognisable thing a bot
does wrong.

A value counts as known only when it is not blank. An empty string in the
answers is what an earlier node writes when it looked and found nothing, and
treating that as an answer would skip the one question the form was added to
ask.

`waitsForReply()` is `false` here even though the node often waits. A form may
ask nothing at all and pass straight through, so the engine cannot know from the
kind of node whether it stopped — only the node knows, and only after it has
looked.

---

# Flow

## `keyword`

Splits the walk on words the visitor has already written.

| | |
| --- | --- |
| Class | `KeywordStep` |
| Outputs | `OptionsElse` |
| Speaks | no |
| Settings | none — the words live on the options |
| Trace | `branched` |

Asks nothing and says nothing: it reads the message that brought the
conversation here. That is what separates it from a question — a question
interrupts to collect an answer, this sorts an answer that already exists, which
is how a scenario reacts to "I want to cancel" without first asking what the
visitor wants.

Matching is on word boundaries. A plain substring search is what this used to
be, and it sends people down the wrong branch quietly: "no" is inside
"notebook", "tour" is inside "detour", and the visitor asking about their
notebook order was routed to the refusal branch with nothing in the transcript
to explain why. The lookarounds are written out as `(?<![\p{L}\p{N}_])` rather
than relying on `\b`, because the boundary has to hold for Cyrillic too, and the
class of letters that counts as a word character is exactly what `\b` leaves to
the engine's configuration.

## `condition`

Branches on what is already known, without asking anything.

| | |
| --- | --- |
| Class | `ConditionStep` |
| Outputs | `OptionsElse` |
| Speaks | no |
| Settings | none — each option carries a `when` clause |
| Trace | `branched` |

Branching lives in the node's options here, exactly as it does for a question
and for a keyword match. The three look different on screen — a button, a list
of words, a comparison — and are the same thing underneath: a label plus the key
of the next node. One way of branching instead of three is why the canvas draws
outputs for all of them with one piece of code, and why an author who has
understood one has understood the others.

**The first matching branch wins.** Not the best match, not the most specific:
the first, in the order the author put them in. Any other rule would make the
order on screen not mean what it looks like it means.

Condition fields are a registry of their own — channel, identified, returning,
working hours, time, weekday, month, day, date, turns, only-greeting, page,
handed-off, and any variable. A host adds its own; see
[Extending]({{ site.baseurl }}/extending).

Two `notice`-level issues are reported while editing: a branch with no condition
(it can never be taken) and a branch the evaluator cannot use.

## `set`

Remembers a value without asking anyone for it.

| | |
| --- | --- |
| Class | `SetStep` |
| Outputs | `Single` |
| Speaks | no |
| Trace | `passed`, `failed` |

| Setting | |
| --- | --- |
| `name` | The variable name. Required |
| `value` | May contain `{{other_name}}`, substituted when the node runs |

**What it is for is marking a branch.** The visitor tapped "Find me a trip", and
three nodes later the scenario has to know that is what they came for — but the
button they tapped is by then only a line in the trace, and a condition cannot
read the trace. This node writes `interest = tour` at the moment the branch is
taken, and everything downstream reads a fact instead of trying to reconstruct
which way the conversation came.

A node with no `name` is a blocking issue: it would do nothing at all.

## `delay`

Waits a moment before the next node runs.

| | |
| --- | --- |
| Class | `DelayStep` |
| Outputs | `Single` |
| Speaks | no |
| Trace | `delayed` |

| Setting | |
| --- | --- |
| `seconds` | Default 2, clamped to between 1 and 3600 |

Three messages landing in the same second is the first thing that gives a bot
away, and it is not a matter of taste: the visitor is still reading the greeting
when the question and its buttons push it off the screen, so they answer a
question they never read.

**The pause is a scheduled continuation, never a sleeping request.** Holding the
request open for the same three seconds would hold a worker open too, and a
hundred conversations pausing politely at once is a queue of workers doing
nothing — the bot stops answering anybody. On the `sync` queue driver there is
no worker to schedule onto and the pause becomes exactly the sleeping request
this avoids; `chatbot:doctor` warns when an installation has both.

The bounds are enforced in `run()` as well as on the field. The field guards the
editor, and the editor is not the only way a step row is written: seeders,
imported scenarios and a host's own migration reach the same column, and a zero
from any of them throws inside `StepResult::delay()` and takes the whole turn
down.

## `jump`

Hands the conversation to another scenario and does not come back.

| | |
| --- | --- |
| Class | `JumpStep` |
| Outputs | `None` |
| Speaks | no |
| Trace | `jumped`, `failed` |
| Pins | `scenarios` |

| Setting | |
| --- | --- |
| `scenario` | The slug to continue in. Required |

The difference from `call` is the whole reason both exist. A call runs another
scenario and returns with its result; a jump is a change of subject — the
visitor asked about a refund in the middle of a booking, and the booking
scenario has nothing left to do afterwards. Drawn as one node with a "returns"
checkbox, the two read the same on the canvas and behave completely differently,
and an author would only discover which one they built by watching a
conversation end where they expected it to continue. Two nodes, two shapes, no
checkbox.

Because it never returns, the node has no exits at all: anything drawn after it
would be unreachable, and a node with an arrow nobody follows is worse than a
node with none.

**An unset target ends at a person, not at silence.** The alternative is closing
the branch, and that is the failure nobody reports: the visitor asked something,
the bot went quiet, and the conversation looks answered from the panel. Handing
over costs an operator a minute; the quiet version costs a customer.

## `call`

Runs another scenario and comes back with its answer.

| | |
| --- | --- |
| Class | `CallStep` |
| Outputs | `Pair` — `returned` / `could not` |
| Speaks | no |
| Trace | `called`, `failed` |
| Pins | `scenarios` |

| Setting | |
| --- | --- |
| `scenario` | The slug to call. Required |
| `into` | Variable for the returned value. Empty ignores it |

Work that several scenarios need — identifying the visitor, collecting a phone
number, checking whether an order exists — was previously copied into each of
them, and copies drift: one gets a fix, the others keep asking the old question
for another year.

The second exit is for the cases where the scenario cannot even be started — no
such scenario, or calls nested deeper than `chatbot.bot.max_jumps` allows —
because a scenario that silently did nothing here would look identical to one
that returned an empty answer.

## `result`

Ends a scenario and hands a value back to whoever called it.

| | |
| --- | --- |
| Class | `ResultStep` |
| Outputs | `None` |
| Speaks | no |
| Ends | yes |
| Trace | `returned` |

| Setting | |
| --- | --- |
| `value` | Supports `{{variables}}`. Empty returns nothing |

**The value goes to the calling scenario, not to the visitor.** A called
scenario that wanted to say something already said it; this node answers the
caller's question, and the caller decides whether any of it is worth repeating
out loud.

Reaching it in a scenario nobody called simply ends the conversation. That is
not a fallback so much as a requirement: the same scenario has to be runnable on
its own and callable from elsewhere, and forcing an author to maintain two
endings for the two cases is how the two endings diverge.

## `for_each`

Walks a list one entry at a time, and always stops.

| | |
| --- | --- |
| Class | `ForEachStep` |
| Outputs | `Pair` — `each` / `done` |
| Speaks | no |
| Trace | `passed`, `failed` |

| Setting | |
| --- | --- |
| `source` | A variable holding a list, one entry per line. Required |
| `item` | Variable to remember each entry as. Required |
| `index` | Variable for the entry's number. Optional |
| `max_items` | **Required.** Default 10, between 1 and 100 |

The `each` exit is entered once per entry, the branch drawn from it comes back
to this node, and `done` is taken when the list — or the limit — runs out.

**The limit is a required setting, not a default with an escape hatch.** A loop
with no finite bound is not a slow scenario, it is a conversation that never
reaches its next sentence: the walk spins inside one turn until the engine's
step ceiling stops it, and the visitor looks at a chat that answered nothing
while the server did the work of a hundred. Worse, the author will not see it —
on the three records their test data holds, an unbounded loop and a bounded one
are the same picture, and the customer with two hundred is the one who finds
out. So a missing or unreasonable limit blocks publication, and a loop that
reaches a conversation anyway refuses out of the `done` exit with the reason in
the trace.

A limit above the ceiling of 100 is refused rather than clamped. A clamp would
let a scenario be published with 5000 written on the node and walk 100 entries,
while the author went on reading the number they typed.

The list is read as lines, because that is how `query` in list mode writes one.
Anything else an author puts in that variable is looped the same way, which
keeps the two nodes usable together without this one knowing about data
resources at all.

The position is kept on the conversation and cleared as soon as the loop leaves.
A position left behind outlives the list it counted, and the next visit would
start the loop halfway through a list that is not the same list any more.

## `approval`

Asks for a yes before something that cannot be taken back.

| | |
| --- | --- |
| Class | `ApprovalStep` |
| Outputs | `Pair` — `approved` / `declined` |
| Speaks | yes |
| Waits | yes |
| Trace | `waiting`, `approved`, `declined`, `unknown` |

| Setting | |
| --- | --- |
| `yes_label` | The confirming answer. Default `Yes` |
| `no_label` | The refusing answer. Default `No` |
| `attempts` | Default 2, between 1 and 5 |

**An answer this node does not understand is a refusal.** That single rule is
the whole reason it exists as a node of its own rather than as one more question
with two buttons. Everywhere else in a scenario an unparsed answer can be
guessed at, and guessing wrong costs a clumsy sentence. Here it stands in front
of cancelling an order, writing to the host's records, charging somebody: a bot
that reads "hmm, I'm not sure" or a mistyped word as "probably yes" performs the
irreversible thing on behalf of a person who was hesitating. The two mistakes
are not comparable — asking again, or handing over, costs a minute; a
cancellation nobody asked for costs the customer.

So the attempts run out into `declined` rather than into `approved`, and **there
is no third exit for "did not understand"**: a scenario that branched on it
would eventually route it somewhere convenient, and the convenient place is the
one this node was put there to block. While attempts remain the visitor is asked
again, because the common unparsed answer is a question back ("what happens to
my deposit?") rather than a refusal, and hanging up on that is its own kind of
rude.

Matching reads the author's labels first, then `1`/`2`, then the built-in yes
and no words, then a prefix of either label. An author who relabelled the
answers meant those labels to be what the visitor types.

This is the node the publication rule looks for. Only its **approving** exit
carries approval forward to the nodes below it, and a host may subclass it —
`ScenarioGraph` recognises a confirmation by class as well as by key, so
extending the package does not make every write below it start failing to
publish.

---

# Data

## `query`

Reads the host's own records into the conversation's variables.

| | |
| --- | --- |
| Class | `QueryStep` |
| Outputs | `Pair` — `found` / `nothing found` |
| Speaks | no |
| Trace | `queried`, `empty_result`, `failed` |
| Pins | `data_resources` |

| Setting | |
| --- | --- |
| `resource` | The declared data resource. Required |
| `mode` | `DataQueryMode`: first, list or count. Default first |
| `filters` | Field/value pairs, compared for equality. Values may contain `{{variables}}` |
| `select` | Fields to read. Empty means the resource's defaults |
| `limit` | 1 to 100 |
| `into` | Variable prefix for the result. Required |

Everything it may read was declared as a data resource first, and this node adds
nothing to that declaration: it chooses a resource, a shape and a few equality
filters, and the reader refuses whatever the declaration did not allow. See
[Data resources]({{ site.baseurl }}/data-resources).

**Who is asking comes from the conversation, never from the settings.** The
`RuntimeContext` is built from the conversation row alone, so an access scope
resolves against server-side facts. Every other value in reach at this point was
written by the visitor or shaped by their message, and a visitor who can name
their own customer id can name somebody else's — the read that results is valid,
fast, indexed and completely indistinguishable in the logs from a legitimate
one. Filters may carry visitor-supplied values because they only narrow *within*
the scope; the scope itself may not.

**Filters compare for equality and nothing else, on purpose.** The wider set —
ranges, substrings, "one of" — needs a filter builder in the editor that does
not exist yet, and each of those comparisons is refused per field by the
declaration anyway, so an author would be choosing from a list whose entries
mostly fail. The alternative considered and rejected was a third column holding
an operator: a control that is drawn, filled in, saved and then ignored teaches
the author a rule the bot does not follow, and the scenario that relies on it is
wrong in a way no screen shows.

The three modes are stored differently because they are read differently. A
**count** goes under the name as it is. A **first** is spread over one variable
per field — `booking_status`, `booking_total` — because a message inserts one
value in the middle of a sentence, and every field glued together with commas
reads as one unintelligible value where a date was meant to go. A **list** is
stored as lines plus a `_count`, because it is spoken as a list; spreading it
over prefixed names would need an index in every name, and an author cannot
write a message against names they cannot know the number of.

"Could not read" and "nothing matched" take the same exit and are told apart in
the trace, because both lead the scenario to the same place while only one of
them is a fault somebody has to fix.

## `choose`

Shows the visitor their own records and remembers the one they pick.

| | |
| --- | --- |
| Class | `ChooseStep` |
| Outputs | `Pair` — `picked` / `not in the list` |
| Speaks | yes |
| Waits | yes |
| Trace | `waiting`, `answered`, `branched`, `empty_result` |
| Pins | `data_resources` |

| Setting | |
| --- | --- |
| `resource` | The declared data resource. Required |
| `filters` | Field/value pairs, equality only |
| `limit` | How many to offer. Default 5, between 1 and 10 |
| `label` | One line per record. `{{field}}` of the **record** |
| `into` | Variable prefix for the pick. Required |
| `none_label` | The last option. Default "Not in the list" |

This is the node every installation would otherwise write for itself. "Which of
your bookings?", "which order?", "which ticket?" are the same question about a
different table, and each hand-written version repeats the part that is easy to
get wrong.

**The second exit is taken both when the list is empty and when the answer names
nothing on it, and that is the right shape rather than a lost distinction.**
Asking "which of your bookings?" of somebody with nothing to choose from is not
a question, it is a dead end with a prompt on it — equally true whether they
have no bookings or the resource could not be read. All of those cases need the
same next node: say something honest and fetch a person. Two arrows landing on
the same one would draw a choice the author does not have. What they do need is
to be told apart afterwards, so the refusal carries its reason into the trace.

**The offered list is read back from the conversation, not queried again.** That
is not an optimisation. Between the question and the answer the records can
change — one is cancelled, another created, a status moves and the sort order
with it — so a second read can return a different list in a different order. The
visitor who typed "2" chose the second line they were shown, and a node that
re-read would hand the scenario the second line of a list nobody ever saw.

The offer is stored in `conversation.meta`, keyed per node, and dropped as soon
as it is answered. Kept in the answers bag it would appear in the variable
picker, be offered for substitution into messages, and be one setting away from
reaching a query as though the visitor had supplied it. Left behind after the
answer, an entry of "1" meant for a different question weeks later would
silently pick a record from a conversation that has ended.

Label placeholders are filled from the record and from nothing else. Falling
back to the conversation's variables for an unknown name looks harmless and is
not: a mistyped field name would quietly print the visitor's own answer next to
every record in the list, and each line would read as a fact about that record.

## `mutate`

Writes one row of a declared data resource.

| | |
| --- | --- |
| Class | `MutateStep` |
| Outputs | `Pair` — `written` / `could not` |
| Speaks | no |
| Trace | `passed`, `failed` |
| Pins | `data_resources` |
| Side effect | `Write` |

| Setting | |
| --- | --- |
| `resource` | The declared data resource. Required, and it must be declared writable |
| `operation` | `insert` or `update`. Default `insert` |
| `key` | The variable holding the record to change. Update only |
| `values` | Field/value pairs. `{{variables}}` are substituted |
| `into` | Receives the key of the row that was written |

The only node that changes the host's records without a line of host code, and
the narrowest one in the package as a result. It can add a row or change a row
it can name, and nothing else: no delete, because a conversation is a bad place
to decide something is gone forever, and no bulk update, because a filter that
matches more rows than the author pictured is the usual way to lose a table —
and here the author is not necessarily a programmer.

**An update needs the resource to declare a lock column, and refuses without
one.** Two conversations about the same record are ordinary: somebody writes
from the site and from Telegram within a minute of each other. A blind update
makes the second one silently undo the first, and neither person is told.

If the row changed between the read and the write, the node takes its second
exit rather than retrying. The scenario decided what to write from what it read,
and that reading is now stale — repeating the write would apply a decision made
about a record that no longer exists in that form.

**Only the key of the written row comes back, never the row.** A scenario that
wants to read the record says so with [`query`](#query), where every rule about
approved and sensitive fields applies. Returning the row from here would be a
second way out of the same table with none of those rules on it.

Publishing refuses a scenario where this node is reachable without an
[`approval`](#approval) above it. That is not a runtime check and cannot be: the
engine is handed one node and cannot see the graph. The defence is a node
somebody had to draw and a release that fails while it is missing.

## `link`

Points the conversation at a record of the host application.

| | |
| --- | --- |
| Class | `LinkStep` |
| Outputs | `Pair` — `linked` / `could not` |
| Speaks | no |
| Trace | `passed`, `empty_result`, `failed` |
| Pins | `data_resources` |

| Setting | |
| --- | --- |
| `resource` | The declared data resource the record belongs to. Required |
| `role` | `contact` — who this is — or `subject` — what this is about. Default `contact` |
| `key` | The variable holding the record key. Required |
| `replace` | Whether a link already on the conversation may be moved. Default off |

The missing half of the data nodes. A scenario could already find a record and
even create one — [`choose`](#choose) remembers a key, [`mutate`](#mutate)
returns one — and then the key sat in a variable and died with the run. The
thread an operator opened afterwards still said nothing about which customer or
which order it was, and the only way to attach one was by hand, off a number
written in a message three screens up.

What it writes is a morph pair the package never dereferences: a type and an id
the host reads back through `contact` and `subject`. Which of the two is the
node's own setting, because they answer different questions, and a conversation
routinely fills them from different resources.

**The type is never typed in.** It comes from a declared data resource, and that
is the whole security of the node: `contact_type` holds a model class, and a
free-text field there would let whoever writes scenarios aim a conversation at
any model in the application — including one no resource ever exposed — after
which the host, doing exactly what it was told, renders that record to whoever
is in the chat.

Going through a resource also means **the key is checked against that resource's
access scopes**, the same rule a write goes through. A key comes from a
variable, and a variable one node earlier may hold something the visitor typed;
without the scopes, naming somebody else's order number would point the
conversation at it. An unreachable key links to nothing, and the node says so
only in its trace — a message distinguishing "not yours" from "not there" would
answer a question the visitor should not be able to ask.

The type is written as the record's `getMorphClass()`, so an application with a
morph map gets its alias rather than a class name. The wrong one fails nowhere:
the link simply resolves to null, months later, wherever it is read.

**A link already on the conversation is not moved unless `replace` says so.**
What is there came from the channel, from the host, or from an earlier and
better-informed turn. Pointing at the record it already points at is a success —
the scenario asked for a state and the state holds — but pointing at a different
one takes the second exit, so a thread cannot be quietly refiled under the wrong
customer.

## `action`

Does one of the things the host application registered as a capability.

| | |
| --- | --- |
| Class | `ActionStep` |
| Outputs | `Pair` — `done` / `could not` |
| Speaks | no |
| Trace | `passed`, `failed` |
| Pins | `capabilities`, with the version |

| Setting | |
| --- | --- |
| `capability` | The registered action key. Required |
| `input` | Name/value pairs. Values may contain `{{variables}}` |
| `into` | Variable prefix for what the action returned |

A data resource lets a scenario read records without anybody writing code; this
node covers everything a read cannot do — award the points, open the ticket,
cancel the booking, work out a price from rules that live in the host. See
[Capabilities]({{ site.baseurl }}/capabilities).

The node knows nothing about any particular action, and that is what makes it
worth having: the author picks a registered action from a list, the call form is
drawn from the descriptors that action declared, and the result lands in
variables under a name of the author's choosing.

The second exit means the action did not happen — unregistered, refused for a
bad input, or thrown out of. All three are the same decision for a scenario and
are told apart in the trace.

Variables are substituted **before** the gateway sees the payload, not inside
it. The gateway is what tells a good payload from a bad one and it has to judge
the values that will genuinely be passed: filled afterwards, an input that
looked present as `{{booking_id}}` would arrive at the handler as an empty
string whenever the conversation never learned one, through a check that had
already said the payload was complete.

The pin carries the action's version, not just its key. Pinned by key alone, an
action whose meaning was deliberately changed — points that now cost money, an
input that became required — would slide under a released scenario with nothing
to show for it, and the first sign would be the wrong thing happening to a
customer.

The action list an author picks from appends "(changes data)" to writing
actions. The label alone is not enough on a list that mixes reads and writes: an
author choosing between "Cancel booking" and "Check cancellation fee" cannot
tell from those words which one cannot be taken back.

## `http`

Asks another system something, and comes back either way.

| | |
| --- | --- |
| Class | `HttpStep`, implements `HasSideEffect` |
| Outputs | `Pair` — `answered` / `failed` |
| Speaks | no |
| Trace | `passed`, `failed` |

| Setting | |
| --- | --- |
| `method` | GET, POST, PUT, PATCH or DELETE. Default GET |
| `url` | May contain `{{variables}}`. Required |
| `headers` | Name/value pairs, values substituted |
| `body` | Sent as JSON when it parses as JSON, plain text otherwise |
| `path` | A dotted path inside the JSON answer. Empty keeps the whole body |
| `into` | Variable prefix for the answer. Required |
| `timeout` | Seconds, 1 to 60. Falls back to `chatbot.http.timeout` |

**This is the most dangerous node in the package, and everything about it is
written from that end.** It sends a request built from a URL somebody typed into
a web form, carrying values the visitor supplied, from inside the application's
own network — the exact shape of a server-side request forgery. Every rule below
exists because leaving it out turns a scenario editor into a way to read this
application's cloud credentials, its internal admin pages, or its neighbours on
the private network.

- **The address is checked before anything leaves**, by `OutboundGuard`: an
  allow-list that refuses everything until a domain is listed in
  `chatbot.http.allowed_domains`, https outside development, and a range check
  on the addresses the name actually **resolves to**, not on the text of the
  URL.
- **Redirects are not followed.** A redirect is the other system choosing where
  this request goes next, and the guard ran against a host it chose to leave
  behind: a 302 to `http://169.254.169.254/` would otherwise walk past every
  check with the allow-list still perfectly satisfied.
- **Only GET is retried.** A repeated read costs a second request; a repeated
  POST that timed out after the other side had already committed it books the
  trip twice, and the visitor gets two invoices for a node that reported one
  failure.
- **Nothing throws out of here.** A refused address, a connection that never
  opened, a certificate that did not verify, a body that was not the JSON it
  promised — all of them take the second exit with the reason in the trace,
  because on the other side of that exit is a scenario that can apologise and
  fetch a person.

`sideEffect()` is answered from the method rather than fixed per kind: a GET
reads, and a POST to somebody else's system creates a record this application
cannot take back, which deserves the same "was anyone asked first?" treatment as
a write into the host's own tables. Marking every outbound node as a write would
put a warning on every price lookup, and a warning on everything is a warning
nobody reads.

A URL holding no `{{variable}}` is checked against the guard **while editing**,
and a refusal is a blocking issue — the difference between a typo found in the
editor and one found by a customer whose conversation quietly took the failed
exit.

A configured `path` that is absent from the answer is a failure, not an empty
value. That is the difference between "the order has no status" and "we asked
the wrong system"; written into the variable as an empty string, the second
reads to the visitor as the first.

The response is cut to `chatbot.http.max_response_bytes` before it is stored.
The ceiling is honest about what it is: an answer of any size has already
arrived by the time it is read. What it prevents is the part that lasts — a
megabyte of somebody else's page stored in a variable, carried through the rest
of the walk and written into the run journal, where it stays.

## `transform`

Reshapes a value the conversation already has into another variable.

| | |
| --- | --- |
| Class | `TransformStep` |
| Outputs | `Single` |
| Speaks | no |
| Trace | `passed`, `failed` |

| Setting | |
| --- | --- |
| `source` | The variable to take. Required |
| `operation` | One of the ten below. Default `trim` |
| `into` | Variable to write the result to. Required |

Operations: `upper`, `lower`, `trim`, `first_word`, `digits_only`, `truncate`,
`default`, `join`, `format_date`, `replace`. Each brings its own extra settings
— `length` for truncate, `fallback` for default, `with` and `separator` for
join, `format` for format_date, `search` and `replace` for replace.

The small work every scenario needs and no node was doing: a name typed in
capitals that has to be greeted with, a phone number with spaces that has to be
matched against a record, a date printed the way people read dates, an empty
answer that needs a stand-in. Without this node each of those is either a host
action written for one sentence, or a bot that says "HELLO IVAN".

**The operations are a closed list, and it stays closed.** The obvious
alternative is one field holding an expression, and it is the single most
expensive thing that could be added to this package. An expression field is code
execution; the code is typed into a web form by whoever edits scenarios — a
marketer, a support lead, somebody with the panel password — and it runs on the
application server with the application's database behind it. Every sandbox for
that is a project of its own that is bypassed by a construct nobody thought of,
and the reward for all of it is a shorter way to write "uppercase". A list of
named operations is refused by not existing: an operation this class does not
implement cannot be reached, whatever anybody types.

**An operation the node does not know leaves the target variable untouched.**
The tempting alternative — copy the source across unchanged — is what turns a
typo in a saved scenario into a message that reads plausibly and says the wrong
thing: "we will call you on IVAN" from a scenario whose author believed they
were formatting a phone number. Nothing written means the message shows a gap,
which is the one outcome an author notices in the first conversation.

One exit: there is nothing here that can fail in a way a scenario should branch
on — a value that was not a date is still a value — so a failure is carried in
the trace and the walk goes on.

---

# Knowledge and the model

## `answer`

Answers the visitor's last message from the knowledge base, or admits it cannot.

| | |
| --- | --- |
| Class | `AnswerStep` |
| Outputs | `Pair` — `answered` / `do not know` |
| Speaks | **no** |
| Trace | `answered`, `unknown`, `handed_off`, `jumped`, `failed` |

| Setting | |
| --- | --- |
| `category` | Limit the search to one section. Empty searches every topic |
| `fallback` | Sent when a topic matches but its answer field is empty |

The node exists so that a scenario does not have to enumerate the questions
people ask. **What it may say is decided by the topic it finds, not by this
node**: the mode on the topic is the company's standing decision about that
subject, and it has to survive being reached from any scenario anybody writes.

`speaks()` is false, and the docblock is explicit about why: `speaks()` is about
the node's *own* body text, which this node has none of. Answering true would
make the editor draw a message box, and whatever an author wrote in it would be
sent ahead of the real answer — the visitor reads two replies to one question,
the first usually blank, and nothing on the canvas explains the empty line.

What happens depends on the matched topic's `KnowledgeMode`:

| Mode | |
| --- | --- |
| `Answer` | Sends the text, takes the first exit |
| `Verify` | Sends the text and flags `needs_check` in the trace |
| `Handoff` | **Sends nothing** and takes the second exit |
| `Urgent` | Hands the conversation to a person immediately |
| `Scenario` | Jumps to the topic's `scenario_slug` |

**A `Handoff` topic's text is never sent, and there is deliberately no setting
that would let it be.** These topics are refunds, prices, deadlines and house
rules — the answers a customer repeats back as a promise the company then has to
keep. A sentence that was true last quarter is still stored, still matches the
question perfectly, and is now a commitment nobody made. Withholding costs one
handover; sending costs an argument the company loses.

`Urgent` routes to a person directly rather than through the second exit,
because the second exit is an ordinary "I do not know" that an author is free to
wire into another question or a polite goodbye — and urgent topics exist
precisely to cut that short.

`Verify` is answered exactly like `Answer` and flagged in the trace instead.
Holding it back would empty the knowledge base of everything seasonal, and
prefixing the reply with a warning teaches visitors to distrust the bot on the
topics it is right about most of the time. The flag is for the person reading
the journal, who is the one who can check.

The second output means "no answer was given" for every reason at once — no
topic matched, two matched equally, or the topic was one the bot may not speak
about. They are told apart in the trace rather than by extra arrows, because an
author wires all of them to the same place: a person. The trace also carries the
runners-up, because a complaint about a wrong answer is investigated by asking
what came second, and by the time anybody asks, the visitor's exact wording is
gone.

## `ai`

Answers the visitor with a model, from the material this installation gave it.

| | |
| --- | --- |
| Class | `AiStep` |
| Outputs | `Pair` — `answered` / `could not` |
| Speaks | no |
| Trace | `said`, `failed`, `empty_result` |

| Setting | |
| --- | --- |
| `provider` | A named gateway, or empty for the installation's default |
| `instruction` | Defaults to `chatbot.ai.answer.instruction` |
| `candidates` | Articles offered to the model. 1 to 20 |

**A node of its own, not a switch inside `answer`, and that is the decision this
class exists to record.** While the model lived inside that node, the canvas
showed one box for two completely different things: a paragraph the company
wrote and approved, and a sentence composed on the spot by somebody else's
program. Whoever drew the scenario could not see which was which, and neither
could the reviewer reading it. Worse, the choice was made once for the whole bot
by a setting — so a company that wants a model for opening hours and a fixed
text for cancellation terms had no way to say so. Two boxes, and the branch is
chosen where it belongs: on the canvas, per branch, by the person accountable
for the wording.

**An answer that names no given source is not spoken.** The model is handed
labelled fragments and asked to say which it used; a claim naming something
outside that set is a fragment it made up, and an answer built on a made-up
fragment is the kind that sounds exactly like a real one. Refusing it costs a
"could not" branch; accepting it costs a customer being told the wrong refund
policy in the company's own voice.

The second exit is taken far more often than a demo suggests: no driver
configured, no key, the library not installed, the provider down or refusing,
the budget spent, the answer unreadable, no material found, or an answer that
named no material at all. A scenario drawn without that exit connected goes
silent the first time a provider hiccups — which is why the node reports a
warning while editing when no model is available.

## `ai_agent`

Hands one turn to the model: it reads the thread, may use the tools this node
offers, and answers in its own words.

| | |
| --- | --- |
| Class | `AiAgentStep` |
| Outputs | `Pair` — `answered` / `needs a person` |
| Speaks | no |
| Trace | `said`, `passed`, `failed` |

| Setting | |
| --- | --- |
| `provider` | Must be a driver that can hold a conversation — `anthropic` today |
| `instruction` | Required. Who the model is, how it speaks, what it may not promise |
| `tools` | The capabilities it may call. An allow-list, per node |
| `slots` | The names `remember` may write to. None chosen, no remembering |
| `search` | Offer the knowledge search. On by default |
| `call_human` | Offer "this needs a person". On by default |
| `window` | Messages sent verbatim. 2 to 60, default `chatbot.ai.agent.window` |
| `rounds` | Model calls allowed per turn. 1 to 8, default `chatbot.ai.agent.rounds` |

**The difference from `ai` is who decides what happens.** `ai` answers one
question from material the scenario found for it, and the scenario decides
everything else: what to ask next, when to file the request, when to fetch a
person. Here those decisions are the model's, inside the bounds this node draws.
That is what makes a conversation feel answered rather than processed, and it is
also why the bounds are on the canvas and not in the instruction: a sentence in
a prompt is a request, a missing tool is a fact.

**A scripted form is still the better place for a required interview.** The
twelve questions an agency needs are a list somebody is accountable for; a model
asked to collect them will get eleven and improvise the twelfth. The division
that works is this node for the conversation — greeting, questions about
anything, reading a booking, working out what the person actually wants — with
`remember` writing what it learns into the same slots a form would have filled.

**Three tools are the package's own**, switched on per node rather than
registered by a host, because all three are about the conversation rather than
about the application: `search_knowledge` looks something up in the company's
own material, `remember` writes a value where the rest of the bot can read it,
and `call_human` says this needs a person. A model with no way to ask for help
improvises instead, which is how a bot promises a refund.

**Rounds are capped and the cap is low.** A model that cannot finish asks for
the same thing again, and every round is a billed request carrying the whole
window and the whole catalogue. Uncapped, one confused turn spends a month's
budget while the visitor watches nothing happen; capped, it ends in the second
exit, which the author has already wired to a person.

**A tool that was not offered is refused and reported back to the model** rather
than ending the turn. Models do invent a tool, usually a plausible one —
`cancel_booking` sounds like something a travel bot would have. Answering "there
is no such tool" lets it recover within the turn and say something honest.

**Nothing reaches the visitor until the model has finished.** A model usually
says a sentence alongside its request — "сейчас посмотрю" — and speaking those
would mean two or three messages per answer in a channel where each one is a
notification. They are kept for the journal; what the visitor reads is the
answer the model gave after seeing what the tools returned.

How much of the thread the model sees is decided by `ConversationMemory`: a
window of recent messages verbatim, a running summary of what fell out of it,
and a line drawn under both when a scenario finishes. Everything leaving goes
through the redactor, the visitor's own words included.

## `ai_task`

Pulls named values out of what somebody wrote, and does nothing else.

| | |
| --- | --- |
| Class | `AiTaskStep` |
| Outputs | `Pair` — `extracted` / `could not` |
| Speaks | no |
| Trace | `passed`, `failed`, `empty_result` |

| Setting | |
| --- | --- |
| `instruction` | What to pull out. Required |
| `input` | May contain `{{variables}}`. Empty reads the message just sent |
| `fields` | The value names to expect |
| `into` | Variable prefix. Required |
| `provider` | A named gateway, or the installation's default |

The useful half of a language model with none of the dangerous half. "We are two
adults and a child, flying from Rostov around the tenth of June" holds four
values no pattern reliably finds, and this node turns them into variables the
rest of the scenario can branch on, ask about and send.

**What it may not do is decide anything.** It does not choose the next node, it
does not call a host action, it does not compose a word the visitor will read.
That is a boundary, not a missing feature. A model allowed to choose the route
takes a different one on Tuesday for the same sentence it saw on Monday — and
the author, asked by a customer why the conversation went the way it did, has no
answer and no way to get one. Extraction is checkable: the values land in named
variables, the journal shows them, and the branching next to it is a condition
somebody wrote and can read back.

**Nothing is written on the failing path.** Writing partial or invented values
would be worse than not extracting at all: the conversation would carry on
confidently against a passenger count nobody said.

Every expected name gets a variable, including the ones the model did not find.
A name left unwritten keeps whatever an earlier run of the same node put there —
a previous traveller's city, printed into this traveller's confirmation — and
that is far harder to notice than a gap in a sentence. A returned value that is
not a plain scalar is dropped rather than flattened: a nested object arriving
where a city name was asked for means the model answered a different question,
and JSON-encoding it would print a fragment of JSON in the middle of a sentence
to a customer.

---

# Ending

## `handoff`

Calls in a person and falls silent for the rest of the conversation.

| | |
| --- | --- |
| Class | `HandoffStep` |
| Outputs | `None` |
| Speaks | yes |
| Ends | yes |
| Trace | `handed_off` |

Silent for the rest of it, not until the next node: the operator now owns the
thread, and a bot that woke up again on the visitor's next message would be
talking over them. That is why the node ends the branch instead of having an
exit.

It is also the node the operator's notification hangs off, and this is the only
honest place for it. Ring the bell earlier — when the visitor writes, when a
scenario fails to match — and the desk fills with conversations the bot was
about to answer perfectly well; people learn to ignore the bell, and the one
conversation that genuinely needed them waits with the rest. Here, every ring
means the same thing: the bot has run out of answers and somebody is waiting.

## `close`

Says goodbye and closes the conversation.

| | |
| --- | --- |
| Class | `CloseStep` |
| Outputs | `None` |
| Speaks | yes |
| Ends | yes |
| Trace | `closed` |

**Closing is a state, not a deletion and not a door being locked.** The thread,
its messages and its trace stay exactly where they were, the visitor is free to
write again, and their next message reopens the conversation and starts a new
walk. Read as "the visitor may no longer write", this node would be the cruellest
thing in the package — somebody with one more question would find the chat
refusing them.

What it does buy is a desk that means something. Without it every finished
conversation stays open forever, the operator's list grows without bound, and
the ones that genuinely need an answer are lost among conversations that ended
politely three weeks ago.

---

## Next

- [Data resources]({{ site.baseurl }}/data-resources) — what `query` and `choose` are allowed to read.
- [Capabilities]({{ site.baseurl }}/capabilities) — what `action` invokes.
- [Extending]({{ site.baseurl }}/extending) — writing a node of your own.
