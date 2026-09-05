<?php

declare(strict_types=1);

use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

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
