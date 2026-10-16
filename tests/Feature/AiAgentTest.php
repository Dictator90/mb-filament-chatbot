<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Ai\AiGateway;
use MB\FilamentChatbot\Ai\AiGatewayFactory;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\AiReply;
use MB\FilamentChatbot\Ai\KnowledgeSource;
use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepFlow;
use MB\FilamentChatbot\Bot\Steps\Types\AiAgentStep;
use MB\FilamentChatbot\Capabilities\CapabilityAction;
use MB\FilamentChatbot\Chat\ConversationMemory;
use MB\FilamentChatbot\Enums\DataQueryMode;
use MB\FilamentChatbot\Enums\MessageAuthor;
use MB\FilamentChatbot\Enums\MessageDirection;
use MB\FilamentChatbot\Enums\SideEffect;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\DataResource;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;
use MB\FilamentChatbot\Tests\Support\RecordingRuntime;
use Workbench\App\Models\DemoRecord;

/**
 * A conversation the model leads, and the six ways that goes wrong quietly.
 *
 * None of these shows up as an error. A tool the model invented, a payload it
 * composed out of nothing, a model that keeps asking and never answers, a
 * driver that cannot hold a conversation at all — every one of them ends as a
 * visitor staring at a chat that stopped, or as an action taken on somebody's
 * record that nobody asked for. So the exchange is driven through a faked
 * provider rather than through a stub gateway: the parsing of `tool_use` blocks
 * is the newest code here and the easiest to get subtly wrong.
 */
function useAnthropic(): void
{
    config()->set('chatbot.ai.default', 'anthropic');
    config()->set('chatbot.ai.drivers.anthropic.api_key', 'test-key');
    config()->set('chatbot.ai.drivers.anthropic.base_url', 'https://claude.example.test/v1');
}

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function anthropicAsks(string $name, array $input = [], string $id = 'call-1', string $text = ''): array
{
    $content = $text === '' ? [] : [['type' => 'text', 'text' => $text]];
    $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];

    return [
        'content' => $content,
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 20, 'output_tokens' => 5],
    ];
}

/**
 * @return array<string, mixed>
 */
function anthropicSays(string $text): array
{
    return [
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 25, 'output_tokens' => 9],
    ];
}

/**
 * @param  list<array<string, mixed>>  $replies
 */
function anthropicReplies(array $replies): void
{
    Http::fake([
        'claude.example.test/*' => Http::sequence(array_map(
            static fn (array $reply) => Http::response($reply),
            $replies,
        )),
    ]);
}

/**
 * @param  array<string, mixed>  $config
 */
function agentNode(array $config = []): Step
{
    return Step::factory()->make([
        'key' => 'agent',
        'type' => 'ai_agent',
        'body' => null,
        'config' => [
            'instruction' => 'Ты консультант агентства. Отвечай коротко.',
            ...$config,
        ],
    ]);
}

function agentContext(Conversation $conversation, Step $step, RecordingRuntime $runtime, ?string $said = null): StepContext
{
    return new StepContext($conversation, new Scenario, $step, $runtime, $said);
}

/**
 * Material for the knowledge tool, so the agent can be tested without the
 * knowledge base it will really be bound to.
 */
final class AgentKnowledge implements KnowledgeSource
{
    /**
     * @param  list<array{title: string, text: string, key: string}>  $entries
     */
    public function __construct(private readonly array $entries = []) {}

    /**
     * @return list<array{title: string, text: string, key: string}>
     */
    public function candidates(?int $botId, ?string $said, int $limit): array
    {
        return array_slice($this->entries, 0, $limit);
    }
}

function visitorSaid(Conversation $conversation, string $body): Message
{
    return Message::create([
        'conversation_id' => $conversation->getKey(),
        'direction' => MessageDirection::Incoming,
        'author' => MessageAuthor::Visitor,
        'body' => $body,
    ]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    useAnthropic();

    $this->conversation = Conversation::factory()->create([
        'bot_id' => Bot::factory()->create()->getKey(),
        'channel' => 'site',
    ]);

    $this->runtime = new RecordingRuntime;
});

/**
 * The whole point of the node: the model asks for a host action, reads what it
 * returned, and answers the visitor with it.
 *
 * The capability is called through the ordinary gateway, so the payload the
 * model composed is checked against the declared input rather than adjusted —
 * which is what keeps "the model made up an argument" from becoming "the action
 * ran with an empty one".
 */
it('lets the model use a host action and answer from what it returned', function (): void {
    $seen = new stdClass;
    $seen->calls = [];

    app(ChatbotRegistry::class)->registerCapability(new CapabilityAction(
        key: 'crm.booking_state',
        version: '1',
        label: 'Состояние брони',
        description: 'Отдаёт состояние брони клиента.',
        effect: SideEffect::Read,
        input: [Field::text('reference')->required()],
        requiresApproval: false,
        handler: function (array $payload) use ($seen): array {
            $seen->calls[] = $payload;

            return ['status' => 'подтверждена'];
        },
    ));

    anthropicReplies([
        anthropicAsks('crm_booking_state', ['reference' => 'A-17'], text: 'Смотрю.'),
        anthropicSays('Бронь подтверждена.'),
    ]);

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => ['crm.booking_state'], 'search' => false]),
        $this->runtime,
        'что с моей бронью A-17?',
    ));

    expect($result->flow)->toBe(StepFlow::Wait)
        ->and($seen->calls)->toBe([['reference' => 'A-17']])
        ->and($this->runtime->said)->toBe(['Бронь подтверждена.'])
        ->and($result->trace['did'][0]['tool'])->toBe('crm_booking_state')
        ->and($result->trace['usage']['input_tokens'])->toBe(45);
});

