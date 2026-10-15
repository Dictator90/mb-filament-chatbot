<?php

declare(strict_types=1);

use MB\FilamentChatbot\Bot\Slots\Slot;
use MB\FilamentChatbot\Facades\Chatbot;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Step;

beforeEach(function (): void {
    $this->conversation = Conversation::factory()->create([
        'bot_id' => Bot::factory()->create()->getKey(),
        'channel' => 'site',
    ]);
});

/**
 * A graph may hold a slot as an object rather than as a key, and the node has
 * to read both.
 *
 * The settings panel writes plain keys, so a graph drawn on the canvas never
 * produces the other shape — which is exactly why this went unnoticed. A
 * scenario that arrives any other way, from a host's seeder or an import, says
 * `{"slot": "phone", "required": true}`, and casting that to a string is a
 * fatal in the middle of the bot's turn: the visitor's conversation stops
 * mid-question with no reply and no error anywhere they can see.
 */
it('asks for slots a graph stores as objects', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [
                ['slot' => 'name', 'required' => true],
                ['slot' => 'phone', 'required' => true],
            ],
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Got it.'],
    ]);

    visitorSays($this->conversation, 'I want a quote');

    expect($this->conversation->refresh()->bot_slot)->toBe('name');

    visitorSays($this->conversation, 'Nina');

    expect($this->conversation->refresh()->bot_slot)->toBe('phone');

    visitorSays($this->conversation, '+7 999 123-45-67');

    expect(botSaid($this->conversation))->toContain('Got it.')
        ->and($this->conversation->refresh()->bot_slot)->toBeNull();
});

/**
 * The names the editor shows for a form's variables come from the same reading.
 *
 * The screen that lists them is where an author copies the name into the next
 * node's sentence, and it is drawn before any conversation exists — so the same
 * foreign shape took the editor down rather than the bot.
 */
it('names the variables of a form stored as objects', function (): void {
    $step = Step::factory()->make([
        'type' => 'form',
        'config' => ['slots' => [['slot' => 'name', 'required' => true], 'phone']],
    ]);

    expect(Chatbot::step('form')->writes($step))->toBe(['name', 'phone']);
});

/**
 * An optional slot nobody answers is not a failed form.
 *
 * The author marks it optional because the conversation is worth having
 * without it — a budget the visitor has not decided is the case — and the
 * "could not" exit means fetching a person. Taking that exit over the one
 * answer that did not matter is how a bot hands over a conversation it had
 * already finished.
 */
it('moves on when an optional slot stays unanswered', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [
                ['slot' => 'name', 'required' => true],
                /*
                 * An e-mail rather than a free-text slot: free text accepts
                 * anything, so a form built on it finishes whatever the visitor
                 * types and the branch this checks is never reached. The first
                 * version of this test did exactly that and passed without the
                 * fix.
                 */
                ['slot' => 'email', 'required' => false],
            ],
            'attempts' => 1,
            'else_key' => 'sorry',
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Got it.'],
        ['key' => 'sorry', 'type' => 'say', 'body' => 'Let me fetch someone.'],
    ]);

    visitorSays($this->conversation, 'hello');
    visitorSays($this->conversation, 'Nina');

    expect($this->conversation->refresh()->bot_slot)->toBe('email');

    visitorSays($this->conversation, 'no idea, sorry');

    expect(botSaid($this->conversation))->toContain('Got it.')
        ->and(botSaid($this->conversation))->not->toContain('Let me fetch someone.')
        ->and($this->conversation->refresh()->bot_slot)->toBeNull();
});

/**
 * A slot given up on is not asked again later in the same form.
 *
 * The case only appears when something follows the optional slot: the next
 * answer walks the list again, finds the same empty slot with its attempts
 * counter freshly cleared, and asks for it a second time. The visitor sees the
 * question they have just declined come back after answering something else,
 * which is the point at which people stop writing.
 */
