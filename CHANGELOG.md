# Changelog

All notable changes to `mb4it/filament-chatbot` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this package intends to follow [Semantic Versioning](https://semver.org/) once
it reaches 1.0.

## [0.14.5] — 2026-10-16

- **No approval node is demanded in front of an agent.** The agent asks for its
  own yes since 0.14.0, and the graph check still marked an agent offered an
  approval-needing action as a blocking defect.

## [0.14.4] — 2026-10-16

- **Redaction labels say what happened**: «[телефон получен, скрыт системой]»
  instead of «[телефон]». Seen live: the bare label read to the model as a
  broken placeholder, and it kept telling the customer the number "did not
  come through" while the number sat in the record.

## [0.14.3] — 2026-10-16

- **Anthropic thinking is off by default** (`chatbot.ai.drivers.anthropic.thinking`,
  `CHATBOT_ANTHROPIC_THINKING`: `disabled`, `adaptive`, or empty to omit).
  Seen live with Haiku: thinking is on by default on new models, it spent the
  whole 600-token budget, the reply had no text, and the node fetched a
  manager. A reply cut off that way now says so in the journal.
- **The agent sees what is already written down on every turn** — values for
  ordinary slots, contacts by name only. The phone number was in the record
  while the exchange still showed "[телефон]", and the model asked a fourth
  time.

## [0.14.2] — 2026-10-16

- **A phone number or email the visitor types is written down by the package.**
  Seen live: a customer sent their number three times, the model saw
  "[телефон]" each time, asked again twice and fetched a manager. The value
  still never reaches the provider; it is parsed on the server into the node's
  allowed slots (`chatbot.ai.agent.contact_slots`, default `phone` and
  `email`), and the model is told it was written and must not ask again.

## [0.14.1] — 2026-10-16

- **The bot's model applies when its driver is left on "default".** Seen live:
  the bot said `claude-haiku-5-5`, the driver field was empty, the default was
  Anthropic, and every request went to Sonnet — the model name was dropped
  because the bot "had no driver" to attach it to.

## [0.14.0] — 2026-10-16

- **The agent may be offered actions that need approval — and still cannot run
  them on its own.** When the model calls one, the package does not run it: the
  model words a summary, the visitor gets it with yes/no buttons, and the action
  runs only after a yes. Anything that is not a yes counts as a no, and the
  model is told so to find out what to change. The node's required fields gate
  these actions the way they gate `pass_on`. New node settings: the two button
  labels and a note always added under the question by code — the place for
  consent to the processing of personal data.
- **Prompt caching for Anthropic.** The instruction, the tool catalogue and the
  exchange so far are marked for the provider's cache; the part of the
  instruction that changes per turn (missing fields, summary) travels after the
  mark so it does not invalidate it. Cached tokens are counted in the budget at
  their real price. `AiTurn` gained `stable` (bytes of the instruction that do
  not change), which other drivers ignore.
- **The bot's system prompt reaches the model.** The field promised "sent on
  every turn" and no node read it; the agent and the answer node now put it
  before their own instruction. The field moved to the «ИИ» tab.

## [0.13.4] — 2026-10-16

- **The bot's own lines reach the model as they were said.** Seen live: the bot
  told a customer "заявка № 261015-0064", read its own words back next turn as
  "№ [number]-0064", saw the full number in the record and announced it had
  named the order wrongly. What the bot said came from the host's data through
  the rules that govern what it may show; only the visitor's words are
  redacted now.
- **`chatbot.ai.redact.keep`: shapes that are never redacted** — a host's own
  order and booking references. A customer asked about «O-261007-ZHAE», the six
  digits became "[number]", and the model could not find "O-[number]-ZHAE".

## [0.13.3] — 2026-10-16

- **Out of rounds, the model answers in words instead of handing over.** Seen
  live: on «в любые числа» the model spent all four rounds writing down what
  it had learnt — eleven calls, one value each — and never replied, so a
  conversation going well went to a person. One last request now goes out with
  no tools offered; a person is fetched only if even that is empty.
- **`remember` takes several values at once** (`values: {field: value, …}`), so
  writing down what was learnt costs one round however much there is.

## [0.13.2] — 2026-10-16

The rest of the audit.

- **Messenger connection fields are translated.** Labels and hints of the
  Telegram, VK and MAX credentials were English strings in the drivers, shown
  as such in a Russian panel; they live in `chatbot::panel.channel_fields` now.
- **«Сегодня», «завтра», «послезавтра» are dates.** Only "today" and
  "tomorrow" were understood. The words are in `chatbot::bot.relative_days`,
  merged with English.
- **The order of a slashed date is a setting.** "01/08" is the first of August
  almost everywhere and January the eighth in the United States;
  `chatbot.slots.date_order` (`dmy` by default, or `mdy`) decides. Dotted dates
  are day-first regardless.
- **Placeholders and hints no longer speak of tours and bookings.** Examples
  such as `trip_city`, `booking_status` and «число туристов» became neutral.

## [0.13.0] — 2026-10-16

An audit for things that only made sense for the one host this package grew up
in. The package is meant for any Filament application.

- **Words that call a person now come from the language files.** The list lived
  in config as one English set, so on a Russian installation «менеджер» and
  «оператор» never reached a person. `chatbot.bot.handoff_words` now adds to
  `chatbot::bot.handoff_words` instead of being the whole list, and its default
  is empty.
- **The "8 → +7" phone rule is a setting.** It is Russian, and it was applied
  everywhere: an eleven-digit number starting with 8 became a Russian one on
  any installation. `chatbot.slots.phone_trunk_prefixes` is empty by default;
  a Russian host sets `['8' => '7']`. **Breaking for Russian hosts that relied
  on it.**
- **Name lead-ins and range words moved to the language files**
  (`name_lead_ins`, `range_words`), merged with English the way months are.
- **The agent's tool texts are neutral and translated.** Descriptions spoke as a
  travel agency ("agency material", "money, complaints and promises"); what a
  host forbids belongs in the node's instruction. Tool results the model reads
  ("Done.", "Remembered …") come from the language files, so a Russian
  instruction no longer gets English replies; journal reasons stay English.
- `chatbot.channels.telegram.upload_timeout` is declared in config; it was read
  but never defined.
- **Asking for a person by word gets a reply.** The handover used to be silent;
  once «менеджер» started to count, a scenario's own "Позвать менеджера" button
  went straight to a person with no reply at all. The line comes from
  `chatbot::bot.handing_off`, `chatbot.bot.handoff_message` overrides it, and an
  empty string keeps the old silence.

## [0.12.3] — 2026-10-16

- **An empty reply after tools is a stumble, not a failure.** Seen live: the
  model remembered "море", searched the knowledge base and came back with an
  empty message; the node took its second exit, and a customer who asked
  "подскажи варианты" was told a manager would answer. The model is now asked
  once more; if it is empty again but said something earlier in the turn, that
  is spoken; a person is fetched only when there is nothing to say at all.

## [0.12.2] — 2026-10-16

- **What the model remembers is parsed the way a form parses it.** Seen live:
  the model wrote a date as "в августе, следующего месяца", and the order failed
  after the customer had already pressed "Да, всё верно" — the host expected a
  date and got a phrase. `remember` now runs a value through the slot's own
  parser when the field is a slot, storing the parsed value, `<slot>_said` and
  the extras exactly as `form` does.

## [0.12.1] — 2026-10-16

- **The driver and model chosen on the bot are the ones that answer.** Both
  fields were shown on the bot screen and read by nothing at runtime: the
  driver came from the node or the installation default, the model from the
  environment file. Found on a live installation where the bot said
  `claude-sonnet-5-5` while its conversations ran on another model. The order
  is now node, then bot, then installation; the bot's model name applies only
  to the bot's own driver, since a Claude model name handed to an OpenAI
  endpoint would only be refused.

## [0.12.0] — 2026-10-16

- **The agent is not a Claude-only feature any more.** The `openai` driver
  implements `AiConversationGateway`, and with it every endpoint that speaks the
  chat-completions shape: OpenAI, OpenRouter, DeepSeek, Azure, Ollama, vLLM.
  They all answer tools in one form; what differs from Anthropic is only the
  spelling — the arguments of a call are a JSON string, and each result is a
  message of its own with the role `tool`. `laravel-ai` does not converse yet:
  it reaches a library whose methods it discovers at runtime, and tool calling
  there would be guesswork.

## [0.11.2] — 2026-10-16

- **The model is told every turn what is still not written down.** Seen live: a
  model says a value out loud — "двое взрослых и дети 5 и 9 лет" — and never
  writes it. It has answered the person, so as far as it is concerned the
  matter is closed. The `pass_on` gate would tell it, but only once it tries to
  hand over, and a model still happily asking about beach preferences never
  tries. The line is computed from the runtime each turn, so it is never stale
  and never names something already written.

## [0.11.1] — 2026-10-15

- **`requires`: what must be collected before `pass_on` is allowed.** The
  clearest example in this package of an instruction not being a check. The
  node's text said, in as many words, to call `pass_on` only when everything
  was collected and never in the same turn in which the model had asked
  something — and the very first live reply asked "Сколько лет детям?" and
  called `pass_on` alongside it, with no name collected at all. The
  conversation left the model in the middle of its own question. The refusal
  now happens in code and names the missing values, which the model reads like
  any other tool result and carries on asking within the same turn. Dropping
  the call silently would be worse than either: the visitor would hear "передаю
  подбор" and then nothing.

## [0.11.0] — 2026-10-15

- **The model says when its part is done: `pass_on`.** The `ai_agent` node's
  first exit is taken on that tool rather than after every answer, and an
  ordinary answer leaves the conversation with the model, waiting at the node.
  It was the other way round for one release, with a condition on the canvas
  deciding — "once these values are filled, carry on" — and one live
  conversation showed why that cannot work: the model asked "куда бы Вы хотели
  поехать?", the visitor's previous message happened to complete the list, and
  the scenario spoke over the question it had just asked. The customer read a
  question and then a summary answering a different one. Only the model knows
  whether it has just asked something.
- **The next message comes back to the node instead of being routed again.** A
  conversation the model is leading must not be taken from it mid-sentence by a
  scenario that matched a keyword. Breaking out stays its own decision: fetch a
  person, or pass the conversation on.
- `pass_on` is offered only when the first exit leads somewhere. A node without
  one is a conversation the model holds to the end, and offering to pass it on
  there would end a turn in silence.

## [0.10.2] — 2026-10-15

- **Said out loud what the agent does not promise, unlike the answer node.**
  That one refuses to speak an answer whose claimed sources were not among the
  fragments it was handed, which is what makes an invented refund policy
  harmless. The agent's answer is composed after several tool results, it was
  never asked to name a source, and there is nothing to check a claim against.
  What remains real: the knowledge search goes through `KnowledgeSource`, which
  never offers a topic whose mode says "fetch a person" or "run a scenario". The
  rest is the instruction's job — which is why it is required.

## [0.10.1] — 2026-10-15

- **The agent can read, through the data resources.** A tool per allow-listed
  resource, `read_<resource>`, which is how it answers "what have I got booked"
  without a second road to the same columns: the scopes the resource declared
  narrow the rows to this conversation's own person — resolved from server state
  the model is never shown and never asked for — the sensitive fields are
  dropped by the reader, and the limit is the resource's own. No filters are
  offered: a model composing them invents field names, and each invention costs
  a refused round and a sentence to the visitor about a field they never
  mentioned.
- **A reader's refusal is not repeated to the model in its own words.** Those
  name the resource, the field and the limit; handed to a model they end up
  quoted at a customer as the reason their trip could not be found. The model is
  told it cannot see the data, and the real sentence goes to the run journal.

## [0.10.0] — 2026-10-15

### A conversation the model can lead

- **`ai_agent`: a new node where the model reads the thread, uses the tools the
  node offers and answers in its own words.** The two model nodes so far could
  answer one question and pull values out of a sentence; everything else — what
  to ask next, when to file the request, when to fetch a person — was the
  scenario's. This node hands those decisions to the model inside bounds drawn
  on the canvas, which is the difference between a conversation that feels
  answered and one that feels processed.
- **A second gateway contract, opted into per driver.** `AiConversationGateway`
  adds one method, `converse(AiTurn): AiReply`; `anthropic` implements it. The
  reasoning that kept tool calling out of `AiGateway` still holds for hosts
  satisfying it with an HTTP endpoint, so a driver that cannot hold an exchange
  is not broken — the node takes its second exit and the journal names the
  driver.
- **The agent cannot reach a provider around the budget.** It is the most
  expensive caller in the package: every round is a billed request carrying the
  window and the whole tool catalogue. `BudgetedGateway` therefore implements
  the conversation contract as well, reserves against the same ceiling, and is
  the only way in.
- **What the model may do is an allow-list on the node, not a sentence in the
  prompt.** Handed the whole capability registry, a model is one confidently
  worded customer message away from cancelling a booking — and that conversation
  reads like an ordinary request politely granted. A tool that was not named
  does not exist; one the model invents is refused and the refusal goes back to
  it, so it recovers inside the turn instead of leaving the visitor with
  silence.
- **Three tools are the package's own**: `search_knowledge` (answer from the
  company's material rather than from the model's world knowledge), `remember`
  (write into the slots the author named, where the scenario and the desk read
  them) and `call_human` (a model with no way to ask for help improvises, which
  is how a bot promises a refund).
