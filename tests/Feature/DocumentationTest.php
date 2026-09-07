<?php

declare(strict_types=1);

use MB\FilamentChatbot\Bot\Steps\StepType;
use MB\FilamentChatbot\Channels\WidgetTheme;
use MB\FilamentChatbot\Http\WidgetEmbed;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * @return list<string>
 */
function docPages(): array
{
    return array_values(glob(__DIR__.'/../../docs/*.md') ?: []);
}

function docContents(): string
{
    return implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        docPages(),
    ));
}

function docPage(string $name): string
{
    return (string) file_get_contents(__DIR__."/../../docs/{$name}.md");
}

/**
 * A link between pages that goes nowhere is the classic silent rot: the site
 * still builds, the page still renders, and the reader lands on a 404 that only
 * they ever see.
 */
it('does not link to a page that does not exist', function (): void {
    preg_match_all('/\{\{ site\.baseurl \}\}\/([a-z0-9-]+)/', docContents(), $matches);

    $targets = array_unique($matches[1]);

    $this->assertNotEmpty($targets);

    foreach ($targets as $target) {
        $this->assertFileExists(
            __DIR__."/../../docs/{$target}.md",
            "docs/{$target}.md is linked to but does not exist",
        );
    }
});

/**
 * Jekyll renders a page with no front matter as raw text, and it is the only
 * page on the site that looks wrong — which nobody notices until a reader says
 * so, because whoever added it was looking at the page they had just written.
 */
it('gives every page front matter and a place in the order', function (): void {
    foreach (docPages() as $path) {
        $contents = (string) file_get_contents($path);
        $name = basename($path);

        $this->assertStringStartsWith("---\n", $contents, "{$name} has no front matter");
        $this->assertMatchesRegularExpression('/^title:/m', $contents, "{$name} has no title");
        $this->assertMatchesRegularExpression('/^nav_order:/m', $contents, "{$name} has no nav_order");
    }
});

/**
 * Two pages sharing a position leave the sidebar order to whichever the theme
 * happens to sort first — which is to say it changes when a page is renamed.
 */
it('gives every page a different position in the sidebar', function (): void {
    $orders = [];

    foreach (docPages() as $path) {
        preg_match('/^nav_order:\s*(\d+)/m', (string) file_get_contents($path), $found);

        if ($found !== []) {
            $orders[] = (int) $found[1];
        }
    }

    $this->assertSame(array_values(array_unique($orders)), $orders, 'two pages claim the same nav_order');
});

/**
 * The node reference rots faster than anything else on the site.
 *
 * A node added to the package and not to the page is invisible to every reader
 * and perfectly visible on the palette — so somebody uses it, gets it wrong, and
 * finds nothing written about it. Walking the registry rather than a list here
 * is what makes the omission fail instead of going unnoticed.
 */
it('documents every node kind the package registers', function (): void {
    $reference = docPage('nodes');
    $kinds = array_keys(app(ChatbotRegistry::class)->steps());

    $this->assertNotEmpty($kinds);

    foreach ($kinds as $kind) {
        $this->assertStringContainsString(
            "`{$kind}`",
            $reference,
            "docs/nodes.md never mentions the [{$kind}] node",
        );
    }
});

/**
 * The same for anything a scenario author picks from a list: a slot or a
 * condition nobody wrote about is one they will use by guessing.
 */
it('documents every slot and condition the package registers', function (): void {
    $page = docPage('slots-and-conditions');
    $registry = app(ChatbotRegistry::class);

    foreach (array_keys($registry->slots()) as $slot) {
        $this->assertStringContainsString(
            "`{$slot}`",
            $page,
            "docs/slots-and-conditions.md never mentions the [{$slot}] slot",
        );
    }

    foreach (array_keys($registry->conditions()) as $condition) {
        $this->assertStringContainsString(
            "`{$condition}`",
            $page,
            "docs/slots-and-conditions.md never mentions the [{$condition}] condition",
        );
    }
});

/**
 * Every choice the widget form offers is described somewhere.
 *
 * A bubble style, a launcher shape or a corner of the screen is picked from a
 * list, and a list entry nobody wrote about gets chosen by guessing — or, more
 * often, never chosen at all, because nobody can tell what it does without
 * setting it and looking. The vocabulary is closed and small precisely so that
 * describing all of it is cheap.
 */
it('documents every appearance choice a widget offers', function (): void {
    $page = docPage('channels-and-widget');

    foreach (WidgetTheme::STYLES as $style) {
        $this->assertStringContainsString(
            "`{$style}`",
            $page,
            "docs/channels-and-widget.md never mentions the [{$style}] bubble style",
        );
    }

    foreach (WidgetTheme::LAUNCHERS as $launcher) {
        $this->assertStringContainsString(
            $launcher,
            $page,
            "docs/channels-and-widget.md never mentions the [{$launcher}] launcher",
        );
    }
});