/**
 * Обычный ответ оставляет разговор у модели, а не продвигает сценарий.
 *
 * Так и было наоборот одну версию: первый выход брался после каждого ответа,
 * а решал условие на полотне — «собрано столько-то значений, идём дальше».
 * Живой разговор на бою показал, чего так увидеть нельзя: модель спросила
 * «куда бы Вы хотели поехать?», предыдущая реплика человека как раз дособрала
 * список, и сценарий заговорил поверх собственного вопроса — человек прочитал
 * вопрос, а следом резюме про другое.
 *
 * Ждём на том же узле, а не кончаем прогон: следующее сообщение должно вернуться
 * к модели, а не разойтись по маршрутизации — иначе разговор, который она ведёт,
 * у неё посреди фразы отберёт сценарий, поймавший зацепку.
 */
it('оставляет разговор у модели, пока она не передала его дальше', function (): void {
    anthropicReplies([
        anthropicSays('Расскажите, куда хотите поехать?'),
        anthropicAsks('pass_on', ['note' => 'собрано всё'], text: 'Передаю подбор.'),
    ]);

    $node = agentNode(['tools' => [], 'search' => false]);
    $node->next_key = 'дальше';

    $answered = app(AiAgentStep::class)->run(agentContext($this->conversation, $node, $this->runtime, 'здравствуйте'));

    expect($answered->flow)->toBe(StepFlow::Wait)
        ->and($this->runtime->said)->toBe(['Расскажите, куда хотите поехать?']);

    $passed = app(AiAgentStep::class)->resume(
        agentContext($this->conversation, $node, $this->runtime, 'в Турцию, вылет из Ростова'),
        'в Турцию, вылет из Ростова',
    );

    expect($passed->flow)->toBe(StepFlow::Next)
        ->and($passed->trace['passed_on'])->toBeTrue()
        ->and($this->runtime->said)->toBe(['Расскажите, куда хотите поехать?', 'Передаю подбор.']);
});

/**
 * Передать дальше без собранного — отказ, и модель читает, чего не хватает.
 *
 * Самый ясный в пакете случай, когда инструкция не защита. Текст узла говорил
 * дословно: зови pass_on, только когда всё собрано, и не тем же ходом, которым
 * о чём-то спросил. Первая же живая реплика на бою: модель спросила «Сколько
 * лет детям?» и тем же ходом вызвала pass_on — имени она при этом не знала,
 * и разговор ушёл от неё посреди её собственного вопроса.
 *
 * Молча проглотить такой вызов было бы хуже отказа: человек услышал бы
 * «передаю подбор» и больше ничего.
 */
it('отказывает в передаче, пока не собрано обязательное', function (): void {
    anthropicReplies([
        anthropicAsks('pass_on', ['note' => 'кажется, всё'], text: 'Передаю подбор.'),
        anthropicSays('А как Вас зовут?'),
    ]);

    $node = agentNode(['tools' => [], 'search' => false, 'slots' => ['name'], 'requires' => ['name']]);
    $node->next_key = 'дальше';

    $result = app(AiAgentStep::class)->run(agentContext($this->conversation, $node, $this->runtime, 'хочу тур'));

    expect($result->flow)->toBe(StepFlow::Wait)
        ->and($result->trace['did'][0]['ok'])->toBeFalse()
        ->and($result->trace['did'][0]['reason'])->toContain('name')
        ->and($this->runtime->said)->toBe(['А как Вас зовут?']);

    Http::assertSent(function ($request): bool {
        foreach ($request->data()['messages'] ?? [] as $message) {
            foreach ((array) ($message['content'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                    return ($block['is_error'] ?? false) === true
                        && str_contains((string) $block['content'], 'name');
                }
            }
        }

        return false;
    });
});

/**
 * Чего ещё не записано — модель читает каждый ход, а не при попытке передать.
 *
 * Сбой увиден живьём: модель произносит значение вслух — «двое взрослых
 * и дети 5 и 9 лет» — и не записывает его. Она ведь ответила человеку,
 * значит вопрос для неё закрыт. Отказ в передаче сказал бы ей о пропуске,
 * но только когда она попробует передать, а модель, продолжающая спрашивать
 * про пожелания к пляжу, не пробует вовсе.
 */
it('называет модели незаписанное обязательное на каждом ходу', function (): void {
    anthropicReplies([anthropicSays('Сколько человек поедет?')]);

    $node = agentNode(['tools' => [], 'search' => false, 'slots' => ['name', 'adults'], 'requires' => ['name', 'adults']]);
    $node->next_key = 'дальше';

    $runtime = new RecordingRuntime;
    $runtime->stored['name'] = 'Максим';

    app(AiAgentStep::class)->run(agentContext($this->conversation, $node, $runtime, 'здравствуйте'));

    Http::assertSent(function ($request): bool {
        $system = anthropicText($request->data()['system'] ?? '');

        return str_contains($system, 'adults') && ! str_contains($system, 'name');
    });
});

/**
 * А собранное обязательное передачу разрешает.
 */
it('передаёт дальше, когда обязательное собрано', function (): void {
    anthropicReplies([
        anthropicAsks('pass_on', ['note' => 'всё собрано'], text: 'Передаю подбор.'),
    ]);

    $this->conversation->forceFill(['bot_answers' => ['name' => 'Максим']])->save();

    $node = agentNode(['tools' => [], 'search' => false, 'slots' => ['name'], 'requires' => ['name']]);
    $node->next_key = 'дальше';

    $runtime = new RecordingRuntime;
    $runtime->stored['name'] = 'Максим';

    $result = app(AiAgentStep::class)->run(agentContext($this->conversation, $node, $runtime, 'оформляйте'));

    expect($result->flow)->toBe(StepFlow::Next)
        ->and($result->trace['passed_on'])->toBeTrue()
        ->and($runtime->said)->toBe(['Передаю подбор.']);
});

/**
 * Модель, выбранная в карточке бота, — та, что и отвечает.
 *
 * Найдено на живой установке: в карточке бота стояло `claude-sonnet-5-5`,
 * а разговоры шли на другой модели из файла окружения. Поля «драйвер»
 * и «модель» на экране бота выглядели принятыми и не читались ничем.
 */
it('берёт драйвер и модель из карточки бота', function (): void {
    $this->conversation->bot->forceFill(['ai_driver' => 'anthropic', 'ai_model' => 'claude-haiku-test'])->save();
    config()->set('chatbot.ai.default', 'null');
    config()->set('chatbot.ai.drivers.anthropic.model', 'claude-from-env');

    anthropicReplies([anthropicSays('Здравствуйте.')]);

    app(AiAgentStep::class)->run(agentContext(
        $this->conversation->refresh(),
        agentNode(['tools' => [], 'search' => false]),
        $this->runtime,
        'привет',
    ));

    Http::assertSent(fn ($request): bool => ($request->data()['model'] ?? null) === 'claude-haiku-test');
});

/**
 * Пустой драйвер у бота — это драйвер по умолчанию, и модель из карточки
 * относится к нему. На бою в карточке стоял `claude-haiku-5-5` при драйвере
 * «по умолчанию», а все запросы уходили в Sonnet: модель отбрасывалась,
 * потому что «у бота нет своего драйвера».
 */
it('берёт модель из карточки бота и при драйвере по умолчанию', function (string $nodeProvider): void {
    $this->conversation->bot->forceFill(['ai_driver' => null, 'ai_model' => 'claude-haiku-test'])->save();
    config()->set('chatbot.ai.drivers.anthropic.model', 'claude-from-env');

    anthropicReplies([anthropicSays('Здравствуйте.')]);

    app(AiAgentStep::class)->run(agentContext(
        $this->conversation->refresh(),
        agentNode(['tools' => [], 'search' => false, 'provider' => $nodeProvider]),
        $this->runtime,
        'привет',
    ));

    Http::assertSent(fn ($request): bool => ($request->data()['model'] ?? null) === 'claude-haiku-test');
})->with(['узел без своего провайдера' => [''], 'узел с тем же провайдером' => ['anthropic']]);

/**
 * Передавать некуда — инструмента нет вовсе.
 *
 * Узел без первого выхода — это разговор, который ведёт только модель:
 * предложи ей «передать дальше», и она это сделает, а передавать будет некуда
 * — прогон кончится молча, и человек останется без ответа.
 */
it('не предлагает передать разговор, когда первый выход никуда не ведёт', function (): void {
    anthropicReplies([anthropicSays('Слушаю Вас.')]);

    app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'search' => false]),
        $this->runtime,
        'здравствуйте',
    ));

    Http::assertSent(function ($request): bool {
        $names = array_column((array) ($request->data()['tools'] ?? []), 'name');

        return ! in_array('pass_on', $names, true) && in_array('call_human', $names, true);
    });
});