- **Rounds are capped and the cap is low.** A model that cannot finish asks for
  the same thing again; uncapped, one confused turn spends a month's budget
  while the visitor watches nothing happen.
- **`ConversationMemory`: a window, a running summary and a line that can be
  drawn under both.** The model sees the last few exchanges verbatim plus a
  summary of what fell out of the window, rewritten once per windowful rather
  than per turn. `closeWindow()` restarts the window where a scenario finished —
  nothing is deleted, the transcript is still the record, but the model stops
  re-opening a request that closed three weeks ago — and `forget()` drops the
  summary too. Everything leaving goes through the redactor, the visitor's own
  words included.

## [0.9.11] — 2026-10-15

- **The desk can delete a conversation.** Closing a thread was all it could do,
  and the three cases that need more than closing — a customer asking for their
  messages to be gone, a thread holding a photograph of somebody's passport, a
  rehearsal that reached a real chat — were cleared out of the database by hand,
  which in practice meant they stayed. Confirmed, named for what it removes, and
  it takes the files with the rows: an attachment row is the only thing that
  knows where its bytes are, so deleting rows alone leaves the photograph on the
  disk at its old address with nothing left to find it by.
- **Deleting asks the host about deleting.** The policy tries
  `chatbot.delete.<subject>`, then `chatbot.delete`, and only then `manage`, so
  everybody who answers the chat may write in it while removing a year of it
  stays with whoever the host says. An application that has said nothing about
  `chatbot.delete` behaves exactly as before.

