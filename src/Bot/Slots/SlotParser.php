<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Slots;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads a human answer into the shape a slot promised to store.
 *
 * People do not answer questions in the format a database column wants. Asked
 * for a phone they write "+7 (999) 123-45-67" or "8 999 1234567"; asked how
 * many they write "two"; asked when they write "10.03". A bot that only
 * accepted canonical input would reject nearly every real answer and hand the
 * conversation to a person over punctuation, which is the one outcome a bot
 * exists to avoid.
 *
 * The opposite failure is worse and quieter: reading an answer into something
 * plausible but wrong. That is why an unreadable answer returns
 * {@see SlotValue::missed()} instead of a best guess — one more question costs
 * a few seconds, a wrong date costs a booking.
 */
class SlotParser
{
    private const TEXT_LIMIT = 1000;

    private const LONG_TEXT_LIMIT = 4000;

    private const MIN_PHONE_DIGITS = 10;

    private const MAX_PHONE_DIGITS = 15;

    /**
     * The factors behind `chatbot::bot.money_multipliers`. The words are
     * translated, the factors are not, so they live here and every language
     * file answers to the same two keys.
     *
     * @var array<string, int>
     */
    private const MULTIPLIERS = ['thousand' => 1000, 'million' => 1000000];

    /**
     * Spelled-out numbers understood without any host configuration.
     *
     * English only, because that is the one language the package itself can
     * claim to speak. A host adds its own under `chatbot.slots.number_words`.
     *
     * @var array<string, int>
     */
    private const NUMBER_WORDS = [
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
    ];

    /**
     * Words that separate the two ends of a range.
     *
     * A bare hyphen is deliberately absent: it is also the separator inside an
     * ISO date, and splitting "2026-05-10 to 2026-05-17" on it would yield six
     * numbers instead of two dates. Ranges are found by looking for two date
     * tokens instead of by cutting the sentence in half.
     */
    private const DATE_TOKEN_PATTERN = '/\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[.\/]\d{1,2}(?:[.\/]\d{2,4})?|today|tomorrow/iu';

    public function parse(Slot $slot, ?string $answer): SlotValue
    {
        $raw = $answer ?? '';

        if (trim($raw) === '') {
            return SlotValue::missed($raw);
        }

        return match ($slot->kind()) {
            SlotKind::Text => $this->parseFreeText($raw, self::TEXT_LIMIT),
            SlotKind::LongText => $this->parseFreeText($raw, self::LONG_TEXT_LIMIT),
            SlotKind::Name => $this->parseName($raw),
            SlotKind::Phone => $this->parsePhone($raw),
            SlotKind::Email => $this->parseEmail($raw),
            SlotKind::Number => $this->parseNumber($raw),
            SlotKind::Money => $this->parseMoney($raw),
            SlotKind::Date => $this->parseDateAnswer($raw),
            SlotKind::DateRange => $this->parseDateRange($raw),
            SlotKind::Choice => $this->parseChoice($slot, $raw),
        };
    }

    /**
     * Free text is capped rather than rejected.
     *
     * The limit guards the column and the reply, not the person: someone who
     * pastes a whole email thread into "tell me what you need" has answered the
     * question, and refusing them would restart a conversation over length.
     */
    private function parseFreeText(string $raw, int $limit): SlotValue
    {
        return SlotValue::parsed(mb_substr(trim($raw), 0, $limit));
    }

    /**
     * The name out of the sentence it was answered in.
     *
     * «Меня зовут Ирина» became the customer's name verbatim: the bot addressed
     * her as "Меня зовут Ирина" in the next sentence, and the same string went
     * into her card. The lead-ins below are the handful people actually use,
     * and only at the very start of the answer — a surname that happens to
     * contain one of them keeps it.
     *
     * **Nothing is ever rejected here.** Trimming that leaves nothing — "меня
     * зовут" and no more — gives the original back rather than an empty name,
     * because an answered question must not be asked again over a parse this
     * class got wrong. The same rule caps the result at four words rather than
     * refusing a long one: «Пётр Иванович Иванов» and «Иван да Марья» are real
     * answers, a paragraph is not a name, and keeping its first four words is
     * closer to right than keeping all of it.
     */
    private function parseName(string $raw): SlotValue
    {
        $answer = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);

