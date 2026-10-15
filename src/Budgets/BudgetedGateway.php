<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Budgets;

use MB\FilamentChatbot\Ai\AiConversationGateway;
use MB\FilamentChatbot\Ai\AiFailure;
use MB\FilamentChatbot\Ai\AiGateway;
use MB\FilamentChatbot\Ai\AiPrompt;
use MB\FilamentChatbot\Ai\AiReply;
use MB\FilamentChatbot\Ai\AiTurn;
use MB\FilamentChatbot\Models\Bot;
use Throwable;

/**
 * The month's budget, standing between a scenario and a provider.
 *
 * A wrapper rather than four lines repeated in the two model nodes and the
 * router, and the reason is what a fourth caller would look like: it would work,
 * it would answer visitors, and it would spend money nobody counted, because
 * nothing about calling a gateway says a budget was supposed to be taken first.
 * Wrapped, the only way to reach a provider is through the reservation, and a
 * caller cannot forget a step it does not perform.
 *
 * **Reserve, ask, settle — and the order is the whole feature.** The estimate is
 * written before the request leaves, so two conversations arriving in the same
 * second contend for one row instead of both reading the same balance and both
 * spending it. Settling afterwards replaces that estimate with what the provider
 * actually counted. Reversed — ask first, account after — the guard would be a
 * report rather than a limit, and would let any number of simultaneous calls
 * through a budget that had already run out.
 *
 * **An exhausted budget declines; it does not throw.** It arrives as
 * {@see AiFailure::BudgetExhausted}, which every node routes into the exit it
 * already has for an unset key or a provider outage. That is the package's rule
 * that a scenario must keep working with the model off, applied to the one
 * failure an installation causes itself: running out of money on a Tuesday
 * afternoon must degrade the bot exactly as a bad afternoon at the provider
 * does, and not end the visitor's turn in silence.
 */
final class BudgetedGateway implements AiConversationGateway
{
    /**
     * Characters per token in the estimate, deliberately low.
     *
     * English tokenises at roughly four characters per token and Cyrillic at
     * closer to two, and this package is used in both. The lower figure
     * over-estimates English prompts, which costs nothing that matters: an
     * over-estimate is corrected by settle() moments later, while an
     * under-estimate is what lets a burst of concurrent calls walk past a
     * ceiling that was nearly reached.
     */
    private const CHARS_PER_TOKEN = 3;

    /**
     * The answer's length when the prompt does not cap it.
     *
     * Matches the drivers' own default ceiling, so the estimate assumes the
     * longest answer the request could have produced rather than the shortest.
     * A budget that only reserved the prompt would be reached by output nobody
     * had reserved room for.
     */
    private const ASSUMED_OUTPUT_TOKENS = 600;

