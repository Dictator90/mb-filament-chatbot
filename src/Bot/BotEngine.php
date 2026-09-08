<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot;

use MB\FilamentChatbot\Bot\Steps\StepContext;
use MB\FilamentChatbot\Bot\Steps\StepFlow;
use MB\FilamentChatbot\Bot\Variables\Variables;
use MB\FilamentChatbot\Chat\OperatorBell;
use MB\FilamentChatbot\Enums\BotState;
use MB\FilamentChatbot\Enums\ConversationStatus;
use MB\FilamentChatbot\Enums\RunOutcome;
use MB\FilamentChatbot\Jobs\ContinueScenario;
use MB\FilamentChatbot\Models\Conversation;
use MB\FilamentChatbot\Models\Message;
use MB\FilamentChatbot\Models\Run;
use MB\FilamentChatbot\Models\RunStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Walks a scenario for one turn of a conversation.
 *
 * The single place between an incoming message and an answer. Steps decide what
 * they did; this decides where the conversation goes, what gets saved, and when
 * to stop — and holding that in one place is why a host can add a node without
 * ever being able to leave a visitor parked on a node that asked them nothing.
 *
 * The engine falls silent in three situations, and each of them is a rule about
 * people rather than about code: the conversation has been handed to a human,
 * somebody has taken it, or the bot is switched off. A bot that keeps talking
 * over an operator is worse than no bot.
 */
class BotEngine
{
    /**
     * How far one turn may walk before the engine gives up.
     *
     * A loop guard, not a tuning knob. A scenario that walks in a circle would
     * otherwise hold the request open until PHP gives up, and the visitor would
     * see nothing at all — not even the error.
     */
    protected int $maxSteps;

    protected int $maxJumps;

    protected int $jumps = 0;

    public function __construct(
        protected readonly ChatbotRegistry $registry,
        protected readonly ScenarioPicker $picker,
        protected readonly Variables $variables,
        protected readonly RunJournal $journal,
    ) {
        $this->maxSteps = (int) config('chatbot.bot.max_steps_per_turn', 40);
        $this->maxJumps = (int) config('chatbot.bot.max_jumps', 5);
    }

    /**
     * Answers one incoming message. Returns whether the bot took the turn.
     */
    public function handle(Conversation $conversation, Message $incoming): bool
    {
        if ($this->silent($conversation)) {
            return false;
        }

        $said = (string) $incoming->body;

        if ($this->asksForHuman($said)) {
            $this->handOff($conversation);

            return true;
        }

        if ($conversation->bot_step_key !== null && $conversation->bot_scenario_id !== null) {
            return $this->resumeWaiting($conversation, $said);
        }

        return $this->start($conversation, $said, $this->isFirstIncoming($conversation, $incoming));
    }

    /**
     * Picks up where a pause left off.
     *
     * Starts at the node after the delay rather than at the delay itself. Re-
     * entering it would schedule the same pause again, and the conversation
     * would wait forever in increments.
     */
    public function resumeAfterDelay(Conversation $conversation, string $stepKey): void
    {
        if ($this->silent($conversation)) {
            return;
        }

        $scenario = $conversation->botScenario()->with('steps')->first();
        $paused = $scenario?->step($stepKey);

        if ($scenario === null || $paused === null || $conversation->bot_step_key !== $stepKey) {
            return;
        }

        $this->journal->attach($this->openRun($conversation));

        $conversation->forceFill(['bot_step_key' => null])->save();

        $this->walk($conversation, $scenario, $scenario->step($paused->next_key), null);
    }

    /**
     * Whether the bot must not speak at all.
     *
     * Assignment counts even without an explicit handover: an operator opening a
     * conversation and taking it is the same statement as pressing the button,
     * and a bot that answered over them would be answering a person who is
     * already typing.
     */
    protected function silent(Conversation $conversation): bool
    {
        return ! config('chatbot.bot.enabled', true)
            || $conversation->bot_state === BotState::HandedOff
            || $conversation->assigned_to_id !== null;
    }

