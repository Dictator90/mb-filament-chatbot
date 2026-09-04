<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Demo;

use Illuminate\Support\Facades\DB;
use MB\FilamentChatbot\Enums\KnowledgeMode;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\KnowledgeEntry;
use MB\FilamentChatbot\Models\QuickReply;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * A working bot somebody can talk to a minute after installing the package.
 *
 * An empty panel is the worst first impression this package can make: every
 * screen is a list with nothing in it, the canvas is a blank sheet, and there is
 * no way to tell a package that works from one that is broken. So the demo is
 * not documentation — it is the only proof a fresh installation offers that the
 * router, the runtime and the knowledge base are wired to each other.
 *
 * The six scenarios are chosen to cover the node kinds that are hard to reason
 * about from the field list alone. Anyone can guess what "say" does; nobody
 * guesses that a call returns and a jump does not, or that a form skips what it
 * already knows, and those are exactly the ones an author gets wrong first.
 *
 * Everything is addressed by slug and topic key rather than by id, and every
 * write is an upsert. The command that drives this is run twice by anybody
 * experimenting — once to look, once after breaking something — and a second run
 * that produced a second copy of every scenario would leave two fallbacks racing
 * for the same message, which is precisely the fault this data exists to
 * demonstrate the absence of.
 */
final class DemoData
{
    public const BOT_SLUG = 'demo';

    /**
     * Canvas geometry. Steps land on a grid instead of at (0, 0) because nodes
     * stacked in one point are not a diagram — the editor opens on a single
     * black square, and the first thing a new user has to do is drag fourteen
     * invisible nodes apart before they can read anything.
     */
    private const ORIGIN_X = 80;

    private const ORIGIN_Y = 160;

    private const COLUMN = 260;

    private const ROW = 150;

    /**
     * @var array{scenarios: int, steps: int, topics: int, quick_replies: int}
     */
    private array $counts = [
        'scenarios' => 0,
        'steps' => 0,
        'topics' => 0,
        'quick_replies' => 0,
    ];

    /**
     * Writes the demo bot and everything that belongs to it.
     *
     * One transaction for the whole set, because a scenario is only useful
     * together with the scenarios it jumps into: a run interrupted halfway would
     * leave "welcome" pointing at a "support" that does not exist yet, and the
     * visitor who tapped the button would meet a dead end instead of a bot.
     */
    public function seed(?string $botSlug = self::BOT_SLUG): Bot
    {
        $slug = $this->slug($botSlug);

        $this->counts = ['scenarios' => 0, 'steps' => 0, 'topics' => 0, 'quick_replies' => 0];

        return DB::transaction(function () use ($slug): Bot {
            $bot = $this->bot($slug);

            foreach ($this->scenarios() as $definition) {
                $this->syncScenario($bot, $definition);
            }

            foreach ($this->knowledge() as $entry) {
                $this->syncKnowledgeEntry($bot, $entry);
            }

            foreach ($this->quickReplies() as $reply) {
                $this->syncQuickReply($reply);
            }

            return $bot;
        });
    }

    /**
     * Removes the demo and nothing else.
     *
     * Scoped to the demo bot and to the known slugs, shortcuts and topic keys
     * rather than truncating the tables. The demo is seeded into installations
     * that already have real scenarios beside it — that is the whole point of
     * shipping it — and a wipe that emptied the tables would take a working
     * bot's conversations with it while looking like it had only removed a
     * sample.
     */
    public function wipe(?string $botSlug = self::BOT_SLUG): void
    {
        $slug = $this->slug($botSlug);

        DB::transaction(function () use ($slug): void {
            $bot = Bot::query()->where('slug', $slug)->first();

            QuickReply::query()
                ->whereNull('user_id')
                ->whereIn('shortcut', array_column($this->quickReplies(), 'shortcut'))
                ->delete();

            if ($bot === null) {
                return;
            }

            KnowledgeEntry::query()
                ->where('bot_id', $bot->getKey())
                ->delete();

            $scenarioIds = Scenario::query()
                ->where('bot_id', $bot->getKey())
                ->pluck('id')
                ->all();

            /*
             * Steps go first rather than being left to the cascade. SQLite
             * enforces foreign keys only when the connection asked it to, and an
             * installation that develops against a file database with the pragma
             * off would keep every step of every deleted scenario — invisible
             * until the next seed hit the unique index on (scenario_id, key).
             */
            Step::query()->whereIn('scenario_id', $scenarioIds)->delete();
            Scenario::query()->whereIn('id', $scenarioIds)->delete();

            $bot->delete();
        });
    }

