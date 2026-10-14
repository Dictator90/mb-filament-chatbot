---
title: Channels and the widget
layout: default
nav_order: 10
---

# Channels and the widget

Two channels ship with the package: the site widget and Telegram. Both are
ordinary registrations, replaceable and removable, and both go through the same
door on the way in and the same outbox on the way out.

---

## The driver contract

`ChannelDriver` is an interface rather than a base class, because the two shipped
channels share nothing at all: the site widget delivers by doing nothing and
letting the browser ask, Telegram delivers by calling somebody else's HTTP API.
A shared parent would have to be empty.

```php
public function key(): string;          // stored in chatbot_conversations.channel
public function label(): string;
public function isConfigured(): bool;   // could it deliver right now
public function send(Conversation $conversation, Message $message): DeliveryResult;
public function supportsButtons(): bool;
```

Writing one is covered in
[Extending]({{ site.baseurl }}/extending#your-own-channel). What matters here is
what surrounds it.

### A channel without buttons is not a degraded channel

`supportsButtons(): false` makes `ChannelText` append the options to the outgoing
text as a numbered list, and the engine accepts a bare number as an answer —
matching an option by its position as well as by its label.

That transformation lives in one class rather than in each driver, and that is
the point of the class. Written per driver, the third one added is the one that
forgets, on a channel nobody on the team uses, discovered by a customer who could
not reply.

**The stored body is never changed.** What goes out on a plain channel and what
the transcript says are different questions: an operator reading the thread
afterwards should see the sentence the bot composed, not a rendering artefact of
whichever channel it happened to leave through.

---

## Incoming: one door

Widget, webhook, importer and test all arrive at `MessageRouter::receive()`. The
rules it enforces are each one line of code, and each would otherwise be
re-decided per channel by whoever wrote it — differently, and in good faith.

### A redelivered webhook must not produce a second answer

Providers redeliver on a schedule. One that did not get its 200 in time —
because the bot was thinking, because a worker was busy — sends the identical
update again, and again after that. Without a check, one question from the
customer becomes one stored message per attempt and one full bot turn per
attempt: three copies of the answer, three capability calls, three of whatever
the scenario does on the way.

The router looks for the same `external_id` in the same conversation and, on a
hit, **returns the message already stored** rather than null. That is what lets
the caller stay idempotent: the second webhook gets the same result as the first
and reports success, which is the only thing that makes a provider stop
retrying.

Two copies of one webhook can also be in flight simultaneously and both pass that
check before either inserts. The unique index on (conversation, external id) is
what actually decides; a violation is caught and turned into the same answer the
check would have given a second later. The SQLSTATE class `23` is matched rather
than the driver's message, which is translated on some MySQL builds and worded
differently on SQLite and Postgres.

### The write and the bot's turn are not one transaction

The bot sends things out of the building: a message to Telegram, a capability
that charges a card. A transaction wrapped around that would, on any later
failure, roll back the record of what was sent while the customer keeps the
message — leaving the journal saying it never happened and the next turn
repeating it.

### A blank field is filled; a filled one is left alone

Channels hand over whatever they happen to have on this request. Telegram sends
the profile name on every update; the widget sends whatever is currently typed
into its form. Both are frequently emptier than the row. An operator who
corrected a misspelled name, or a visitor who gave a phone number in the first
message and none in the second, would otherwise watch it be replaced by the
provider's version — or by nothing — on the very next line they wrote. Nothing
reports the loss, which is what makes it worth a rule: the thread simply stops
knowing how to call the person back.

Correcting a known value stays possible; it is an edit an operator makes, not
something an inbound payload does on its own.

Everything on an `InboundMessage` is a claim. The display name, the phone, the
e-mail are whatever the visitor or the provider said, recorded as claims and
never used to decide who somebody is —
[that is `identified_at`]({{ site.baseurl }}/extending#telling-the-package-who-this-person-is).

A payload with no text — a sticker, a location, a keep-alive — stores nothing
and does not open a thread or wake the bot.

### Finding the thread

By the provider's own conversation id where there is one, and by the visitor's
hashed token where there is not. The site widget is the second case: a browser
has no account and no chat id, and the token is the only thing tying today's tab
to the thread it opened yesterday.

---

## Outgoing: never inline

`Outbox::dispatch()` queues a `DeliverMessage` job. It never sends during the
request.

A provider answers in about a second when it is healthy and does not answer at
all when it is not — and the request that would be waiting is a visitor's own,
held open behind somebody else's outage while their browser spins. Queued, the
conversation is already saved and on screen; the network is a worker's problem.

The message row exists before delivery is attempted and keeps existing whatever
comes back. Speaking succeeded; delivering has not yet. A message with nowhere to
go — a channel whose package was removed — is marked failed with the channel
named in the reason, rather than dropped or left pending forever: both of those
look identical to an operator watching the thread, and only one of them is
visible on the row.

`DeliveryResult::failed($reason, retryable: false)` stops the retries for
failures that waiting cannot fix. Retry count is `chatbot.channels.delivery.tries`
(3 by default).

---

## Messenger accounts belong to a bot

A token is not a property of the deployment; it is the account one bot speaks
from. An installation running a sales bot and a support bot has two Telegram
bots, and config — which holds one token per installation — could express only
the first.

So credentials live in `chatbot_channel_connections`: one row per bot and
messenger, credentials encrypted as a whole, edited in the **Channels** tab on
the bot's own screen. What stays in config is what is the same for every account
on the installation — where the provider lives and how long to wait for it.

```php
'channels' => [
    'telegram' => ['api_url' => 'https://api.telegram.org', 'timeout' => 10],
    'max' => ['api_url' => 'https://platform-api2.max.ru', 'timeout' => 10],
    'vk' => ['api_url' => 'https://api.vk.com/method', 'timeout' => 10],
    'whatsapp' => ['api_url' => 'https://graph.facebook.com/v21.0', 'timeout' => 10],
],
```

### The address carries the account

Every provider delivers to `/chatbot/webhook/{channel}/{connection}`, where the
second segment is the connection's `public_id` — 32 random characters, generated
once and never regenerated. A provider's POST says nothing about which bot it
belongs to, so without it an installation could run one account per messenger
and no more.

It is also why an unknown address and a switched-off one answer the same 404: a
different answer for a real connection would tell whoever guessed it that they
had guessed right.

`public_id` is stable on purpose. Rotating it would point a live webhook at an
address that no longer exists, and nothing would say so until a customer wrote.

### What each provider needs, and who registers the webhook

| Messenger | Credentials | Authenticates by | Webhook registered |
|---|---|---|---|
| Telegram | bot token, webhook secret | `X-Telegram-Bot-Api-Secret-Token` | by the package, `setWebhook` |
| MAX | bot token, webhook secret | `X-Max-Bot-Api-Secret` | by the package, `POST /subscriptions` |
| VK | community token, secret key, confirmation string | `secret` inside the event body | by a person, in the community's settings |
| WhatsApp | access token, phone number id, verify token, app secret | `X-Hub-Signature-256` over the raw body | by a person, in the app's dashboard |

The two a person registers are not failures. Both providers take the address in
their own dashboard — VK shows a confirmation string that has to come back from
the server, Meta verifies by fetching the address once — so the button on the
settings screen answers with what to paste and where, and says so as information
rather than as an error.

### The quirks that are not optional

**VK wants a bare `ok`.** Not `{"result":"ok"}`, not an empty 200: the Callback
API compares the body, treats anything else as a failure and redelivers until
the community is paused. While the address is being added it wants the
confirmation string in the same way, through the same endpoint.

**WhatsApp draws at most three buttons, titles capped at 20 characters.** Past
either limit Meta refuses the whole message, so the driver sends the options as
a numbered list instead — the engine accepts a bare digit either way, and a
question with four answers stays answerable rather than arriving unanswerable.

**WhatsApp signs the bytes it sent.** The signature is computed over the raw
request body; re-encoding the decoded JSON changes spacing and key order, and
that is the usual way this check passes in tests and fails in production.

**MAX accepts two messages a second into one dialog** and refuses text over
4000 characters outright, so the driver truncates deliberately rather than
letting the provider drop a sentence the bot already committed to.

**A MAX button press leaves a spinner** until the bot answers the callback
through `POST /answers`, which this package does not do: the reply arrives a
moment later through the queue and clears it.

## Telegram

**No Telegram SDK, deliberately.** What the driver needs is one POST to
`sendMessage` and one shape of JSON back. Every package that wraps the Bot API
also brings its own migrations, its own models for bots and chats, and its own
opinion about where a conversation lives — all of which this package already has,
differently. Installing one would mean maintaining a translation between two
conversation tables forever, in exchange for a helper around a single HTTP call.

The chat id is the conversation's `external_id`, written by the webhook when the
thread was opened. A thread without one cannot be answered at all, and the driver
says so rather than letting the Bot API return an error about a missing
parameter.

### The webhook authenticates by secret header

The endpoint is `POST /chatbot/webhook/telegram/{connection}`. Its URL is public
and unguessable only for as long as nobody guesses it, so what actually
authenticates the caller is the secret header Telegram attaches to every update
when the webhook is registered with one. Without it, anyone who learns the path
can post a message that appears in the desk as coming from a customer, with a
chat id of their choosing.

**An unconfigured secret refuses everything.** The tempting reverse — skip the
check when no secret is set — would make a forgotten setting into an open
endpoint writing into the customer desk, and it would work perfectly in testing,
which is exactly when the setting is most likely to be missing.

The comparison uses `hash_equals`, so it takes the same time whatever the wrong
value was; a plain `===` leaks the correct prefix to anyone patient enough to
measure.

Anything that gets past the secret gets a 200, including updates the package
makes nothing of. Telegram treats any other status as a failure and redelivers
the same update on a schedule, so answering a sticker with an error would produce
an endless retry of something no version of the code will ever accept.

### `parse_mode` is empty on purpose

Telegram rejects the whole message when its markup does not parse, and a
scenario's text carries whatever the conversation collected — a name with an
underscore in it, a reference number in brackets. A parse mode turns somebody
else's data into a delivery failure. Set it only if you format your messages
deliberately and escape accordingly.

---

## The site widget

![The widget on a page that is standing in for a customer's own site]({{ site.baseurl }}/assets/widget-on-a-site.png)

A widget is a record you create in the panel, under **Widgets**. It names the
bot that answers, the sites it may be embedded on, and how it looks. The panel
hands you the tag; you paste it before `</body>`:

```html
<script src="https://app.example.com/chatbot/widget.js" async
        data-widget="main-site"></script>
```

### One bot, several widgets

![The widget's own screen: which bot answers, and what a visitor may do]({{ site.baseurl }}/assets/widget-basics.png)

That is the reason a widget is its own record rather than a tab on the bot.

A bot is a personality: what it knows, which scenarios it walks, which model
thinks for it. A widget is a placement: where it is embedded, what colour it is,
what the launcher says. The same support bot belongs on your main site in your
blue and on a partner's portal in theirs, with a different launcher label and a
different list of sites allowed to embed it — one bot, two widgets.

It also decides what travels onto somebody else's page. The tag carries an
opaque widget key and not the bot's name, so **which bot answers is a setting on
your side**: you can point a widget at a different bot without asking anybody to
edit their website.

### What the empty values mean

Three settings look unset and are not. Each empty is a decision the runtime
acts on.

- **No bot** — every conversation from this widget goes straight to a person.
  That is how a small support desk runs, not an unfinished configuration. A
  widget whose bot has been switched off behaves the same way.
- **Widget switched off** — the tag stays on the page and the chat stops
  opening. A message that still arrives is not thrown away: the conversation
  opens and goes to a person. Losing what somebody typed over a configuration
  mistake they had no part in is worse than answering slowly.
- **No sites listed** — the widget belongs wherever the installation allows.
  This list *narrows* [the installation-wide allow-list](#the-origin-allow-list),
  it does not replace it; that list is the guard that fails closed.

### Attributes

Everything except `data-widget` is optional, and everything optional overrides
the widget's own settings. That direction is deliberate: the tag is written by
whoever maintains the page it sits on, so a colour chosen in the panel never
silently repaints a widget a site owner has already matched to their design —
and every embed written before widgets existed keeps working exactly as it does
today.

| Attribute | Default | What it does |
| --- | --- | --- |
| `data-widget` | — | the widget's key; everything else follows from it |
| `data-bot` | — | **older spelling**, still honoured: a bot's slug resolves to that bot's first widget |
| `data-title` | from the widget | the panel's heading |
| `data-greeting` | from the widget | a line shown before the first message |
| `data-placeholder` | from the widget | the input's placeholder |
| `data-launcher` | from the widget | the closed launcher's label |
| `data-color` | from the widget | the accent colour |
| `data-position` | from the widget | `left` or `right` |
| `data-prefix` | `chatbot` | prefix for both the localStorage keys and the CSS class names, so two widgets on one page do not collide |
| `data-auth-id` | — | your own id for this person |
| `data-auth-sig` | — | HMAC-SHA256 of that id under the shared secret |
| `data-auth-name`, `data-auth-phone`, `data-auth-email` | — | contact details, accepted only with a valid signature |

### How it looks

![Colours and shape, with the preview redrawing as they change]({{ site.baseurl }}/assets/widget-appearance.png)

Colours, corner radii, panel width, the launcher's shape, the bot's face and the
bubble style are set on the widget and fetched by the script before it draws
anything — its own endpoint rather than a field on the first history call,
because the launcher is on screen before any conversation exists and it is the
launcher that carries the colour.

The theme is asked for and applied, never awaited. A widget that waited would be
absent for as long as the request takes — on a slow connection, long enough for
a visitor to decide the site has no chat — and if the request never answered it
would never appear at all.

**Bubble styles.** Four, and only two of them are about corner radius:

| Style | What it does |
| --- | --- |
| `rounded` | plain rounded corners, every message on its own |
| `grouped` | consecutive messages from one speaker pulled into a block, inner corners cut |
| `ios` | the same grouping, plus a tail on the last bubble of a run |
| `square` | the same arrangement as `rounded` with the corners taken off |

`grouped` and `ios` are one arrangement written once, differing in the tail
alone. The grouping is what does the work — it is what shows a reader where one
turn ends and the next begins — so the tail on top of it is a matter of taste.

**The launcher** is either a `pill` carrying its label or a bare `circle`. The
circle hides the words rather than dropping them, so the button still announces
itself to a screen reader, which cannot see that it is a speech bubble.

Which style a message belongs to is decided on the server and written onto the
element, not left to CSS sibling selectors. Messages arrive one at a time from a
poll, and a rule that styled a bubble by what follows it would restyle the one
already on screen the moment the next arrived — a corner changing shape under a
message somebody had finished reading.

**Every value is checked before it is used.** A theme is written by whoever can
edit a widget and rendered inside a `style` attribute on a customer's page, so
colours are hex only — `var(--x)`, `url(...)` and `calc()` are all valid CSS in
that position and each is a way out of the property it was written into — sizes
are clamped, and an avatar is accepted only as an absolute `https` address. A
relative path there would point at the customer's own server and resolve to
their 404 page.

### What a visitor may do

Two switches, set per widget rather than per installation, because the answer
differs by where the widget is: a support page and a public landing page are not
the same room.

| Switch | Default | What it does |
| --- | --- | --- |
| Emoji picker | on | a short list beside the message box |
| Files from visitors | **off** | a paper clip beside it |

![The emoji picker open beside the message box]({{ site.baseurl }}/assets/widget-emoji.png)

The emoji list is written into `widget.js` rather than fetched. The file carries
no dependencies by design — it lands next to whatever framework the site already
loads — so an emoji library is not on the table, and a full palette without one
is a searchable, paginated, skin-toned thing that is a product of its own. Thirty
characters cover what somebody reaches for mid-sentence; the rest is already on
the keyboard of every phone that will open this widget.

The desk has its own copy of the same list, and the switch does not govern it:
staff always have it.

### Files

**Off unless you turn it on.** This is the application taking a file from an
anonymous stranger and keeping it, which is a decision rather than a default.

A file rides along with the message that carries it. There is no upload endpoint
beside it, and that is the point: a standalone upload would create a row owned by
a token, waiting for a send that may never come, sweepable only by a schedule a
package cannot assume the host runs. Riding along, a file inherits the origin
allow-list and the send rate limit without either being written down twice.

What is accepted, and how it is judged:

- **An allow-list of types, never a block-list** — a block-list is always
  incomplete. It is `chatbot.attachments.mimes`, and `image/svg+xml` and
  `text/html` are deliberately absent from it: an SVG is a script, and it would
  run on *this* application's origin.
- **The type is read from the bytes**, never from the extension and never from
  the `Content-Type` the browser announced. Both of those are written by whoever
  is sending the file.
- **The size ceiling is `chatbot.widget.max_attachment_kb`**, one number for both
  ends. An operator and a visitor attaching the same file to the same thread must
  succeed or fail together, or a reply lands in a conversation the other side
  cannot answer in kind.

Where it goes:

```php
'attachments' => [
    'disk' => env('CHATBOT_ATTACHMENT_DISK', 'local'),
    'path' => 'chatbot/attachments',
    'mimes' => [ /* the allow-list */ ],
],
```

**Never a public disk by default.** A visitor attaches a passport scan and an
invoice to a support chat; those must not sit at a guessable address. The name on
disk is random and the sent name lives only in the database — a file called
`passport-scan.pdf` should not be findable by asking for `passport-scan.pdf`.

Serving is by the file's own opaque id, and the conversation it belongs to comes
from the visitor's token rather than from the request. A file from another thread
answers **404 and not 403**: a 403 confirms that the id exists, which is the one
thing the request was fishing for. Operators fetch the same file through the
panel, behind the policy on that conversation.

Responses carry `X-Content-Type-Options: nosniff` and are served `inline` only
for the handful of raster image types; everything else downloads.

### The preview

The widget's form shows a live preview beside it, and what it draws is the real
`widget.js` on a stand-in page. Only the server is faked.

That is worth stating because the alternative is tempting and wrong: a preview
that drew its own approximation of a widget would be a second implementation of
how the widget looks, and it would drift from the first. A preview that lies is
worse than no preview.

The embed code is on its tab beside it, with this installation's own address and
this widget's own key already in it — and it appears only while a tag copied
from it would genuinely open a conversation. Handed over while the widget is off
or no origin is allowed, it is a promise kept by a 403 on somebody else's page:
it installs cleanly, looks right in the source, and fails where neither of you is
watching.

![The embed code, with the real address and key]({{ site.baseurl }}/assets/widget-embed.png)

The file is vanilla, unbundled, and one file. It runs on somebody else's site
next to whatever framework they already load, and every dependency it carried
would be a version conflict waiting to happen. It also means whoever is asked to
approve putting it on their site can read it — which is a real conversation, and
it goes better when the answer is "open it".

### The API address is derived, not configured

The widget strips `/chatbot/widget.js` off its own `src` and talks to what is
left. Asking the site to configure a URL as well means the two can disagree, and
when they do the widget loads perfectly and talks to nothing.

### The visitor token

The first message a visitor sends earns them a 64-character token from Laravel's
CSPRNG. It comes back once in that response, the widget keeps it in the embedding
page's `localStorage`, and every later request carries it in the
`X-Chatbot-Token` header.

**A token, not a cookie.** The widget runs on somebody else's domain, so a cookie
set here would be a third-party cookie: blocked outright by Safari and Firefox,
being removed by Chrome, and requiring `SameSite=None` on top. A conversation
that survives a reload for some visitors and not others is worse than one that
never survives, because nobody can reproduce it.

**A header, not a query parameter.** URLs end up in access logs, referrer headers
and browser history, and this is a credential.

**Only the sha256 is stored.** The column is looked up on every widget request,
so it is an index over live credentials: a leaked backup, a query accidentally
logged, a screenshot of a database client would each hand the reader everything
needed to resume other people's conversations. Hashed, the same leak is
worthless — and nothing is lost, because the server never needs to send a token
back to anyone who does not already have it. The hashing happens at the edge that
received the token, so the raw credential never travels further into the
application than the request that carried it.

A missing token is an ordinary state, not an error: every visitor starts without
one.

### Identity: unsigned is ignored

Everything in the `identity` block arrives from a script running on a page this
application does not control, where the visitor can edit it before it is sent.
Taken at face value, "who this is" would be whatever the visitor typed into their
own console.

So the host signs on its own server — the only part of the round trip the
visitor cannot reach — and signs **every field it is asserting**, not just the
id:

```php
use MB\FilamentChatbot\Channels\WidgetIdentity;

$signature = WidgetIdentity::sign(config('chatbot.widget.identity_secret'), [
    'id' => (string) $user->id,
    'name' => $user->name,
    'phone' => $user->phone,
    'email' => $user->email,
]);
```

```blade
<script src="{{ url('/chatbot/widget.js') }}" async
        data-widget="main-site"
        data-auth-id="{{ $user->id }}"
        data-auth-sig="{{ $signature }}"
        data-auth-name="{{ $user->name }}"
        data-auth-phone="{{ $user->phone }}"
        data-auth-email="{{ $user->email }}"></script>
```

**Signing the id alone was the earlier scheme, and it was a hole.** A visitor
reads their own valid pair out of their own network tab, puts a stranger's phone
number beside it, and the pair still verifies — the number is then recorded as
*proved*, a contact directory files the thread against that stranger's record,
and every resource scoped on the conversation's contact answers with their data.
One signature, obtained legitimately, opened every account whose phone number its
holder could guess.

Use `WidgetIdentity::sign()` rather than writing the hash by hand: it is the
same function the server verifies with, and two descriptions of one signature in
two applications diverge on the first field somebody adds.

An unsigned identity is ignored entirely, and so is one whose signature does not
check out. **With no `identity_secret` configured there is nothing to check
against, so nothing is accepted** — the same direction as the empty origin list
below, and for the same reason.

A verified identity is also the only thing that marks a conversation as
identified, which is what the `identified` condition branches on. A phone number
typed into the box is a claim, however plausible it looks.

### The origin allow-list

```php
'widget' => [
    'enabled' => true,
    'origins' => [ /* from CHATBOT_WIDGET_ORIGINS */ ],
    'identity_secret' => env('CHATBOT_WIDGET_SECRET'),
    'token_ttl' => 600,
    'max_attachment_kb' => 10240,
    'rate_limits' => [ /* ... */ ],
],
```

Usually you never touch this file. The allow-list is read from one environment
variable, comma-separated, because it is the setting that genuinely differs
between every environment you have:

```
CHATBOT_WIDGET_ORIGINS=https://shop.example.com,https://www.example.com
```

Blank entries are dropped, so a trailing comma is harmless. Publishing the
config and writing the array by hand works too, and wins.

`enabled` is checked before anything else on every widget endpoint, so switching
it off refuses them all with a stated reason rather than leaving a launcher that
opens onto errors.

There is no `class_prefix` or `storage_prefix` setting. The embed's
`data-prefix` attribute does that job, because the clash it exists to resolve —
a site already using `.chatbot__` for something of its own — belongs to the
embedding site, and one value here could not be right for every site that embeds
the widget.

**An empty list blocks everything.** The opposite default would make a forgotten
setting into an open endpoint: anyone could embed the widget on their own site,
open conversations here and spend this installation's model budget having them
answered — and it would look exactly like ordinary traffic in the panel. A
package cannot know which sites are yours, so the only safe thing it can assume
is none.

**Two lists, and they combine rather than compete.** This one says which sites
may reach the widget endpoints at all and fails closed. A widget's own list of
sites narrows it further — which of those pages carry *this* widget — and an
empty one narrows nothing. One application serving a shop and a helpdesk from a
single panel is the ordinary case, and without the second list the shop's widget
would answer on the helpdesk's pages to anybody who copied the tag across.

Matching is exact on scheme, host and port together. Not a host comparison and
not a suffix match: `https://shop.example.com` and `http://shop.example.com` are
different origins to the browser and must be different here, or an allow-list
entry quietly covers the plaintext version of the site as well. Suffix matching
would be worse still — `example.com` would admit `example.com.attacker.net`.

The response echoes the one origin that was allowed, never a wildcard, and sends
`Vary: Origin` with it. Without `Vary`, a shared cache can serve the headers
computed for one site to a request from another, turning a correct allow-list
into an accidental one.

### Why CSRF does not apply, and what replaces it

These routes are loaded without the `web` group. Every one of them is called by
something that is not a browser sitting on this application's own pages — a
widget on a customer's domain, Telegram's servers — and the `web` group would
give all of them a session cookie they cannot use and a CSRF check they cannot
pass. There is no session to put a token in and no server-rendered form to read
one from.

Two things stand in its place: **the allow-list says which sites may ask at
all**, and **the visitor token says which conversation the asker owns.** A forged
cross-site request is therefore stopped by not being on the list — not by a token
the browser would happily have attached anyway.

Requests also use `credentials: 'omit'`, so no ambient cookie travels with them
in either direction.

### A request with no Origin header

Browsers omit `Origin` on a same-origin GET. A widget embedded on the application
that serves it — a marketing page of the same Laravel app — would otherwise be
refused outright: a 403 in the console and a launcher that opens onto nothing.

So a request with no `Origin` is allowed **only when the `Referer` host matches
the request's own host.** That is safe because the reverse never happens: a
cross-site `fetch` always carries an `Origin`, and a cross-site GET that omits
one — an `<img>`, a `<script>` — cannot read the reply. Anything else arriving
without an Origin is refused: a server, a script or a crawler, none of which the
allow-list can vouch for.

The host is compared against the request's own host rather than `app.url`,
because the two disagree on every installation reached by more than one name — a
staging alias, an internal hostname, a domain being migrated — and disagreeing
here would break the widget on the second name only, which is the hardest kind of
fault to be told about.

### Preflight

The browser asks permission before sending a custom header, on the same path with
`OPTIONS`. Without a route to match, that preflight is a 404 and every widget
request fails before it is made, with nothing in the log but a missing route
nobody wrote. The route exists, points at a controller method rather than a
closure (a host running `route:cache` cannot serialize a closure, and the failure
would appear as a deploy command refusing to run), and the middleware answers it
before the controller is reached.

### Rate limits are per endpoint

```php
'chatbot-site-history' => 120,
'chatbot-site-send'    => 20,
'chatbot-site-read'    => 60,
'chatbot-site-typing'  => 60,
'chatbot-webhook'      => 300,
```

Polling for history happens every few seconds and legitimately, while sending a
message is how a conversation is created and how the model budget gets spent. One
shared ceiling would have to be set high enough for the first, which leaves it
uselessly high for the second.

The limiter is keyed **by the visitor's token where there is one, and by IP
otherwise.** IP alone is wrong in both directions: an office, a school or a mobile
carrier puts thousands of different people behind one address, and one chatty
visitor there would lock out everybody else — meanwhile anyone who actually wants
to flood the endpoint changes address more easily than anything else. The token
is what a real conversation has and a flood does not. It is hashed into the key,
because limiter keys reach the cache, and a shared Redis holding live credentials
in its key names is the same leak the `visitor_key` column exists to avoid.

---

## Serving the widget files

`GET /chatbot/widget.js` and `GET /chatbot/widget.css` serve from the package,
not from `public/`.

Publishing would put one more step between installing the package and a working
chat — and, worse, a step nobody repeats after an upgrade, leaving a stale widget
talking to a newer endpoint.

These two routes sit outside the origin allow-list and send
`Access-Control-Allow-Origin: *`. This is a public script tag, identical for
every site, so there is nothing to leak by letting any of them fetch it — and
refusing would break the widget on exactly the pages it is for. The allow-list
guards the endpoints that carry a conversation.

### An hour with a validator, not "forever"

`public, max-age=3600` plus an ETag and Last-Modified, with conditional requests
answered.

`immutable` would be right if the URL carried a version, and it cannot: the host
writes this URL by hand into a script tag on a page the package will never see,
so there is nothing to bump on an upgrade. A year-long immutable cache against a
fixed URL means a browser that fetched the widget once is never asked again — an
upgrade reaches nobody, and the symptom is a widget from last year talking to
this year's endpoints. An hour plus an ETag costs one conditional request and
cannot get stuck.

A missing file is a 404 rather than an exception. The one way that happens is an
installation whose vendor directory was pruned, and a stack trace inside a script
tag on a customer's marketing page is a worse outcome than a script that quietly
does not load.
