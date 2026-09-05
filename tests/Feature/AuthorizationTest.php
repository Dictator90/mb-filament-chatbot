<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Policies\Policies;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

/**
 * A model with no policy is not "protected by default" — Laravel reads it as
 * having no opinion, and Filament shows the screen. So the failure of forgetting
 * one is an open screen, which nothing else in the suite would notice.
 *
 * Walking the models directory rather than listing them here is the point: a
 * model added tomorrow and left out of the map fails this.
 */
it('has a policy for every model the package ships', function (): void {
    $models = collect(glob(__DIR__.'/../../src/Models/*.php'))
        ->map(fn (string $path): string => 'MB\\FilamentChatbot\\Models\\'.basename($path, '.php'))
        ->all();

    expect($models)->not->toBeEmpty();

    foreach ($models as $model) {
        expect(Policies::map())->toHaveKey($model);
        expect(Gate::getPolicyFor($model))->not->toBeNull();
    }
});

/**
 * Installed and immediately usable. A package that denied by default would put
 * its screens in the menu and refuse to open them, with nothing on screen
 * naming the ability that is missing.
 */
it('allows everything while the host has expressed no opinion', function (): void {
    expect(Gate::forUser($this->user)->allows('viewAny', Scenario::class))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('create', Scenario::class))->toBeTrue();
});

/**
 * The moment a host defines the gate, it decides — including when it says no.
 * Silently overruling a host's refusal is the one thing a package must not do
 * here.
 */
it('obeys a gate the host defines, including when it refuses', function (): void {
    Gate::define('chatbot.manage', fn (): bool => false);

    expect(Gate::forUser($this->user)->allows('create', Scenario::class))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('viewAny', Scenario::class))->toBeTrue();
});

/**
 * Coarse or fine, whichever the application prefers — the specific ability is
 * asked first so that "support may edit the knowledge base but not the
 * scenarios" is expressible without the package knowing about support staff.
 */
it('prefers the specific ability over the broad one', function (): void {
    Gate::define('chatbot.manage', fn (): bool => false);
    Gate::define('chatbot.manage.scenarios', fn (): bool => true);

    expect(Gate::forUser($this->user)->allows('create', Scenario::class))->toBeTrue();
});

/**
 * A journal an operator can edit is worth as much as their memory of it, which
 * is nothing on the one occasion anybody reads it. No gate makes this editable.
 */
it('never lets anybody write a run, whatever the host says', function (): void {
    Gate::define('chatbot.manage', fn (): bool => true);

    $run = Run::factory()->create();

    expect(Gate::forUser($this->user)->allows('create', Run::class))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('update', $run))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('viewAny', Run::class))->toBeTrue();
});
