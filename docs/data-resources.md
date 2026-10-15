---
title: Data resources
layout: default
nav_order: 5
---

# Data resources

A data resource is one declared window onto a model of the host application: a
model class, an approved field list, the query shapes that are allowed, a row
ceiling, and the conditions that narrow every read to the person who is asking.

It is how the bot answers with facts instead of with a promise that somebody
will look it up, and it is the part of the package where a mistake is worth the
most. **Every rule below refuses rather than repairs**, and the reason is always
the same shape: a refusal is seen by a developer, and a silently repaired query
is seen by a customer.

Read access from the whole package passes through one method,
`DataReader::read()`. The rules live there rather than at the call sites,
because a rule that lives in a call site is a rule the next call site has to
remember — and there will be more call sites: a bot node, a panel preview, a
tool exposed to a language model, each written on a different day.

---

## Declaring one

Two ways, and they are not equivalent.

### In the panel

`DataResourceResource` writes rows into `chatbot_data_resources` directly. Good
for exploring, and for resources a support team genuinely owns.

### In configuration, synced

```php
// config/chatbot.php
'data_resources' => [
    'enabled' => true,
    'max_limit' => 100,
    'default_limit' => 10,

    'resources' => [
        [
            'key' => 'bookings',
            'label' => 'Bookings',
            'model' => \App\Models\Booking::class,
            'modes' => ['first', 'list', 'count'],

            'fields' => [
                ['name' => 'reference', 'label' => 'Reference', 'returned_by_default' => true, 'filterable' => true, 'operators' => ['equals']],
                ['name' => 'status', 'label' => 'Status', 'returned_by_default' => true, 'filterable' => true],
                ['name' => 'departs_on', 'label' => 'Departure', 'returned_by_default' => true, 'sortable' => true],
                ['name' => 'passport_number', 'sensitive' => true],
            ],

            'default_sort' => ['field' => 'departs_on', 'direction' => 'desc'],
            'default_limit' => 5,
            'max_limit' => 20,

            'scopes' => [
                ['field' => 'customer_id', 'operator' => 'equals', 'source' => 'conversation.contact_id'],
            ],
        ],
    ],
],
```

Then, on every deployment:

```bash
php artisan chatbot:sync-resources
```

or, from a service provider or a command of your own:

```php
use MB\FilamentChatbot\Data\DataResourceSync;

app(DataResourceSync::class)->sync();
```

**The config alone does nothing — the sync is what the reader consults.** Put
the command in the deployment next to `migrate` and `optimize`, because what a
forgotten sync produces is a bot that looks correct everywhere you would check
it: the resource is declared in the file, the scenario is drawn correctly on the
canvas, no error is logged anywhere. The node simply takes its second exit on
every conversation, the journal says "empty result", and the visitor is told
there is nothing to show them. One installation ran that way from the day it
opened and nobody noticed for weeks. Since 0.9.5 a node pointing at a resource
with no row reports a blocking flaw, so the editor and the readiness page say
so — but the flaw only appears once somebody opens one of those screens, and
the deployment is where this belongs.

**The point of the detour through configuration is review.** A data resource is
the sentence that says which of the host's columns a chat may reach, and a
sentence like that belongs in a file that goes through a pull request and ships
with a deployment — not only in a form somebody can edit in production at four
in the afternoon with nobody watching. The table stays the one thing the reader
consults, so the panel keeps working; the sync is how a host makes the file the
authority over the rows it cares about.

Rows are matched on `key`, never on the primary key: declarations get copied
between installations whose auto-increment counters have nothing to do with each
other, and a sync keyed on id would quietly rewrite whichever resource happened
to be seventh.

A declaration with no `key` or no `model` **throws** rather than being skipped.
The skip is the worse outcome by far: the resource simply never exists, every
scenario pointing at it answers "unknown resource" at run time, and the one
place that knew why said nothing at the moment it could have named the offending
entry.

**A gap worth naming rather than hiding:** rows whose key is absent from the
configuration are left exactly as they are — not deactivated, not deleted. Two
kinds of row share this table, the ones the file owns and the ones somebody
created in the panel, and no column tells them apart; "delete what I did not
declare" would mean the first host to call `sync()` loses every resource their
staff built. So **deleting a declaration from the config file does not revoke
access.** Revoke by keeping the declaration and setting `is_active` to `false`,
which is a reviewable line in a diff — unlike a deletion, which leaves nothing
behind.

A declaration naming **no modes gets `count` and nothing else.** Falling back to
`list` would hand rows out of a resource whose author never said rows may leave
it, and the omission most likely to happen is exactly the one where nobody
thought about that question at all.