/**
 * A tool nobody offered is refused and the refusal goes back to the model.
 *
 * Models invent plausible tools — `cancel_booking` is exactly what a travel bot
 * would have — and the dangerous answer is not "it ran" but "the turn ended":
 * the visitor sees silence caused by a tool nobody ever wrote. Told there is no
 * such tool, the model recovers inside the same turn.
 */
it('refuses a tool it never offered and lets the model recover', function (): void {
    anthropicReplies([
        anthropicAsks('cancel_booking', ['reference' => 'A-17']),
        anthropicSays('Отменить бронь я не могу, передам менеджеру.'),
    ]);

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'search' => false]),
        $this->runtime,
        'отмени мою бронь',
    ));

    expect($result->flow)->toBe(StepFlow::Wait)
        ->and($this->runtime->said)->toBe(['Отменить бронь я не могу, передам менеджеру.'])
        ->and($result->trace['did'][0]['ok'])->toBeFalse();
});

/**
 * Что модель узнала — записано там, где это прочитают и сценарий, и стол.
 *
 * Иначе собранная анкета живёт только в переписке: менеджер, подхватывая
 * разговор, читает её глазами, а условия и шаблоны ниже по сценарию видят
 * пустоту.
 */
it('запоминает узнанное в разрешённые поля и отказывает в остальных', function (): void {
    /*
     * Имя на разговоре стирается нарочно: занятое поле модель не
     * перезаписывает — подписанного клиента она переименовать не может, —
     * и проверять надо именно пустой случай.
     */
    $this->conversation->forceFill(['display_name' => null])->save();

    anthropicReplies([
        anthropicAsks('remember', ['field' => 'name', 'value' => 'Ирина'], id: 'c1'),
        anthropicAsks('remember', ['field' => 'notes', 'value' => 'что-то своё'], id: 'c2'),
        anthropicSays('Приятно познакомиться, Ирина.'),
    ]);

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'search' => false, 'slots' => ['name', 'phone']]),
        $this->runtime,
        'меня зовут Ирина',
    ));

    expect($result->flow)->toBe(StepFlow::Wait)
        ->and($this->runtime->stored['name'] ?? null)->toBe('Ирина')
        ->and($this->runtime->stored)->not->toHaveKey('notes')
        ->and($this->conversation->refresh()->display_name)->toBe('Ирина')
        ->and($result->trace['did'][1]['ok'])->toBeFalse();
});

/**
 * Записанное моделью проходит тот же разбор, что и ответ анкете.
 *
 * Живой разговор: модель записала дату как «в августе, следующего месяца»,
 * и заявка упала уже после «Да, всё верно» — сборка ждала дату, а получила
 * фразу. Анкета кладёт разобранное значение и рядом сказанное; модель
 * обязана делать то же, иначе для заявки важно, кто спрашивал.
 */
