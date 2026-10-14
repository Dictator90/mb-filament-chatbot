# Changelog

All notable changes to `mb4it/filament-chatbot` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this package intends to follow [Semantic Versioning](https://semver.org/) once
it reaches 1.0.

## [0.9.2] — 2026-10-14

- **A choice the slot offers is never read as "skip".** A button labelled
  «Не знаю» on an optional question — "don't know whether the passport is
  valid", which is an answer — matched the skip words of 0.9.1 and was thrown
  away.

## [0.9.1] — 2026-10-14

- **An optional question in a form can be skipped.** It carries a "Skip"
  button unless it is a choice (a choice has its own way out), and a typed
  "don't know" or «не скажу» skips it at once. Before, the answer went to the
  parser, the parser found no amount, and the bot asked again — arguing with a
  visitor who had just declined to name a budget — while a free-text slot took
  "don't know" as the answer and the manager read it as the visitor's wish.
  The words live in `chatbot::bot.form.skip_words` and match whole answers only.

## [0.9.0] — 2026-10-14

- **Placeholders accept any letter.** A set node writing `интерес` or a lookup
  writing into `поездка` produced names the old `[A-Za-z]` pattern never
  matched, and such a placeholder was not left empty — it was not touched at
  all: the visitor received «Нашёл вот что: {{поездка_hotel}}» from a sentence
  that looked finished on the canvas.
- **`{{name|fallback}}`** prints literal text when the value is blank. A summary
  the visitor is asked to confirm must not show an empty line where an
  unanswered question was; «Бюджет: не уточнено» is the truth, «Бюджет: » reads
  as a fault. The fallback is never itself expanded.
- **A choice slot inside a form is asked with buttons.** The form said the
  question as bare text, so a messenger that draws buttons everywhere else drew
  none for "beach, sightseeing or both?" and the visitor had to type the word
  exactly. A pressed button arrives as its position, which the parser already
  read.

## [0.8.3] — 2026-10-14

- **The desk no longer makes its own page scroll.** Filament renders two empty
  containers after a page's content, and the page's flex gap put 2rem in front
  of each — 4rem of nothing under the desk, on every screen. The gap is removed
  on the desk's own page, and the default offset is now the measured `8rem`
  (topbar plus the page padding above and below) rather than `7rem`, which left
  the page a few pixels taller than the window: invisible on a desktop, a
  scroll that hid the conversation header on a phone.

## [0.8.2] — 2026-10-14

- **The desk's height subtracts a variable, `--chatbot-inbox-offset`.** A panel
  with a fixed bottom navigation on phones made the page taller than the
  screen: the desk still asked for the whole viewport, the page scrolled, the
  conversation header slid under the topbar and an empty band opened above the
  bottom menu. On a desktop it looked perfect, which is why nobody saw it. The
  host knows its chrome and sets the variable; the default is the old `7rem`.

## [0.8.1] — 2026-10-14

- **A way back to the list on narrow screens.** The list of conversations is
  hidden while one is open, and there was no way out of a thread short of
  reloading the page.

## [0.8.0] — 2026-10-14

### A name is a name, and a confirmation has buttons

- **`SlotKind::Name`, and the built-in `name` slot uses it.** Almost nobody
  answers "как вас зовут?" with one word: they answer «меня зовут Ирина», «я
  Пётр», "it's Nina". A free-text slot kept the sentence, so the bot's next
  line read «Меня зовут Ирина, оформляю заявку на подбор» and the same string
  went into the customer's card. Nothing is ever rejected: a trim that leaves
  nothing hands the original back.
- **The approval node offers its answers as choices.** It used to print
  "1. Да, оформляйте / 2. Нет, поправлю" as plain text, so a messenger drew no
  buttons; the visitor answered in words — «Оформляйте. Вы можете отель
  подобрать?» — and was told the answer was not understood. The labels now
  travel as the message's options, so Telegram draws buttons and a channel
  without them still gets the numbered list, from itself.

#### Found while fixing the first of those

`trim($value, " …—–-")` cuts **bytes**, not characters: an em dash in that list
is three bytes, one of which is the second byte of Cyrillic «р». «Пётр» came
back a broken string, `preg_split` then answered `false`, and the parse fell
back to the whole sentence — the very thing it was written to avoid. Edges are
stripped with a `/u` expression now.

## [0.7.1] — 2026-10-14

- **Anthropic is no longer sent a temperature nobody asked for.** Newer models
  refuse the whole request over it — `temperature is deprecated for this model`,
  status 400 — and the conversation took the node's second exit, so the bot went
  quiet over a setting nobody had touched. Caught by a live call from a
  production server minutes after the driver shipped. A temperature named in the
  request or written into the driver's config is still sent.

## [0.7.0] — 2026-10-14

### Anthropic as a driver, and a proxy for model calls

- **`anthropic` speaks the Messages API directly.** Not the OpenAI-compatible
  endpoint: the instruction is a `system` field of its own, the answer arrives
  as content blocks, and the token counts have different names. Routing that
  through a compatibility layer means debugging two translations whenever an
  answer is wrong, and the request itself is four fields.
- **`proxy` on an AI driver**, with `chatbot.ai.proxy` as the fallback. A
  network that cannot reach the provider fails as a silent timeout, which reads
  on screen as the model being slow rather than as a closed route — the same
  symptom, and the same cure, as on a messenger connection.
- **The two HTTP drivers now share one decoder.** Composing the instruction,
  asking for JSON, stripping a fenced code block, reading the sources back and
  pricing the tokens were about to exist twice; the copy is what drifts, and
  the fence-stripping rule in particular was learned from live models once
  already.

## [0.6.0] — 2026-10-14

### The desk's contact line, and a knowledge base you can actually search

- **Linking and unlinking moved to the contact line itself**, as small text
  buttons, with **Unlink** beside them. In the row of conversation actions they
  read as actions on the whole thread, next to "take it" and "close".
- **Unlinking is possible without picking a replacement.** A thread pointing at
  the wrong person is worse than one pointing at nobody: everything above it —
  the name, the history, the card — is then about somebody else.
- **The knowledge list shows the answer and searches it.** A base of two
  hundred topics is searched by what the bot says, not by what the topic is
  called: "где-то про возврат за сутки" while the heading reads "Отмена брони".
  Category is searchable too, and priority is a column you can switch on.
- **Keywords are searched as JSON elements, not with `like`.** The obvious
  `like '%адрес%'` never matched anything: PHP escapes non-ASCII into the JSON
  column as `ад…`, so a Cyrillic keyword is not in the stored text at
  all. A keyword now matches whole, which is how somebody searches for a word
  they put there themselves.
- **Priority is chosen from a list instead of typed.** A free string collected
  "срочно", "P2 " and "2" in one column, and nothing could be filtered by it.
  A value that arrived through import and is outside the list is kept and shown
  rather than silently dropped — and there is still no enum cast on the column,
  because the import writes it and `from()` would take the screen down over one
  unfamiliar word.

## [0.5.0] — 2026-10-14

### A mislinked thread can be re-pointed, and the header stops hiding a line

- **`ContactPicker`, and a *Link to someone else* button on the desk.**
  Recognition by phone number is wrong often enough to matter — the number
  belongs to a husband, a colleague, or whoever had it before — and the
  operator's only choices were answering a customer under somebody else's
  history or losing the link along with the history. Searching stays with the
  host, which is the only side that knows what its records are called.
- **The thread header no longer clips its own third line.** The stage is a flex
  column with `overflow: hidden`, and the header's explicit `min-height` turned
  from a floor into a target: a thread with a contact card has three lines, the
  header was squeezed back, and the channel, status and phone number were
  simply not drawn. `flex: none` keeps them.

## [0.4.0] — 2026-10-14

### Files reach Telegram, pressed buttons stop lying, and people can be filed

- **An attached file is sent as a file.** An operator attaching a photograph or
  a contract from the desk sent the customer a line of text with the file's
  name on it — `XXXL.webp` — and the transcript said it had been delivered.
  Pictures go as photos, everything else as documents, uploaded rather than
  linked so that a private disk stays private. Text becomes the caption when it
  fits in 1024 characters and is sent first when it does not.
- **A pressed button loses its keyboard.** Telegram keyboards never expire: the
  answer is the option's position, and on yesterday's menu position two means
  something else entirely. Pressing "call a person" there made the bot answer
  that it could not find that in the knowledge base. The callback is answered —
  which stops the spinner too — and the buttons are taken away.
- **`ContactRegistrar`, and a *File this person* button on the desk.**
  `ContactDirectory` can only recognise somebody already in the host's
  directory; a visitor who writes for the first time, leaves a name and a phone
  number and is handed to a person was filed nowhere. Binding the new contract
  is optional, the threshold stays with the host, and `null` from `register()`
  means "not created" rather than "failed".

## [0.3.1] — 2026-10-14

### A form node could end the conversation mid-question

- **`slots` is read in both shapes a graph can hold.** The settings panel
  writes plain keys; a graph from a seeder or an import may hold
  `{"slot": "phone", "required": true}`. The old reading cast that array to a
  string, and the fatal landed inside the bot's turn: the visitor's
  conversation stopped after the node before it, with no reply and nothing on
  screen to explain it. The same reading draws the node's variable names, so
  the editor went down with it.
- **`required: false` now means what it says.** An optional slot nobody can
  answer no longer takes the "could not" exit — the form stops asking for it
  and carries on, and does not come back to it later in the same form.
- **The poller survives a message it cannot hand on.** One throwing step used
  to end the process; systemd restarted it, the same update was fetched again,
  it threw again, and every bot on the installation went quiet over one node in
  one graph. The reason is written onto the connection and the cursor advances
  past it, because an update that is refetched for ever buries the questions
  queued behind it.

## [0.3.0] — 2026-10-14

### Polling, for networks the provider cannot reach

- **`chatbot:poll` fetches what is waiting**, for connections switched to the
  new *How messages arrive → Polling* mode. The case is a webhook that is
  registered, resolved and never delivered: a Russian host's inbound from
  Telegram is dropped on the way, so `getWebhookInfo` reports a timeout while
  the server's access log has no entry at all. A proxy cannot help, because a
  proxy of ours is only in the path of calls this application makes.
- **Updates are decoded by the code the webhook uses**, wrapped back into a
  request. Fetched and delivered updates are the same object, and a second
  decoder would drift on the first fix — buttons being the half that breaks
  first and says nothing.
- **The cursor lives on the connection**, saved only after the batch has been
  handed on. An interrupted pass refetches and the router drops the duplicates;
  the other order loses the question instead.
- **A webhook in the way is removed rather than reported.** Telegram refuses
  `getUpdates` while one is registered, and an installation switching to
  polling has one by definition. Pending updates are kept.

## [0.2.0] — 2026-10-14, with the proxy in [0.2.1]

### Messengers belong to a bot

- **Four channels: Telegram, MAX, VK and WhatsApp.** Each one receives, answers,
  and draws the scenario's options as buttons where its provider allows them —
  WhatsApp falls back to a numbered list past three options or a 20-character
  title, because Meta refuses the whole message over either and the question
  would arrive unanswerable.
- **Credentials moved out of config and into `chatbot_channel_connections`**,
  one row per bot and messenger, encrypted as a whole and edited in a
  **Channels** tab on the bot's own screen. A token identifies an account and an
  account answers as one bot: an installation running sales and support
  separately has two Telegram bots, and config could hold only one token.
- **Webhooks now carry the account**: `/chatbot/webhook/{channel}/{connection}`,
  where the second segment is 32 random characters generated once. A provider's
  delivery says nothing about which bot it belongs to, so the address has to.
- **The settings screen can check the connection and, where the provider has an
  API for it, register the webhook.** VK and WhatsApp are registered by a person
  in somebody else's dashboard, and the button says what to paste and where
  rather than reporting a failure that is not one.

- **A proxy per account, for networks that cannot reach a provider at all.**
  A Russian host calling Telegram's API is the case: the call fails as a
  ten-second silence rather than a refusal, which reads on screen as a fault in
  this package rather than as a blocked route. The field sits on the connection
  and applies only to calls this application makes — the webhook travels the
  other way, and an installation whose inbound is blocked too needs polling,
  which this package does not do.

#### Breaking

- `POST /chatbot/webhook/telegram` is gone, and with it
  `TelegramWebhookController`. Create a connection on the bot's Channels tab and
  register the new address with `setWebhook` — the button on that tab does it.
  `CHATBOT_TELEGRAM_TOKEN` still answers for threads that have no connection
  behind them, so an installation keeps delivering until it is moved over.

## [0.1.0] — 2026-09-14

The first tagged release. Everything below shipped in it.

### Conversations

- Threads on any channel, with a bot that answers and a person who can take over.
  A thread is never started twice: the pair of channel and external id is unique,
  so somebody writing again six months later lands back in their own history.
- Contact and subject are nullable morphs the package writes, indexes and hands
  back, and never looks inside. That is what lets the same package serve a shop
  whose contacts are customers and a helpdesk whose contacts are assets.
- An operator desk: every conversation on every channel in one list, with search,
  filters, stock phrases and an unread badge counted per person. Answering takes
  the conversation over and silences the bot; "give back to the bot" undoes both
  halves. Quality-check rehearsals never reach it.

### Scenarios

- Twenty-six kinds of node, walked one turn at a time by an engine that decides
  where the conversation goes while each node only reports what it did.
- `call` and `result`: a scenario can be called from another and return a value,
  so shared work is written once instead of copied and left to drift.
- Publishing freezes the graph together with the data resources, capabilities
  and sub-scenarios it depends on, and refuses two things outright: a write
  reachable without an approval node above it, and a loop with no finite bound.
- A run journal that records every node walked, why each branch was taken, and
  what each one wrote.

### Teaching it about your application

- **Data resources** — declare a model, its approved fields and a ceiling, and
  the bot can read your records with no code at all. Unknown fields, operators
  outside the allow-list and limits above the maximum are refused rather than
  trimmed; access scopes come only from a server-side context and never from the
  conversation.
- **Capabilities** — register an action with an input schema and a handler.
- **Your own node** — one PHP class. Its settings panel is drawn from field
  descriptors declared in PHP, so a host node needs no JavaScript and no rebuild.
- The same applies to conditions, slots, variable sources, channel drivers, AI
  drivers and installation checks.

### Editor

- A canvas built on React Flow, shipped prebuilt, loaded only on the screen that
  draws a graph.
- Problems are marked on the node and explained in the panel next to it, in
  sentences rather than codes.

### Channels

- A site widget in one script tag: vanilla, unbundled, served by a route rather
  than published so that installing the package is enough.
- Telegram, without a dependency on any Telegram library.
- Channels that cannot draw buttons get the options as a numbered list, and the
  engine reads a bare digit back as the option it names.

### AI

- Off by default. Every scenario has to remain useful with the model switched
  off: a missing key, an outage and an exhausted budget all take the same
  "could not" branch.
- Drivers for any OpenAI-compatible endpoint and for `laravel/ai` when it is
  installed, plus whatever you register yourself.
- An answer from a model is only sent when it cites material it was given.

### Operations

- `chatbot:doctor` checks an installation and exits non-zero on a failure, so it
  belongs in a deployment pipeline.
- `chatbot:quality` replays saved conversations against the bot and tells a
  failed expectation apart from a check that could not run at all.
- Monthly token and cost budgets, reserved before the request rather than
  counted after it.
- Authorisation deferred to the host: the package asks, and allows while the
  host has no opinion.

### Documentation

- A full site under `docs/`, published with GitHub Pages.
- `chatbot:demo-data` and `chatbot:demo` seed a working bot and hold a
  conversation with it, so the second thing you do after installing is watch it
  answer.

### Found by installing it somewhere real

The package was installed into an unrelated Laravel 13 application and driven
through a browser. Four things only that could show, and each is now covered by
a test that fails without the fix:

- Panel screens ask `Filament::auth()`, never `auth()`. A panel may sign people
  in on a guard of its own, and against such a host `auth()` answers `null` while
  an operator is looking at the page — with no error, just a screen missing from
  the menu and replies filed with no author.
- Dates are typed `CarbonInterface`. An application that has called
  `Date::use(CarbonImmutable::class)` was met with a `TypeError` where the desk
  should have been.
- The canvas takes its theme from Filament's `dark` class alone. It also read
  `prefers-color-scheme`, which overruled Filament for anybody who had chosen
  light on a machine set to dark.
- `CHATBOT_WIDGET_ORIGINS` sets the widget's allow-list, so the one setting that
  differs between every environment no longer needs the config file published.
