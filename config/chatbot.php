<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Panel
    |--------------------------------------------------------------------------
    |
    | Where the chat screens appear once ChatbotPlugin is added to a panel.
    |
    */

    'panel' => [
        'navigation_group' => 'Chatbot',
        'navigation_sort' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator model
    |--------------------------------------------------------------------------
    |
    | The staff member who answers a conversation. Null falls back to the model
    | behind the default auth provider, which is what almost every application
    | wants; set it only when operators are not the same table as panel users.
    |
    */

    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    */

    'chat' => [
        'history_limit' => 50,
        'max_message_length' => 4000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sandbox
    |--------------------------------------------------------------------------
    |
    | Trying the bot out from the panel writes a real conversation held by the
    | real engine: it is hidden from the desk and from reports rather than
    | simulated. Each person keeps one such thread per screen, and threads
    | nobody has touched for this many hours are swept the next time any
    | sandbox is opened.
    |
    | Swept on open rather than on a schedule, because a package cannot assume
    | the host runs one and a cleanup that needs configuring is a cleanup that
    | does not happen. Zero keeps every thread forever, and the table then grows
    | with conversations nobody can see.
    |
    */

    'sandbox' => [
        'keep_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot
    |--------------------------------------------------------------------------
    |
    | max_steps_per_turn and max_jumps are loop guards, not tuning knobs. A
    | scenario that walks in a circle would otherwise hold the request open
    | until PHP gives up, and the visitor would see nothing at all.
    |
    */

    'bot' => [
        'enabled' => true,
        'max_steps_per_turn' => 40,
        'max_jumps' => 5,
        'handoff_words' => ['operator', 'human', 'manager', 'agent'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Slots
    |--------------------------------------------------------------------------
    |
    | Spelled-out numbers the parser accepts as counts. English ships with the
    | package because that is the only language the package can claim to speak;
    | a host adds its own words here, and the whole table is replaced when it
    | does. People answer "how many of you" with a word at least as often as
    | with a digit, and a bot that understands only the digit re-asks a question
    | that was already answered.
    |
    */

    'slots' => [
        'number_words' => [
            'zero' => 0,
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            'six' => 6,
            'seven' => 7,
            'eight' => 8,
            'nine' => 9,
            'ten' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data resources
    |--------------------------------------------------------------------------
    |
    | Read access to the host application's own records, declared rather than
    | coded. Every limit here is a ceiling the runtime refuses to cross: an
    | unknown field, an operator outside the allow-list or a limit above the
    | maximum fails the query instead of being trimmed to fit. Silent repair
    | is how a bot ends up answering with someone else's data.
    |
    | max_limit is an installation-wide ceiling and wins against a resource that
    | declares a higher one. The runtime takes the smaller of the two, so raising
    | it here is the only way to let any resource go above it.
    |
    | There is deliberately no row budget or statement timeout key. Enforcing
    | either honestly needs a row estimate before the query and a per-driver
    | session timeout, and SQLite offers neither — a setting that looked like a
    | guard and enforced nothing would be worse than not having one. The real
    | cost guard is the hard limit in the generated SQL.
    |
    */

    'data_resources' => [
        'enabled' => true,
        'max_limit' => 100,
        'default_limit' => 10,
        'resources' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound HTTP
    |--------------------------------------------------------------------------
    |
    | The http step refuses to run until a domain is listed here — in every
    | environment, testing included. An empty allow-list is a working scenario
    | that cannot reach anything, which is the safe default for a step whose
    | whole purpose is leaving the application.
    |
    */

    'http' => [
        'allowed_domains' => [],
        'allow_private_networks' => false,
        'timeout' => 10,
        'max_response_bytes' => 65536,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    |
    | The null driver is the default on purpose. A scenario has to stay useful
    | with AI switched off — an unset key, a provider outage or an exhausted
    | budget all take the same "could not" branch — so the package ships in the
    | state that proves it.
    |
    | Providers are drivers behind the package's own AiGateway contract rather
    | than a hard dependency on any one SDK. laravel/ai is the obvious candidate
    | and is supported, but it stays a suggestion: it is still 0.x with a
    | shifting provider surface, it requires aws/aws-sdk-php outright, and its
    | illuminate/json-schema constraint would drag the whole package's floor up
    | to Laravel 12.62. Requiring it would make this package break whenever it
    | does, on every installation, including ones that only ever talk to a
    | single HTTP endpoint.
    |
    | The openai driver covers every OpenAI-compatible endpoint — OpenRouter,
    | Ollama, LM Studio, Azure, DeepSeek, a local vLLM — over Laravel's HTTP
    | client and nothing else. Anything else a host needs, it registers itself.
    |
    */

    'ai' => [
        'default' => env('CHATBOT_AI_DRIVER', 'null'),

        'drivers' => [
            'null' => [],

            'openai' => [
                'base_url' => env('CHATBOT_AI_BASE_URL', 'https://api.openai.com/v1'),
                'api_key' => env('CHATBOT_AI_API_KEY'),
                'model' => env('CHATBOT_AI_MODEL', 'gpt-4o-mini'),
                'timeout' => 20,
                'temperature' => 0.2,
                'max_tokens' => 600,
            ],

            /*
             * Used only when laravel/ai is installed. The driver reports itself
             * unavailable otherwise, which takes the same branch as an outage:
             * a scenario pointed at a provider nobody installed degrades, it
             * does not fatal.
             */
            'laravel-ai' => [
                'provider' => env('CHATBOT_LARAVEL_AI_PROVIDER'),
                'model' => env('CHATBOT_LARAVEL_AI_MODEL'),
                'temperature' => 0.2,
                'max_tokens' => 600,
            ],
        ],

        /*
         * Letting a model choose which scenario answers a message, and only
         * once routing by words has found nothing.
         *
         * Off by default, and the default is the point rather than caution
         * about the bill. Keyword routing is free, instant, works with the
         * provider down, and — the part that matters to whoever draws the
         * scenarios — can be read off the canvas, because the words are written
         * on the start node. With a model choosing, "why did it go there" is
         * answered by somebody else's service. Only scenarios whose author
         * wrote an invocation contract are ever offered, and every failure falls
         * back to the deterministic behaviour, so switching this on can add a
         * match but never take one away.
         */
        'routing' => [
            'enabled' => false,
            'instruction' => 'You route a customer message to one of the listed conversation scenarios. Choose the single best fit, or none at all when nothing fits well. Never name anything that is not in the list.',
            'candidates' => 12,
        ],

        /*
         * Defaults for the node that answers with a model.
         *
         * The wording lives here as well as on the node so that an installation
         * can set its tone once instead of on every node, and so that the two
         * rules that keep the node honest — answer only from the material given,
         * say so when it does not cover the question — are what an author starts
         * from rather than something they have to think of.
         */
        'answer' => [
            'instruction' => 'You answer customers on behalf of this company. Use only the material you are given, keep the reply short and in the language the customer wrote in, and say plainly that you do not know when the material does not answer the question.',
            'candidates' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge base
    |--------------------------------------------------------------------------
    |
    | min_score is how much of a question has to land on a topic before it is
    | treated as being about that topic, and min_gap is how far ahead of the
    | runner-up the winner has to be. The second one is the important one: two
    | topics that fit equally well mean the question did not contain the words
    | that tell them apart, and a confident answer about the wrong one is worse
    | than none at all. Lower it and the bot starts guessing.
    |
    | stop_words is the only language-specific part of matching, which is why it
    | is a setting rather than code. English ships here because that is the only
    | language the package can claim to speak; a host writing its knowledge base
    | in another language replaces the whole list.
    |
    */

    'knowledge' => [
        'enabled' => true,
        'candidates' => 5,
        'min_score' => 0.2,
        'min_gap' => 0.25,
        'stop_words' => [
            'about', 'after', 'all', 'also', 'and', 'any', 'are', 'ask', 'been',
            'before', 'being', 'but', 'can', 'could', 'did', 'does', 'doing',
            'done', 'for', 'from', 'get', 'gets', 'got', 'had', 'has',
            'have', 'hello', 'her', 'here', 'hers', 'him', 'his', 'how',
            'into', 'its', 'just', 'know', 'let', 'like', 'may', 'might',
            'much', 'must', 'need', 'not', 'now', 'onto', 'our', 'ours', 'out',
            'over', 'please', 'said', 'say', 'she', 'should', 'some', 'such',
            'tell', 'than', 'thanks', 'thank', 'that', 'the', 'their', 'them',
            'then', 'there', 'these', 'they', 'this', 'those', 'through',
            'under', 'until', 'very', 'was', 'were', 'what', 'when', 'where',
            'which', 'while', 'who', 'whom', 'why', 'will', 'with', 'would',
            'you', 'your', 'yours',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | Delivery is queued and retried, never done in the request that produced
    | the message: a provider answers in about a second when it is healthy and
    | not at all when it is not, and the request waiting on that belongs to a
    | visitor watching their own message fail to appear.
    |
    | tries is a ceiling on retries of failures that might not repeat. A message
    | whose channel has no driver, or whose credentials are missing, is not
    | retried at all — waiting cannot make a driver appear, and the retries would
    | only delay the reason reaching the operator.
    |
    | The Telegram secret_token is what actually authenticates the webhook: the
    | URL is public and its only protection is that nobody has guessed it yet.
    | An empty secret refuses every update rather than accepting every update,
    | because the reverse would make a forgotten setting into an open door into
    | the customer desk that works perfectly in testing.
    |
    */

    'channels' => [
        'delivery' => [
            'tries' => 3,
        ],

        'site' => [
            'enabled' => true,
        ],

        'telegram' => [
            'enabled' => false,
            'token' => env('CHATBOT_TELEGRAM_TOKEN'),
            'secret_token' => env('CHATBOT_TELEGRAM_SECRET'),
            'api_url' => 'https://api.telegram.org',
            'timeout' => 10,

            /*
             * Empty on purpose. Telegram rejects the whole message when its
             * markup does not parse, and a scenario's text carries whatever the
             * conversation collected — a name with an underscore in it, a
             * reference number in brackets — so a parse mode turns somebody
             * else's data into a delivery failure. A host that formats its
             * messages deliberately sets this and escapes accordingly.
             */
            'parse_mode' => '',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Site widget
    |--------------------------------------------------------------------------
    |
    | An empty origin list blocks the widget rather than allowing everything.
    | The reverse default would turn a forgotten setting into an open endpoint
    | that any site can embed and bill to this application's AI budget.
    |
    | Origins are matched whole and exactly — scheme, host and port together —
    | so 'https://shop.example.com' does not admit the plaintext version of the
    | same site. A request arriving with no Origin header at all is refused too:
    | the widget is cross-origin by definition and always sends one.
    |
    | identity_secret is what the host signs a logged-in visitor's id with, on
    | its own server, before printing it into the embed. Without it the identity
    | block is ignored entirely — everything in it comes from a script the
    | visitor can edit, so an unsigned "this is customer 4711" is a claim, not a
    | fact, and treating it as one would let anybody read anybody's thread.
    |
    | rate_limits are per minute, keyed by visitor token where there is one and
    | by IP otherwise. Polling for history is frequent and harmless; sending a
    | message opens conversations and spends the model budget, which is why they
    | are separate numbers rather than one ceiling high enough for the first.
    |
    | origins can also come from CHATBOT_WIDGET_ORIGINS as a comma-separated
    | list. It reads oddly for a config file to parse a string, and it is worth
    | the oddity: which sites may embed the widget is the one setting that
    | genuinely differs between every environment a host has — staging is not
    | production — and it was the only such setting here that could not be given
    | without publishing this file and editing it per deployment. Everything
    | else a host must vary already reads an env var.
    |
    | An empty list stays an empty list, and the widget then admits nobody.
    | Silence is not taken as permission: `chatbot:doctor` fails on exactly this
    | and says which setting to fill in.
    |
    */

    'widget' => [
        'enabled' => true,
        'origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CHATBOT_WIDGET_ORIGINS', '')),
        ), static fn (string $origin): bool => $origin !== '')),
        'identity_secret' => env('CHATBOT_WIDGET_SECRET'),
        'token_ttl' => 600,
        'max_attachment_kb' => 10240,

        /*
         * The widget's CSS class and storage prefixes are deliberately absent.
         * They are set with `data-prefix` on the embed instead, because the
         * clash they exist to resolve — a site that already uses `.chatbot__`
         * for something of its own — belongs to that site, and one setting in
         * this application cannot be right for every site that embeds the
         * widget. A key here would have looked authoritative and been read by
         * nothing.
         */

        'rate_limits' => [
            'chatbot-site-history' => 120,
            'chatbot-site-send' => 20,
            'chatbot-site-read' => 60,
            'chatbot-site-typing' => 60,
            'chatbot-webhook' => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Budgets
    |--------------------------------------------------------------------------
    |
    | Reserved before the request to the model leaves, not counted after it
    | returns: two conversations arriving together would otherwise both pass a
    | check against the same remaining balance and both spend it.
    |
    */

    'budgets' => [
        'enabled' => false,
        'monthly_tokens' => null,
        'monthly_cost' => null,
        'tool_calls_per_turn' => 5,
        'seconds_per_turn' => 90,
    ],

];