    protected function start(Conversation $conversation, ?string $said, bool $isFirstMessage): bool
    {
        $scenario = $this->picker->pick($conversation, $said, $isFirstMessage);

        if ($scenario === null) {
            return false;
        }

        return $this->enter($conversation, $scenario, $said);
    }

    /**
     * Begins a named scenario for this conversation, whatever the router thinks.
     *
     * Public for one caller: the sandbox beside a scenario's canvas, where the
     * author is testing the drawing in front of them rather than the routing.
     * Every other way into a scenario still goes through `handle()`, which is
     * what keeps the silence rules and the handoff words in one place.
     *
     * Exposed rather than reimplemented because entering is five fields written
     * together — state, scenario, the version pinned at entry, the cleared step
     * and the cleared stack — and a copy of those five in a panel screen is a
     * second definition of what starting a scenario means. The copy that drifts
     * pins no version, and the sandbox then answers from whatever is drawn today
     * while every visitor walks the published version.
     */
    public function enter(Conversation $conversation, Scenario $scenario, ?string $said): bool
    {
        $start = $scenario->startStep();

        if ($start === null) {
            return false;
        }

        $conversation->forceFill([
            'bot_state' => BotState::Active,
            'bot_scenario_id' => $scenario->getKey(),
            'bot_version_id' => $scenario->live_version_id,
            'bot_step_key' => null,
            'bot_stack' => null,
        ])->save();

        $this->journal->open($conversation, $scenario);

        return $this->walk($conversation, $scenario, $start, $said);
    }

    /**
     * Hands the visitor's answer to the node that asked for it.
     */
    protected function resumeWaiting(Conversation $conversation, string $said): bool
    {
        $scenario = $conversation->botScenario()->with('steps')->first();
        $waiting = $scenario?->step($conversation->bot_step_key);

        /*
         * A missing node is not a crash. Scenarios get edited while people are
         * mid-conversation, and the node somebody is waiting on can simply stop
         * existing. Starting over is the only honest option — the alternative is
         * a visitor whose every message disappears.
         */
        if ($scenario === null || $waiting === null) {
            $conversation->forceFill(['bot_step_key' => null, 'bot_scenario_id' => null])->save();

            return $this->start($conversation, $said, false);
        }

        $this->journal->attach($this->openRun($conversation));

        $conversation->forceFill(['bot_step_key' => null])->save();

        return $this->walk($conversation, $scenario, $waiting, $said, $said);
    }

