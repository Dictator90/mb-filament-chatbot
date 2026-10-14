---
title: Slots and conditions
layout: default
nav_order: 8
---

# Slots and conditions

Two vocabularies with one job between them: slots decide what a bot can ask for
and how it reads the reply, conditions decide what it can branch on. Both are
registries rather than fixed lists, and both ship with a deliberately small set
of built-ins.

---

## Slots

A slot is one thing the bot can ask for and remember.

The reason they exist rather than free-form variables is that "the visitor's
phone number" has to mean the same thing across an installation: the same
question, the same parsing, the same name in the stored answers. With free-form
variables one scenario writes `phone` and another writes `tel`, both look
correct on the canvas, and the host code reading the answers silently finds
nothing in half the conversations.

One dictionary per installation is what makes the following true at once: a
`Form` node can ask for five things without anybody writing five questions, an
operator reading a summary sees the same labels every time, and your own code
can query on a key it knows.

### The eight built-ins

| Key | Kind | Default question |
| --- | --- | --- |
| `name` | text | How should I address you? |
| `phone` | phone | What is the best number to reach you on? |
| `email` | email | What is your email address? |
| `contact_preference` | choice — a phone call / email / a messenger | How would you like us to get back to you? |
| `company` | text | Which company are you with? |
| `request` | long text | Tell me what you need |
| `urgency` | choice — right now / today / this week / no rush | How soon do you need this? |
| `order_number` | text | Do you have a reference number for this? |

Eight, and deliberately no more: a name, three ways to reach somebody, what they
want, how soon, a reference they may already have, and who they work for.
Nothing here names an industry — there is no "destination" and no "symptom" —
because a travel agency and a clinic install the same package, and a slot picker
full of somebody else's vocabulary is worse than an empty one.

`order_number` is labelled "reference number" for the same reason: whatever you
call the thing people quote back at you, this is the slot it lands in, and a name
borrowed from one industry reads as a mistake in every other.

