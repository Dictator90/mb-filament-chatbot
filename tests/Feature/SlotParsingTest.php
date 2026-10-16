<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use MB\FilamentChatbot\Bot\Slots\Slot;
use MB\FilamentChatbot\Bot\Slots\SlotParser;
use MB\FilamentChatbot\ChatbotServiceProvider;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/*
 * Эти проверки написаны на русских ответах, и «8 → +7» — российское правило.
 * Пакет больше не зашивает ни того, ни другого: русские слова лежат в файле
 * языка, а префикс — в конфиге, по умолчанию пустом. Здесь включено то и другое,
 * как на русской установке; что без настройки восьмёрка не трогается, проверено
 * отдельно.
 */
beforeEach(function (): void {
    app()->setLocale('ru');
    config()->set('chatbot.slots.phone_trunk_prefixes', ['8' => '7']);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/**
 * Чужой номер с ведущей восьмёркой не превращается в российский.
 *
 * Правило «8 → +7» национальное и было зашито в разборщик: одиннадцать цифр,
 * начинающихся с 8, на любой установке становились номером России.
 */
it('не переписывает ведущую восьмёрку без настройки', function (): void {
    config()->set('chatbot.slots.phone_trunk_prefixes', []);

    expect((new SlotParser)->parse(Slot::phone('phone'), '8 999 123 45 67')->value())->toBe('+89991234567');
});

/**
 * One number written five ways has to become one number.
 *
 * This is the failure that costs the most and shows the least: every spelling
 * stored verbatim means the same caller is three records, the host's lookup by
 * phone finds nothing, and the second conversation starts from zero while every
 * screen still looks correct.
 */
it('reads one phone number out of every way a person writes it', function (): void {
    $parser = new SlotParser;
    $slot = Slot::phone('phone');

    $spellings = [
        '+7 (999) 123-45-67',
        '8 999 123 45 67',
        '79991234567',
        '8-999-123-45-67',
        'мой телефон +7 999 123 45 67, звоните',
    ];

    foreach ($spellings as $spelling) {
        $value = $parser->parse($slot, $spelling);

        expect($value->ok())->toBeTrue()
            ->and($value->extra()['digits'])->toBe('79991234567')
            ->and($value->value())->toBe('+79991234567');
    }
});

/**
 * The trunk prefix is rewritten only where it is unambiguous.
 *
 * Eleven digits beginning with 8 are a prefixed number and must match the same
 * person as the 7 form. Ten digits beginning with 8 are a subscriber number,
 * and rewriting that first digit would hand the host a number nobody owns.
 */
it('rewrites a leading eight only on an eleven digit number', function (): void {
    $parser = new SlotParser;
    $slot = Slot::phone('phone');

    expect($parser->parse($slot, '8 999 123 45 67')->extra()['digits'])->toBe('79991234567')
        ->and($parser->parse($slot, '8991234567')->extra()['digits'])->toBe('8991234567')
        ->and($parser->parse($slot, '8991234567')->value())->toBe('+8991234567');
});

/**
 * People answer with a sentence, not with an address on its own line. Demanding
 * the bare address turns "write to me at ..." into a re-ask of something the
 * person plainly answered.
 */
it('takes the address out of the sentence around it', function (): void {
    $value = (new SlotParser)->parse(Slot::email('email'), 'пиши на a.b+c@example.co.uk, пожалуйста');

    expect($value->ok())->toBeTrue()
        ->and($value->value())->toBe('a.b+c@example.co.uk');
});

/**
 * "2" and "two" answer the same question. Understanding only the digit makes
 * the bot re-ask half the people who already replied.
 */
it('counts both digits and spelled out numbers', function (): void {
    $parser = new SlotParser;
    $slot = Slot::number('guests');

    expect($parser->parse($slot, 'we are 4 in total')->value())->toBe('4')
        ->and($parser->parse($slot, 'three of us')->value())->toBe('3')
        ->and($parser->parse($slot, 'nobody at all')->ok())->toBeFalse();
});

/**
 * Amounts are quoted back to the person and stored as money. Reading the last
 * separator by locale instead of by what follows it turns one of these two into
 * a thousandfold error in a figure someone is asked to confirm.
 */
it('reads an amount whatever the separators are', function (): void {
    $parser = new SlotParser;
    $slot = Slot::money('budget');

    expect($parser->parse($slot, '1 200,50 руб')->value())->toBe('1200.50')
        ->and($parser->parse($slot, '$1,200')->value())->toBe('1200')
        ->and($parser->parse($slot, 'about 1 200.50 EUR')->value())->toBe('1200.50');
});

/**
 * The most expensive mistake in this file.
 *
 * "10.03" answered in December means next March. Filling in the current year —
 * the obvious implementation — dates the trip nine months into the past, and
 * nothing downstream objects: the bot confirms it, the operator's card shows
 * it, and a person notices only by reading.
 */
it('sends a date with no year forwards, never into the past', function (): void {
    CarbonImmutable::setTestNow('2026-12-15 10:00:00');
    Carbon::setTestNow('2026-12-15 10:00:00');

    $parser = new SlotParser;
    $slot = Slot::date('departure');

    expect($parser->parse($slot, '10.03')->value())->toBe('2027-03-10')
        ->and($parser->parse($slot, '20.12')->value())->toBe('2026-12-20')
        ->and($parser->parse($slot, 'tomorrow')->value())->toBe('2026-12-16')
        ->and($parser->parse($slot, '2027-01-09')->value())->toBe('2027-01-09');
});

/**
 * A range whose end precedes its start breaks every query written against it,
 * and the slip that produces one is a slip of the fingers, not a different
 * request. A single date stays unread: inventing the other end invents a stay.
 */
it('orders a range and refuses a half of one', function (): void {
    $parser = new SlotParser;
    $slot = Slot::dateRange('stay');

    $value = $parser->parse($slot, 'с 17.05.2026 - 10.05.2026');

    expect($value->ok())->toBeTrue()
        ->and($value->extra())->toBe(['from' => '2026-05-10', 'to' => '2026-05-17'])
        ->and($value->value())->toBe('10.05.2026 – 17.05.2026')
        ->and($parser->parse($slot, '10.05.2026 to 17.05.2026')->extra())
        ->toBe(['from' => '2026-05-10', 'to' => '2026-05-17'])
        ->and($parser->parse($slot, 'starting 10.05.2026')->ok())->toBeFalse();
});

/**
 * Only the site widget draws buttons. Everywhere else the bot reads the options
 * out as a numbered list and the person replies "3" — accepting labels alone
 * leaves the bot working in exactly one channel.
 */
it('matches an option by label, by number and by key', function (): void {
    $parser = new SlotParser;
    $slot = Slot::choice('urgency', [
        'now' => 'Right now',
        'today' => 'Today',
        'this_week' => 'This week',
        'no_rush' => 'No rush',
    ]);

    expect($parser->parse($slot, '  right NOW ')->value())->toBe('now')
        ->and($parser->parse($slot, '3')->value())->toBe('this_week')
        ->and($parser->parse($slot, 'no_rush')->value())->toBe('no_rush')
        ->and($parser->parse($slot, 'whenever')->ok())->toBeFalse();
});

/**
 * An unread answer must stay quotable.
 *
 * The re-ask reads the person's own words back, and an operator taking the
 * conversation over reads the same line. A missed value that dropped or
 * reshaped what was typed would make both of them look at something nobody
 * wrote.
 */
it('keeps the answer exactly as typed when it cannot be read', function (): void {
    $answer = '  позвоните мне вечером, номер у вас есть  ';
    $value = (new SlotParser)->parse(Slot::phone('phone'), $answer);

    expect($value->ok())->toBeFalse()
        ->and($value->value())->toBeNull()
        ->and($value->raw())->toBe($answer)
        ->and($value->extra())->toBe([]);
});

/**
 * The built-in slots have to be there without the host registering anything —
 * an empty picker is how an author concludes the package is broken.
 */
it('registers its own slots on boot', function (): void {
    app()->setLocale('en');

    $slots = app(ChatbotRegistry::class)->slots();

    expect(array_keys($slots))->toContain('name', 'phone', 'email', 'contact_preference', 'company', 'request', 'urgency', 'order_number')
        ->and($slots)->toHaveCount(8)
        ->and($slots['order_number']->name())->toBe('Reference number');
});

/**
 * A host that redefines a built-in slot keeps its definition.
 *
 * Providers boot in load order, so a package registering slots before this one
 * would have them silently reverted to the package defaults — on some installs
 * and not others, depending on nothing but a manifest. The author sees their
 * own wording in the editor and cannot tell where it went.
 */
it('never displaces a slot the host registered itself', function (): void {
    $registry = app(ChatbotRegistry::class);
    $registry->registerSlot(Slot::phone('phone')->label('Mobile only')->question('Mobile, please'));

    (new ChatbotServiceProvider(app()))->boot();

    expect($registry->slot('phone')->name())->toBe('Mobile only')
        ->and($registry->slot('phone')->defaultQuestion())->toBe('Mobile, please');
});

/**
 * Имя достаётся из фразы, которой на вопрос и отвечают.
 *
 * Одним словом на «как вас зовут?» не отвечает почти никто: пишут «меня зовут
 * Ирина», «я Пётр». Свободный текст сохранял фразу целиком — и бот следующей
 * репликой говорил «Меня зовут Ирина, оформляю заявку на подбор», а в карточку
 * клиента уезжало то же самое. Поймано на бою.
 */
it('достаёт имя из вежливой фразы', function (string $said, string $expected): void {
    $slot = app(ChatbotRegistry::class)->slot('name');

    expect(app(SlotParser::class)->parse($slot, $said)->value())->toBe($expected);
})->with([
    'меня зовут' => ['Меня зовут Ирина', 'Ирина'],
    'я' => ['Я Пётр', 'Пётр'],
    'это' => ['Это Нина', 'Нина'],
    'по-английски' => ["It's Nina", 'Nina'],
    'с точкой' => ['Меня зовут Ирина.', 'Ирина'],
    'просто имя' => ['Ирина', 'Ирина'],
    /* Фамилия с «я» внутри не теряет начала: зацепка снимается только в начале ответа. */
    'не трогает середину' => ['Яковлев Пётр', 'Яковлев Пётр'],
    'отчество на месте' => ['Меня зовут Пётр Иванович Иванов', 'Пётр Иванович Иванов'],
]);

/**
 * Разбор, оставивший пустоту, отдаёт сказанное как есть.
 *
 * Иначе вопрос пришлось бы задавать второй раз — из-за того, что эта строка
 * не поняла ответ, а не из-за того, что человек его не дал.
 */
it('не теряет ответ, в котором одна зацепка', function (): void {
    expect(app(SlotParser::class)->parse(app(ChatbotRegistry::class)->slot('name'), 'Меня зовут')->value())
        ->toBe('Меня зовут');
});

/**
 * Бюджет называют с множителем, и читать одни цифры — значит записать сто
 * пятьдесят рублей вместо ста пятидесяти тысяч. Эту цифру турист потом видел
 * в резюме на подтверждение, а менеджер искал по ней туры.
 */
it('понимает тысячи и миллионы в сумме', function (): void {
    app()->setLocale('ru');

    $parser = new SlotParser;
    $slot = Slot::money('budget');

    expect($parser->parse($slot, '150 тысяч')->value())->toBe('150000')
        ->and($parser->parse($slot, 'около 150к на двоих')->value())->toBe('150000')
        ->and($parser->parse($slot, '1,5 млн')->value())->toBe('1500000')
        ->and($parser->parse($slot, '220 000 руб')->value())->toBe('220000')
        ->and($parser->parse($slot, '150 km')->value())->toBe('150');
});

/**
 * Вилка «от и до» остаётся вилкой: значение — верхний край («не дороже»),
 * края — отдельно. Левый край без своего множителя берёт правый, но только
 * пока сам слишком мал, чтобы быть суммой.
 */
it('читает вилку бюджета', function (): void {
    app()->setLocale('ru');

    $parser = new SlotParser;
    $slot = Slot::money('budget');

    $range = $parser->parse($slot, '200–230 тысяч');

    expect($range->value())->toBe('230000')
        ->and($range->extra())->toBe(['from' => '200000', 'to' => '230000'])
        ->and($parser->parse($slot, 'от 150000 до 200 тыс')->extra())->toBe(['from' => '150000', 'to' => '200000'])
        ->and($parser->parse($slot, '2 взрослых, 200 тысяч')->extra())->toBe([]);
});

/**
 * Дату в поездку называют месяцем чаще, чем цифрами. Прежде «в феврале» не
 * разбиралось вовсе, а голое "february" Carbon читал февралём текущего года —
 * в ноябре это дата в прошлом, которую никто ниже по течению не оспорит.
 */
it('понимает месяц словом и не уходит в прошлое', function (): void {
    app()->setLocale('ru');
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    Carbon::setTestNow('2026-11-15 10:00:00');

    $parser = new SlotParser;
    $slot = Slot::date('departure');

    $month = $parser->parse($slot, 'в феврале');

    expect($month->value())->toBe('2027-02-01')
        ->and($month->extra())->toBe(['month' => '2027-02'])
        ->and($parser->parse($slot, '12 июня')->value())->toBe('2027-06-12')
        ->and($parser->parse($slot, 'декабрь 2026')->extra())->toBe(['month' => '2026-12'])
        ->and($parser->parse($slot, 'в ноябре')->value())->toBe('2026-11-01')
        ->and($parser->parse($slot, 'february')->value())->toBe('2027-02-01')
        ->and($parser->parse($slot, 'маяк')->ok())->toBeFalse();
});