it('does not come back to a slot it gave up on', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [
                ['slot' => 'email', 'required' => false],
                ['slot' => 'name', 'required' => true],
            ],
            'attempts' => 1,
            'else_key' => 'sorry',
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Got it.'],
        ['key' => 'sorry', 'type' => 'say', 'body' => 'Let me fetch someone.'],
    ]);

    visitorSays($this->conversation, 'hello');

    expect($this->conversation->refresh()->bot_slot)->toBe('email');

    visitorSays($this->conversation, 'rather not say');

    expect($this->conversation->refresh()->bot_slot)->toBe('name');

    visitorSays($this->conversation, 'Nina');

    expect($this->conversation->refresh()->bot_slot)->toBeNull()
        ->and(botSaid($this->conversation))->toContain('Got it.');
});

/**
 * A required slot that never arrives still takes the "could not" exit.
 *
 * The half the optional case must not quietly take with it: a form that
 * finishes without the phone number it was added to collect produces a request
 * nobody can answer, and the branch exists to fetch a person before that
 * happens.
 */
it('takes the could-not exit when a required slot stays unanswered', function (): void {
    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [['slot' => 'email', 'required' => true]],
            'attempts' => 1,
            'else_key' => 'sorry',
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Got it.'],
        ['key' => 'sorry', 'type' => 'say', 'body' => 'Let me fetch someone.'],
    ]);

    visitorSays($this->conversation, 'hello');
    visitorSays($this->conversation, 'no idea, sorry');

    expect(botSaid($this->conversation))->toContain('Let me fetch someone.')
        ->and(botSaid($this->conversation))->not->toContain('Got it.');
});

/**
 * Вопрос-выбор внутри анкеты приходит с кнопками.
 *
 * «Пляжный, экскурсионный или комбинированный?» уходил голым текстом: человеку
 * приходилось набирать слово в точности, а канал, рисующий кнопки у каждого
 * другого вопроса, здесь не рисовал ни одной. Нажатая кнопка приходит номером,
 * и разбор понимает его так же, как слово.
 */
it('предлагает варианты выбора кнопками', function (): void {
    Chatbot::registerSlot(
        Slot::choice('trip_type', ['beach' => 'Пляжный', 'tour' => 'Экскурсионный'])->question('Какой отдых планируете?'),
    );

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => ['slots' => ['trip_type']]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Принято: {{trip_type}}.'],
    ]);

    visitorSays($this->conversation, 'хочу отдохнуть');

    $asked = $this->conversation->messages()->where('body', 'Какой отдых планируете?')->sole();

    expect($asked->meta['options'] ?? null)->toBe(['Пляжный', 'Экскурсионный']);

    visitorSays($this->conversation, '2');

    expect(botSaid($this->conversation))->toContain('Принято: tour.');
});

/**
 * «Не знаю» на необязательный вопрос пропускает его сразу.
 *
 * Прежде ответ уходил в разбор, разбор не находил суммы, и бот переспрашивал —
 * спорил с человеком, который только что сказал, что не хочет называть бюджет.
 * А поле свободного текста принимало «не знаю» как ответ, и менеджер читал его
 * как пожелание туриста.
 */
it('пропускает необязательный вопрос по «не знаю» без переспроса', function (): void {
    app()->setLocale('ru');

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [
                ['slot' => 'email', 'required' => false],
                ['slot' => 'name', 'required' => true],
            ],
            'attempts' => 2,
            'else_key' => 'sorry',
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Принято.'],
        ['key' => 'sorry', 'type' => 'say', 'body' => 'Позову человека.'],
    ]);

    visitorSays($this->conversation, 'здравствуйте');

    expect($this->conversation->refresh()->bot_slot)->toBe('email');

    visitorSays($this->conversation, 'Не знаю.');

    expect($this->conversation->refresh()->bot_slot)->toBe('name');
});

/**
 * Необязательный вопрос без вариантов приходит с кнопкой «Пропустить», и она
 * работает. У обязательного такой кнопки нет: пропустить его нельзя.
 */