    public function __construct(
        private readonly AiGateway $inner,
        private readonly BudgetGuard $guard,
        private readonly ?Bot $bot = null,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function isAvailable(): bool
    {
        return $this->inner->isAvailable();
    }

    /**
     * Takes room for the call, makes it, and closes the reservation either way.
     *
     * A gateway with nothing behind it is asked directly and nothing is
     * reserved. Its decline costs no tokens and reaches no provider, and a
     * period row raised and lowered again for it would put a call in the ledger
     * that never left the building — on every installation that has not
     * configured a model, which is most of them.
     */
    public function ask(AiPrompt $prompt): AiReply
    {
        if (! $this->inner->isAvailable()) {
            return $this->inner->ask($prompt);
        }

        $reservation = $this->guard->reserve($this->bot, $this->estimate($prompt));

        if ($reservation->isDenied()) {
            return AiReply::declined(AiFailure::BudgetExhausted, $reservation->reason);
        }

        try {
            $reply = $this->inner->ask($prompt);
        } catch (Throwable $exception) {
            $this->guard->release($reservation);

            throw $exception;
        }

        $this->close($reservation, $reply);

        return $reply;
    }

    /**
     * The same reservation around an exchange, and the reason the wrapper
     * declares the richer contract even when the driver behind it does not.
     *
     * An agent runs several rounds inside one visitor message, each one a
     * billed request carrying the whole history and the whole tool catalogue —
     * the most expensive thing in the package by a wide margin. Reached around
     * the budget it would be the one caller that spends uncounted, which is
     * exactly what this class exists to make impossible, so the agent is given
     * no way to ask for an unwrapped gateway.
     *
     * A driver that cannot hold an exchange is answered here rather than
     * asked: declining with a reason that names the driver puts "this
     * installation's model answers questions but cannot run an agent" in the
     * run journal, where the author of the scenario will read it. Letting the
     * call through to a driver without the method would be a `TypeError`
     * ending a visitor's turn in silence.
     */
    public function converse(AiTurn $turn): AiReply
    {
        if (! $this->inner instanceof AiConversationGateway) {
            return AiReply::declined(
                AiFailure::NotConfigured,
                "The [{$this->inner->name()}] driver answers single questions and cannot hold a conversation with tools.",
            );
        }

        if (! $this->inner->isAvailable()) {
            return $this->inner->converse($turn);
        }

        $reservation = $this->guard->reserve($this->bot, $this->estimateTurn($turn));

        if ($reservation->isDenied()) {
            return AiReply::declined(AiFailure::BudgetExhausted, $reservation->reason);
        }

        try {
            $reply = $this->inner->converse($turn);
        } catch (Throwable $exception) {
            $this->guard->release($reservation);

            throw $exception;
        }

        $this->close($reservation, $reply);

        return $reply;
    }

    /**
     * Turns the estimate into what the call really cost, in one of three ways.
     *
     * Usage reported, whatever the outcome: settle with it. A request that left,
     * was billed and then failed to parse is spending, and a ledger that counted
     * only successful answers would under-report precisely the calls somebody is
     * investigating.
     *
     * Nothing reported and no answer either: release. Nothing left, nothing was
     * billed, and an estimate left standing would let a run of provider outages
     * spend a month without a single answer having been generated.
     *
     * An answer with no usage on it — the shape of every driver whose provider
     * or library does not count tokens back — keeps the estimate instead. The
     * call happened and it cost something; writing zero would make the cheapest
     * ledger in the installation belong to the driver nobody can measure, and a
     * budget set against it would never be reached.
     */
    private function close(BudgetReservation $reservation, AiReply $reply): void
    {
        $usage = $reply->usage();

        if ($usage->total() > 0) {
            $this->guard->settle($reservation, $usage->inputTokens, $usage->outputTokens, $usage->costMicro);

            return;
        }

        if (! $reply->ok()) {
            $this->guard->release($reservation);

            return;
        }

        $this->guard->settle($reservation, $reservation->estimatedTokens, 0, $usage->costMicro);
    }

    /**
     * A guess at the size of the call, and named one on purpose.
     *
     * Nothing here counts tokens: a real count needs the provider's own
     * tokeniser, which differs per model and would have to be shipped, kept in
     * step with models nobody has released yet, and run on the hot path of every
     * turn. What the reservation needs is not accuracy but a number written
     * before the request leaves, and settle() replaces it with the provider's
     * own count a moment later. The estimate is only ever load-bearing for the
     * calls that overlap it.
     *
     * The schema is left out of the count. It is a handful of field names next
     * to an instruction and a page of context, and adding it would make this
     * look more precise than it is.
     */
    private function estimate(AiPrompt $prompt): int
    {
        $text = $prompt->instruction."\n".$prompt->question."\n".$prompt->contextText();

        $output = $prompt->maxTokens > 0 ? $prompt->maxTokens : self::ASSUMED_OUTPUT_TOKENS;

        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN) + $output;
    }

    /**
     * The same guess for an exchange, with the tool catalogue counted in.
     *
     * The catalogue is not a rounding error: a dozen actions with their field
     * descriptions is comparable in size to the history, and it is re-sent on
     * every round of every turn. Left out, the estimate would be reliably and
     * increasingly low on precisely the installations that give the agent most
     * to do.
     */
    private function estimateTurn(AiTurn $turn): int
    {
        $text = $turn->instruction;

        foreach ($turn->messages as $message) {
            $text .= "\n".$message->text;

            foreach ($message->calls as $call) {
                $text .= "\n".$call->name.' '.json_encode($call->input);
            }
        }

        foreach ($turn->tools as $tool) {
            $text .= "\n".$tool->name.' '.$tool->description.' '.json_encode($tool->schema);
        }

        $output = $turn->maxTokens > 0 ? $turn->maxTokens : self::ASSUMED_OUTPUT_TOKENS;

        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN) + $output;
    }
}
