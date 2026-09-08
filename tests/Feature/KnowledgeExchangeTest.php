<?php

declare(strict_types=1);

use MB\FilamentChatbot\Enums\KnowledgeMode;
use MB\FilamentChatbot\Knowledge\KnowledgeExchange;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\KnowledgeEntry;

/**
 * The knowledge base as a file: export it, write the answers in a spreadsheet,
 * load it back.
 *
 * The base is the one part of a bot nobody can seed for a company — an answer is
 * the company's own voice — and it gets written the way anything long gets
 * written, in a spreadsheet, over a fortnight. Every case here is a way the
 * round trip silently loses somebody's work: an empty cell taken for an
 * erasure, a `\` at the end of an answer joining two rows, a file saved by an
 * Excel in another locale.
 */
function exchange(): KnowledgeExchange
{
    return app(KnowledgeExchange::class);
}

/**
 * The point of the whole thing: what comes out goes back in unchanged.
 *
 * A round trip that alters anything is worse than no exchange at all, because
 * what it alters is a base somebody has been writing by hand — and the change
 * arrives in rows they never opened.
 */
it('takes back everything it gave out', function (): void {
    KnowledgeEntry::factory()->create([
        'bot_id' => null,
        'topic_key' => 'hours',
        'category' => 'Company',
        'question' => 'What are your opening hours?',
        'answer' => 'Weekdays 9 to 18; a semicolon; and a "quote" too.',
        'keywords' => ['hours', 'open'],
        'variants' => ['when do you open', 'are you open now'],
        'mode' => KnowledgeMode::Answer,
        'is_active' => true,
    ]);

    $file = exchange()->export(KnowledgeEntry::all());

    KnowledgeEntry::query()->update(['answer' => 'wiped', 'keywords' => [], 'variants' => []]);

    exchange()->apply(exchange()->plan($file));

    $entry = KnowledgeEntry::query()->where('topic_key', 'hours')->first();

    expect($entry?->answer)->toBe('Weekdays 9 to 18; a semicolon; and a "quote" too.')
        ->and($entry?->keywords)->toBe(['hours', 'open'])
        ->and($entry?->variants)->toBe(['when do you open', 'are you open now']);
});

/**
 * An answer ending in a backslash is the case PHP's own default gets wrong. The
 * writer leaves it unescaped, and on the way back it swallows the next quote —
 * joining the row to the one after it. The loss is silent and the topic that
 * disappears is a random one.
 */
it('survives an answer that ends in a backslash', function (): void {
    KnowledgeEntry::factory()->create([
        'bot_id' => null, 'topic_key' => 'path', 'question' => 'Where is it?',
        /*
         * The semicolon is what makes the cell get quoted, and the trailing
         * backslash is what breaks out of the quotes again. Either alone is
         * harmless, which is why the pair is worth writing down.
         */
        'answer' => 'Look in; then open C:\\',
    ]);

    KnowledgeEntry::factory()->create([
        'bot_id' => null, 'topic_key' => 'after', 'question' => 'And then?',
        'answer' => 'Then you are done.',
    ]);

    $plan = exchange()->plan(exchange()->export(KnowledgeEntry::all()));

    expect($plan->rows)->toHaveCount(2)
        ->and($plan->warnings)->toBe([]);
});

/**
 * **An empty cell means "I did not touch this row", never "erase what is
 * there".** The file goes around for weeks while somebody writes answers in the
 * evenings, and a colleague fixes one answer in the panel meanwhile. An
 * overwriting load would return that fix to nothing, in a row the first person
 * never opened.
 */
it('does not erase an answer because a cell came back empty', function (): void {
    KnowledgeEntry::factory()->create([
        'bot_id' => null, 'topic_key' => 'hours', 'question' => 'Opening hours?',
        'answer' => 'Written by a colleague this morning.',
    ]);

    $plan = exchange()->plan(
        "Topic key;Question;Answer\r\nhours;Opening hours?;\r\n",
    );

    exchange()->apply($plan);

    expect(KnowledgeEntry::query()->where('topic_key', 'hours')->value('answer'))
        ->toBe('Written by a colleague this morning.')
        ->and($plan->keptAnswers)->toBe(1);
});

/**
 * One spoiled row must not take the rest down with it. Otherwise somebody with
 * one stray value in one cell out of a hundred and seventy loads nothing at all
 * and is not told where the mistake is.
 */