it('разбирает записанное моделью так же, как анкета', function (): void {
    anthropicReplies([
        anthropicAsks('remember', ['field' => 'phone', 'value' => '8 (949) 415-74-04']),
        anthropicSays('Спасибо.'),
    ]);

    app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'search' => false, 'slots' => ['phone']]),
        $this->runtime,
        'мой телефон 8 (949) 415-74-04',
    ));

    expect($this->runtime->stored['phone_said'] ?? null)->toBe('8 (949) 415-74-04')
        ->and($this->runtime->stored['phone'] ?? null)->not->toBe('8 (949) 415-74-04')
        ->and($this->runtime->stored['phone'] ?? '')->toContain('4157404');
});

/**
 * Пустой ответ после инструментов — переспросить, а не звать менеджера.
 *
 * Живой разговор: модель записала «море», поискала в базе знаний и вернула
 * пустое сообщение. Узел счёл это сбоем — человек попросил «подскажи
 * варианты» и получил «передаю менеджеру».
 */
it('переспрашивает модель, вернувшую пустой ответ после инструментов', function (): void {
    anthropicReplies([
        anthropicAsks('search_knowledge', ['query' => 'море']),
        ['content' => [], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]],
        anthropicSays('Могу предложить Турцию или Египет.'),
    ]);

    app()->instance(KnowledgeSource::class, new AgentKnowledge([
        ['key' => 'sea', 'title' => 'Море', 'text' => 'Турция и Египет.'],
    ]));

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => []]),
        $this->runtime,
        'подскажи варианты',
    ));

    expect($result->flow)->toBe(StepFlow::Wait)
        ->and($this->runtime->said)->toBe(['Могу предложить Турцию или Египет.']);
});

/**
 * А если и повтор пуст — сказать то, что модель уже произнесла по ходу хода,
 * а не уводить человека к менеджеру.
 */
it('говорит сказанное по ходу, если модель дважды промолчала', function (): void {
    anthropicReplies([
        anthropicAsks('search_knowledge', ['query' => 'море'], text: 'Море — отличный выбор, подберём.'),
        ['content' => [], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]],
        ['content' => [], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]],
    ]);

    app()->instance(KnowledgeSource::class, new AgentKnowledge([
        ['key' => 'sea', 'title' => 'Море', 'text' => 'Турция и Египет.'],
    ]));

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => []]),
        $this->runtime,
        'море',
    ));

    expect($result->flow)->toBe(StepFlow::Wait)
        ->and($this->runtime->said)->toBe(['Море — отличный выбор, подберём.']);
});

/**
 * «Нужен человек» — это успех узла и его второй выход, а не сбой.
 *
 * Модель без возможности позвать человека начинает выдумывать: так бот и
 * обещает возврат. А если сообщить это как сбой, в журнале «модель не
 * ответила» окажется у каждого разговора, ушедшего менеджеру как задумано.
 */
it('уходит во второй выход, когда модель просит человека', function (): void {
    anthropicReplies([
        anthropicAsks('call_human', ['reason' => 'жалоба на отель'], text: 'Передаю менеджеру.'),
    ]);

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'search' => false]),
        $this->runtime,
        'отель ужасный, верните деньги',
    ));

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($this->runtime->said)->toBe(['Передаю менеджеру.'])
        ->and($result->trace['wants_human'])->toBeTrue()
        ->and($result->trace['reason'])->toBe('жалоба на отель');
});

/**
 * Модель, которая просит и просит, останавливается на потолке раундов —
 * и отвечает словами одним последним запросом без инструментов.
 *
 * Каждый раунд — отдельный платный запрос с полной историей и каталогом
 * инструментов, поэтому потолок есть. Но живой разговор показал и обратную
 * сторону: модель все четыре раунда записывала узнанное и так и не ответила,
 * а узел передал менеджеру разговор, который шёл хорошо.
 */
it('на потолке раундов заставляет модель ответить без инструментов', function (): void {
    anthropicReplies([
        anthropicAsks('search_knowledge', ['query' => 'визы'], id: 'c1'),
        anthropicAsks('search_knowledge', ['query' => 'визы ещё раз'], id: 'c2'),
        anthropicSays('Виза в Турцию не нужна.'),
    ]);

    app()->instance(KnowledgeSource::class, new AgentKnowledge([
        ['key' => 'visa', 'title' => 'Визы', 'text' => 'Виза в Турцию не нужна.'],
    ]));

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'rounds' => 2]),
        $this->runtime,
        'нужна ли виза?',
    ));

    expect($result->flow)->toBe(StepFlow::Wait)
        ->and($this->runtime->said)->toBe(['Виза в Турцию не нужна.']);

    Http::assertSent(fn ($request): bool => count($request->data()['messages'] ?? []) > 1
        && ! isset($request->data()['tools']));
});

/**
 * А если и без инструментов модель промолчала — потолок есть потолок.
 */
it('прекращает ход на потолке раундов и уходит к человеку', function (): void {
    anthropicReplies([
        anthropicAsks('search_knowledge', ['query' => 'визы'], id: 'c1'),
        anthropicAsks('search_knowledge', ['query' => 'визы ещё раз'], id: 'c2'),
        ['content' => [], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]],
    ]);

    app()->instance(KnowledgeSource::class, new AgentKnowledge([
        ['key' => 'visa', 'title' => 'Визы', 'text' => 'Виза в Турцию не нужна.'],
    ]));

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'rounds' => 2]),
        $this->runtime,
        'нужна ли виза?',
    ));

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($this->runtime->said)->toBe([])
        ->and($result->trace['rounds'])->toBe(3);
});

