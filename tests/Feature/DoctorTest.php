<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MB\FilamentChatbot\Models\Bot;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\DataResource;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use Workbench\App\Models\DemoRecord;

/**
 * The doctor's job is to be run by a machine as well as by a person, and a
 * machine reads one thing. A report that describes a dead widget in full and
 * then exits zero is a report the pipeline steps over.
 */
it('fails the run when a check finds something broken and passes when it does not', function (): void {
    config(['chatbot.widget.enabled' => true, 'chatbot.widget.origins' => []]);

    $this->artisan('chatbot:doctor --check=widget')->assertExitCode(1);

    config(['chatbot.widget.origins' => ['https://shop.example.com']]);

    $this->artisan('chatbot:doctor --check=widget')->assertExitCode(0);
});

/**
 * An empty origin list is the one setting that blocks everybody while looking
 * like it has been configured. The widget is on, the embed code is on the page,
 * and the only symptom is a bubble that never opens — which reads as a broken
 * package rather than as a field nobody filled in.
 */
it('names a widget that is switched on and cannot answer anybody', function (): void {
    config(['chatbot.widget.enabled' => true, 'chatbot.widget.origins' => []]);

    $this->artisan('chatbot:doctor --check=widget')
        ->expectsOutputToContain('no origin may embed it')
        ->assertExitCode(1);
});

/**
 * A node of a kind nothing provides changes what a scenario does without anybody
 * editing it: the conversation that reaches it is handed to a person, and the
 * canvas still draws the branch the author meant.
 */
it('names a scenario holding a node kind nothing in the installation provides', function (): void {
    $scenario = Scenario::factory()->create(['bot_id' => null]);

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'key' => 'orphan',
        'type' => 'nothing-provides-this',
    ]);

    $this->artisan('chatbot:doctor --check=step-types')
        ->expectsOutputToContain('registered by nothing')
        ->expectsOutputToContain($scenario->slug)
        ->assertExitCode(1);
});

/**
 * --check narrows the report to one subject. Without this the option is a filter
 * that reads as one and runs everything, and a pipeline asking about the widget
 * would be failed by an unrelated warning about scenarios.
 */
it('runs only the check it was asked for', function (): void {
    config(['chatbot.widget.enabled' => true, 'chatbot.widget.origins' => ['https://shop.example.com']]);

    $this->artisan('chatbot:doctor --check=widget')
        ->expectsOutputToContain('Site widget')
        ->doesntExpectOutputToContain('Node kinds')
        ->assertExitCode(0);
});

/**
 * A warning must not fail an ordinary run and must fail one that asked for it.
 * Both halves matter: warnings that failed a release would get the doctor taken
 * out of the pipeline, and a --strict that passed anyway would make the flag a
 * decoration on the release nobody is allowed to break.
 */
it('lets a warning through unless the run asked for strictness', function (): void {
    $this->artisan('chatbot:doctor --check=scenarios')
        ->expectsOutputToContain('WARN')
        ->assertExitCode(0);

    $this->artisan('chatbot:doctor --check=scenarios --strict')->assertExitCode(1);
});

/**
 * The busiest write in the installation is a counter nobody reads.
 *
 * Every widget endpoint is behind a rate limiter, the widget polls every few
 * seconds per open tab, and a limiter counts by writing. On `CACHE_STORE=database`
 * over SQLite that write lands in the same file everything else is read from,
 * and SQLite admits one writer at a time: two visitors, or a visitor and an
 * operator, and the polling endpoint answers "database is locked" — which the
 * visitor sees as a chat that opens empty and never fills in.
 *
 * Unfindable by hand. One person on a laptop never collides with themselves, so
 * the fault waits for the second real visitor.
 */
it('says when the rate limiter shares one SQLite file with everything else', function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database' => ['driver' => 'database', 'connection' => null],
        'database.default' => 'testing',
        'database.connections.testing.driver' => 'sqlite',
    ]);

    $this->artisan('chatbot:doctor --check=rate-limits')
        ->expectsOutputToContain('WARN')
        ->expectsOutputToContain('one writer at a time')
        ->assertExitCode(0);

    config(['cache.default' => 'file', 'cache.stores.file' => ['driver' => 'file']]);

    $this->artisan('chatbot:doctor --check=rate-limits')
        ->doesntExpectOutputToContain('WARN')
        ->assertExitCode(0);
});

