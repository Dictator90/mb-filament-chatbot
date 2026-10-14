# Changelog

All notable changes to `mb4it/filament-chatbot` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this package intends to follow [Semantic Versioning](https://semver.org/) once
it reaches 1.0.

## [Unreleased]

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