it('даёт необязательному вопросу кнопку пропуска', function (): void {
    app()->setLocale('ru');

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [
                ['slot' => 'email', 'required' => false],
                ['slot' => 'name', 'required' => true],
            ],
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Принято.'],
    ]);

    visitorSays($this->conversation, 'здравствуйте');

    $asked = $this->conversation->messages()->where('body', __('chatbot::nodes.slots.email.question'))->sole();

    expect($asked->meta['options'] ?? null)->toBe(['Пропустить']);

    visitorSays($this->conversation, 'Пропустить');

    $next = $this->conversation->messages()->where('body', __('chatbot::nodes.slots.name.question'))->sole();

    expect($this->conversation->refresh()->bot_slot)->toBe('name')
        ->and($next->meta['options'] ?? [])->toBe([]);
});

/**
 * Ответ, в котором «не знаю» лишь начало фразы, — это ответ, а не отказ.
 */
it('не принимает за пропуск фразу, которая только начинается с «не знаю»', function (): void {
    app()->setLocale('ru');

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [['slot' => 'company', 'required' => false]],
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Записал: {{company}}.'],
    ]);

    visitorSays($this->conversation, 'здравствуйте');
    visitorSays($this->conversation, 'не знаю точно, ООО Ромашка');

    expect(botSaid($this->conversation))->toContain('Записал: не знаю точно, ООО Ромашка.');
});

/**
 * Вариант выбора, совпавший со словом пропуска, остаётся ответом.
 *
 * Автор, написавший кнопку «Не знаю», имел в виду ответ: в анкете турагентства
 * это «не знаю, есть ли загранпаспорт», и по нему менеджер ставит метку
 * «проверить документы». Прочитанная как «пропустить», нажатая кнопка
 * выбрасывала ровно этот ответ.
 */
it('не путает вариант выбора «Не знаю» с пропуском', function (): void {
    app()->setLocale('ru');

    Chatbot::registerSlot(
        Slot::choice('passport', ['yes' => 'Есть', 'unsure' => 'Не знаю'])->question('Загранпаспорт есть?'),
    );

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [['slot' => 'passport', 'required' => false]],
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Паспорт: {{passport|не уточнено}}.'],
    ]);

    visitorSays($this->conversation, 'здравствуйте');
    visitorSays($this->conversation, 'Не знаю');

    expect(botSaid($this->conversation))->toContain('Паспорт: unsure.');
});

/**
 * Анкета помнит и разобранное, и сказанное.
 *
 * Разобранное нужно для расчёта и записи, сказанное — чтобы показать человеку
 * его же слова. Резюме на подтверждение, собранное из одних разобранных
 * значений, говорило туристу «Даты: 2027-02-01» в ответ на «в феврале», и
 * подтвердить такое — значит подтвердить, что бот понял не то.
 */
it('запоминает ответ дословно рядом с разобранным', function (): void {
    app()->setLocale('ru');

    Chatbot::registerSlot(Slot::money('budget')->question('Какой бюджет?'));

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => ['slots' => ['budget']]],
        ['key' => 'thanks', 'type' => 'say', 'body' => '{{budget}} / {{budget_said}}'],
    ]);

    visitorSays($this->conversation, 'здравствуйте');
    visitorSays($this->conversation, ' 150 тысяч ');

    expect(botSaid($this->conversation))->toContain('150000 / 150 тысяч');
});

/**
 * Пропущенное не возвращается и в следующей анкете того же разговора.
 *
 * Сценарий с правкой резюме прогоняет человека через анкеты заново, и каждый
 * проход спрашивал снова всё, от чего он уже отказался, — бюджет, который он
 * не хочет называть, по разу на каждую правку. Пометка о пропуске жила только
 * до конца анкеты.
 */
it('не спрашивает пропущенное во второй анкете того же разговора', function (): void {
    app()->setLocale('ru');

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'first', 'config' => ['trigger' => 'fallback']],
        ['key' => 'first', 'type' => 'form', 'next_key' => 'second', 'config' => [
            'slots' => [['slot' => 'email', 'required' => false]],
        ]],
        ['key' => 'second', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [['slot' => 'email', 'required' => false], ['slot' => 'name', 'required' => true]],
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Принято.'],
    ]);

    visitorSays($this->conversation, 'здравствуйте');
    visitorSays($this->conversation, 'не скажу');

    expect($this->conversation->refresh()->bot_slot)->toBe('name');
});