/**
 * A key that is present and a key that is accepted are different facts.
 *
 * The gateway's own `isAvailable()` answers the first, and is right to: nodes
 * consult it before every single call, so it checks two strings and never makes
 * a request. Reused here it made this report say a gateway "can be reached" on
 * the strength of a non-empty key — and it said exactly that about a real
 * installation whose provider answered every request with "user location is not
 * supported". The doctor passed; the bot took its "could not" branch for every
 * question anybody asked, which reads as scenarios written badly.
 *
 * Telling those two facts apart is the whole reason this command exists, so it
 * asks the provider for real, and repeats what the provider said.
 */
it('asks the provider rather than trusting that a key is filled in', function (): void {
    Http::fake(['*' => Http::response(
        ['error' => ['message' => 'User location is not supported for the API use.']],
        400,
    )]);

    config([
        'chatbot.ai.default' => 'openai',
        'chatbot.ai.drivers.openai.api_key' => 'looks-real-enough',
        'chatbot.ai.drivers.openai.base_url' => 'https://provider.example.test/v1',
    ]);

    Bot::factory()->create(['is_active' => true, 'ai_driver' => 'openai']);

    $this->artisan('chatbot:doctor --check=ai')
        ->expectsOutputToContain('WARN')
        ->expectsOutputToContain('User location is not supported')
        ->assertExitCode(0);
});

/**
 * A pipeline with no outbound access must still be able to run the doctor — and
 * must not be told a provider answered when nothing was asked of it.
 */
it('says plainly what it did not verify when it may not leave the machine', function (): void {
    Http::fake(['*' => Http::response([], 500)]);

    config([
        'chatbot.ai.default' => 'openai',
        'chatbot.ai.drivers.openai.api_key' => 'looks-real-enough',
        'chatbot.ai.drivers.openai.base_url' => 'https://provider.example.test/v1',
    ]);

    Bot::factory()->create(['is_active' => true, 'ai_driver' => 'openai']);

    $this->artisan('chatbot:doctor --check=ai --offline')
        ->expectsOutputToContain('Nothing was asked of the provider')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

/**
 * Every way the bell can be dead is silent by construction: it swallows its own
 * failures because it rings from inside the path that stores a customer's
 * message, and none of the three mistakes below leave a trace. A desk that
 * nobody has been told about looks exactly like a quiet afternoon.
 */
it('names an operator model that cannot be notified at all', function (): void {
    config(['chatbot.user_model' => Conversation::class]);

    $this->artisan('chatbot:doctor --check=bell')
        ->expectsOutputToContain('does not use the Notifiable trait')
        ->assertExitCode(1);
});

it('names an unassigned conversation that would ring nobody', function (): void {
    Conversation::factory()->create();

    $this->artisan('chatbot:doctor --check=bell')
        ->expectsOutputToContain('Nobody would hear an unassigned conversation')
        ->assertExitCode(0);
});

/**
 * Источники данных объявляются в конфиге, а читает их таблица — и выкат,
 * который прогнал `migrate` и `optimize`, но не `chatbot:sync-resources`,
 * даёт установку, где всё выглядит правильно: объявление в файле, сценарий
 * на полотне, ни строчки в журнале ошибок. Узел при этом уходит вторым
 * выходом на каждом разговоре, и человеку сообщают, что записей нет.
 *
 * Так и проработала боевая установка несколько недель, ни разу не ответив
 * по брони. Поэтому отказ, а не предупреждение: сценарий, ссылающийся
 * в пустоту, — это ветка, которая не может сработать.
 */
it('валит прогон, когда сценарий ссылается на незаведённый источник данных', function (): void {
    $scenario = Scenario::factory()->create();

    Step::factory()->create([
        'scenario_id' => $scenario->getKey(),
        'type' => 'choose',
        'config' => ['resource' => 'bookings', 'into' => 'поездка', 'label' => '{{hotel}}'],
    ]);

    $this->artisan('chatbot:doctor --check=data-resources')
        ->expectsOutputToContain('bookings')
        ->assertExitCode(1);

    DataResource::factory()->create(['key' => 'bookings']);

    $this->artisan('chatbot:doctor --check=data-resources')->assertExitCode(0);
});

/**
 * Объявленный, но не синхронизированный источник, которым пока никто
 * не пользуется, — предупреждение: ничего ещё не сломано, но синхронизация
 * не прогонялась, и первый сценарий, который его возьмёт, сломается молча.
 */
it('предупреждает об объявленном источнике, которого нет в базе', function (): void {
    config(['chatbot.data_resources.resources' => [
        ['key' => 'orders', 'model' => DemoRecord::class],
    ]]);

    $this->artisan('chatbot:doctor --check=data-resources')
        ->expectsOutputToContain('orders')
        ->assertExitCode(0);

    $this->artisan('chatbot:doctor --check=data-resources --strict')->assertExitCode(1);
});