## [0.9.10] — 2026-10-15

- **What the channel vouched for is kept apart from what it was told.**
  `$conversation->proven('phone')` answers with the contact card Telegram
  filled in, or the detail a signed identity carried — never with an answer
  typed into the bot's question. `contact_phone` could not carry that answer,
  because of an ordering nobody plans for: somebody types a number (a claim,
  written to the column by the scenario) and taps "share my contact"
  afterwards. The column is taken by then, a filled column is never
  overwritten, and a host reading `identified_at` beside `contact_phone` would
  read the stamp of the verified card against the digits of the claim — and
  hand the chat whatever record those digits belong to. Hosts that look a
  customer up in order to show their orders must read `proven()`.

## [0.9.9] — 2026-10-15

- **A phone number the messenger vouches for.** Asked for a number, the bot
  now offers Telegram's own "share my contact" key next to the question, and
  what comes back carries the account it came from. A number typed into a
  message is a claim about somebody — anybody — and a host that looks up a
  customer by it hands the chat whatever that customer's record holds. The
  card Telegram fills in is checked against the sender (`contact.user_id` has
  to be the person writing), and only then does the conversation get its
  `identified_at` mark; a forwarded card, or a number read out in words,
  arrives as plain text with no mark at all.
- **`Runtime::say()` takes a third argument.** `say($text, $options,
  $wantsContact)`; a channel that cannot ask for a contact card ignores the
  flag. Phone slots of `form` and `collect` set it themselves.

