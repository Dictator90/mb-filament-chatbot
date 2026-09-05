---
title: The operator desk
layout: default
nav_order: 11
---

# The operator desk

The bot answers what it has been taught. Everything else has to reach a person,
and this is the screen that person works at: every conversation on every
channel, in one list, with a box to answer from.

It is a Filament page — `MB\FilamentChatbot\Filament\Pages\ChatInbox` — and the
plugin registers it, so there is nothing to add to a panel that already has
[the plugin installed]({{ site.baseurl }}/installation).

---

## What the operator sees

The page is two columns. On the left, the conversations; on the right, the one
that is open.

**The list** carries a search box (name, username, phone or e-mail), a status
filter (open, closed, all), an assignee filter (anyone, mine, unassigned) and an
"unanswered only" switch. Each row shows the visitor, a one-line preview
prefixed by who spoke last — `Bot: …`, `You: …` — the time, a dot when there is
something unread, and tags for the channel, the bot and whether the row is
`free`, `yours` or `taken`.

**The thread** shows the transcript with the visitor on the left and the bot and
the operator on the right, told apart by colour and by a name above each bubble.
Under it sits the reply box, a character counter and the stock phrases.

The list is capped at 80 conversations and says so when it is capped. It is not
a page of results to walk through: a desk with more than eighty conversations
waiting is a staffing problem, and the answer to it is the filters, not a second
page.

---

## Answering takes the conversation over

This is the part worth reading before anything else, because it happens without
being asked for and nothing on screen announces it until it has.

**The first reply hands the conversation to the person who typed it.** Sending
sets the owner, if nobody had taken it, and moves the conversation to
`HandedOff`. Both, together: [the engine]({{ site.baseurl }}/scenarios) keeps
quiet while a conversation has an owner *or* has been handed off, and doing one
without the other leaves the bot running its scenario over the operator's
answer. The customer then gets two answers to one question in two different
voices, and the transcript is the only place that shows.

A banner above the box says so while the bot is still answering: *"The bot is
answering this conversation. Your first reply takes it over."*

**A conversation a colleague already owns is never taken.** Helping out on
somebody else's thread does not move it off their list. The owner is what the
rest of the desk routes by, and a silently transferred conversation is one
nobody is told about.

**Handing back needs both halves too.** "Give back to the bot" clears the owner
and returns the conversation to `Active`. Clearing one and not the other leaves
a screen claiming the bot is answering while the visitor talks to nobody.

The reply itself goes through `MB\FilamentChatbot\Chat\OperatorReply`, which is
also what a host should call to answer from anywhere else:

```php
app(\MB\FilamentChatbot\Chat\OperatorReply::class)
    ->send($conversation, 'We have posted your refund today.', auth()->user());
```

It settles the conversation before it writes the message, because the message
observer queues delivery the moment the row exists and nothing recalls a sent
message. An empty body throws `InvalidArgumentException` rather than returning
null — a message with nothing in it is a notification the customer cannot read
and an empty bubble the operator believes they sent.

---

## Read, unread and the badge

**A conversation is marked read by being opened**, and again while it stays
open. Left to a button, "unread" would measure who remembered to press it, and
an operator would be sent back all day to conversations they have already dealt
with.

The sidebar badge counts **what is unanswered and not somebody else's** — the
unassigned work plus this operator's own. Counting the whole desk would show
every operator the same number and mean nothing to any of them.

The count is cached for fifteen seconds, because the sidebar renders it on every
page of the panel. The cache key carries the viewer's id. That is not tidiness:
under a shared key the first person to open the panel hands their own number to
everybody else for the life of the entry, including the count of conversations
assigned to them personally.

---

## What the desk deliberately does not do

**It does not load the assignee.** Rows say `free`, `yours` or `taken` from the
id alone. The package does not know whether the host's user model has a name
worth printing, and asking each row for its assignee would be a query per row to
find out.

**It does not show quality rehearsals.** Threads written by the
[quality runner]({{ site.baseurl }}/operations) look exactly like real ones, and
an operator who opened one would answer a customer who does not exist. A global
scope keeps them off the desk, and the desk does not lift it.

**It does not delete conversations.** Closing sets a status; the transcript
stays. What a customer was told is a record, and a screen where it can be made
to disappear is a screen where it will be.

**It has no page-per-conversation.** `?conversation=41` restores an open thread,
so a link still works and a refresh does not lose the place, but the desk is one
screen.

---

## Access

The page is visible to whoever the host lets see conversations:

```php
Gate::define('viewAny', fn ($user) => $user->hasPermission('chat.view'));
```

`canAccess()` asks `viewAny` on the `Conversation` model, so the answer comes
from the host's own policy — see [Extending]({{ site.baseurl }}/extending) for
how the package defers authorization. A panel where nobody may see
conversations simply has no Chat item in its menu.

---

## Styling

The desk's appearance is a `<style>` block inside its view, scoped under
`.chatbot-inbox`.

This is deliberate and it is the sort of decision worth knowing about before
wondering why. A host's Tailwind build has not scanned this package's views, so
utility classes written here may not exist in the host's compiled CSS at all; a
published stylesheet, meanwhile, works right up until somebody upgrades the
package without re-running `filament:assets`, and then it is wrong in a way that
looks like a rendering bug.

If you would rather have a real stylesheet, it is one `FilamentAsset::register()`
call in `ChatbotPlugin::boot()` and a `publishes()` tag.