/**
 * The embed the documentation shows is the embed the panel hands out.
 *
 * `data-bot` still works and is still described, but it is the older spelling
 * kept for pages this application cannot edit. A sample that led with it would
 * teach every new reader the compatibility path, and the widget key — which is
 * the thing that lets a bot be re-pointed without touching somebody's website —
 * would look optional.
 */
it('shows the embed that the panel actually produces', function (): void {
    $shown = WidgetEmbed::snippet('main-site');

    expect($shown)->toContain('data-widget="main-site"')
        ->and($shown)->not->toContain('data-bot');

    foreach (['channels-and-widget'] as $slug) {
        $this->assertStringContainsString(
            'data-widget',
            docPage($slug),
            "docs/{$slug}.md does not show the embed the panel produces",
        );
    }

    foreach (['README.md', 'ru.README.md'] as $readme) {
        $this->assertStringContainsString(
            'data-widget',
            (string) file_get_contents(dirname(__DIR__, 2).'/'.$readme),
            "{$readme} still shows an embed nobody is given any more",
        );
    }
});

/**
 * A class named in a code sample that does not exist is worse than no sample:
 * somebody writes against it and concludes the package is broken.
 *
 * Only unambiguous references are checked — a `use` line and a `::class` — and
 * that restraint is the point. Namespaces appear in prose too ("everything under
 * MB\FilamentChatbot\Bot\Steps\Types"), and a check that treated those as class
 * names would fail on correct documentation, which is how a check gets deleted.
 */
it('names only classes that exist', function (): void {
    $text = docContents();

    preg_match_all('/^use (MB\\\\FilamentChatbot\\\\[A-Za-z0-9_\\\\]+);/m', $text, $imports);
    preg_match_all('/(MB\\\\FilamentChatbot\\\\[A-Za-z0-9_\\\\]+)::class/', $text, $literals);

    $named = array_unique([...$imports[1], ...$literals[1]]);

    $this->assertNotEmpty($named);

    foreach ($named as $class) {
        $this->assertTrue(
            class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class),
            "the documentation names [{$class}], which does not exist",
        );
    }
});

/**
 * The number of node kinds, wherever it is written out in words.
 *
 * The check above walks the registry and demands a heading in `nodes.md` for
 * every kind, so a new node cannot go undocumented. It says nothing about the
 * sentence a paragraph earlier that *counts* them — and that sentence is what a
 * reader believes. `mutate` shipped, the reference page grew a section for it,
 * every check stayed green, and four places went on saying twenty-four, one of
 * them adding that `mutate` was still to come.
 *
 * A count in prose is a fact about the code and rots exactly like a link.
 */
it('counts the node kinds correctly wherever it counts them in words', function (): void {
    $spelled = [
        20 => 'twenty', 21 => 'twenty-one', 22 => 'twenty-two', 23 => 'twenty-three',
        24 => 'twenty-four', 25 => 'twenty-five', 26 => 'twenty-six', 27 => 'twenty-seven',
        28 => 'twenty-eight', 29 => 'twenty-nine', 30 => 'thirty',
    ];

    $kinds = count(app(ChatbotRegistry::class)->steps());

    $this->assertArrayHasKey($kinds, $spelled, "Add the spelling for {$kinds} node kinds.");

    $expected = $spelled[$kinds];

    /*
     * README and CHANGELOG are read alongside the site: they are the first two
     * things anybody looks at, and nothing else here covers them.
     */
    $files = [...docPages(), __DIR__.'/../../README.md', __DIR__.'/../../CHANGELOG.md'];

    foreach ($files as $path) {
        $text = (string) file_get_contents($path);
        $name = basename($path);

        foreach (array_diff($spelled, [$expected]) as $word) {
            /*
             * The trailing `(?!-)` is load-bearing: a hyphen is a word boundary,
             * so `\btwenty\b` matches inside "twenty-four" and the check reports
             * the wrong number in correct prose — the sort of false alarm that
             * gets a check deleted rather than fixed.
             */
            $this->assertDoesNotMatchRegularExpression(
                '/\b'.preg_quote($word, '/').'\b(?!-)[^.\n]{0,40}\b(?:kinds?|nodes?)\b/i',
                $text,
                "{$name} counts [{$word}] node kinds; there are {$kinds}, so it should say [{$expected}].",
            );
        }
    }
});

it('keeps the step contract the documentation promises', function (): void {
    foreach (['key', 'label', 'run', 'resume', 'fields', 'outputs', 'issues', 'pins'] as $method) {
        $this->assertTrue(
            method_exists(StepType::class, $method),
            "StepType::{$method}() is documented but no longer exists",
        );
    }
});
