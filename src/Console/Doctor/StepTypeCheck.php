<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;
use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Nodes of a kind nothing in this installation provides.
 *
 * A package was removed, or a scenario was exported from an installation that
 * had one. The node is kept — the editor draws it as a defect rather than
 * deleting somebody's work — but a conversation reaching it is handed to a
 * person, and the panel gives no hint that a whole branch of a scenario has
 * become a dead end.
 *
 * A failure, unlike the softer scenario warnings, because there is no reading
 * of this under which the installation is doing what its author drew. Deploying
 * over it means shipping a scenario whose behaviour changed without anyone
 * editing it.
 *
 * Scenarios are named rather than nodes, because the first thing anybody does
 * with this report is open one.
 */
final class StepTypeCheck extends DoctorCheck
{
    public function __construct(protected readonly ChatbotRegistry $registry) {}

    public static function key(): string
    {
        return 'step-types';
    }

    public function title(): string
    {
        return 'Node kinds';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        $types = Step::query()->distinct()->orderBy('type')->pluck('type');

        $orphans = $types
            ->filter(fn (mixed $type): bool => is_string($type) && ! $this->registry->hasStep($type))
            ->values()
            ->all();

        if ($orphans === []) {
            return [DoctorFinding::pass('Every node in the database is of a kind this installation provides.')];
        }

        return [DoctorFinding::fail(
            count($orphans).' node kinds are used by scenarios and registered by nothing.',
            'Reinstall the package that provided them, or replace those nodes. A conversation reaching one is handed to a person, and the scenario now behaves differently from what it draws.',
            $this->scenariosUsing($orphans),
        )];
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    protected function scenariosUsing(array $types): array
    {
        $subjects = [];

        foreach ($types as $type) {
            $slugs = Scenario::query()
                ->whereIn('id', Step::query()->select('scenario_id')->where('type', $type))
                ->orderBy('slug')
                ->pluck('slug')
                ->all();

            $subjects[] = $type.': '.($slugs === [] ? 'no scenario' : implode(', ', $slugs));
        }

        return $subjects;
    }
}