---

## The query

`DataQuery` is a value object, and it is immutable on purpose: the same object
travels from the node that built it into the reader that checks it, and a
mutable one could be checked in one place and changed in another — the shape of
every authorisation bug that survives a code review.

```php
use MB\FilamentChatbot\Data\DataQuery;
use MB\FilamentChatbot\Data\DataReader;
use MB\FilamentChatbot\Data\RuntimeContext;
use MB\FilamentChatbot\Enums\DataQueryMode;

$result = app(DataReader::class)->read(
    new DataQuery(
        resource: 'bookings',
        mode: DataQueryMode::First,
        select: ['reference', 'status'],
        filters: [
            ['field' => 'reference', 'operator' => 'equals', 'value' => 'ABC-123'],
        ],
        sort: [],
        limit: null,
    ),
    RuntimeContext::forConversation($conversation),
);

if (! $result->ok()) {
    // $result->error() names the resource and the field, never the value
}
```

`DataQuery::fromArray()` lays a raw payload out into that shape and **repairs
nothing**. Parsing that silently drops what it does not understand turns
"unknown field" into "field missing from the answer", and the difference is
found by the customer rather than by the developer: the reply arrives complete,
confident and short of one column, with nothing anywhere saying why. So an
undeclared field, an operator outside the allow-list and a limit above the
ceiling all survive `fromArray()` intact and are refused later by the reader, out
loud, with the resource and the field named. The only thing decided there is
structure — that a filter is a triple and a sort entry is a pair — because a
half-built triple has nothing to refuse. An unrecognised mode falls back to
`List` rather than throwing, because the mode is checked against the resource's
own allow-list anyway, so the fallback picks the value most likely to be refused
there rather than granting anything.

`read()` never throws. A `DataQueryException` becomes a failed `DataResult`, and
so does any driver-level failure: a read for a chat reply must never take the
surrounding request down with it, and the scenario already has a branch for
"could not read".

---

## The rules, and what each one costs when it is wrong

### An unknown field, operator, mode or limit ends the query

Nothing is trimmed to fit. The alternative in each case is an answer that looks
correct: a reply built on a column nobody approved, a filter quietly widened, a
partial result read as the whole set.

| Refusal | Message |
| --- | --- |
| `unknownField` | resource does not declare a field named X |
| `operatorNotAllowed` | field X of resource does not allow the Y operator |
| `limitTooHigh` | resource allows at most N rows, M were requested |
| `modeNotAllowed` | resource does not allow the X query mode |
| `sensitiveField` | field X is marked sensitive and may never be returned |

Messages name the resource and the field, **never the value that was filtered
on**: these strings end up in logs and, when a runtime is careless, in a reply,
and a customer's phone number does not belong in either.

Asking for more than the ceiling fails instead of being trimmed for a specific
reason: trimming reads as an answer to the question that was asked — "there are
40 matches" when the caller asked for 500 and the ceiling is 40 — and a caller
that cannot tell a full result from a truncated one will report the truncated
one as the truth. A missing or non-positive limit is a different case; it asks
for nothing in particular rather than for more than allowed, so it takes the
declared default.

### The ceiling is the smaller of the two

`chatbot.data_resources.max_limit` and the resource's own `max_limit`, and the
runtime takes the minimum.

A resource declaring 500 does not get 500 when the installation settled on 100.
The installation-wide number is one an administrator chose for the whole
application; a resource row is edited far more often and by more people — a form
in the panel, a config file, a seeder in a package the host installed. If the
resource could raise its own ceiling, the application-wide setting would not be
a limit at all, only a default any declaration is free to ignore, and the first
person to type a large number into a form would be the one deciding how much
data may leave in one reply.

### An unspecified field descriptor grants nothing

Every missing key in a field descriptor resolves to the closed value: not
returnable, not filterable, not searchable, not sortable. A field added to the
list with nothing but a name grants no access at all. The opposite default would
make every future key the package adds retroactively permissive on declarations
written before it existed.

Two rules are applied while normalising rather than at query time, because a
rule enforced at the point of use is one every future call site has to remember:

- A field marked filterable with **no operator list gets equality only.** The
  tempting alternative — every operator the type could support — hands out range
  and substring matching to declarations that asked for a lookup.
- **Substring operators are stripped from any field not marked `searchable`,**
  even when the declaration lists them. Text matching is the one comparison
  whose cost and whose blast radius are both chosen by whoever is typing in the
  chat, and `searchable` is where somebody accepted that.

