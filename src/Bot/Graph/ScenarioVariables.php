<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Bot\Graph;

use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * The names this scenario fills in, gathered from its own nodes.
 *
 * The editor could already show where a variable comes *from* — the host's
 * registered sources, listed in the picker — and could not show the ones the
 * scenario makes itself, which are most of them. An author who put a form node
 * on the canvas and picked four slots got four variables and no name for any of
 * them; the next node's message, where those names have to be typed by hand,
 * was the first place the guess was tested, and a wrong guess substitutes to an
 * empty string. The sentence goes out short of a word, to a customer, with
 * nothing anywhere reporting a fault.
 *
 * Read from the draft on the canvas rather than from a conversation, because
 * this answers a question asked while writing: what may I type here? A run's
 * variables are the wrong answer twice over — they exist only after the run,
 * and they are one visitor's, not the scenario's.
 *
 * **Every node is asked, including the ones above and below.** Ordering the
 * list by what is reachable from here would need the graph walked per node and
 * would still be a guess: a name written by a node on the other branch is
 * legitimately readable by anything downstream of the join. The list says what
 * the scenario names; whether a given name holds anything at a given moment is
 * what the author is reading the canvas to work out.
 */
final class ScenarioVariables
{
    public function __construct(private readonly ChatbotRegistry $registry) {}

    /**
     * Every name the scenario's nodes write, each labelled by the node.
     *
     * A name written by two nodes is listed once, under the first that names
     * it. Two entries with the same value would draw the author's eye to a
     * conflict that is usually deliberate — a phone number asked for at the
     * start and again after a correction is one variable, on purpose.
     *
     * @return list<array{name: string, label: string}>
     */
    public function forScenario(Scenario $scenario): array
    {
        $scenario->loadMissing('steps');

        $found = [];

        foreach ($scenario->steps as $step) {
            $type = $this->registry->step((string) $step->type);

            if ($type === null) {
                continue;
            }

            foreach ($type->writes($step) as $name) {
                $name = trim((string) $name);

                if ($name === '' || isset($found[$name])) {
                    continue;
                }

                $found[$name] = $this->describe($type->label(), $step);
            }
        }

        ksort($found);

        $variables = [];

        foreach ($found as $name => $label) {
            $variables[] = ['name' => (string) $name, 'label' => $label];
        }

        return $variables;
    }

    /**
     * Which node fills this in, said the way the canvas says it.
     *
     * The node's own key and not only its kind, because a scenario routinely
     * holds three collect nodes and "Collect" would send the author looking
     * through all of them.
     */
    private function describe(string $typeLabel, Step $step): string
    {
        $key = trim((string) $step->key);

        return $key === '' ? $typeLabel : $typeLabel.' · '.$key;
    }
}
