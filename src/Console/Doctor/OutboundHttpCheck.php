<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use MB\FilamentChatbot\Bot\Steps\Types\HttpStep;
use MB\FilamentChatbot\Models\Scenario;
use MB\FilamentChatbot\Models\Step;

/**
 * Scenarios that call out, against the list of places they may call.
 *
 * The allow-list starts empty on purpose — an http node that could reach
 * anything the moment it was drawn would be a scenario author with the
 * application's network access. The cost of that safety is this exact
 * situation: a scenario built and tested against a live endpoint, deployed to a
 * host whose config was never edited, where every call is refused. The refusal
 * takes the node's failure exit, so the conversation continues and simply never
 * has the data.
 *
 * A failure rather than a warning, because the scenario is already drawn: an
 * author decided that call was necessary, and an installation that cannot make
 * it is running a scenario that cannot do its job.
 */
final class OutboundHttpCheck extends DoctorCheck
{
    public static function key(): string
    {
        return 'http';
    }

    public function title(): string
    {
        return 'Outbound HTTP';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        $scenarios = Scenario::query()
            ->whereIn('id', Step::query()->select('scenario_id')->where('type', HttpStep::key()))
            ->orderBy('slug')
            ->pluck('slug')
            ->all();

        if ($scenarios === []) {
            return [DoctorFinding::skipped('No scenario makes an outbound call.')];
        }

        $domains = array_values(array_filter(
            (array) config('chatbot.http.allowed_domains', []),
            static fn (mixed $domain): bool => is_string($domain) && trim($domain) !== '',
        ));

        if ($domains === []) {
            return [DoctorFinding::fail(
                count($scenarios).' scenarios make an outbound call and no domain is allowed.',
                'List the hosts in chatbot.http.allowed_domains. Until then every call is refused and the node takes its failure exit in silence.',
                $scenarios,
            )];
        }

        return [DoctorFinding::pass(
            count($scenarios).' scenarios call out, to '.count($domains).' allowed domains.',
            $domains,
        )];
    }
}