`contains` and `starts_with` values are escaped for `LIKE` before they are
bound. A lone `%` typed into the chat would otherwise turn a lookup into "match
every row" — a full scan the visitor chose, over a result set nobody restricted,
returned as though it were an answer to their question. `_` is the same problem
one character at a time, which is worse, because it still looks like a plausible
search. The escaping neutralises the wildcards rather than making them match
literally: doing the second properly needs an `ESCAPE` clause whose spelling
differs between drivers, and the price of not having it is that searching for a
literal per-cent sign finds nothing — a bad search result rather than a
disclosure.

### An empty default selection means one field, not all of them

When a declaration marks nothing as `returned_by_default`, `defaultSelect()`
falls back to the **first** safe field.

An empty selection degrading into "everything" is precisely how a column nobody
approved ends up in a reply: the declaration that forgot to mark its defaults is
the same declaration that never thought about which columns are fit to show, and
reading silence as consent turns an oversight into a disclosure. One field gives
a visibly incomplete answer, which is a bug report; all of them give a
complete-looking answer, which is an incident.

A field marked both `sensitive` and `returned_by_default` is a declaration
contradicting itself, and it is resolved **against** returning. Consistency is
not worth an argument here: one of the two flags leaks data when it is the one
obeyed, and the other merely withholds it.

### A sensitive field is never returned and never filtered on

Not returned, even when named explicitly — an explicit request is not a stronger
claim than the declaration, it is the exact case the declaration was written to
answer. An **undeclared** field also counts as sensitive: the question is only
ever asked to decide whether something may be shown, and "we have never heard of
this column" is not a reason to show it.

**And never filtered on, which is the part that is easy to get wrong.** An
equality filter on a hidden column is an oracle. The value never comes back, but
the answer does: "count records where `passport_number` = 4517 123456" returning
1 confirms the passport number without ever printing it, and a few hundred of
those confirm it digit by digit. The `sensitive` flag would then mean "not
printed" rather than "not exposed", which is not what anybody ticking it
believed it meant.

A **scope** may filter on a sensitive field, and that is not an inconsistency:
its value is fetched from the server-side context, so the person asking cannot
choose it and learns nothing from the result they did not already know.

### Scopes come from the runtime context, and only from there

`RuntimeContext` is the most security-sensitive class in the package, and the
rule it exists to enforce is one sentence: **a value that narrows a query to one
person's rows comes from here, and from here only.**

Everything else within reach at query time was written by, or influenced by,
whoever is typing in the chat. The message text obviously; but also the
conversation's `bot_answers`, which is nothing but the visitor's own replies
stored under friendly names; also anything a language model produced, since it
produced it after reading the visitor's message; also the node's settings, once
a scenario author has been persuaded to point one at an answer.

A visitor who can name their own customer id is a visitor who can name somebody
else's, and the query that results is a perfectly valid, perfectly indexed,
perfectly fast read of another person's records, with nothing in the logs to
distinguish it from a legitimate one.

So a scope declaration never carries a value — only a `source` string naming
where the value is to be fetched from at query time:

```php
'scopes' => [
    ['field' => 'customer_id', 'operator' => 'equals', 'source' => 'conversation.contact_id'],
],
```

The sources are a closed set:

| Prefix | Resolves |
| --- | --- |
| `conversation.` | `id`, `contact_id`, `contact_type`, `subject_id`, `subject_type`, `channel` |
| `actor.` | `id`, or any attribute of the authenticated model / array |
| `tenant.` | Any key of the tenant array passed into the context |

The conversation list is a closed match rather than a lookup on the model,
because the conversation row also holds `bot_answers` — the visitor's own words
— and a generic `conversation.<column>` reader would put those one typo away
from being a scope value. `actor` accepts only an Eloquent model or an array:
an arbitrary object would mean reaching for a public property or a getter by
name, and a name that resolves to a method call is a name that can be made to do
something other than read a value.

The actor is passed **into** the context rather than read from `auth()` inside
it, because the same read runs in three places where the authenticated user is
three different things — a panel request, a queued job continuing a scenario, an
inbound webhook with no session at all — and a class that guessed would be right
in the first case and silently wrong in the other two.

The resolved value is never shown to the model and never shown to the scenario
author: **a scope that can be read is a scope that can be argued with.**

### A scope may reach through a relation

In a normalised schema it usually has to. A booking belongs to a customer
through its order and carries no customer column of its own, so without this the
table has only two possible declarations — unreachable, or unscoped, which is
the whole table:

