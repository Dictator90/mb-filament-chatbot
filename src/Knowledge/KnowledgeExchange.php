<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Knowledge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MB\FilamentChatbot\Enums\KnowledgeMode;
use MB\FilamentChatbot\Models\KnowledgeEntry;

/**
 * The knowledge base as a file: export it, write the answers in a spreadsheet,
 * load it back.
 *
 * The base is the one part of a bot nobody can seed for a company: an answer is
 * the company's own voice. And it is written the way anything long gets written
 * — in a spreadsheet, over a fortnight, by somebody who is not going to open a
 * hundred and seventy forms to do it. Without a file the base stays half empty
 * and the bot keeps saying it does not know.
 *
 * **An empty cell means "I did not touch this row", never "erase what is
 * there".** One decision for every text column, and here is why. The file goes
 * around for weeks: somebody exports a hundred and seventy topics and writes
 * answers in the evenings, while a colleague fixes one answer in the panel. An
 * overwriting load would return that fix to nothing — silently, in a row the
 * first person never opened. The cost the other way round is small and
 * immediate: to erase an answer for good, you open the topic and clear the
 * field. How many answers survived an empty cell is in the plan
 * ({@see KnowledgeImportPlan::summary()}) — without that the decision would be
 * a surprise rather than a decision.
 *
 * **CSV rather than a spreadsheet format.** Every column here is text, so there
 * is nothing for a locale to mangle; and the file has to be *read back*, which
 * a spreadsheet reader would mean adding as a dependency for one screen. Nine
 * lines of CSV parsing costs less.
 *
 * The two ends of the round trip disagree about almost everything by default —
 * the byte order mark, the delimiter, the escape character — and each of the
 * constants below is one of those disagreements, settled.
 */
final class KnowledgeExchange
{
    /**
     * The three bytes by which Excel on Windows recognises UTF-8.
     *
     * Without them a localised Excel reads the file in the system codepage and
     * shows mojibake instead of answers. A file that opened broken never gets
     * edited: whoever opened it decides the export does not work and goes back
     * to editing one topic at a time.
     */
    public const BOM = "\xEF\xBB\xBF";

    /**
     * The column separator is a semicolon.
     *
     * Excel does not take a comma as one: it reads the separator from the
     * locale's list separator, which in a good part of the world is a
     * semicolon. A file written with commas opens as one column per row, and
     * there is nothing in it to edit. A semicolon inside an answer costs
     * nothing — `fputcsv` quotes such a cell and parsing returns it whole.
     */
    public const DELIMITER = ';';

    /**
     * What separates keywords inside one cell.
     *
     * A comma, precisely because the columns are not separated by one: a comma
     * inside a cell needs no quoting, the file stays readable by eye, and a
     * person writes the list the way they would write it by hand.
     */
    public const KEYWORD_SEPARATOR = ',';

    /**
     * The ceiling on rows in a loaded file.
     *
     * A large base is a few hundred topics. A thousand is several times that —
     * room for copies and for years of edits — and at the same time the line
     * past which the file is certainly not ours: somebody else's export, or a
     * log that got pasted in.
     */
    public const MAX_ROWS = 1000;

    /**
     * The file size ceiling, in kilobytes.
     *
     * Derived from the number above rather than chosen separately: a thousand
     * rows with an answer of up to four thousand characters each is about four
     * megabytes. Our knowledge base cannot be bigger than that.
     */
    public const MAX_SIZE_KB = 4096;

    /**
     * The columns: the field key and the heading a person sees.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'topic_key' => 'Topic key',
        'category' => 'Section',
        'question' => 'Question',
        'mode' => 'How to answer',
        'priority' => 'Priority',
        'answer' => 'Answer',
        'scenario_slug' => 'Scenario',
        'keywords' => 'Keywords',
        'variants' => 'How people ask it',
        'is_active' => 'Switched on',
    ];

    /**
     * What counts as yes and what as no in the "Switched on" column.
     *
     * A list rather than `filter_var`, because the file was written by somebody
     * copying what the export put there, and the export writes words.
     *
     * @var array<string, bool>
     */
    private const FLAGS = [
        '1' => true,
        'true' => true,
        'yes' => true,
        'y' => true,
        'да' => true,
        '0' => false,
        'false' => false,
        'no' => false,
        'n' => false,
        'нет' => false,
    ];

