<?php

declare(strict_types=1);

use MB\FilamentChatbot\Channels\WidgetTheme;
use MB\FilamentChatbot\Models\Bot;

/**
 * A theme is written by whoever can edit a bot and rendered inside a `style`
 * attribute on somebody else's website. That sentence is the whole reason this
 * file exists: every value below crosses from one person's permissions into
 * another person's page.
 */

/**
 * Anything that is not a colour is refused, and the default stands in.
 *
 * The values here go into CSS custom properties. `var(--x)`, `url(...)` and a
 * closing brace are all legal CSS in that position, and each is a way out of the
 * property into the rest of the page — a panel permission quietly becoming a
 * permission on the customer's own site. Refusing anything but hex is a
 * narrowing on purpose, not a gap in the parser.
 */
it('refuses anything that is not a plain hex colour', function (string $written): void {
    $theme = WidgetTheme::make(['accent' => $written]);

    expect($theme->properties()['--chatbot-accent'])->toBe('#4f46e5');
})->with([
    'a css variable' => 'var(--brand)',
    'a url' => 'url(https://example.test/x.png)',
    'an escape from the property' => 'red; background: url(https://evil.test)',
    'a function' => 'rgb(0 0 0)',
    'a named colour' => 'rebeccapurple',
    'nonsense' => 'not a colour',
    'nothing' => '',
]);

it('keeps a colour that is one', function (): void {
    expect(WidgetTheme::make(['accent' => '#FF8800'])->properties()['--chatbot-accent'])->toBe('#ff8800')
        ->and(WidgetTheme::make(['accent' => '#f80'])->properties()['--chatbot-accent'])->toBe('#f80');
});

/**
 * Sizes are clamped rather than refused. A width of nine thousand is somebody
 * dragging a slider, not an attack, and a widget that covered the customer's
 * whole page would be reported as the widget being broken.
 */
it('clamps a size to something a panel can still be seen through', function (): void {
    expect(WidgetTheme::make(['width' => 9000])->properties()['--chatbot-width'])->toBe('520px')
        ->and(WidgetTheme::make(['width' => 10])->properties()['--chatbot-width'])->toBe('280px')
        ->and(WidgetTheme::make(['radius' => -5])->properties()['--chatbot-radius'])->toBe('0px')
        ->and(WidgetTheme::make(['width' => 'wide'])->properties()['--chatbot-width'])->toBe('360px');
});

it('falls back to the first choice when a style is not one it offers', function (): void {
    expect(WidgetTheme::make(['bubbles' => 'ios'])->flags()['bubbles'])->toBe('ios')
        ->and(WidgetTheme::make(['bubbles' => 'grouped'])->flags()['bubbles'])->toBe('grouped')
        ->and(WidgetTheme::make(['bubbles' => 'whatever'])->flags()['bubbles'])->toBe('rounded')
        ->and(WidgetTheme::make(['position' => 'left'])->flags()['position'])->toBe('left')
        ->and(WidgetTheme::make(['launcher' => 'circle'])->flags()['launcher'])->toBe('circle');
});

/**
 * `grouped` and `ios` are one arrangement and differ in one thing: the tail.
 *
 * The grouping rules are written once for both, and this is what keeps them
 * that way. Copied instead, a change made to one and not the other would show
 * up as two styles that are meant to be identical and quietly are not —
 * invisible unless somebody happens to switch between them on the same
 * transcript.
 *
 * Read from the stylesheet rather than exercised, because the difference is
 * entirely CSS and the suite has no browser. What can be checked is the shape
 * of the file: every grouping rule names both styles, and every tail rule names
 * only `ios`.
 */
it('groups the same way with a tail and without one', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/widget/widget.css');

    $rules = array_values(array_filter(
        explode('}', $css),
        static fn (string $rule): bool => str_contains($rule, 'data-run=') && str_contains($rule, 'data-bubbles='),
    ));

    expect($rules)->not->toBeEmpty();

    foreach ($rules as $rule) {
        $selector = trim(explode('{', $rule)[0]);

        /*
         * The squared-off 6px corner counts as part of the tail even though it
         * carries no mask: it exists only because the tail grows out of that
         * corner, and a style with no tail wants the corner left round.
         */
        $isTail = str_contains($rule, 'mask-image')
            || str_contains($selector, '::after')
            || str_contains($rule, 'radius: 6px')
            || str_contains($rule, 'position: relative');

        if ($isTail) {
            expect($selector)->not->toContain('data-bubbles="grouped"');

            continue;
        }

        expect($selector)->toContain('data-bubbles="grouped"')
            ->and($selector)->toContain('data-bubbles="ios"');
    }
});

/**
 * A relative avatar path is refused rather than resolved.
 *
 * The widget runs on the customer's domain, so `/img/bot.png` there points at
 * the customer's own server — where it quietly resolves to their 404 page and
 * draws a broken image beside every reply the bot sends. Plain http is refused
 * too: a page served over https will not load it, and the result is the same
 * broken image with a console warning nobody reads.
 */
it('takes an avatar only as an absolute https address', function (): void {
    expect(WidgetTheme::make(['avatar' => 'https://cdn.example.test/bot.png'])->flags()['avatar'])
        ->toBe('https://cdn.example.test/bot.png')
        ->and(WidgetTheme::make(['avatar' => '/img/bot.png'])->flags()['avatar'])->toBeNull()
        ->and(WidgetTheme::make(['avatar' => 'http://cdn.example.test/bot.png'])->flags()['avatar'])->toBeNull()
        ->and(WidgetTheme::make(['avatar' => 'javascript:alert(1)'])->flags()['avatar'])->toBeNull();
});

/**
 * Every property the stylesheet reads is always present, whatever was stored.
 *
 * A theme saved before a release that added a colour would otherwise leave that
 * one property unset, and an unset custom property is not a default — it is
 * nothing, and the rule using it collapses. That shows up as one unreadable
 * corner of the widget on exactly the installations that have been running
 * longest.
 */
it('answers with every property even for a bot that has never been themed', function (): void {
    $bot = Bot::factory()->create(['settings' => null]);
    $properties = WidgetTheme::forBot($bot)->properties();

    foreach (['accent', 'on-accent', 'surface', 'ink', 'muted', 'line', 'agent', 'agent-ink'] as $name) {
        expect($properties)->toHaveKey('--chatbot-'.$name);
        expect($properties['--chatbot-'.$name])->toStartWith('#');
    }

    expect($properties)->toHaveKeys(['--chatbot-radius', '--chatbot-bubble-radius', '--chatbot-width']);
});

it('reads what a bot has stored', function (): void {
    $bot = Bot::factory()->create([
        'settings' => ['widget' => ['accent' => '#0ea5e9', 'bubbles' => 'ios', 'width' => 420]],
    ]);

    $theme = WidgetTheme::forBot($bot);

    expect($theme->properties()['--chatbot-accent'])->toBe('#0ea5e9')
        ->and($theme->properties()['--chatbot-width'])->toBe('420px')
        ->and($theme->flags()['bubbles'])->toBe('ios');
});