```php
'scopes' => [
    ['relation' => 'order', 'field' => 'customer_id', 'operator' => 'equals', 'source' => 'conversation.contact_id'],
],
```

The condition becomes a `whereHas` on that relation. Not a join: a join
multiplies rows when the relation is a has-many, and the limit applied afterwards
would return five copies of two records — a wrong answer that reads as a short
one.

The relation name is declared in the same reviewed file as the model class and
trusted exactly as far. A name Eloquent does not know throws, and the read is
refused rather than widened.

**A resource scoped this way can be read but not written.** `mutate` and
[`link`]({{ site.baseurl }}/nodes#link) address one row by its key and have
nowhere to put a condition about a related table; carrying on would apply a
weaker scope than the declaration states and change a record the conversation
was never allowed to see. So they refuse, out loud, naming the resource. That
costs a resource which is read-only; the alternative costs somebody else's row.

### An unknown scope source refuses the read

`resolve()` returns `null` for an unknown prefix, a known prefix with nothing
bound to it, and a known attribute that happens to be empty. All three refuse
the query.

**This is the rule that matters most, and it guards against a typo.**
`actor.tenant_id` written where the host meant `tenant.id` is a source nobody
will notice is wrong, because the query keeps working — it just stops being
restricted, and one resource quietly starts answering with the whole table.
Every row it returns looks exactly like a correct answer. A missing value has to
be the loudest outcome here, not the most permissive.

Scopes are applied last and unconditionally. There is no query shape, no mode
and no filter combination that skips the loop, because the scope is the only
thing standing between "show me my orders" and "show me the orders".

### Only declared columns are asked for, and `$appends` never runs

The generated SQL names the approved columns, and the returned row is rebuilt
from the select list rather than from whatever the model happens to be holding.

The tempting shortcut is to fetch the model and serialise it, and it is the
simplest way there is to hand out a column nobody approved: the host's
`$appends` run on serialisation and put computed values in the output that
appear in no declaration and in no migration; `$casts` can turn a column into a
nested structure carrying more than the column itself; an eager-loaded relation
arrives as a whole related row. None of those are visible in the field list
anybody reviewed.

Values are reduced to scalars on the way out. An object or array leaving here is
either a cast result carrying more than the column, or a relation, and neither
is something the declaration described. Backed enums become their value, dates
become ATOM strings.

**Global scopes the host put on its own model are deliberately left in place.**
They are the host's own rules about which rows exist — soft deletes, tenancy, an
archive filter — and this package has no standing to decide that a bot reply is
the one context where they do not apply.

### An unsortable field is dropped, not refused

The one asymmetry on this page, and it is deliberate. Unlike a filter or a field
selection, a sort order cannot widen what is returned, only reorder it, and a
query that failed because somebody sorted by the wrong column would be a refusal
with no security question behind it. What it must not do is sort by an
undeclared column anyway — that is an index scan chosen by the visitor. So an
undeclared or non-sortable sort entry is skipped, and if none survive, the
resource's `default_sort` is applied instead.

---

## The nodes that use it

Two, and both build their `RuntimeContext` from the conversation row alone.

**[`query`]({{ site.baseurl }}/nodes#query)** reads into variables. Its filters
are equality-only pairs whose values may contain `{{variables}}` — safe there in
a way a scope would not be, because a filter can only narrow the rows the scope
has already restricted to this visitor.

**[`choose`]({{ site.baseurl }}/nodes#choose)** lists the visitor's own records
and remembers the one they pick. The list it offers is exactly the list the
declaration allows, which is also why the pick is a pick out of that list rather
than a free-text lookup.

Both pin the resource key into a published scenario version, so a release can
say which declarations moved underneath it.

---

## What is deliberately absent

**There is no row budget and no statement timeout setting.** Enforcing either
honestly needs a row estimate before the query and a per-driver session timeout,
and SQLite offers neither. A setting that looked like a guard and enforced
nothing would be worse than not having one — somebody would set it, believe it,
and stop thinking about the question. The real cost guard is the hard limit in
the generated SQL, plus `searchable` being the only door to substring matching.

**There is no expression language for filters.** See the
[`transform` node]({{ site.baseurl }}/nodes#transform) for the same argument
written out in full: an expression field is code execution typed into a web form
by whoever has the panel password, and it runs on the application server with
the application's database behind it.

---

## Next

- [Capabilities]({{ site.baseurl }}/capabilities) — the other half: doing something rather than reading it.
- [Node reference]({{ site.baseurl }}/nodes) — `query` and `choose` in detail.