    /**
     * Runs nodes one after another until the conversation stops or ends.
     *
     * The scenario is a local that may change: a jump replaces it, a call pushes
     * the current one onto the conversation's stack and a return pops it back.
     * Recursing per scenario instead would make the step budget per scenario
     * rather than per turn, and a chain of five scenarios could quietly walk two
     * hundred nodes for one message.
     */
    protected function walk(
        Conversation $conversation,
        Scenario $scenario,
        ?Step $step,
        ?string $said,
        ?string $resumeAnswer = null,
    ): bool {
        $runtime = new BotRuntime($conversation, $this->variables, $this->journal, $said);
        $spoke = false;
        $guard = 0;

        while ($step !== null) {
            if (++$guard > $this->maxSteps) {
                $this->journal->record($scenario, $step, $said, RunStep::FAILED, ['reason' => 'step limit']);
                $this->finish($conversation, RunOutcome::StepLimit);
                $this->handOff($conversation);

                return true;
            }

            $type = $this->registry->step((string) $step->type);

            /*
             * A node kind nobody claims means a package was removed while its
             * nodes stayed in a scenario. There is no safe guess about what it
             * was supposed to do, so the conversation goes to a person — the one
             * outcome that is never wrong.
             */
            if ($type === null) {
                $this->journal->record($scenario, $step, $said, RunStep::UNKNOWN, ['type' => $step->type]);
                $this->finish($conversation, RunOutcome::Failed);
                $this->handOff($conversation);

                return true;
            }

            $context = new StepContext($conversation, $scenario, $step, $runtime, $said);

            if ($resumeAnswer === null && $type->speaks()) {
                $body = $runtime->fill($step->body);

                if (trim($body) !== '') {
                    $runtime->say($body, $type->choiceLabels($step));
                    $spoke = true;
                }
            }

            $result = $resumeAnswer !== null
                ? $type->resume($context, $resumeAnswer)
                : $type->run($context);

            $resumeAnswer = null;

            if ($result->outcome !== null) {
                $this->journal->record($scenario, $step, $said, $result->outcome, $result->trace);
            }

            $spoke = $spoke || $result->spoke;

            switch ($result->flow) {
                case StepFlow::Wait:
                    $conversation->forceFill(['bot_step_key' => $step->key])->save();
                    $this->journal->flush();

                    return true;

                case StepFlow::Delay:
                    $conversation->forceFill(['bot_step_key' => $step->key])->save();
                    $this->journal->flush();

                    ContinueScenario::dispatch($conversation->getKey(), $step->key)
                        ->delay(now()->addSeconds((int) $result->seconds));

                    return true;

                case StepFlow::Handoff:
                    $this->finish($conversation, RunOutcome::HandedOff);
                    $this->handOff($conversation);

                    return true;

                case StepFlow::Close:
                    $this->finish($conversation, RunOutcome::Closed);
                    $this->closeConversation($conversation);

                    return true;

                case StepFlow::Jump:
                    $target = $this->scenarioBySlug($conversation, (string) $result->target);

                    if ($target === null || ++$this->jumps > $this->maxJumps) {
                        $this->journal->record($scenario, $step, $said, RunStep::FAILED, [
                            'scenario' => $result->target,
                            'reason' => $target === null ? 'no such scenario' : 'jump limit',
                        ]);
                        $this->finish($conversation, RunOutcome::Failed);
                        $this->handOff($conversation);

                        return true;
                    }

                    $conversation->forceFill(['bot_scenario_id' => $target->getKey()])->save();
                    $scenario = $target;
                    $step = $target->startStep();

                    break;

                case StepFlow::Call:
                    $target = $this->scenarioBySlug($conversation, (string) $result->target);

                    if ($target === null || ++$this->jumps > $this->maxJumps) {
                        $this->journal->record($scenario, $step, $said, RunStep::FAILED, [
                            'scenario' => $result->target,
                            'reason' => $target === null ? 'no such scenario' : 'call depth',
                        ]);
                        $step = $scenario->step($step->secondTarget());

                        break;
                    }

                    $this->push($conversation, $scenario, $step, (string) $step->setting('into', ''));
                    $conversation->forceFill(['bot_scenario_id' => $target->getKey()])->save();
                    $scenario = $target;
                    $step = $target->startStep();

                    break;

                case StepFlow::Returns:
                    $frame = $this->pop($conversation);

                    /*
                     * Returning with nothing to return to is not an error worth
                     * failing over: a scenario may be run on its own as well as
                     * called, and the same terminal node has to work either way.
                     * On its own it simply ends the conversation.
                     */
                    if ($frame === null) {
                        $this->finish($conversation, RunOutcome::Closed);
                        $this->closeConversation($conversation);

                        return true;
                    }

                    $caller = $this->scenarioBySlug($conversation, (string) $frame['scenario']);
                    $callSite = $caller?->step((string) $frame['step']);

                    if ($caller === null || $callSite === null) {
                        $this->finish($conversation, RunOutcome::Failed);
                        $this->handOff($conversation);

                        return true;
                    }

                    if (($frame['into'] ?? '') !== '') {
                        $runtime->store((string) $frame['into'], $result->value === null ? null : (string) $result->value);
                    }

                    $conversation->forceFill(['bot_scenario_id' => $caller->getKey()])->save();
                    $scenario = $caller;
                    $step = $caller->step($callSite->next_key);

                    break;

                case StepFlow::Second:
                    $step = $scenario->step($step->secondTarget());

                    break;

                case StepFlow::Goto:
                    $step = $scenario->step($result->target);

                    break;

                case StepFlow::Next:
                    $step = $scenario->step($step->next_key);

                    break;
            }
        }

        return $this->deadEnd($conversation, $spoke);
    }