## [0.9.8] — 2026-10-15

- **A pressed button stays visible in Telegram.** Tapping an inline button
  leaves nothing in the chat: the keyboard vanishes and the visitor is looking
  at a question with no answer under it. On a form of a dozen questions that is
  most of the conversation gone — ask them what they chose and they cannot say.
  Telegram will not let a bot post as the visitor, so the choice is written
  into the question itself: `— Экскурсионный` under the text it answered.
- **The desk records the word, not the position.** Callback data cannot hold a
  label, so the buttons carry positions — and the position was what the
  operator read in the transcript: "2". Telegram returns the keyboard with the
  callback, and the label is taken from there.

## [0.9.7] — 2026-10-15

- **The desk fits a phone.** Its header took a quarter of a 375-point screen:
  the back button, the avatar, the name, the contact line, the channel line and
  three labelled buttons wrapped into four rows, with the "a bot is answering"
  strip under them, and the reply box went off the bottom. The header is now a
  single row — 52 points, 71 with a contact attached — meta lines are clipped
  rather than wrapped, the page gives the desk its full width, and the send
  button is a square.
- **Conversation actions are icon buttons with tooltips.** Take it, close,
  file a contact, hand back to the bot, relink and unlink: six labelled
  buttons competed with the name of the person being talked to. The label
  lives in the tooltip and in `aria-label`.
