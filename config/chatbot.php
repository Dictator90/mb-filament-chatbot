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
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge base
    |--------------------------------------------------------------------------
    */

    'knowledge' => [
        'enabled' => true,
        'candidates' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    */

    'channels' => [
        'site' => [
            'enabled' => true,
        ],
        'telegram' => [
            'enabled' => false,
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
    */

    'widget' => [
        'enabled' => true,
        'origins' => [],
        'class_prefix' => 'chatbot',
        'storage_prefix' => 'chatbot',
        'token_ttl' => 600,
        'max_attachment_kb' => 10240,
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
