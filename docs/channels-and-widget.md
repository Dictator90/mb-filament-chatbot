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

## Telegram

```php
'telegram' => [
    'enabled' => false,
    'token' => env('CHATBOT_TELEGRAM_TOKEN'),
    'secret_token' => env('CHATBOT_TELEGRAM_SECRET'),
    'api_url' => 'https://api.telegram.org',
    'timeout' => 10,
    'parse_mode' => '',
],
```

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

The endpoint is `POST /chatbot/webhook/telegram`. Its URL is public and
unguessable only for as long as nobody guesses it, so what actually
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

One script tag on any page:

```html
<script src="https://app.example.com/chatbot/widget.js" async
        data-bot="support"
        data-title="Support"
        data-launcher="Chat with us"
        data-color="#4f46e5"></script>
```

### Attributes

| Attribute | Default | What it does |
| --- | --- | --- |
| `data-bot` | — | the bot's slug; picks which bot answers |
| `data-title` | `Chat` | the panel's heading |
| `data-greeting` | — | a line shown before the first message |
| `data-placeholder` | `Write a message` | the input's placeholder |
| `data-launcher` | `Chat with us` | the closed launcher's label |
| `data-color` | `#4f46e5` | the accent colour |
| `data-position` | `right` | `left` or `right` |
| `data-prefix` | `chatbot` | prefix for both the localStorage keys and the CSS class names, so two widgets on one page do not collide |
| `data-auth-id` | — | your own id for this person |
| `data-auth-sig` | — | HMAC-SHA256 of that id under the shared secret |
| `data-auth-name`, `data-auth-phone`, `data-auth-email` | — | contact details, accepted only with a valid signature |

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

So the host signs its own id on its own server — the only part of the round trip
the visitor cannot reach:

```php
$signature = hash_hmac('sha256', (string) $user->id, config('chatbot.widget.identity_secret'));
```

```blade
<script src="{{ url('/chatbot/widget.js') }}" async
        data-bot="support"
        data-auth-id="{{ $user->id }}"
        data-auth-sig="{{ $signature }}"
        data-auth-name="{{ $user->name }}"
        data-auth-email="{{ $user->email }}"></script>
```

An unsigned identity is ignored entirely, and so is one whose signature does not
check out. **With no `identity_secret` configured there is nothing to check
against, so nothing is accepted** — the same direction as the empty origin list
below, and for the same reason.

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
