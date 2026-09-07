<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Exceptions;

use RuntimeException;

/**
 * A scenario was asked to go live while something in it is broken.
 *
 * Thrown rather than returned as a flag so that no caller can publish by
 * ignoring the answer. The blocking issues travel with the exception because
 * the screen that catches it has to say which nodes to fix — a refusal that
 * only says "no" sends the author back to the canvas to look for a defect the
 * checker had already found.
 */
class ScenarioNotPublishable extends RuntimeException
{
    /**
     * @param  list<array{severity: string, key: string|null, message: string}>  $issues
     */
    final public function __construct(string $message, public readonly array $issues = [])
    {
        parent::__construct($message);
    }

    /**
     * @param  list<array{severity: string, key: string|null, message: string}>  $issues
     */
    public static function because(string $scenarioName, array $issues): static
    {
        $count = count($issues);

        return new static(
            trans_choice('chatbot::nodes.graph.not_publishable', $count, [
                'scenario' => $scenarioName,
                'count' => $count,
            ]),
            $issues,
        );
    }

    /**
     * The messages alone, for a notification that has no room for the nodes.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(
            static fn (array $issue): string => (string) $issue['message'],
            $this->issues,
        );
    }
}