/**
 * Всё узнанное — одним вызовом, а не вызовом на каждое значение.
 */
it('записывает несколько значений одним вызовом', function (): void {
    anthropicReplies([
        anthropicAsks('remember', ['values' => ['name' => 'Максим', 'phone' => '8 949 415-74-04']]),
        anthropicSays('Спасибо, Максим.'),
    ]);

    app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'search' => false, 'slots' => ['name', 'phone']]),
        $this->runtime,
        'Максим, 8 949 415-74-04',
    ));

    expect($this->runtime->stored['name'] ?? null)->toBe('Максим')
        ->and($this->runtime->stored['phone_said'] ?? null)->toBe('8 949 415-74-04');
});

/**
 * Драйвер, умеющий только отвечать на один вопрос, узла не ломает.
 *
 * Это обычное состояние установки: модель настроена, но не та. Узел уходит
 * во второй выход и называет драйвер — а не падает TypeError'ом посреди
 * чьего-то хода.
 */
it('уходит во второй выход, когда драйвер не умеет вести разговор', function (): void {
    app(AiGatewayFactory::class)->extend('plain', fn (): AiGateway => new class implements AiGateway
    {
        public function name(): string
        {
            return 'plain';
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function ask(AiPrompt $prompt): AiReply
        {
            return AiReply::answered('один ответ');
        }
    });

    config()->set('chatbot.ai.default', 'plain');

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(),
        $this->runtime,
        'здравствуйте',
    ));

    expect($result->flow)->toBe(StepFlow::Second)
        ->and($result->trace['reason'])->toContain('cannot hold a conversation')
        ->and($result->trace['reason'])->toContain('plain')
        ->and($this->runtime->said)->toBe([]);
});

/**
 * Агент — не только для Claude.
 *
 * Через драйвер `openai` ходят OpenAI, OpenRouter, DeepSeek, Azure и локальные
 * Ollama и vLLM, и все они понимают инструменты в одной форме. Отличается она
 * от Anthropic только написанием: аргументы вызова — строкой JSON, результат —
 * отдельным сообщением с ролью `tool`. Перепутай одно — и модель не увидит,
 * что ей ответил инструмент, и попросит то же самое снова.
 */
it('ведёт разговор с инструментами и через OpenAI-совместимых провайдеров', function (): void {
    config()->set('chatbot.ai.default', 'openai');
    config()->set('chatbot.ai.drivers.openai.api_key', 'test-key');
    config()->set('chatbot.ai.drivers.openai.base_url', 'https://models.example.test/v1');

    $this->conversation->forceFill(['display_name' => null])->save();

    Http::fake([
        'models.example.test/*' => Http::sequence([
            Http::response([
                'choices' => [['message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call-9',
                        'type' => 'function',
                        'function' => ['name' => 'remember', 'arguments' => '{"field":"name","value":"Ирина"}'],
                    ]],
                ]]],
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 6],
            ]),
            Http::response([
                'choices' => [['message' => ['content' => 'Приятно познакомиться, Ирина.']]],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 8],
            ]),
        ]),
    ]);

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'search' => false, 'slots' => ['name']]),
        $this->runtime,
        'меня зовут Ирина',
    ));

    expect($result->flow)->toBe(StepFlow::Wait)
        ->and($this->runtime->said)->toBe(['Приятно познакомиться, Ирина.'])
        ->and($this->runtime->stored['name'] ?? null)->toBe('Ирина')
        ->and($result->trace['usage']['input_tokens'])->toBe(70);

    Http::assertSent(function ($request): bool {
        $messages = $request->data()['messages'] ?? [];
        $last = end($messages);

        return is_array($last)
            && ($last['role'] ?? '') === 'tool'
            && ($last['tool_call_id'] ?? '') === 'call-9';
    });
});

/**
 * Окно истории — это и есть то, чем модель помнит разговор.
 *
 * Проверяется свойство, а не число: сообщений больше, чем окно, и в запрос
 * уходит ровно хвост плюс нынешняя реплика — один раз, а не дважды. Дубль
 * последнего сообщения модель читает как «он повторил вопрос».
 */
it('отдаёт модели хвост переписки и нынешнюю реплику один раз', function (): void {
    foreach (['первое', 'второе', 'третье', 'четвёртое'] as $body) {
        visitorSaid($this->conversation, $body);
    }

    visitorSaid($this->conversation, 'пятое и последнее');

    anthropicReplies([anthropicSays('Понял.')]);

    app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'search' => false, 'window' => 3]),
        $this->runtime,
        'пятое и последнее',
    ));

    Http::assertSent(function ($request): bool {
        $messages = $request->data()['messages'] ?? [];
        $texts = array_map(static fn (array $message): string => anthropicText($message['content']), $messages);

        return $texts === ['третье', 'четвёртое', 'пятое и последнее'];
    });
});

/**
 * Сводка о том, что было раньше, уходит в инструкцию, а не в переписку.
 *
 * Вставленная репликой человека, она становится тем, что модель может ему
 * процитировать; репликой бота — тем, что бот якобы уже сказал.
 */
it('кладёт сводку прошлого в инструкцию, а не в реплики', function (): void {
    app(ConversationMemory::class)->remember($this->conversation, 'Летит из Донецка с двумя детьми.');

    anthropicReplies([anthropicSays('Хорошо.')]);

    app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'search' => false]),
        $this->runtime,
        'а в июле дешевле?',
    ));

    Http::assertSent(function ($request): bool {
        $data = $request->data();
        $messages = array_map(static fn (array $message): string => anthropicText($message['content']), $data['messages'] ?? []);

        return str_contains(anthropicText($data['system']), 'Летит из Донецка с двумя детьми.')
            && $messages === ['а в июле дешевле?'];
    });
});