    /**
     * A file with every field that is worth editing.
     *
     * @param  iterable<KnowledgeEntry>  $entries
     */
    public function export(iterable $entries): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, self::BOM);

        $this->putRow($handle, array_values(self::COLUMNS));

        foreach ($entries as $entry) {
            $this->putRow($handle, [
                (string) $entry->topic_key,
                (string) $entry->category,
                (string) $entry->question,
                $entry->mode instanceof KnowledgeMode ? $entry->mode->value : (string) $entry->mode,
                (string) $entry->priority,
                (string) $entry->answer,
                (string) $entry->scenario_slug,
                implode(self::KEYWORD_SEPARATOR.' ', $this->listOf($entry->keywords)),
                implode(self::KEYWORD_SEPARATOR.' ', $this->listOf($entry->variants)),
                $entry->is_active ? 'yes' : 'no',
            ]);
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * Parsing with not one write to the database.
     *
     * One spoiled row does not take the rest down with it: it is named by
     * number and by reason, skipped, and the file is read to the end.
     * Otherwise somebody with one stray space in one cell out of a hundred and
     * seventy loads nothing at all and is not told where the mistake is.
     *
     * @param  bool  $skeleton  never write the answer column, whatever the file says
     */
    public function plan(string $contents, bool $skeleton = false): KnowledgeImportPlan
    {
        if (str_starts_with($contents, self::BOM)) {
            $contents = substr($contents, strlen(self::BOM));
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $delimiter = self::detectDelimiter($contents);
        $header = fgetcsv($handle, separator: $delimiter, escape: '');

        if ($header === false || $header === [null]) {
            fclose($handle);

            return new KnowledgeImportPlan(warnings: [__('chatbot::panel.knowledge.exchange.empty_file')]);
        }

        $map = self::columnMap($header);

        if (! array_key_exists('topic_key', $map)) {
            fclose($handle);

            return new KnowledgeImportPlan(warnings: [__('chatbot::panel.knowledge.exchange.no_key_column')]);
        }

        $existing = KnowledgeEntry::query()->pluck('answer', 'topic_key');

        $rows = [];
        $warnings = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $keptAnswers = 0;
        $seen = [];
        $number = 1;

        while (($row = fgetcsv($handle, separator: $delimiter, escape: '')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $number++;

            if (count($rows) + $skipped >= self::MAX_ROWS) {
                $warnings[] = __('chatbot::panel.knowledge.exchange.too_many_rows', ['max' => self::MAX_ROWS]);

                break;
            }

            $topicKey = self::cell($row, $map, 'topic_key');

            if ($topicKey === '') {
                $warnings[] = __('chatbot::panel.knowledge.exchange.row_no_key', ['row' => $number]);
                $skipped++;

                continue;
            }

            if (in_array($topicKey, $seen, true)) {
                $warnings[] = __('chatbot::panel.knowledge.exchange.row_repeated', ['row' => $number, 'key' => $topicKey]);
                $skipped++;

                continue;
            }

            $isNew = ! $existing->has($topicKey);
            $attributes = $this->attributes($row, $map, $isNew, $skeleton, $warning);

            if ($attributes === null) {
                $warnings[] = __('chatbot::panel.knowledge.exchange.row_refused', ['row' => $number, 'reason' => $warning]);
                $skipped++;

                continue;
            }

            if (! $isNew && ! array_key_exists('answer', $attributes) && filled($existing->get($topicKey))) {
                $keptAnswers++;
            }

            $seen[] = $topicKey;
            $rows[] = ['topic_key' => $topicKey, 'attributes' => $attributes];

            $isNew ? $created++ : $updated++;
        }

        fclose($handle);

        return new KnowledgeImportPlan($rows, $warnings, $created, $updated, $skipped, $keptAnswers);
    }

    /**
     * Writing a parsed plan.
     *
     * All of it in one transaction: half a loaded file is a state in which
     * nobody can tell what was written from what was not, and the answer to
     * that is to load the file again — over their own fresh edits.
     *
     * @param  int|null  $botId  the bot these topics belong to, or null for the shared base
     */
    public function apply(KnowledgeImportPlan $plan, ?int $botId = null): void
    {
        DB::transaction(function () use ($plan, $botId): void {
            foreach ($plan->rows as $row) {
                KnowledgeEntry::query()->updateOrCreate(
                    ['bot_id' => $botId, 'topic_key' => $row['topic_key']],
                    $row['attributes'],
                );
            }
        });
    }

    /**
     * One row's fields, or null when the row cannot be taken.
     *
     * An empty cell never reaches the set at all — that is what "I did not
     * touch this" means (see the class docblock). One exception: a new topic
     * has nowhere to get its question from, the column is required, and a row
     * without it is not a row.
     *
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     * @return array<string, mixed>|null
     */
    private function attributes(array $row, array $map, bool $isNew, bool $skeleton, ?string &$warning): ?array
    {
        $warning = null;
        $attributes = [];

        foreach (['category', 'question', 'scenario_slug', 'priority'] as $key) {
            $value = self::cell($row, $map, $key);

            if ($value !== '') {
                $attributes[$key] = $value;
            }
        }

        /*
         * The one column a skeleton import will not write, and the reason it
         * has a flag at all. An analyst's list of questions is a valuable
         * thing and its answer column is either empty or somebody's guess;
         * loaded over a base the office has been writing, it replaces their
         * words with a stranger's.
         */
        $answer = $skeleton ? '' : self::cell($row, $map, 'answer');

        if ($answer !== '') {
            $attributes['answer'] = $answer;
        }

        foreach (['keywords', 'variants'] as $key) {
            $value = self::cell($row, $map, $key);

            if ($value !== '') {
                $attributes[$key] = self::splitKeywords($value);
            }
        }

        $mode = self::cell($row, $map, 'mode');

        if ($mode !== '') {
            $resolved = self::resolveMode($mode);

            if ($resolved === null) {
                $warning = __('chatbot::panel.knowledge.exchange.unknown_mode', ['mode' => $mode]);

                return null;
            }

            $attributes['mode'] = $resolved;
        }

        $active = self::cell($row, $map, 'is_active');

        if ($active !== '') {
            $flag = self::FLAGS[mb_strtolower($active)] ?? null;

            if ($flag === null) {
                $warning = __('chatbot::panel.knowledge.exchange.unknown_flag', ['value' => $active]);

                return null;
            }

            $attributes['is_active'] = $flag;
        }

        if ($isNew && ! isset($attributes['question'])) {
            $warning = __('chatbot::panel.knowledge.exchange.new_without_question');

            return null;
        }

        return $attributes;
    }

    /**
     * The mode by its stored value, or by the label a person sees in the panel.
     *
     * Both are accepted deliberately: the export writes the value, but somebody
     * typing a row by hand will copy the label off the screen, and refusing it
     * there would look like pedantry.
     */
    private static function resolveMode(string $value): ?KnowledgeMode
    {
        $needle = mb_strtolower(trim($value));

        foreach (KnowledgeMode::cases() as $mode) {
            if ($needle === $mode->value || $needle === mb_strtolower($mode->getLabel())) {
                return $mode;
            }
        }

        return null;
    }

    /**
     * The delimiter is read off the header row rather than fixed.
     *
     * Excel in another locale saves the same file with commas, and refusing to
     * read it would look like the export being broken. Counting on the headers
     * is safe: the column labels contain neither commas nor semicolons — while
     * the data contains both, since a comma separates keywords, and counting
     * over the whole file would get it wrong.
     */
    private static function detectDelimiter(string $contents): string
    {
        $header = Str::before($contents, "\n");

        return substr_count($header, self::DELIMITER) >= substr_count($header, ',')
            ? self::DELIMITER
            : ',';
    }

    /**
     * Which column is where. A heading is recognised both by the label the
     * export writes and by the field name: the file may have been assembled
     * from another system entirely.
     *
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private static function columnMap(array $header): array
    {
        $byHeading = [];

        foreach (self::COLUMNS as $key => $label) {
            $byHeading[mb_strtolower($label)] = $key;
            $byHeading[$key] = $key;
        }

        $map = [];

        foreach ($header as $index => $label) {
            $key = $byHeading[mb_strtolower(trim((string) $label))] ?? null;

            if ($key !== null && ! array_key_exists($key, $map)) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     */
    private static function cell(array $row, array $map, string $key): string
    {
        $index = $map[$key] ?? null;

        return $index === null ? '' : trim((string) ($row[$index] ?? ''));
    }

    /**
     * @return list<string>
     */
    private static function splitKeywords(string $value): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(self::KEYWORD_SEPARATOR, $value)),
            static fn (string $word): bool => $word !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function listOf(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            (array) $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * One row of the file.
     *
     * `escape: ''` is required at both ends of the exchange. With PHP's default
     * backslash escaping the writer steps outside RFC 4180: an answer ending in
     * `\` is written unescaped, and on the way back in it swallows the next
     * quote — joining the row to the one after it. A round trip through such a
     * topic would lose data in silence.
     *
     * @param  resource  $handle
     * @param  list<string>  $values
     */
    private function putRow($handle, array $values): void
    {
        fputcsv($handle, $values, self::DELIMITER, '"', '', "\r\n");
    }

    /**
     * A file name with the date in it: exports land in one folder, and
     * identical names overwrite each other there.
     */
    public static function fileName(): string
    {
        return 'chatbot-knowledge-'.now()->format('Y-m-d').'.csv';
    }
}