The wording is a starting point, not a fixture. Each slot carries a default
question so an author can assemble a working form before writing a line of copy,
and any scenario may override it. Add your own with
[`Chatbot::registerSlot()`]({{ site.baseurl }}/extending#your-own-slot).

### The kind decides parsing, not presentation

People do not answer a question about dates with a date. They write "next
Friday", "the 3rd", "in two weeks". A slot that only accepted an ISO date would
reject nearly every real answer and hand the conversation to a person over a
formatting problem — the one outcome a bot exists to avoid.

The opposite failure is worse and quieter: reading an answer into something
plausible but wrong. That is why an unreadable answer is reported as unread
rather than guessed at. One more question costs a few seconds; a wrong date
costs a booking.

**`text`** — one line, taken as written. **`long_text`** — several sentences.
Both are capped rather than rejected (1,000 and 4,000 characters). The cap
guards the column and the outgoing message, not the person: somebody who pastes
a whole email thread into "tell me what you need" has answered the question, and
refusing them would restart a conversation over length.

**`phone`** — digits extracted, everything else dropped. `+7 (999) 123-45-67`,
`8 999 1234567` and `79991234567` are the same person, and you are expected to
find them by that number; leaving the punctuation in means one caller becomes
three records and a second conversation never recognises the first. Ten to
fifteen digits are accepted. An eleven-digit number starting with `8` has the
trunk prefix rewritten to `7`, and only that case — a ten-digit number starting
with `8` is a subscriber number, not a prefixed one, and touching it would
corrupt it. Stored as `+<digits>`, with the bare digits in `extra` under
`digits`.

**`email`** — the address is pulled out of the sentence around it. Demanding a
message that is nothing but an address fails on "write to me at a@b.ru please",
which is how people answer, and the re-ask that follows reads as the bot being
unable to see what is plainly there.

**`number`** — digits and words are the same answer. "2" and "two" both answer
"how many of you are travelling", and a bot understanding only one of them looks
broken to half the people who reply. English words zero to ten are understood
without configuration — that is the one language the package can claim to speak
— and you extend the list under `chatbot.slots.number_words`:

```php
'slots' => [
    'number_words' => [
        'ноль' => 0, 'один' => 1, 'два' => 2, 'три' => 3, // ...
    ],
],
```

A minus sign is refused rather than dropped: negative counts do not exist, and
silently reading "-2" as 2 would store a number nobody typed.

**`money`** — separators, currency words and spaces removed. Which separator was
decimal is decided by what follows it, not by locale: three digits after the last
separator mean thousands (`$1,200`), one or two mean a fraction (`1 200,50`).
Guessing by locale would turn one of those into a thousandfold error in a number
somebody is quoted back. A multiplier word after the amount applies — `150k`,
`150 тысяч`, `1.5 million` — from `chatbot::bot.money_multipliers`, because that
is how budgets are said and the digits alone store a hundred and fifty. A range
("200–230 тысяч", "from 200 to 230k") is stored as its upper end, the honest
reading of a ceiling, with both ends in `extra` under `from` and `to`; the left
end borrows the right end's multiplier while it is too small to be an amount on
its own.

**`date`** — `today`, `tomorrow`, `2026-05-10`, `10.05`, `10/05/26`, a month by
name with or without a day (`12 июня`, `в феврале 2027`, `june`) from
`chatbot::bot.months`, and anything else Carbon can read. Stored as `Y-m-d`. A
month named without a day is stored as its first day with the month itself in
`extra` under `month` (`2027-02`): "in February" asks for February, not for the
first of it. English month names are read whatever the app's language.

**`date_range`** — two dates out of one sentence, stored as a readable
`10.05.2026 – 17.05.2026` with machine halves in `extra` under `from` and `to`.
Its own kind rather than two date slots because that is how people answer: "from
the 10th to the 17th" arrives in one message, and asking twice for something
already said is the fastest way to make a bot feel like a form. Reversed ends are
reordered — "17th to the 10th" is a slip of the fingers, and a stored range whose
end precedes its start breaks every query written against it. A single date is
refused rather than completed: guessing the other end invents a stay nobody asked
for.

**`choice`** — matched by label, then by key, then by position in the list.

### Why a date without a year goes forwards

"10.03" answered in December means next March.

Filling in the current year is the obvious implementation and it produces a date
nine months in the past — and nothing downstream objects. The bot confirms a trip
that already happened, the request reaches an operator dated backwards, and the
mistake surfaces only when a human reads the card. So a bare day and month that
would land before today is moved to next year instead. An explicit year is always
respected; a two-digit year is read as 20xx.

### Why position matters for a choice

Only the site widget can draw buttons. In a messenger, over email or read out on
the phone, the bot lists the options as a numbered list and the person replies
"2". Accepting the label alone would leave the bot working in exactly one channel
and stuck in every other one — which is also why a channel driver answering
`supportsButtons(): false` stays fully usable.

### What the value is, and what `extra` is for

A successful read stores two things and they have different jobs. The value is
both stored under the slot key and pasted into the bot's next sentence, so it
stays the form a human would write: a range read as `2026-05-10..2026-05-17`
would be correct in the store and unreadable in a confirmation, where a person
seeing it assumes the bot broke.

The machine halves go into `extra`, and the `Collect` node stores them as
sibling variables named `<slot>_<key>` — so a `travel_dates` slot fills
`travel_dates`, `travel_dates_from` and `travel_dates_to`, and a `phone` slot
fills `phone` and `phone_digits`. Query on those.

Both `Collect` and `Form` also keep the answer exactly as written under
`<slot>_said`. A summary the visitor is asked to confirm should quote them, not
the parsed value: "Dates: 2027-02-01" in reply to "in February" reads as a bot
that misunderstood, and "Budget: 150000" in reply to "150 тысяч" as one that
cannot count.

### What happens to an answer that cannot be read

An unreadable answer keeps the raw text exactly as typed — not trimmed, not
shortened. That text is what a re-ask quotes and what an operator reads when they
take the conversation over, and a bot that quietly reshapes what somebody wrote
makes that handover misleading. "I could not read '9 evenings'" is a question
somebody can answer; "I could not read that" is not.

Free text can never be misread — whatever the person wrote is the answer — so
only the shaped kinds are ever asked again. `SlotKind::canBeMisread()` is what
draws that line.

The `Collect` node re-asks up to its `attempts` setting (two by default) and then
**stores the raw text and carries on**. It has one output and no way to fail, on
purpose: somebody who has typed their phone number twice and been told twice that
it is not a phone number will not type it a third time, and an operator reading
"call me on the shop line" understands it perfectly. A scenario that genuinely
cannot continue without a parsed value wants the `Form` node, which has a branch
for giving up.

---

## Conditions

A condition is one thing a branch can ask about. The package knows about the
conversation itself — its channel, whether the person has written before, what
the bot has collected, what time it is. Whether they have an unpaid invoice is
your question, and you
[register it]({{ site.baseurl }}/extending#your-own-condition).

Conditions are read-only by contract. One that wrote anything would run a
different number of times depending on how a scenario branches, and an author
reading the canvas has no way to see that.

### The fourteen built-ins

| Key | Kind | What it asks |
| --- | --- | --- |
| `variable` | text, needs a name | Branches on something the bot collected |
| `channel` | choice | Which channel this thread arrived on |
| `identified` | flag | Has the host told us who this person is |
| `has_contact` | flag | Is this thread filed against a record |
| `returning` | flag | Has this person written before |
| `working_hours` | flag | Is the bot inside its own working hours |
| `time` | clock | Time of day |
| `weekday` | choice | Day of the week |
| `month` | number | Month, 1 to 12 |
| `day` | number | Day of the month, 1 to 31 |
| `date` | date | Today's date |
| `turns` | number | How many messages the visitor has sent |
| `only_greeting` | flag | They said hello and nothing else |
| `page` | text | The page the chat was opened from |
| `cookie` | text, needs a name | A cookie the placement declared |
| `handed_off` | flag | The thread already belongs to a person |

Several of these look redundant next to each other and are not.

**`working_hours` versus `time`.** "We will answer in the morning" is the single
most common reason a scenario needs the clock, and rebuilding it out of `time`
gets the night shift wrong — a window crossing midnight is not a comparison
anyone writes correctly on the first try. The field defers to `Bot::isWorkingHours()`
rather than re-implementing it, because a second copy of that comparison is
exactly how a bot ends up answering at the wrong hour in one place and the right
hour in another, with nothing on the canvas to say which the author was looking
at. A conversation with no bot row is treated as always open: a bot that is never
open is indistinguishable from a bot that is broken.

**`identified` versus a phone number.** This is the gate in front of anything
personal, and it asks the conversation rather than looking for contact details. A
phone number typed into the widget was typed by whoever was at the keyboard; a
branch built on "we have a phone number" would read somebody their own data only
by luck. `identified` is your application saying it authenticated the visitor.

**`has_contact` versus `identified`.** Different questions, and a scenario that
treats them as one is wrong in both directions. `identified` asks whether anybody
proved who this is; `has_contact` asks whether the thread has been filed against
a record. A thread an operator attached to a customer card by hand, or one your
`ContactDirectory` recognised by phone number, is linked and unproven — good
enough to fetch an order's status into a variable, nowhere near good enough to
read somebody their own invoice. A visitor whose identity your site signed is
proven and unlinked whenever you keep no record of them. So `has_contact` is the
condition in front of anything that *reads a record*, and `identified` stays the
condition in front of anything *personal*. A scenario that wants both says both.

**`returning`** is answered from the contact you linked, not from the thread. One
person writes from the site today and from Telegram tomorrow, and each channel
opens its own conversation; counting threads on this channel alone would greet a
returning customer as a stranger every time they changed app. A conversation with
no contact answers false without a query — "we do not know who this is" cannot be
"they have written before", and asking the database about a null contact would
match every other unlinked thread in the table.

**`only_greeting`** exists because the two openings want opposite answers. "Hi"
is an invitation to say what the bot can do; "hi, where is my order" is a
question, and answering it with a menu is the most annoying thing a bot does. A
keyword branch cannot tell them apart — the second message contains the first.

**`turns`** is the measure of a conversation going nowhere. A person on their
sixth message to a bot is not being helped, whatever each individual branch
thought, and this is the only condition that can see that; every other one looks
at a single moment.

**`page`** is the page the conversation *started* on, not the one the visitor is
looking at now. People keep browsing while they type, and branching on the
current page would make the same question take different routes depending on how
long somebody took to write it.

**`cookie`** is a value the embedding page was allowed to hand over: the campaign
somebody arrived through, the plan their account is on, the branch they picked.
Only cookies the widget declared ever arrive — you list them by name on the
placement, and that list is what keeps an embed on a customer's site from being a
reader of every cookie on it. It is checked twice: once when the widget is told
what to read, and again on the server when the answer comes back.

A cookie may steer a conversation and must never open anything. Anybody can set
one on a page, so a branch reading `plan = enterprise` is reading a claim by
whoever was at the keyboard: use it to shorten a path, choose a tone or route to
the right team, and use `identified` for the gate in front of somebody's data.
Like `page`, it is read as the thread opened rather than as it is now.

**`handed_off`** looks useless — the engine stops the bot when a thread goes to a
person, so a running scenario never sees it true. It is needed in the two places
that are not a running scenario: a scenario resumed from a queued pause, and a
quality check replaying a conversation that ends in a handover. Both run against
a conversation whose state moved on after the branch was drawn.

**`channel`** reads its options from configuration rather than a fixed list,
because channels are registered by hosts. A closed list would leave every channel
the package did not ship out of the editor's picker while still working at
runtime — the kind of gap authors work around by typing the key into some other
field.

### Kinds and operators

A condition declares a `ConditionKind`, and the kind decides which operators the
editor offers and whether it draws a value box at all.

| Kind | Operators |
| --- | --- |
| `flag` | `yes`, `no` |
| `text` | `equals`, `not_equals`, `contains`, `starts_with`, `one_of`, `filled`, `blank` |
| `number` | `equals`, `not_equals`, `at_least`, `at_most`, `filled`, `blank` |
| `clock`, `date` | `at_least`, `at_most`, `equals`, `filled`, `blank` |
| `choice` | `equals`, `not_equals`, `one_of`, `filled`, `blank` |

Kinds exist rather than a free operator list per field because an author
choosing "is the visitor known" should never be offered "greater than", and a
field author should not have to remember to exclude it.

`yes`, `no`, `filled` and `blank` need no value, and the editor hides the box for
them. A visible box that is ignored is how an author ends up believing they set a
threshold nothing reads.

Comparison is shared across every field through the `ComparesValues` trait.
"Equals" has to mean the same thing on a channel, on a collected answer and on
your own field; the moment a second field writes its own comparison the two
drift — one trims, the other does not — and an author who moved a branch between
fields finds it stops matching for no visible reason. Numbers are compared as
numbers, so a threshold typed as `5` matches a value stored as `5.0`; everything
else is compared case-insensitively without its outer spaces.

Two guards inside that trait are worth knowing because they prevent a branch that
silently swallows everything below it. `contains` and `starts_with` with an empty
needle answer **false**, not true — `str_contains` says every string contains
`""`, so an author who chose "contains" and then cleared the box would have drawn
a branch that always fires and makes every branch under it unreachable, with
nothing on the canvas to show it. `at_least` and `at_most` with an empty
expectation fail in both directions for the same reason.

### `filled` and `blank` are not `equals ""`

They are on every kind except the flag, and they are the operators people reach
for most: a scenario nearly always needs to know whether something was collected
at all before it can compare it. Leave them off and authors write `equals ""` —
which is a different question.

There are three states, not two:

| State | `filled` | `blank` | `equals ""` |
| --- | --- | --- | --- |
| never asked (null) | false | **false** | false |
| answered with nothing | false | **true** | true |
| answered with something | true | false | false |

The middle row is why `blank` exists. The bottom of that distinction is the top
one: **never asked is neither.** It falls through to "otherwise", which is where
a scenario asks the question for the first time. Let `blank` swallow null as well
and a branch meant to say "that answer was empty, let me ask again" would say it
to a visitor who was never asked anything.

Every comparison naming a value also answers false for null. A branch drawn as
"is not Moscow" was drawn about an answer that exists, and firing it for a
question nobody asked sends the conversation down a path built on a fact the bot
does not have.

### An unknown field or operator is `false`, never an exception

A branch naming a condition whose package has been removed, or an operator the
editor no longer offers, is a fault in the scenario — but it turns up
mid-conversation, with a customer waiting. `false` sends them down the
"otherwise" branch and the conversation continues; an exception would end it with
a stack trace and nothing said.

The scenario is still visibly wrong to the person who can fix it: the branch
never fires, the run journal records which condition was unrecognised, and the
editor marks the node. It fails loudly upwards and quietly downwards.

The operator check lives in the evaluator rather than in each field, because a
field asked "is `contains` true of a yes/no question" has no sensible answer, and
leaving every field to notice that for itself means every host-written field has
to remember to.

A field implementing `ReceivesName` with no name in the branch also answers
false. And `isUsable()` — a separate method used only by the editor — is what
marks a branch that can never fire: unusable and simply-not-matched-yet look
identical at runtime, and the difference is only visible while somebody is still
editing.

---

## Branching is options, not nodes

A question with buttons, a keyword match and a condition look different on
screen — a button, a list of words, a comparison — and are the same thing
underneath: **a label plus the key of the next node, stored in the node's own
options.**

That is why the canvas can draw the outputs of all three with one piece of code,
and why an author who has understood one has understood the others. It is also
why a host node declaring `StepOutputs::Options` or `OptionsElse` gets working
branch handles without the editor knowing anything about it.

Two rules follow, and both are about the picture matching the behaviour.

**The first matching branch wins.** Not the best match, not the most specific:
the first, in the order the author put them in. Any other rule would make the
order on screen not mean what it looks like it means.

**A pair is not a short options list.** `StepOutputs::Pair` has exactly two
exits, named by the engine and not editable — an option is something a person
creates and labels, while a pair's two exits mean an outcome the engine decided
and the author only routes: a lookup found rows or found none, the model answered
or declined, an approval came back yes or no. Let a pair be edited as options and
the third click produces a node with four branches, three of which the engine has
no way to reach; the node runs, one arrow is never taken, and the author spends
the afternoon asking why. Keeping the kinds apart makes that click impossible
rather than merely discouraged.

A condition node's fallback exit is the "otherwise" every branch above falls
through to. A branch with no condition at all is reported as a defect while
editing — it can never be taken — as is one the evaluator judges unusable.