/**
 * Нажатая кнопка приходит номером, а не словом, — и анкета обязана прочесть
 * его как слово, которое человек видел.
 *
 * Telegram не умеет нести подпись в данных кнопки: длиннее 64 байт, и драйвер
 * шлёт позицию. Для вопроса-выбора разбор это понимал, а для кнопки
 * «Пропустить» — нет: приходила «1», отказом она не считалась, и «1»
 * записывалась как ответ. На бою это видно в резюме: «Для детей: 1» —
 * и турист подтверждал бы это менеджеру.
 */
it('читает нажатие кнопки «Пропустить» в мессенджере', function (): void {
    app()->setLocale('ru');

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => [
            'slots' => [
                ['slot' => 'company', 'required' => false],
                ['slot' => 'name', 'required' => true],
            ],
        ]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Компания: {{company|не названа}}.'],
    ]);

    visitorSays($this->conversation, 'здравствуйте');

    expect($this->conversation->refresh()->bot_slot)->toBe('company');

    visitorSays($this->conversation, '1');

    expect($this->conversation->refresh()->bot_slot)->toBe('name');

    visitorSays($this->conversation, 'Нина');

    expect(botSaid($this->conversation))->toContain('Компания: не названа.');
});

/**
 * Сказанное дословно у вопроса-выбора — это подпись кнопки, а не её номер.
 *
 * Резюме на подтверждение цитирует `<slot>_said`, и с номером оно читалось
 * как «Формат: 1», «Питание: 2» — бот, который будто читает из сломанной
 * формы. Подтвердить такое — значит подтвердить непонятно что.
 */
it('запоминает подпись нажатой кнопки, а не её номер', function (): void {
    app()->setLocale('ru');

    Chatbot::registerSlot(
        Slot::choice('trip_type', ['beach' => 'Пляжный', 'tour' => 'Экскурсионный'])->question('Какой отдых планируете?'),
    );

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => ['slots' => ['trip_type']]],
        ['key' => 'thanks', 'type' => 'say', 'body' => '{{trip_type}} / {{trip_type_said}}'],
    ]);

    visitorSays($this->conversation, 'хочу отдохнуть');
    visitorSays($this->conversation, '2');

    expect(botSaid($this->conversation))->toContain('tour / Экскурсионный');
});

/**
 * Число вне списка кнопок — это число, а не нажатие.
 *
 * Иначе ответ «10» на вопрос с тремя кнопками превратился бы в третью из них,
 * и подменённый ответ никто бы не заметил.
 */
it('не принимает за нажатие число, которого нет среди кнопок', function (): void {
    app()->setLocale('ru');

    Chatbot::registerSlot(
        Slot::choice('trip_type', ['beach' => 'Пляжный', 'tour' => 'Экскурсионный'])->question('Какой отдых планируете?'),
    );
    Chatbot::registerSlot(Slot::number('nights')->question('Сколько ночей?'));

    scenarioOf([
        ['key' => 'start', 'type' => 'start', 'next_key' => 'who', 'config' => ['trigger' => 'fallback']],
        ['key' => 'who', 'type' => 'form', 'next_key' => 'thanks', 'config' => ['slots' => ['trip_type', 'nights']]],
        ['key' => 'thanks', 'type' => 'say', 'body' => 'Ночей: {{nights}}.'],
    ]);

    visitorSays($this->conversation, 'хочу отдохнуть');
    visitorSays($this->conversation, '9');

    expect($this->conversation->refresh()->bot_slot)->toBe('trip_type');

    visitorSays($this->conversation, 'Пляжный');
    visitorSays($this->conversation, '10');

    expect(botSaid($this->conversation))->toContain('Ночей: 10.');
});