    /**
     * A branch that simply stops.
     *
     * Almost always an unconnected output the author has not finished, which is
     * why it is recorded as its own outcome rather than as a success: a scenario
     * that quietly stops halfway looks fine in the editor and looks broken to
     * the visitor.
     *
     * What happens next depends on whether the bot said anything this turn. If
     * it did, the visitor has an answer and the silence reads as the end of the
     * exchange. If it did not, they wrote to a bot and got nothing at all, and
     * the only decent response is a person.
     */
    protected function deadEnd(Conversation $conversation, bool $spoke): bool
    {
        $this->finish($conversation, RunOutcome::DeadEnd);

        $conversation->forceFill(['bot_step_key' => null])->save();

        if (! $spoke) {
            $this->handOff($conversation);
        }

        return $spoke;
    }

    protected function finish(Conversation $conversation, RunOutcome $outcome): void
    {
        $this->journal->close($outcome);

        $conversation->forceFill(['bot_run_id' => null])->save();
    }

    protected function handOff(Conversation $conversation): void
    {
        $conversation->forceFill([
            'bot_state' => BotState::HandedOff,
            'bot_step_key' => null,
            'bot_handed_off_at' => now(),
        ])->save();

        /*
         * The one moment the bell is honest, and every road to a person goes
         * through here: the node, the handoff words, a scenario running out, a
         * step that failed. Ringing at each of those call sites instead would be
         * five copies of one rule, and the copy somebody forgot would be the
         * branch that goes quiet.
         *
         * The last message rather than the visitor's own, because a handoff
         * often follows the bot's own apology and what the operator needs from
         * the bell is where the conversation got to.
         */
        app(OperatorBell::class)->ring(
            $conversation,
            $conversation->messages()->reorder()->latest('id')->first(),
        );
    }

    protected function closeConversation(Conversation $conversation): void
    {
        $conversation->forceFill([
            'status' => ConversationStatus::Closed,
            'bot_state' => BotState::Off,
            'bot_step_key' => null,
            'bot_slot' => null,
            'bot_slot_attempts' => null,
            'bot_stack' => null,
        ])->save();
    }

    /**
     * Words that mean "stop talking to me and get a person".
     *
     * Checked before any scenario runs, because a visitor asking for a human has
     * already decided the bot is not helping, and walking them through three
     * more nodes first confirms it.
     */
    protected function asksForHuman(?string $said): bool
    {
        $said = mb_strtolower(trim((string) $said));

        if ($said === '') {
            return false;
        }

        foreach ((array) config('chatbot.bot.handoff_words', []) as $word) {
            $word = mb_strtolower(trim((string) $word));

            if ($word !== '' && preg_match('/(?<!\w)'.preg_quote($word, '/').'(?!\w)/u', $said) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function scenarioBySlug(Conversation $conversation, string $slug): ?Scenario
    {
        if ($slug === '') {
            return null;
        }

        return Scenario::query()
            ->active()
            ->forBot($conversation->bot_id)
            ->where('slug', $slug)
            ->with('steps')
            ->first();
    }

    /**
     * @param  string  $into  the variable the called scenario's result lands in
     */
    protected function push(Conversation $conversation, Scenario $scenario, Step $step, string $into): void
    {
        $stack = (array) $conversation->bot_stack;
        $stack[] = ['scenario' => $scenario->slug, 'step' => $step->key, 'into' => $into];

        $conversation->forceFill(['bot_stack' => $stack])->save();
    }

    /**
     * @return array{scenario: string, step: string, into: string}|null
     */
    protected function pop(Conversation $conversation): ?array
    {
        $stack = (array) $conversation->bot_stack;
        $frame = array_pop($stack);

        $conversation->forceFill(['bot_stack' => $stack === [] ? null : $stack])->save();

        return is_array($frame) ? $frame : null;
    }

    protected function openRun(Conversation $conversation): ?Run
    {
        return $conversation->bot_run_id === null
            ? null
            : Run::query()->whereKey($conversation->bot_run_id)->first();
    }

    protected function isFirstIncoming(Conversation $conversation, Message $incoming): bool
    {
        return ! $conversation->messages()
            ->incoming()
            ->where('id', '<', $incoming->getKey())
            ->exists();
    }
}