        $leadIns = [
            'меня зовут', 'моё имя', 'мое имя', 'зовут меня', 'зовут', 'это',
            'my name is', "i'm", 'i am', "it's", 'this is', 'call me', 'я',
        ];

        $lowered = mb_strtolower($answer);

        foreach ($leadIns as $leadIn) {
            if (str_starts_with($lowered, $leadIn.' ')) {
                $answer = trim(mb_substr($answer, mb_strlen($leadIn) + 1));

                break;
            }
        }

        /*
         * Знаки по краям снимаются выражением, а не `trim` со списком символов.
         * `trim` режет байты: длинное тире в таком списке — это три байта, один
         * из которых совпадает со вторым байтом буквы «р», и «Пётр» превращался
         * в битую строку. Дальше `preg_split` по ней отвечал `false`, имя
         * оказывалось пустым, и разбор возвращал исходную фразу целиком —
         * то есть ровно то, от чего этот метод и написан.
         */
        $answer = (string) preg_replace('/^[\s\p{Pd}.,;:!]+|[\s\p{Pd}.,;:!]+$/u', '', $answer);
        $words = array_slice(preg_split('/\s+/u', $answer) ?: [], 0, 4);
        $answer = trim(implode(' ', $words));

        return SlotValue::parsed(mb_substr(
            $answer === '' ? trim($raw) : $answer,
            0,
            self::TEXT_LIMIT,
        ));
    }

    /**
     * One number, written five ways, read as one number.
     *
     * This is not national specificity smuggled into a general package — it is
     * the fact that "+7 (999) 123-45-67", "8 999 1234567" and "79991234567" are
     * the same person, and the host is expected to find them by that number.
     * Leaving the punctuation in means the same caller becomes three records and
     * a second conversation never recognises the first. The leading-8 rule
     * belongs to the same problem: in numbering plans that use a trunk prefix
     * the person types the digit they dial, and only the eleven-digit case is
     * unambiguous enough to rewrite — a ten-digit number starting with 8 is a
     * subscriber number, not a prefixed one, and touching it would corrupt it.
     */
    private function parsePhone(string $raw): SlotValue
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $length = strlen($digits);

        if ($length < self::MIN_PHONE_DIGITS || $length > self::MAX_PHONE_DIGITS) {
            return SlotValue::missed($raw);
        }

        if ($length === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }

        return SlotValue::parsed('+'.$digits, ['digits' => $digits]);
    }

    /**
     * The address is pulled out of the sentence around it.
     *
     * Demanding a message that is nothing but an address fails on "write to
     * me at a@b.ru please", which is how people answer, and the re-ask that
     * follows reads as the bot being unable to see what is plainly there.
     */
    private function parseEmail(string $raw): SlotValue
    {
        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $raw, $match) !== 1) {
            return SlotValue::missed($raw);
        }

        $address = filter_var($match[0], FILTER_VALIDATE_EMAIL);

        if (! is_string($address)) {
            return SlotValue::missed($raw);
        }

        return SlotValue::parsed($address);
    }

    /**
     * Digits and words are the same answer.
     *
     * "2" and "two" both answer "how many of you are travelling", and a bot
     * that understands only one of them looks broken to half the people who
     * reply. A minus sign is refused instead of being dropped: negative counts
     * do not exist, and silently reading "-2" as 2 would store a number nobody
     * typed.
     */
    private function parseNumber(string $raw): SlotValue
    {
        if (preg_match('/-?\d+/', $raw, $match) === 1) {
            if (str_starts_with($match[0], '-')) {
                return SlotValue::missed($raw);
            }

            return SlotValue::parsed((string) (int) $match[0]);
        }

        foreach ($this->numberWords() as $word => $number) {
            if ($number < 0 || $word === '') {
                continue;
            }

            if (preg_match('/(?<!\p{L})'.preg_quote((string) $word, '/').'(?!\p{L})/iu', $raw) === 1) {
                return SlotValue::parsed((string) $number);
            }
        }

        return SlotValue::missed($raw);
    }

    /**
     * @return array<string, int>
     */
    private function numberWords(): array
    {
        $configured = config('chatbot.slots.number_words');

        return is_array($configured) && $configured !== [] ? $configured : self::NUMBER_WORDS;
    }

    /**
     * An amount, with everything a person puts around it removed.
     *
     * The separator is decided by what follows it, not by locale: three digits
     * after the last separator mean thousands ("$1,200"), one or two mean a
     * fraction ("1 200,50"). Guessing by locale instead would turn one of those
     * two into a thousandfold error in a number someone is quoted back.
     */
    private function parseMoney(string $raw): SlotValue
    {
        $amounts = $this->amounts($raw);

        if ($amounts === []) {
            return SlotValue::missed($raw);
        }

        if (count($amounts) >= 2 && $this->joinedAsRange($raw, $amounts[0], $amounts[1])) {
            $ends = [$amounts[0]['value'], $amounts[1]['value']];
            usort($ends, static fn (string $left, string $right): int => (float) $left <=> (float) $right);

            return SlotValue::parsed($ends[1], ['from' => $ends[0], 'to' => $ends[1]]);
        }

        return SlotValue::parsed($amounts[0]['value']);
    }

    /**
     * Every amount in the answer, with its multiplier applied.
     *
     * "150 тысяч" is how a budget is said, and reading only the digits stored
     * a hundred and fifty roubles — a figure the visitor was then asked to
     * confirm, and the manager to search tours with. The left end of a range
     * borrows the right end's multiplier ("200–230 тысяч") but only while it is
     * too small to be an amount on its own: "от 150000 до 200 тысяч" already
     * says what it means.
     *
     * @return list<array{value: string, start: int, end: int}>
     */
    private function amounts(string $raw): array
    {
        $grouped = '\d{1,3}(?:[\s\x{00A0}\x{202F}]\d{3})+(?:[.,]\d{1,2})?(?!\d)';
        $plain = '\d+(?:[.,]\d+)*';

        if (preg_match_all('/'.$grouped.'|'.$plain.'/u', $raw, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $found = [];

        foreach ($matches[0] as $index => [$token, $start]) {
            $end = $start + strlen($token);
            $next = $matches[0][$index + 1][1] ?? strlen($raw);

            $found[] = [
                'number' => $this->amountOf($token),
                'multiplier' => $this->multiplierAfter(substr($raw, $end, $next - $end)),
                'start' => $start,
                'end' => $end,
            ];
        }

        foreach ($found as $index => $amount) {
            $following = $found[$index + 1] ?? null;

            if ($amount['multiplier'] === null
                && $following !== null
                && $following['multiplier'] !== null
                && (float) $amount['number'] < 1000
                && $this->joinedAsRange($raw, $amount, $following)) {
                $found[$index]['multiplier'] = $following['multiplier'];
            }
        }

        return array_map(fn (array $amount): array => [
            'value' => $amount['multiplier'] === null
                ? $amount['number']
                : $this->multiplied($amount['number'], $amount['multiplier']),
            'start' => $amount['start'],
            'end' => $amount['end'],
        ], $found);
    }

    private function amountOf(string $token): string
    {
        $number = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $token) ?? '';
        $separator = $this->lastSeparatorPosition($number);

        if ($separator === null) {
            return $number;
        }

        $fraction = substr($number, $separator + 1);
        $whole = str_replace([',', '.'], '', substr($number, 0, $separator));

        if (strlen($fraction) === 3) {
            return $whole.$fraction;
        }

        return $whole.'.'.$fraction;
    }

    private function multiplierAfter(string $text): ?int
    {
        foreach ($this->moneyMultipliers() as $pattern => $multiplier) {
            if (preg_match('/^[\s\x{00A0}]*(?:'.$pattern.')(?!\p{L})/iu', $text) === 1) {
                return (int) $multiplier;
            }
        }

        return null;
    }

    private function multiplied(string $number, int $multiplier): string
    {
        $formatted = number_format((float) $number * $multiplier, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * Whether two amounts are the ends of one range rather than two figures.
     *
     * Only what stands between them decides: "200–230", "от 200 до 230",
     * "200 to 230". "2 взрослых, 200 тысяч" holds two numbers and no range.
     *
     * @param  array{start: int, end: int}  $left
     * @param  array{start: int, end: int}  $right
     */
    private function joinedAsRange(string $raw, array $left, array $right): bool
    {
        $between = substr($raw, $left['end'], $right['start'] - $left['end']);
        $between = preg_replace('/^[\s\x{00A0}]*(?:'.implode('|', array_keys($this->moneyMultipliers())).')(?!\p{L})\.?/iu', '', $between) ?? $between;

        return preg_match('/^[\s\x{00A0}]*(?:-|–|—|\.\.\.?|…|до|по|to|until)[\s\x{00A0}]*$/iu', $between) === 1;
    }

    /**
     * Word patterns of the app's language and English, each with its factor.
     *
     * @return array<string, int>
     */
    private function moneyMultipliers(): array
    {
        $words = [];

        foreach ([null, 'en'] as $locale) {
            $multipliers = __('chatbot::bot.money_multipliers', [], $locale);

            if (! is_array($multipliers)) {
                continue;
            }

            foreach (self::MULTIPLIERS as $name => $factor) {
                foreach ((array) ($multipliers[$name] ?? []) as $pattern) {
                    if (is_string($pattern) && $pattern !== '') {
                        $words[$pattern] = $factor;
                    }
                }
            }
        }

        return $words;
    }

    private function lastSeparatorPosition(string $number): ?int
    {
        $positions = array_filter([strrpos($number, ','), strrpos($number, '.')], 'is_int');

        return $positions === [] ? null : max($positions);
    }

    /**
     * A date answer. A month named without a day is stored as its first day,
     * with the month itself kept as an extra: «в феврале» is a request for
     * February, not for the first of it, and a host that books by it has to
     * be able to tell the two apart.
     */
    private function parseDateAnswer(string $raw): SlotValue
    {
        $spelled = $this->spelledDate(mb_strtolower(trim($raw)));

        if ($spelled !== null && $spelled['day'] === null) {
            $month = $this->monthAhead($spelled['month'], $spelled['year']);

            return SlotValue::parsed($month->format('Y-m-d'), ['month' => $month->format('Y-m')]);
        }

        $date = $this->parseDate($raw);

        return $date === null
            ? SlotValue::missed($raw)
            : SlotValue::parsed($date->format('Y-m-d'));
    }

    /**
     * A date with the month written as a word: «12 июня», «в феврале 2027»,
     * "June 3rd" is not handled — the day goes first, as it is said in both
     * languages the package ships.
     *
     * Before this, every such answer fell through to Carbon, which knows no
     * Russian at all and reads a bare "february" as February of the current
     * year even in November — a date in the past that nothing downstream
     * questions. A date question in a travel form is answered with a month
     * more often than with digits, so the form either re-asked it until the
     * visitor gave up or recorded a trip that had already happened.
     *
     * @return array{day: ?int, month: int, year: ?int}|null
     */
    private function spelledDate(string $text): ?array
    {
        foreach ($this->monthPatterns() as $index => $pattern) {
            $found = preg_match(
                '/(?:(\d{1,2})[\s\x{00A0}]*)?(?<!\p{L})(?:'.$pattern.')(?!\p{L})\.?(?:[\s\x{00A0}]*(\d{4}))?/iu',
                $text,
                $match,
            );

            if ($found === 1) {
                return [
                    'day' => ($match[1] ?? '') !== '' ? (int) $match[1] : null,
                    'month' => $index + 1,
                    'year' => ($match[2] ?? '') !== '' ? (int) $match[2] : null,
                ];
            }
        }

        return null;
    }

    /**
     * The month named, never behind today: the current month means this one,
     * an earlier month means next year's.
     */
    private function monthAhead(int $month, ?int $year): CarbonImmutable
    {
        $today = CarbonImmutable::today();

        if ($year !== null) {
            return CarbonImmutable::create($year, $month, 1)->startOfDay();
        }

        $thisYear = CarbonImmutable::create($today->year, $month, 1)->startOfDay();

        return $thisYear->endOfMonth()->lt($today) ? $thisYear->addYear() : $thisYear;
    }

    /**
     * Twelve patterns, January first: the app's language, then English.
     *
     * @return list<string>
     */
    private function monthPatterns(): array
    {
        $patterns = array_fill(0, 12, []);

        foreach ([null, 'en'] as $locale) {
            $months = __('chatbot::bot.months', [], $locale);

            if (! is_array($months)) {
                continue;
            }

            foreach (array_values($months) as $index => $pattern) {
                if ($index < 12 && is_string($pattern) && $pattern !== '') {
                    $patterns[$index][] = $pattern;
                }
            }
        }

        return array_map(static fn (array $alternatives): string => implode('|', array_unique($alternatives)) ?: '(?!)', $patterns);
    }

    /**
     * A date, with a missing year resolved forwards.
     *
     * "10.03" answered in December means next March. Filling in the current
     * year — the obvious implementation — would produce a date nine months in
     * the past, and nothing downstream would object: the bot confirms a trip
     * that already happened, the request reaches an operator dated backwards,
     * and the mistake surfaces only when someone reads the card.
     */
    private function parseDate(string $raw): ?CarbonImmutable
    {
        $text = mb_strtolower(trim($raw));
        $today = CarbonImmutable::today();

        if ($text === 'today') {
            return $today;
        }

        if ($text === 'tomorrow') {
            return $today->addDay();
        }

        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $match) === 1) {
            return $this->makeDate((int) $match[3], (int) $match[2], (int) $match[1]);
        }

        if (preg_match('/(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?/', $text, $match) === 1) {
            $day = (int) $match[1];
            $month = (int) $match[2];

            if (($match[3] ?? '') !== '') {
                $year = (int) $match[3];

                return $this->makeDate($day, $month, $year < 100 ? 2000 + $year : $year);
            }

            $thisYear = $this->makeDate($day, $month, $today->year);

            if ($thisYear === null) {
                return $this->makeDate($day, $month, $today->year + 1);
            }

            return $thisYear->lt($today)
                ? $this->makeDate($day, $month, $today->year + 1) ?? $thisYear
                : $thisYear;
        }

        $spelled = $this->spelledDate($text);

        if ($spelled !== null) {
            $day = $spelled['day'] ?? 1;

            if ($spelled['year'] !== null) {
                return $this->makeDate($day, $spelled['month'], $spelled['year']);
            }

            $thisYear = $this->makeDate($day, $spelled['month'], $today->year);

            return $thisYear === null || $thisYear->lt($today)
                ? $this->makeDate($day, $spelled['month'], $today->year + 1) ?? $thisYear
                : $thisYear;
        }

        try {
            return CarbonImmutable::parse($text)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function makeDate(int $day, int $month, int $year): ?CarbonImmutable
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)?->startOfDay();
    }

    /**
     * Two dates out of one sentence, in the order a calendar wants them.
     *
     * Reversed ends are reordered rather than refused, because "17th to the
     * 10th" is a slip of the fingers and not a different request, and because
     * a stored range whose end precedes its start breaks every query written
     * against it. A single date is refused instead: guessing the other end —
     * a week, a night — invents a stay nobody asked for.
     */
    private function parseDateRange(string $raw): SlotValue
    {
        if (preg_match_all(self::DATE_TOKEN_PATTERN, $raw, $matches) === false) {
            return SlotValue::missed($raw);
        }

        $dates = [];

        foreach ($matches[0] as $token) {
            $date = $this->parseDate($token);

            if ($date !== null) {
                $dates[] = $date;
            }

            if (count($dates) === 2) {
                break;
            }
        }

        if (count($dates) < 2) {
            return SlotValue::missed($raw);
        }

        [$from, $to] = $dates;

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return SlotValue::parsed(
            $from->format('d.m.Y').' – '.$to->format('d.m.Y'),
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        );
    }

    /**
     * An option matched by its label, its key, or its position in the list.
     *
     * The position is not a convenience. Only the site widget can draw buttons;
     * in a messenger, over email or on the phone the bot reads the options out
     * as a numbered list, and the person replies "2". Accepting the label alone
     * would leave the bot working in exactly one channel and stuck in every
     * other one.
     */
    private function parseChoice(Slot $slot, string $raw): SlotValue
    {
        $options = $slot->choices();

        if ($options === []) {
            return SlotValue::missed($raw);
        }

        $answer = $this->normalise($raw);

        foreach ($options as $key => $label) {
            if ($this->normalise($label) === $answer) {
                return SlotValue::parsed((string) $key, ['label' => $label]);
            }
        }

        foreach ($options as $key => $label) {
            if ($this->normalise((string) $key) === $answer) {
                return SlotValue::parsed((string) $key, ['label' => $label]);
            }
        }

        if (preg_match('/^\d+$/', $answer) === 1) {
            $keys = array_keys($options);
            $index = (int) $answer - 1;

            if (array_key_exists($index, $keys)) {
                $key = $keys[$index];

                return SlotValue::parsed((string) $key, ['label' => $options[$key]]);
            }
        }

        return SlotValue::missed($raw);
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
