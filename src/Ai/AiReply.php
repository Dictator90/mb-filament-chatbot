<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Ai;

/**
 * What came back from a model, or why nothing did.
 *
 * One type for both outcomes, and no exception anywhere in this layer. A
 * provider being down is not exceptional — it is the ordinary state a scenario
 * has to keep working in — and an exception would travel up out of a node,
 * past the engine, and end a visitor's turn in silence. A reply that says "no,
 * because" is routed by the node that asked, into the exit its author drew.
 *
 * A declined reply still carries usage: the request may have left, been billed
 * and then failed to parse, and a budget that only counts successes
 * under-reports exactly the calls somebody is investigating.
 */
final class AiReply
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $sources
     * @param  list<AiToolCall>  $calls
     */
    private function __construct(
        private readonly bool $ok,
        private readonly string $text = '',
        private readonly array $data = [],
        private readonly array $sources = [],
        private readonly ?AiFailure $failure = null,
        private readonly ?string $error = null,
        private readonly AiUsage $usage = new AiUsage,
        private readonly array $calls = [],
    ) {}

    /**
     * A prose answer.
     *
     * @param  list<string>  $sources  fragment keys the model says it used
     */
    public static function answered(string $text, array $sources = [], ?AiUsage $usage = null): self
    {
        return new self(
            ok: true,
            text: $text,
            sources: array_values($sources),
            usage: $usage ?? new AiUsage,
        );
    }

    /**
     * An answer read back as the structure the prompt's schema asked for.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $sources
     */
    public static function structured(array $data, string $text = '', array $sources = [], ?AiUsage $usage = null): self
    {
        return new self(
            ok: true,
            text: $text,
            data: $data,
            sources: array_values($sources),
            usage: $usage ?? new AiUsage,
        );
    }

    /**
     * The model wants something done before it answers.
     *
     * A successful reply, not a third state: the request arrived, it was
     * billed, and what came back is a well-formed turn. Whether the tools are
     * ones the caller offered, whether their arguments fit and whether they may
     * run at all is the caller's business — a reply type that tried to decide
     * any of that would be deciding it without the conversation in hand.
     *
     * The text travels with the calls because models often say something first
     * ("смотрю Вашу бронь") and ask in the same breath. Dropping it loses the
     * only sentence the visitor would have seen while a tool ran.
     *
     * @param  list<AiToolCall>  $calls
     */
    public static function calling(array $calls, string $text = '', ?AiUsage $usage = null): self
    {
        return new self(
            ok: true,
            text: $text,
            usage: $usage ?? new AiUsage,
            calls: array_values($calls),
        );
    }

    /**
     * No answer, for one of the six reasons.
     *
     * The sentence is for the run journal and may name the provider's own
     * error; the enum is what code is allowed to look at. Callers that branched
     * on the wording of a message from somebody else's API break on the day
     * that API rewords it.
     */
    public static function declined(AiFailure $failure, ?string $reason = null, ?AiUsage $usage = null): self
    {
        return new self(
            ok: false,
            failure: $failure,
            error: $reason ?? $failure->reason(),
            usage: $usage ?? new AiUsage,
        );
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * The fragment keys the model claims it answered from.
     *
     * A claim, never a fact: the caller checks these against what it actually
     * handed over. A model naming a source it was not given has made the source
     * up, and the answer that came with it is the kind that sounds right.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * What the model asked to have done, in the order it asked.
     *
     * @return list<AiToolCall>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function wantsTools(): bool
    {
        return $this->calls !== [];
    }

    public function failure(): ?AiFailure
    {
        return $this->failure;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function usage(): AiUsage
    {
        return $this->usage;
    }
}