    /**
     * What the last seed() wrote, for the command's summary.
     *
     * @return array{scenarios: int, steps: int, topics: int, quick_replies: int}
     */
    public function counts(): array
    {
        return $this->counts;
    }

    private function slug(?string $botSlug): string
    {
        $slug = trim((string) $botSlug);

        return $slug === '' ? self::BOT_SLUG : $slug;
    }

    /**
     * The bot is matched on its slug and its settings are refreshed.
     *
     * Working hours are filled in because one of the scenarios branches on them.
     * Left empty they would mean "always open" — a sensible default for a real
     * bot and a useless one here, since the closed branch would then be
     * unreachable and the condition node would look like it did nothing.
     */
    private function bot(string $slug): Bot
    {
        return Bot::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => 'Demo Assistant',
                'is_active' => true,
                'is_public_widget' => true,
                'greeting' => 'Hi — I am the demo assistant. Ask me about an order, a problem, or our opening hours.',
                'hours_from' => '09:00:00',
                'hours_till' => '18:00:00',
            ],
        );
    }

    /**
     * @param  array{slug: string, name: string, priority: int, description: string, invocation: array<string, mixed>, steps: list<array<string, mixed>>}  $definition
     */
    private function syncScenario(Bot $bot, array $definition): Scenario
    {
        $scenario = Scenario::query()->updateOrCreate(
            ['bot_id' => $bot->getKey(), 'slug' => $definition['slug']],
            [
                'name' => $definition['name'],
                'is_active' => true,
                'priority' => $definition['priority'],
                'description' => $definition['description'],
                'invocation' => $definition['invocation'],
            ],
        );

        $keys = [];

        foreach ($definition['steps'] as $sort => $step) {
            $keys[] = $step['key'];

            Step::query()->updateOrCreate(
                ['scenario_id' => $scenario->getKey(), 'key' => $step['key']],
                [
                    'type' => $step['type'],
                    'body' => $step['body'] ?? null,
                    'options' => $step['options'] ?? null,
                    'config' => $step['config'] ?? null,
                    'next_key' => $step['next'] ?? null,
                    'sort' => $sort,
                    'pos_x' => self::ORIGIN_X + ($step['col'] * self::COLUMN),
                    'pos_y' => self::ORIGIN_Y + ($step['row'] * self::ROW),
                ],
            );
        }

        /*
         * Steps the definition no longer lists are removed, so that editing this
         * file and re-running the command leaves the graph the file describes.
         * Without it a renamed node would survive as an orphan, and the canvas
         * would show a scenario nobody wrote.
         */
        Step::query()
            ->where('scenario_id', $scenario->getKey())
            ->whereNotIn('key', $keys)
            ->delete();

        $this->counts['scenarios']++;
        $this->counts['steps'] += count($keys);

        return $scenario;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function syncKnowledgeEntry(Bot $bot, array $entry): void
    {
        KnowledgeEntry::query()->updateOrCreate(
            ['bot_id' => $bot->getKey(), 'topic_key' => $entry['topic_key']],
            [
                'category' => $entry['category'],
                'question' => $entry['question'],
                'answer' => $entry['answer'] ?? null,
                'scenario_slug' => $entry['scenario_slug'] ?? null,
                'keywords' => $entry['keywords'],
                'mode' => $entry['mode'],
                'is_active' => true,
            ],
        );

        $this->counts['topics']++;
    }

    /**
     * @param  array{title: string, shortcut: string, body: string, sort: int}  $reply
     */
    private function syncQuickReply(array $reply): void
    {
        QuickReply::query()->updateOrCreate(
            ['user_id' => null, 'shortcut' => $reply['shortcut']],
            [
                'title' => $reply['title'],
                'body' => $reply['body'],
                'sort' => $reply['sort'],
                'is_active' => true,
            ],
        );

        $this->counts['quick_replies']++;
    }

    /**
     * The six scenarios, in the order they are easiest to read.
     *
     * "welcome" greets and offers three ways in; "order-status", "support" and
     * "opening-hours" are the three; "identify" is shared work nobody starts
     * directly; "fallback" catches whatever the others did not.
     *
     * They are deliberately entangled — welcome jumps into three of them and
     * support calls a fourth — because a set of six isolated scenarios would
     * demonstrate six nodes and none of the routing between them, which is the
     * part that actually goes wrong.
     *
     * @return list<array{slug: string, name: string, priority: int, description: string, invocation: array<string, mixed>, steps: list<array<string, mixed>>}>
     */
    private function scenarios(): array
    {
        return [
            $this->welcomeScenario(),
            $this->orderStatusScenario(),
            $this->supportScenario(),
            $this->identifyScenario(),
            $this->openingHoursScenario(),
            $this->fallbackScenario(),
        ];
    }

    /**
     * The opening. Priority 50 puts it above the rest, which matters only among
     * greetings: the picker tries every keyword scenario before any greeting, so
     * "where is my order" as a first message still reaches the order lookup
     * instead of the menu.
     *
     * @return array{slug: string, name: string, priority: int, description: string, invocation: array<string, mixed>, steps: list<array<string, mixed>>}
     */
    private function welcomeScenario(): array
    {
        return [
            'slug' => 'welcome',
            'name' => 'Welcome',
            'priority' => 50,
            'description' => 'Greets a new visitor and offers the three things this bot can do.',
            'invocation' => [
                'purpose' => 'Open a conversation and route it.',
                'useWhen' => 'The visitor has only said hello.',
                'doNotUseWhen' => ['The visitor already asked something specific.'],
                'examples' => ['hi', 'hello there', 'good morning'],
            ],
            'steps' => [
                [
                    'key' => 'start',
                    'type' => 'start',
                    'next' => 'hello',
                    'col' => 0,
                    'row' => 0,
                    'config' => [
                        'trigger' => 'greeting',
                        'keywords' => [],
                        'channels' => [],
                        'audience' => 'any',
                        'familiarity' => 'any',
                    ],
                ],
                [
                    'key' => 'hello',
                    'type' => 'say',
                    'next' => 'menu',
                    'col' => 1,
                    'row' => 0,
                    'body' => 'Hello! I am the demo assistant.',
                ],
                [
                    'key' => 'menu',
                    'type' => 'ask',
                    'col' => 2,
                    'row' => 0,
                    'body' => 'What would you like to do?',
                    'options' => [
                        ['label' => 'Track an order', 'next' => 'to-order'],
                        ['label' => 'Get support', 'next' => 'to-support'],
                        ['label' => 'Ask a question', 'next' => 'to-hours'],
                    ],
                    'config' => ['else_key' => 'to-fallback'],
                ],
                [
                    'key' => 'to-order',
                    'type' => 'jump',
                    'col' => 3,
                    'row' => -1,
                    'config' => ['scenario' => 'order-status'],
                ],
                [
                    'key' => 'to-support',
                    'type' => 'jump',
                    'col' => 3,
                    'row' => 0,
                    'config' => ['scenario' => 'support'],
                ],
                [
                    'key' => 'to-hours',
                    'type' => 'jump',
                    'col' => 3,
                    'row' => 1,
                    'config' => ['scenario' => 'opening-hours'],
                ],
                [
                    'key' => 'to-fallback',
                    'type' => 'jump',
                    'col' => 3,
                    'row' => 2,
                    'config' => ['scenario' => 'fallback'],
                ],
            ],
        ];
    }

    /**
     * A lookup wearing a question's clothes: collect one value, branch on it,
     * answer or fetch a person. The "could not" branch hands over rather than
     * asking again, because a visitor who has no reference number will not
     * acquire one by being asked twice.
     *
     * @return array{slug: string, name: string, priority: int, description: string, invocation: array<string, mixed>, steps: list<array<string, mixed>>}
     */
    private function orderStatusScenario(): array
    {
        return [
            'slug' => 'order-status',
            'name' => 'Order status',
            'priority' => 40,
            'description' => 'Looks up an order by its reference number.',
            'invocation' => [
                'purpose' => 'Tell the visitor where their order is.',
                'useWhen' => 'The visitor asks about an order, a delivery or a parcel.',
                'doNotUseWhen' => ['The visitor wants a refund.'],
                'examples' => ['where is my order', 'has my parcel shipped', 'tracking please'],
            ],
            'steps' => [
                [
                    'key' => 'start',
                    'type' => 'start',
                    'next' => 'ask-number',
                    'col' => 0,
                    'row' => 0,
                    'config' => [
                        'trigger' => 'keyword',
                        'keywords' => ['order', 'delivery', 'tracking', 'where is my'],
                        'channels' => [],
                        'audience' => 'any',
                        'familiarity' => 'any',
                    ],
                ],
                [
                    'key' => 'ask-number',
                    'type' => 'collect',
                    'next' => 'check',
                    'col' => 1,
                    'row' => 0,
                    'body' => 'Happy to check. What is the order number?',
                    'config' => ['slot' => 'order_number', 'attempts' => 2],
                ],
                [
                    'key' => 'check',
                    'type' => 'condition',
                    'col' => 2,
                    'row' => 0,
                    'options' => [
                        [
                            'label' => 'We have a number',
                            'next' => 'found',
                            'when' => [
                                'field' => 'variable',
                                'name' => 'order_number',
                                'operator' => 'filled',
                            ],
                        ],
                    ],
                    'config' => ['else_key' => 'no-number'],
                ],
                [
                    'key' => 'found',
                    'type' => 'say',
                    'next' => 'bye',
                    'col' => 3,
                    'row' => 0,
                    'body' => 'Order {{order_number}} left the warehouse and is due to arrive on Friday.',
                ],
                [
                    'key' => 'bye',
                    'type' => 'close',
                    'col' => 4,
                    'row' => 0,
                    'body' => 'Anything else I can help with?',
                ],
                [
                    'key' => 'no-number',
                    'type' => 'handoff',
                    'col' => 3,
                    'row' => 1,
                    'body' => 'Let me get someone who can find it without the number.',
                ],
            ],
        ];
    }

    /**
     * The scenario that shows why "call" exists.
     *
     * Identifying the visitor is written once, in its own scenario, and borrowed
     * here. Copied inline instead — which is what every author does before they
     * find the call node — the same two questions end up in four scenarios, and
     * the day the wording changes it changes in one of them.
     *
     * @return array{slug: string, name: string, priority: int, description: string, invocation: array<string, mixed>, steps: list<array<string, mixed>>}
     */
    private function supportScenario(): array
    {
        return [
            'slug' => 'support',
            'name' => 'Support request',
            'priority' => 40,
            'description' => 'Identifies the visitor, takes the request, and hands it to a person.',
            'invocation' => [
                'purpose' => 'Collect a support request and hand it over.',
                'useWhen' => 'The visitor reports that something is broken or not working.',
                'doNotUseWhen' => ['The visitor only wants to know where an order is.'],
                'examples' => ['my printer is broken', 'the app is not working', 'I have a problem'],
            ],
            'steps' => [
                [
                    'key' => 'start',
                    'type' => 'start',
                    'next' => 'who',
                    'col' => 0,
                    'row' => 0,
                    'config' => [
                        'trigger' => 'keyword',
                        'keywords' => ['broken', 'not working', 'problem', 'help me'],
                        'channels' => [],
                        'audience' => 'any',
                        'familiarity' => 'any',
                    ],
                ],
                [
                    'key' => 'who',
                    'type' => 'call',
                    'next' => 'details',
                    'col' => 1,
                    'row' => 0,
                    'config' => [
                        'scenario' => 'identify',
                        'into' => 'visitor_name',
                        'else_key' => 'human',
                    ],
                ],
                [
                    'key' => 'details',
                    'type' => 'form',
                    'next' => 'thanks',
                    'col' => 2,
                    'row' => 0,
                    'config' => [
                        'slots' => ['request', 'urgency'],
                        'attempts' => 2,
                        'else_key' => 'human',
                    ],
                ],
                [
                    'key' => 'thanks',
                    'type' => 'say',
                    'next' => 'human',
                    'col' => 3,
                    'row' => 0,
                    'body' => 'Thanks {{visitor_name}} — I have written all of that down.',
                ],
                [
                    'key' => 'human',
                    'type' => 'handoff',
                    'col' => 4,
                    'row' => 0,
                    'body' => 'Passing you to the support team now.',
                ],
            ],
        ];
    }

    /**
     * Shared work, reachable only through a call.
     *
     * Its start node triggers on "call", so the router never offers it to a
     * visitor. That matters more than it looks: picked by the fallback, this
     * scenario would open with "How should I address you?" at somebody who
     * asked about a refund — an interrogation with no stated reason, which is
     * the fastest way to lose a conversation.
     *
     * @return array{slug: string, name: string, priority: int, description: string, invocation: array<string, mixed>, steps: list<array<string, mixed>>}
     */
    private function identifyScenario(): array
    {
        return [
            'slug' => 'identify',
            'name' => 'Identify the visitor',
            'priority' => 10,
            'description' => 'Asks for a name and an email, and returns the name to whoever called.',
            'invocation' => [
                'purpose' => 'Find out who we are talking to.',
                'useWhen' => 'Another scenario needs a name and an email.',
                'doNotUseWhen' => ['A visitor has written for the first time.'],
                'examples' => [],
            ],
            'steps' => [
                [
                    'key' => 'start',
                    'type' => 'start',
                    'next' => 'ask',
                    'col' => 0,
                    'row' => 0,
                    'config' => [
                        'trigger' => 'call',
                        'keywords' => [],
                        'channels' => [],
                        'audience' => 'any',
                        'familiarity' => 'any',
                    ],
                ],
                [
                    'key' => 'ask',
                    'type' => 'form',
                    'next' => 'known',
                    'col' => 1,
                    'row' => 0,
                    'config' => [
                        'slots' => ['name', 'email'],
                        'attempts' => 2,
                        'else_key' => 'unknown',
                    ],
                ],
                [
                    'key' => 'known',
                    'type' => 'result',
                    'col' => 2,
                    'row' => 0,
                    'config' => ['value' => '{{name}}'],
                ],
                [
                    'key' => 'unknown',
                    'type' => 'result',
                    'col' => 2,
                    'row' => 1,
                    'config' => ['value' => ''],
                ],
            ],
        ];
    }

    /**
     * The smallest scenario that answers differently depending on the clock, and
     * the reason the demo bot has working hours filled in at all.
     *
     * @return array{slug: string, name: string, priority: int, description: string, invocation: array<string, mixed>, steps: list<array<string, mixed>>}
     */
    private function openingHoursScenario(): array
    {
        return [
            'slug' => 'opening-hours',
            'name' => 'Opening hours',
            'priority' => 40,
            'description' => 'Answers whether we are open, and says so differently after hours.',
            'invocation' => [
                'purpose' => 'Say when the company is open.',
                'useWhen' => 'The visitor asks about opening hours or whether anyone is there.',
                'doNotUseWhen' => ['The visitor wants a person right now.'],
                'examples' => ['what are your hours', 'are you open', 'when are you closed'],
            ],
            'steps' => [
                [
                    'key' => 'start',
                    'type' => 'start',
                    'next' => 'now',
                    'col' => 0,
                    'row' => 0,
                    'config' => [
                        'trigger' => 'keyword',
                        'keywords' => ['hours', 'open', 'closed', 'when are you'],
                        'channels' => [],
                        'audience' => 'any',
                        'familiarity' => 'any',
                    ],
                ],
                [
                    'key' => 'now',
                    'type' => 'condition',
                    'col' => 1,
                    'row' => 0,
                    'options' => [
                        [
                            'label' => 'Open right now',
                            'next' => 'open',
                            'when' => [
                                'field' => 'working_hours',
                                'operator' => 'yes',
                            ],
                        ],
                    ],
                    'config' => ['else_key' => 'closed'],
                ],
                [
                    'key' => 'open',
                    'type' => 'say',
                    'next' => 'bye',
                    'col' => 2,
                    'row' => 0,
                    'body' => 'We are open right now — every weekday from 9:00 to 18:00.',
                ],
                [
                    'key' => 'closed',
                    'type' => 'say',
                    'next' => 'bye',
                    'col' => 2,
                    'row' => 1,
                    'body' => 'We are closed at the moment. We open again at 9:00 and will pick this up then.',
                ],
                [
                    'key' => 'bye',
                    'type' => 'close',
                    'col' => 3,
                    'row' => 0,
                    'body' => 'Anything else?',
                ],
            ],
        ];
    }

    /**
     * What answers when nothing else claimed the message.
     *
     * It counts the visitor's messages before saying anything, because the
     * failure this scenario exists to prevent is not one misunderstanding — it
     * is the third one in a row. A fallback that only ever apologises and
     * re-offers a menu is a loop with good manners.
     *
     * @return array{slug: string, name: string, priority: int, description: string, invocation: array<string, mixed>, steps: list<array<string, mixed>>}
     */
    private function fallbackScenario(): array
    {
        return [
            'slug' => 'fallback',
            'name' => 'Fallback',
            'priority' => 0,
            'description' => 'Answers anything nothing else answered, and gives up gracefully.',
            'invocation' => [
                'purpose' => 'Catch messages no other scenario claimed.',
                'useWhen' => 'Nothing else matched.',
                'doNotUseWhen' => ['Any other scenario matched.'],
                'examples' => [],
            ],
            'steps' => [
                [
                    'key' => 'start',
                    'type' => 'start',
                    'next' => 'stuck',
                    'col' => 0,
                    'row' => 0,
                    'config' => [
                        'trigger' => 'fallback',
                        'keywords' => [],
                        'channels' => [],
                        'audience' => 'any',
                        'familiarity' => 'any',
                    ],
                ],
                [
                    'key' => 'stuck',
                    'type' => 'condition',
                    'col' => 1,
                    'row' => 0,
                    'options' => [
                        [
                            'label' => 'Third message already',
                            'next' => 'human',
                            'when' => [
                                'field' => 'turns',
                                'operator' => 'at_least',
                                'value' => 3,
                            ],
                        ],
                    ],
                    'config' => ['else_key' => 'sorry'],
                ],
                [
                    'key' => 'sorry',
                    'type' => 'say',
                    'next' => 'menu',
                    'col' => 2,
                    'row' => 0,
                    'body' => 'I did not catch that.',
                ],
                [
                    'key' => 'menu',
                    'type' => 'ask',
                    'col' => 3,
                    'row' => 0,
                    'body' => 'Here is what I can do:',
                    'options' => [
                        ['label' => 'Track an order', 'next' => 'to-order'],
                        ['label' => 'Get support', 'next' => 'to-support'],
                        ['label' => 'Ask a question', 'next' => 'to-hours'],
                    ],
                    'config' => ['else_key' => 'human'],
                ],
                [
                    'key' => 'to-order',
                    'type' => 'jump',
                    'col' => 4,
                    'row' => -1,
                    'config' => ['scenario' => 'order-status'],
                ],
                [
                    'key' => 'to-support',
                    'type' => 'jump',
                    'col' => 4,
                    'row' => 0,
                    'config' => ['scenario' => 'support'],
                ],
                [
                    'key' => 'to-hours',
                    'type' => 'jump',
                    'col' => 4,
                    'row' => 1,
                    'config' => ['scenario' => 'opening-hours'],
                ],
                [
                    'key' => 'human',
                    'type' => 'handoff',
                    'col' => 2,
                    'row' => 2,
                    'body' => 'I am not getting this — let me find a person for you.',
                ],
            ],
        ];
    }

    /**
     * Ten topics, and the two that do not answer are the point.
     *
     * "refund-status" is a Handoff entry with no answer text at all: a refund is
     * money, and a bot that guesses at a date has made a promise the company then
     * has to keep. "order-tracking" is a Scenario entry, because the useful reply
     * to "where is my order" is a form and not a paragraph. Both are impossible
     * to discover from a knowledge base full of nothing but answers, and both are
     * the reason the mode column exists.
     *
     * @return list<array<string, mixed>>
     */
    private function knowledge(): array
    {
        return [
            [
                'topic_key' => 'shipping-times',
                'category' => 'Shipping',
                'question' => 'How long does delivery take?',
                'answer' => 'Orders placed before 15:00 ship the same day and usually arrive within two to four working days.',
                'keywords' => ['delivery time', 'how long', 'shipping', 'arrive'],
                'mode' => KnowledgeMode::Answer,
            ],
            [
                'topic_key' => 'returns-policy',
                'category' => 'Returns',
                'question' => 'Can I return something I ordered?',
                'answer' => 'Anything unused can go back within 30 days. Start a return from your account and we email you a label.',
                'keywords' => ['return', 'send back', 'exchange'],
                'mode' => KnowledgeMode::Answer,
            ],
            [
                'topic_key' => 'payment-methods',
                'category' => 'Payments',
                'question' => 'Which payment methods do you accept?',
                'answer' => 'Cards, bank transfer and the usual wallets. Invoices are available for business accounts.',
                'keywords' => ['payment', 'pay', 'card', 'invoice'],
                'mode' => KnowledgeMode::Answer,
            ],
            [
                /*
                 * Verify rather than Answer: warranty length is the sort of term
                 * that changes in a contract nobody remembers to copy into the
                 * knowledge base, and the reply is right often enough to send and
                 * expensive enough to be worth a glance.
                 */
                'topic_key' => 'warranty',
                'category' => 'Products',
                'question' => 'How long is the warranty?',
                'answer' => 'Hardware carries a two-year warranty from the date on the invoice.',
                'keywords' => ['warranty', 'guarantee', 'covered'],
                'mode' => KnowledgeMode::Verify,
            ],
            [
                'topic_key' => 'international-shipping',
                'category' => 'Shipping',
                'question' => 'Do you ship abroad?',
                'answer' => 'We ship across Europe. Duties and import taxes are not included in the checkout price.',
                'keywords' => ['abroad', 'international', 'overseas', 'customs'],
                'mode' => KnowledgeMode::Answer,
            ],
            [
                'topic_key' => 'account-deletion',
                'category' => 'Account',
                'question' => 'How do I delete my account?',
                'answer' => 'Account settings, then "Close account". Orders already placed stay in our records for accounting.',
                'keywords' => ['delete account', 'close account', 'remove my data'],
                'mode' => KnowledgeMode::Answer,
            ],
            [
                'topic_key' => 'refund-status',
                'category' => 'Payments',
                'question' => 'Where is my refund?',
                'answer' => null,
                'keywords' => ['refund', 'money back', 'chargeback'],
                'mode' => KnowledgeMode::Handoff,
            ],
            [
                'topic_key' => 'order-tracking',
                'category' => 'Orders',
                'question' => 'Where is my order?',
                'answer' => null,
                'scenario_slug' => 'order-status',
                'keywords' => ['track', 'where is my order', 'parcel'],
                'mode' => KnowledgeMode::Scenario,
            ],
            [
                'topic_key' => 'contact-details',
                'category' => 'Company',
                'question' => 'How can I contact you?',
                'answer' => 'Write to hello@example.test, or leave a message here and an operator will pick it up.',
                'keywords' => ['contact', 'email you', 'phone number'],
                'mode' => KnowledgeMode::Answer,
            ],
            [
                'topic_key' => 'business-hours',
                'category' => 'Company',
                'question' => 'What are your opening hours?',
                'answer' => 'Weekdays from 9:00 to 18:00. Messages left overnight are answered first thing in the morning.',
                'keywords' => ['hours', 'open', 'closed'],
                'mode' => KnowledgeMode::Answer,
            ],
        ];
    }

    /**
     * @return list<array{title: string, shortcut: string, body: string, sort: int}>
     */
    private function quickReplies(): array
    {
        return [
            [
                'title' => 'Greeting',
                'shortcut' => 'hi',
                'body' => 'Hello! Thanks for getting in touch — how can I help?',
                'sort' => 0,
            ],
            [
                'title' => 'Ask for the order number',
                'shortcut' => 'order',
                'body' => 'Let me pull that up. Could you confirm the order number?',
                'sort' => 1,
            ],
            [
                'title' => 'Refund on its way',
                'shortcut' => 'refund',
                'body' => 'The refund is on its way. It normally reaches the account within three to five working days.',
                'sort' => 2,
            ],
            [
                'title' => 'Closing',
                'shortcut' => 'bye',
                'body' => 'Glad I could help. Have a good day!',
                'sort' => 3,
            ],
        ];
    }
}
