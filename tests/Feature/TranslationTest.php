<?php

declare(strict_types=1);

use MB\FilamentChatbot\ChatbotPlugin;

/**
 * Every language the package ships answers to the same keys.
 *
 * A key added to English and forgotten in Russian does not fail, warn or log.
 * Laravel answers a missing translation with the key itself, so the panel shows
 * `chatbot::panel.bots.tabs.basics` where a tab name belongs — and only to
 * people reading in the language nobody tested. On the machine of whoever added
 * the key, everything looks finished.
 */

/**
 * @return list<string>
 */
function translationKeys(string $locale, string $file): array
{
    $flatten = static function (array $rows, string $prefix = '') use (&$flatten): array {
        $flat = [];

        foreach ($rows as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            /*
             * A list of strings is one key, not one key per entry. Word lists —
             * the greetings a bot recognises, the ways somebody says yes — are
             * deliberately different lengths per language, and counting their
             * entries would make Russian look permanently out of step for
             * knowing more words for "no" than English does.
             */
            $flat = is_array($value) && ! array_is_list($value)
                ? array_merge($flat, $flatten($value, $path))
                : array_merge($flat, [$path]);
        }

        return $flat;
    };

    return $flatten(require dirname(__DIR__, 2)."/lang/{$locale}/{$file}.php");
}

it('answers to the same keys in every language it ships', function (string $file): void {
    $english = translationKeys('en', $file);
    $russian = translationKeys('ru', $file);

    $this->assertSame(
        [],
        array_values(array_diff($english, $russian)),
        "These keys exist in English and not in Russian, so a Russian panel prints the key itself: {$file}",
    );

    $this->assertSame(
        [],
        array_values(array_diff($russian, $english)),
        "These keys exist only in Russian, so nothing reads them and the English panel has no equivalent: {$file}",
    );
})->with(['bot', 'nodes', 'panel', 'widget']);

/**
 * A translation file that is present but empty passes every comparison above,
 * because two empty sets match. It would leave the whole panel printing keys.
 */
it('ships translations that are not merely present', function (): void {
    expect(translationKeys('ru', 'panel'))->toHaveCount(count(translationKeys('en', 'panel')))
        ->and(count(translationKeys('ru', 'panel')))->toBeGreaterThan(100)
        ->and(count(translationKeys('ru', 'nodes')))->toBeGreaterThan(100);
});

/**
 * A heading raises its first letter and leaves the rest of the words alone.
 *
 * Filament's own default title-cases a model label, which is an English rule.
 * Applied to Russian it produced «База Знаний» and «Быстрые Ответы» in the menu
 * — nothing broken, nothing missing, and unmistakably the work of somebody who
 * does not speak the language. The failure is invisible to everyone who does
 * not read it, which is why it survived a whole translation pass.
 *
 * Read from the plugin's own list so a screen added later is held to it too.
 */
it('capitalises a heading the way the language does, not the way English does', function (): void {
    app()->setLocale('ru');

    foreach ((new ChatbotPlugin)->resources() as $resource) {
        $plural = $resource::getTitleCasePluralModelLabel();
        $words = preg_split('/\s+/u', trim($plural)) ?: [];

        expect($plural)->not->toBe('');

        foreach (array_slice($words, 1) as $word) {
            expect(mb_strtolower($word))->toBe(
                $word,
                "[{$resource}] capitalises a word after the first: {$plural}",
            );
        }

        expect(mb_substr($plural, 0, 1))->toBe(mb_strtoupper(mb_substr($plural, 0, 1)));
    }
});

/**
 * Nothing in the Russian files is still English.
 *
 * A key copied across and left untranslated is the failure the parity check
 * above cannot see: the key exists, the file is the right size, and the panel
 * quietly shows an English sentence in a Russian interface. Checked by looking
 * for Cyrillic rather than by comparing the two texts, because some values are
 * legitimately identical in both — a URL, a placeholder, a product name.
 */
it('has actually translated the Russian panel, not copied the English one', function (): void {
    $english = require dirname(__DIR__, 2).'/lang/en/panel.php';
    $russian = require dirname(__DIR__, 2).'/lang/ru/panel.php';

    $identical = [];

    $walk = static function (array $ru, array $en, string $prefix = '') use (&$walk, &$identical): void {
        foreach ($ru as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $other = $en[$key] ?? null;

            if (is_array($value)) {
                $walk($value, (array) $other, $path);

                continue;
            }

            $sentence = is_string($value) && str_contains(trim($value), ' ');

            if ($sentence && $value === $other && preg_match('/\p{Cyrillic}/u', $value) !== 1) {
                $identical[] = $path;
            }
        }
    };

    $walk($russian, $english);

    $this->assertSame(
        [],
        $identical,
        'These Russian values are word-for-word the English ones and contain no Cyrillic, '
        .'so they were copied rather than translated: '.implode(', ', array_slice($identical, 0, 10)),
    );
});
