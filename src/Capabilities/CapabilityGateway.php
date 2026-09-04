<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Capabilities;

use MB\FilamentChatbot\Bot\Fields\Field;
use MB\FilamentChatbot\Bot\Steps\StepContext;
use Throwable;

/**
 * The one place a host action is actually called.
 *
 * Nothing else in the package invokes a handler. A node that called the closure
 * itself would be a node that decides on its own whether the input was checked,
 * whether a thrown exception ends the conversation and whether a handler
 * returning nonsense is a success — and the second node to do it would decide
 * differently. Those three answers are the same for every action, so they are
 * written once, here.
 */
class CapabilityGateway
{
    /**
     * Checks the payload, calls the handler and reports what happened.
     *
     * **A payload that does not fit the description is refused, never adjusted.**
     * Coercing a missing value to an empty string or a word to a zero is how an
     * action ends up awarding nought points, cancelling the booking whose id
     * defaulted to nothing, or writing an empty name over a real one — and every
     * one of those succeeds, so the trace says the action worked. A refusal
     * takes the node's second exit, which is a branch the author drew and can
     * route to a person.
     *
     * **A handler that throws fails this call and nothing else.** The action
     * belongs to the host and can do anything at all — reach a payment gateway
     * that is down, hit a unique index, dereference a record somebody deleted a
     * second ago. Letting that out would end the visitor's turn with a stack
     * trace and no reply, punishing them for the state of a system they cannot
     * see. It is caught, worded into the trace and routed like any other
     * failure.
     *
     * **Anything but an array back is a failure too.** A handler that returns
     * null or true is a handler somebody wrote against a signature they did not
     * read, and treating it as an empty success would make the node write
     * nothing while its "done" branch carried on describing a result.
     *
     * Whether an approval node stands above a writing action is not asked here.
     * A gateway is handed one call and one conversation; it cannot see the
     * graph, and answering from what it can see would mean answering wrongly.
     * That check belongs to the node's issues() and to the release of a
     * version, where the graph is in hand and the fix is still cheap. What is
     * enforced here is the action's own requiresApproval flag, which is the
     * part a scenario cannot argue with.
     *
     * @param  array<string, mixed>  $payload
     */
    public function run(CapabilityAction $action, array $payload, StepContext $context): CapabilityResult
    {
        $refusal = $this->refuse($action, $payload);

        if ($refusal !== null) {
            return CapabilityResult::failed($refusal);
        }

        try {
            $returned = ($action->handler)($payload, $context);
        } catch (Throwable $exception) {
            return CapabilityResult::failed(sprintf(
                '[%s] threw %s: %s',
                $action->reference(),
                $exception::class,
                $exception->getMessage(),
            ));
        }

        if (! is_array($returned)) {
            return CapabilityResult::failed(
                "[{$action->reference()}] returned ".get_debug_type($returned).' instead of an array of results.',
            );
        }

        return CapabilityResult::succeeded($returned);
    }

    /**
     * The reason the payload cannot be passed on, or null when it may be.
     *
     * Reads the descriptors through toArray() rather than through getters of
     * their own: that array is what the editor draws the very same form from,
     * so a field the author was shown as required is the field checked here.
     * Two sources for one answer is how a form marks a value optional while the
     * call refuses it as missing.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function refuse(CapabilityAction $action, array $payload): ?string
    {
        foreach ($action->input as $field) {
            $descriptor = $field->toArray();
            $name = $field->name();
            $value = $payload[$name] ?? null;

            if ($this->missing($value)) {
                if (($descriptor['required'] ?? false) === true) {
                    return "[{$action->reference()}] needs a value for [{$name}].";
                }

                continue;
            }

            $wrong = $this->wrongValue($field->type(), $descriptor, $value);

            if ($wrong !== null) {
                return "[{$action->reference()}] was given a value for [{$name}] that {$wrong}.";
            }
        }

        return null;
    }

    /**
     * What is wrong with a value of this kind, or null when nothing is.
     *
     * An empty option list is not treated as "nothing is allowed". A select
     * whose options are computed at render time can legitimately arrive here
     * without them, and refusing every value in that case would make an action
     * fail for a reason nobody could see in the editor, where the list is
     * plainly there.
     *
     * @param  array<string, mixed>  $descriptor
     */
    protected function wrongValue(string $type, array $descriptor, mixed $value): ?string
    {
        if ($type === Field::NUMBER) {
            return is_numeric($value) ? null : 'is not a number.';
        }

        if ($type !== Field::SELECT) {
            return null;
        }

        $options = $descriptor['options'] ?? null;

        if (! is_array($options) || $options === []) {
            return null;
        }

        $allowed = array_map(static fn (int|string $option): string => (string) $option, array_keys($options));

        foreach ((array) $value as $one) {
            if (! in_array((string) $one, $allowed, true)) {
                return 'is not one of '.implode(', ', $allowed);
            }
        }

        return null;
    }

    /**
     * Whether a value counts as absent.
     *
     * An empty string counts, because that is what a text field submits when
     * nobody typed in it and what {{a_variable}} substitutes to when the
     * conversation never learned one. A required input satisfied by the empty
     * string is a required input that is not enforced at all.
     */
    protected function missing(mixed $value): bool
    {
        if ($value === null || $value === []) {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }
}