/**
 * Черта под разговором: окно начинается после того, что уже сказано.
 *
 * Сводка остаётся — человек тот же, — а вот заявка закрыта, и окно, всё ещё
 * держащее её, заставляет модель уточнять даты, о которых уже никто
 * не спрашивает.
 */
it('начинает окно заново после завершённого сценария', function (): void {
    visitorSaid($this->conversation, 'хочу тур в июле');

    $memory = app(ConversationMemory::class);
    $memory->remember($this->conversation, 'Заявка на июль оформлена.');
    $memory->closeWindow($this->conversation->refresh());

    visitorSaid($this->conversation, 'а документы когда?');

    $window = $memory->window($this->conversation->refresh(), 10);

    expect($window)->toHaveCount(1)
        ->and($window[0]->text)->toBe('а документы когда?')
        ->and($memory->summary($this->conversation))->toBe('Заявка на июль оформлена.');
});

/**
 * Читать данные модель может только объявленным источником.
 *
 * Это и есть ответ на «пусть ИИ смотрит мои брони»: строки сужает правило
 * источника, разрешённые поля — его объявление, а чувствительные он не отдаёт
 * вовсе. Второе действие, выбирающее те же колонки самостоятельно, было бы
 * второй дорогой к ним — и ни одного из этих правил на ней.
 */
it('читает данные через объявленный источник, без чувствительных полей', function (): void {
    DataResource::factory()->create([
        'key' => 'demo-records',
        'label' => 'Записи',
        'description' => 'Демонстрационные записи.',
        'model' => DemoRecord::class,
        'modes' => [DataQueryMode::List->value],
        'fields' => [
            ['name' => 'name', 'returned_by_default' => true],
            ['name' => 'secret_code', 'sensitive' => true],
        ],
        'default_limit' => 10,
        'max_limit' => 20,
        'scopes' => null,
        'is_active' => true,
    ]);

    DemoRecord::create(['name' => 'Тур в Турцию', 'status' => 'open', 'secret_code' => 'не-для-клиента']);

    anthropicReplies([
        anthropicAsks('read_demo-records', ['limit' => 3]),
        anthropicSays('У Вас одна поездка: Тур в Турцию.'),
    ]);

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'search' => false, 'resources' => ['demo-records']]),
        $this->runtime,
        'что у меня забронировано?',
    ));

    expect($result->flow)->toBe(StepFlow::Wait)
        ->and($result->trace['did'][0]['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        $messages = $request->data()['messages'] ?? [];
        $results = [];

        foreach ($messages as $message) {
            foreach ((array) ($message['content'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                    $results[] = (string) $block['content'];
                }
            }
        }

        return $results !== []
            && str_contains($results[0], 'Тур в Турцию')
            && ! str_contains($results[0], 'не-для-клиента');
    });
});

/**
 * Отказ источника модели пересказывают своими словами, а не словами разборщика.
 *
 * Тот называет источник, поле и предел — и, попав к модели, такая строка
 * оказывается процитированной клиенту как объяснение, почему не нашлась его
 * поездка. Настоящая причина уходит в журнал прогона.
 */