it('names the row it could not read and reads the rest', function (): void {
    $plan = exchange()->plan(implode("\r\n", [
        'Topic key;Question;How to answer',
        'good;A question;answer',
        'bad;Another question;telepathy',
        ';Third question;answer',
        'good;A repeat;answer',
        '',
    ]));

    expect($plan->created)->toBe(1)
        ->and($plan->skipped)->toBe(3)
        ->and($plan->warnings)->toHaveCount(3);
});

/**
 * Excel in another locale saves the same file with commas, and refusing to read
 * it would look like the export being broken rather than like a setting.
 */
it('reads a file an Excel in another locale saved with commas', function (): void {
    $plan = exchange()->plan("Topic key,Question,Answer\r\nhours,Opening hours?,Nine to six\r\n");

    expect($plan->created)->toBe(1)
        ->and($plan->rows[0]['attributes']['answer'])->toBe('Nine to six');
});

/**
 * A new topic has nowhere to get its question from, and the column is required.
 * A row without one is not a row.
 */
it('refuses a new topic with no question and updates an old one without it', function (): void {
    KnowledgeEntry::factory()->create(['bot_id' => null, 'topic_key' => 'known', 'question' => 'Already asked']);

    $plan = exchange()->plan(implode("\r\n", [
        'Topic key;Question;Answer',
        'known;;An answer for the known one',
        'unknown;;An answer for nothing at all',
        '',
    ]));

    expect($plan->updated)->toBe(1)
        ->and($plan->created)->toBe(0)
        ->and($plan->skipped)->toBe(1);
});

/**
 * A skeleton import is a list of questions from somebody who is not the office,
 * and its answer column is either empty or a guess. Loaded over a base the
 * office has been writing, it would replace their words with a stranger's — so
 * the flag exists and the column is not read at all.
 */
it('never writes an answer on a skeleton import', function (): void {
    KnowledgeEntry::factory()->create([
        'bot_id' => null, 'topic_key' => 'hours', 'question' => 'Opening hours?',
        'answer' => 'The words the office wrote.',
    ]);

    $file = "Topic key;Question;Answer\r\nhours;Opening hours, restated;A stranger's guess\r\n";

    exchange()->apply(exchange()->plan($file, skeleton: true));

    $entry = KnowledgeEntry::query()->where('topic_key', 'hours')->first();

    expect($entry?->answer)->toBe('The words the office wrote.')
        ->and($entry?->question)->toBe('Opening hours, restated');
});

/**
 * Topics belong to a bot or to nobody, and the console has to be able to say
 * which. A file loaded into the shared base by mistake is in front of every bot
 * in the installation, and nothing says so until one of them answers another
 * company's questions.
 */
it('files imported topics under the bot the console named', function (): void {
    $bot = Bot::factory()->create(['slug' => 'support']);

    $path = tempnam(sys_get_temp_dir(), 'chatbot-knowledge').'.csv';
    file_put_contents($path, "Topic key;Question;Answer\r\nhours;Opening hours?;Nine to six\r\n");

    $this->artisan('chatbot:knowledge-import', ['file' => $path, '--bot' => 'support'])
        ->assertExitCode(0);

    expect(KnowledgeEntry::query()->where('topic_key', 'hours')->value('bot_id'))
        ->toBe($bot->getKey());
});

/**
 * A typo in `--bot` must not quietly put a hundred and seventy topics in front
 * of every bot there is.
 */
it('refuses a bot slug that does not exist rather than sharing the topics', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'chatbot-knowledge').'.csv';
    file_put_contents($path, "Topic key;Question\r\nhours;Opening hours?\r\n");

    $this->artisan('chatbot:knowledge-import', ['file' => $path, '--bot' => 'sopport'])
        ->assertExitCode(1);

    expect(KnowledgeEntry::query()->count())->toBe(0);
});

/**
 * A dry run says what would happen and writes nothing. It is the only way to
 * check a file handed over by somebody else before it touches the base.
 */
it('changes nothing on a dry run', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'chatbot-knowledge').'.csv';
    file_put_contents($path, "Topic key;Question\r\nhours;Opening hours?\r\n");

    $this->artisan('chatbot:knowledge-import', ['file' => $path, '--dry-run' => true])
        ->assertExitCode(0);

    expect(KnowledgeEntry::query()->count())->toBe(0);
});
