---
title: Home
layout: default
nav_order: 1
---

# Filament Chatbot

Customer chat and a visual chatbot builder for any Filament panel.

Conversations from a site widget and from messaging channels, a scenario editor
on a canvas, a knowledge base, and answers from an AI provider when you want
them — without the package knowing anything about your business.

```bash
composer require mb4it/filament-chatbot
php artisan migrate
php artisan chatbot:demo-data
php artisan chatbot:demo "my printer is broken" "Alex" "alex@example.test" "it will not switch on" "today"
```

The last two commands seed a working bot and then hold a conversation with it,
printing the transcript and the run journal. That is deliberately the second
thing you do: a green test suite proves the package works, and this proves it
works **in your application**, with your providers and your database.

---

## The idea

Most chatbot packages either hard-code a domain — orders, bookings, tickets —
or hand you a scripting language and call it flexibility. This one has a small
fixed vocabulary of nodes and **three ways to teach it about your application**,
in increasing order of effort:

| | What you write | When |
| --- | --- | --- |
| [Data resources]({{ site.baseurl }}/data-resources) | A declaration: a model, its approved fields, a ceiling | The bot needs to read your records |
| [Capabilities]({{ site.baseurl }}/capabilities) | An action with an input schema and a handler | It needs to *do* something |
| [Your own node]({{ site.baseurl }}/extending) | One PHP class | Neither of the above fits |

Nothing in the package references your models. A conversation points at your
records through nullable morphs it never dereferences, and the operator model
comes from your auth configuration.

---

## What it does not do

Worth knowing before you install it, because these are decisions rather than
gaps.

**It does not require an AI provider, and does not assume one.** The shipped
default is the null driver, and every scenario has to remain useful with the
model switched off: an unset key, a provider outage and an exhausted budget all
take the same "could not" branch. If that branch is unwired, publishing fails.

**It will not let a scenario write anything without an explicit confirmation
step above it.** Publication refuses, naming the node. That is the last moment
the defect is cheap.

**It does not invent a permission system.** It asks your application whether
somebody may manage scenarios; if you have no opinion, the answer is yes. The
moment you define the gate, it decides — including when it refuses.

**It does not follow redirects, reach private networks, or call a host you have
not listed.** The outbound HTTP node refuses to run until a domain is allowed —
in every environment, including testing.

---

## Where to go next

- [Installation]({{ site.baseurl }}/installation) — the plugin, the migrations, the assets.
- [Scenarios]({{ site.baseurl }}/scenarios) — how a conversation is walked, and what publishing means.
- [Node reference]({{ site.baseurl }}/nodes) — all twenty-six kinds, what each one branches on.
- [Data resources]({{ site.baseurl }}/data-resources) — letting the bot read your records, and every rule that keeps it honest.
- [Capabilities]({{ site.baseurl }}/capabilities) — letting it do something.
- [Slots and conditions]({{ site.baseurl }}/slots-and-conditions) — what a bot can ask for and branch on.
- [AI]({{ site.baseurl }}/ai) — drivers, the knowledge base, and why none of it is required.
- [Channels and the widget]({{ site.baseurl }}/channels-and-widget) — the site widget, Telegram, and writing your own.
- [Extending]({{ site.baseurl }}/extending) — your own nodes, conditions, slots, channels and AI drivers.
- [The operator desk]({{ site.baseurl }}/operator-desk) — where a person answers, and how replying silences the bot.
- [Operations]({{ site.baseurl }}/operations) — `chatbot:doctor`, quality checks, budgets, authorisation.