- **The composer is one row at every width.** The stock phrases moved up beside
  the paperclip as an icon, and emoji, paperclip and phrases lost their borders
  — a border around each made them larger than the icons inside them and
  argued with the border of the field they sit in.
- **The character counter is gone.** The field's own `maxlength` holds the
  limit, and nobody writes four thousand characters into a chat reply.
- The workbench gained a demo contact directory, so the desk's linked-thread
  state can be looked at at all — it is where the header is tightest.

## [0.9.6] — 2026-10-15

- **A button pressed in a messenger is read as the word it carried.** Callback
  data cannot hold a label — it is capped at 64 bytes — so a driver sends the
  button's position and the engine received "1". A choice slot coped; nothing
  else did. The "Skip" button on an optional question arrived as "1", was not
  recognised as a refusal, and "1" was stored as the answer; `<slot>_said`
  held "1" as well, so a confirmation summary read "Board basis: 2" back to
  the visitor. The form now remembers the buttons it drew and translates the
  position back before anything else reads the answer. A number outside the
  list stays a number, and the map is cleared when the form ends.

## [0.9.5] — 2026-10-15

- **A node pointing at a data resource that is not registered is now a flaw.**
  Resources are declared in config and reach the database through
  `chatbot:sync-resources`, which a deployment can easily not call. The node
  then took its second exit on every conversation, the journal said "empty
  result", and the canvas showed a correct scenario — one production
  installation ran that way from the day it opened, its "question about my
  booking" branch never working once. `choose`, `query`, `link` and `mutate`
  all report it, so the editor draws it on the node and the readiness page
  counts it.
- **`chatbot:doctor` gained a `data-resources` check** for the same fault, so a
  deployment catches it without anyone opening a screen: a scenario naming a
  resource with no active row fails the run, and a declared-but-unsynced
  resource nobody uses yet warns.

## [0.9.4] — 2026-10-14

- **A declined question stays declined for the whole conversation.** The skip
  lived in the form's own attempts map and was cleared when the form ended, so
  a scenario that sends the visitor back through its forms to correct one
  answer asked again everything they had already skipped. The skip is now also
  stored as `<slot>_skipped`; clear it to ask again on purpose.

## [0.9.3] — 2026-10-14

- **Amounts understand multipliers and ranges.** "150 тысяч" was stored as 150 —
  the figure the visitor was asked to confirm and the manager searched with.
  `150k`, `1,5 млн`, `200–230 тысяч` and `от 150000 до 200 тыс` now read as
  meant; a range stores its upper end with `from` and `to` in `extra`.
- **Dates understand month names.** «в феврале», «12 июня», «декабрь 2026» did
  not parse at all, so a required date question re-asked until the visitor gave
  up; a bare English "february" was read by Carbon as this year's February even
  in November. A month without a day goes forwards and keeps `month` in
  `extra`.
- **`Form` and `Collect` keep the answer as written** under `<slot>_said`, so a
  confirmation can quote the visitor instead of `2027-02-01`.

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

- A kind of node for each thing a conversation does, walked one turn at a time by
  an engine that decides where the conversation goes while each node only reports
  what it did. (The count of them is deliberately not written here: a release
  note is a record of one day, and a number in it goes stale the next time a node
  is added. The current count lives in the node reference.)
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