it('не отдаёт модели слова отказа источника, но пишет их в журнал', function (): void {
    anthropicReplies([
        anthropicAsks('read_demo-records', ['limit' => 3]),
        anthropicSays('Посмотреть не получилось, передам менеджеру.'),
    ]);

    DataResource::factory()->create([
        'key' => 'demo-records',
        'label' => 'Записи',
        'description' => 'Демонстрационные записи.',
        'model' => DemoRecord::class,
        'modes' => [DataQueryMode::Count->value],
        'fields' => [['name' => 'name', 'returned_by_default' => true]],
        'default_limit' => 10,
        'max_limit' => 20,
        'scopes' => null,
        'is_active' => true,
    ]);

    $result = app(AiAgentStep::class)->run(agentContext(
        $this->conversation,
        agentNode(['tools' => [], 'search' => false, 'resources' => ['demo-records']]),
        $this->runtime,
        'что у меня забронировано?',
    ));

    expect($result->trace['did'][0]['ok'])->toBeFalse()
        ->and($result->trace['did'][0]['reason'])->toContain('demo-records');

    Http::assertSent(function ($request): bool {
        $messages = $request->data()['messages'] ?? [];

        foreach ($messages as $message) {
            foreach ((array) ($message['content'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                    return ! str_contains((string) $block['content'], 'demo-records');
                }
            }
        }

        return false;
    });
});

/**
 * Выпавшее из окна не теряется, а сжимается в сводку — и один раз.
 *
 * Иначе модель забывает первую половину собственного опроса и спрашивает
 * заново: это и есть то, от чего бот перестаёт быть похожим на разговор.
 * Переписывать же сводку каждый ход означало бы второй платный запрос
 * на каждую реплику — и сводку, уезжающую от разговора с каждым
 * пересказом пересказа.
 */
it('сжимает выпавшее из окна в сводку и не пересказывает его дважды', function (): void {
    foreach (range(1, 8) as $number) {
        visitorSaid($this->conversation, 'реплика номер '.$number);
    }

    anthropicReplies([
        anthropicSays('Понял.'),
        anthropicSays('Клиент уточняет детали. Названы реплики с первой по четвёртую.'),
        anthropicSays('И ещё понял.'),
    ]);

    $node = agentNode(['tools' => [], 'search' => false, 'window' => 4]);

    app(AiAgentStep::class)->run(agentContext($this->conversation, $node, $this->runtime, 'реплика номер 8'));

    $memory = app(ConversationMemory::class);
    $summary = $memory->summary($this->conversation->refresh());
    $until = $memory->summarisedUntil($this->conversation);

    expect($summary)->toBe('Клиент уточняет детали. Названы реплики с первой по четвёртую.')
        ->and($until)->toBeGreaterThan(0);

    app(AiAgentStep::class)->run(agentContext($this->conversation->refresh(), $node, $this->runtime, 'реплика номер 8'));

    expect(app(ConversationMemory::class)->summary($this->conversation->refresh()))->toBe($summary)
        ->and(app(ConversationMemory::class)->summarisedUntil($this->conversation))->toBe($until);
});

/**
 * Ответы самого бота уходят модели как были сказаны, а номер заявки, который
 * хост разрешил, — не прячется и в словах клиента.
 *
 * Живой разговор: клиент спросил про «O-261007-ZHAE», шесть цифр в середине
 * стали «[номер]», и модель не нашла такой брони. А свой же прошлый ответ
 * «№ 261015-0064» она прочла как «№ [номер]-0064» и сообщила клиенту, что
 * назвала номер неверно и исправляется.
 */
it('не прячет номера заявок и собственные ответы бота', function (): void {
    config()->set('chatbot.ai.redact.keep', ['/\b[A-Z]-\d{6}-[A-Z0-9]{4}\b/u', '/\b\d{6}-\d{4}\b/u']);

    Message::create([
        'conversation_id' => $this->conversation->getKey(),
        'direction' => MessageDirection::Outgoing,
        'author' => MessageAuthor::Bot,
        'body' => 'Найдена заявка № 261015-0064, бронь у оператора 7712345.',
    ]);

    visitorSaid($this->conversation, 'Подскажи по заявке O-261007-ZHAE, паспорт 4510 123456');

    $window = app(ConversationMemory::class)->window($this->conversation, 10);

    expect($window[0]->text)->toBe('Найдена заявка № 261015-0064, бронь у оператора 7712345.')
        ->and($window[1]->text)->toContain('O-261007-ZHAE')
        ->and($window[1]->text)->not->toContain('123456');
});

/**
 * Из истории уходит то же, что и из одного вопроса: без телефонов и номеров.
 *
 * Модель, ведущая разговор, видит куда больше чужих данных, чем модель,
 * отвечающая на один вопрос, — и видит их на каждом ходу.
 */
it('чистит историю от персональных данных перед отправкой', function (): void {
    visitorSaid($this->conversation, 'мой телефон 8 949 415-74-04');

    $window = app(ConversationMemory::class)->window($this->conversation, 10);

    expect($window)->toHaveCount(1)
        ->and($window[0]->text)->not->toContain('415-74-04')
        ->and($window[0]->text)->toBe('мой телефон '.__('chatbot::bot.redacted.phone'));
});

/**
 * Действие «с подтверждением», которое модель может попросить.
 *
 * Счётчик запусков снаружи: проверяется не ответ модели, а то, выполнилось ли
 * действие на самом деле.
 */
function orderAction(stdClass $seen): CapabilityAction
{
    return new CapabilityAction(
        key: 'crm.create_order',
        version: '1',
        label: 'Завести заявку',
        description: 'Заводит заявку.',
        effect: SideEffect::Write,
        input: [],
        requiresApproval: true,
        handler: function () use ($seen): array {
            $seen->runs++;

            return ['заявка' => '261016-0001'];
        },
    );
}

/**
 * Действие «с подтверждением» модель не выполняет — она его только просит.
 *
 * Иначе заявка заводилась бы тем ходом, которым модель решила, что всё
 * собрано, — без итога перед глазами человека и без его «да». Инструкция
 * просит сперва показать итог, но инструкция — просьба, а не проверка.
 * Приписка под вопросом — согласие на обработку данных — ставится кодом,
 * а не моделью: она должна стоять каждый раз.
 */
it('выполняет действие с подтверждением только после «да» посетителя', function (): void {
    $seen = new stdClass;
    $seen->runs = 0;
    app(ChatbotRegistry::class)->registerCapability(orderAction($seen));

    anthropicReplies([
        anthropicAsks('crm_create_order', text: 'Оформляю.'),
        anthropicSays('Египет, 2 взрослых, август. Всё верно?'),
        anthropicSays('Заявка 261016-0001 заведена, менеджер свяжется.'),
    ]);

    $node = agentNode(['tools' => ['crm.create_order'], 'search' => false, 'approval_note' => 'Нажимая «Да», Вы соглашаетесь на обработку данных.']);
    $yes = (string) __('chatbot::nodes.steps.approval.yes');

    $asked = app(AiAgentStep::class)->run(agentContext($this->conversation, $node, $this->runtime, 'всё, оформляйте'));

    expect($seen->runs)->toBe(0)
        ->and($asked->flow)->toBe(StepFlow::Wait)
        ->and($this->runtime->said)->toBe(["Египет, 2 взрослых, август. Всё верно?\n\nНажимая «Да», Вы соглашаетесь на обработку данных."])
        ->and($this->runtime->offered[0])->toBe([$yes, (string) __('chatbot::nodes.steps.approval.no')]);

    $done = app(AiAgentStep::class)->resume(agentContext($this->conversation, $node, $this->runtime, $yes), $yes);

    expect($seen->runs)->toBe(1)
        ->and($done->trace['approval'])->toBe(['action' => 'crm.create_order', 'approved' => true, 'ok' => true])
        ->and($this->runtime->said[1])->toBe('Заявка 261016-0001 заведена, менеджер свяжется.')
        ->and($this->conversation->refresh()->meta['agent_approval:agent'] ?? null)->toBeNull();
});

/**
 * Неясный ответ — не согласие. «А можно без детей?» на итог заявки — правка,
 * и заявка, заведённая по нему, ушла бы менеджеру с детьми.
 */
it('не выполняет действие, когда ответ на подтверждение — не «да»', function (): void {
    $seen = new stdClass;
    $seen->runs = 0;
    app(ChatbotRegistry::class)->registerCapability(orderAction($seen));

    anthropicReplies([
        anthropicAsks('crm_create_order'),
        anthropicSays('Египет, 2 взрослых и 2 детей. Всё верно?'),
        anthropicSays('Хорошо, уберу детей. Тогда 2 взрослых — верно?'),
    ]);

    $node = agentNode(['tools' => ['crm.create_order'], 'search' => false]);

    app(AiAgentStep::class)->run(agentContext($this->conversation, $node, $this->runtime, 'оформляйте'));

    $declined = app(AiAgentStep::class)->resume(
        agentContext($this->conversation, $node, $this->runtime, 'а можно без детей?'),
        'а можно без детей?',
    );

    expect($seen->runs)->toBe(0)
        ->and($declined->flow)->toBe(StepFlow::Wait)
        ->and($declined->trace['approval'])->toBe(['action' => 'crm.create_order', 'approved' => false]);

    $declinedNote = (string) __('chatbot::nodes.steps.ai_agent.tools.approval_declined', ['action' => 'Завести заявку']);

    Http::assertSent(fn ($request): bool => str_contains(anthropicText($request->data()['system'] ?? ''), $declinedNote));
});

/**
 * Подтверждения не спрашивают, пока не собрано обязательное: «да» на итог
 * без имени и дат — заявка, которую менеджеру придётся собирать заново.
 */
it('не просит подтверждения, пока не собрано обязательное', function (): void {
    $seen = new stdClass;
    $seen->runs = 0;
    app(ChatbotRegistry::class)->registerCapability(orderAction($seen));

    anthropicReplies([
        anthropicAsks('crm_create_order'),
        anthropicSays('Как к Вам обращаться?'),
    ]);

    $node = agentNode(['tools' => ['crm.create_order'], 'search' => false, 'requires' => ['name']]);

    $result = app(AiAgentStep::class)->run(agentContext($this->conversation, $node, $this->runtime, 'оформляйте'));

    expect($seen->runs)->toBe(0)
        ->and($result->trace)->not->toHaveKey('awaits_approval')
        ->and($this->runtime->offered[0])->toBe([])
        ->and($this->conversation->refresh()->meta['agent_approval:agent'] ?? null)->toBeNull();
});

/**
 * Неизменная часть запроса помечена для кэша провайдера, меняющаяся — нет.
 *
 * Без пометки каждый ход агента платил полную цену за одни и те же тысячи
 * токенов инструкции и списка инструментов, и не один раз: ход — это
 * несколько запросов. А пометка на всей инструкции промахивалась бы на каждом
 * ходу, где список недостающего стал короче.
 */
it('помечает для кэша инструкцию, инструменты и переписку', function (): void {
    anthropicReplies([anthropicSays('Здравствуйте!')]);

    $node = agentNode(['tools' => [], 'search' => true, 'slots' => ['name'], 'requires' => ['name']]);

    app(AiAgentStep::class)->run(agentContext($this->conversation, $node, $this->runtime, 'здравствуйте'));

    Http::assertSent(function ($request): bool {
        $data = $request->data();
        $system = $data['system'];
        $tools = $data['tools'];
        $last = $data['messages'][count($data['messages']) - 1]['content'];

        return $system[0]['text'] === 'Ты консультант агентства. Отвечай коротко.'
            && $system[0]['cache_control'] === ['type' => 'ephemeral']
            && str_contains($system[1]['text'], 'name')
            && ! isset($system[1]['cache_control'])
            && ($tools[count($tools) - 1]['cache_control'] ?? null) === ['type' => 'ephemeral']
            && ($last[count($last) - 1]['cache_control'] ?? null) === ['type' => 'ephemeral'];
    });
});

/**
 * Системная подсказка из карточки бота доходит до модели — первой, перед
 * инструкцией узла. Поле обещало «передаётся модели на каждом ходу», а не
 * читал его ни один узел.
 */
it('отдаёт модели системную подсказку бота перед инструкцией узла', function (): void {
    $this->conversation->bot->forceFill(['prompt' => 'Ты — Чемоданчик, помощник агентства.'])->save();

    anthropicReplies([anthropicSays('Здравствуйте!')]);

    app(AiAgentStep::class)->run(agentContext($this->conversation->refresh(), agentNode(['search' => false]), $this->runtime, 'привет'));

    Http::assertSent(fn ($request): bool => str_starts_with(
        anthropicText($request->data()['system']),
        "Ты — Чемоданчик, помощник агентства.\n\nТы консультант агентства. Отвечай коротко.",
    ));
});

/**
 * Прочитанное из кэша стоит десятую часть, записанное — на четверть дороже.
 * Не учтённые вовсе, они сделали бы агента в бюджете почти бесплатным.
 */
it('считает токены кэша в расходе', function (): void {
    config()->set('chatbot.ai.drivers.anthropic.price_per_million_input', 1.0);
    config()->set('chatbot.ai.drivers.anthropic.price_per_million_output', 0.0);

    anthropicReplies([[
        'content' => [['type' => 'text', 'text' => 'Здравствуйте!']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 100, 'output_tokens' => 0, 'cache_creation_input_tokens' => 1000, 'cache_read_input_tokens' => 10000],
    ]]);

    $result = app(AiAgentStep::class)->run(agentContext($this->conversation, agentNode(['search' => false]), $this->runtime, 'здравствуйте'));

    expect($result->trace['usage']['input_tokens'])->toBe(11100)
        ->and($result->trace['usage']['cost_micro'])->toBe(100 + 1250 + 1000);
});
