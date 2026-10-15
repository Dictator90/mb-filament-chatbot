<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use MB\FilamentChatbot\Models\DataResource;
use MB\FilamentChatbot\Models\Step;

/**
 * Whether the data resources a scenario points at actually exist as rows.
 *
 * The config file declares them; `chatbot:sync-resources` writes them; the
 * reader consults the table and nothing else. A deployment that runs `migrate`
 * and `optimize` but not the sync therefore produces an installation where
 * every screen a developer would check looks right — the declaration is in the
 * file, the scenario is drawn correctly on the canvas, nothing is logged — and
 * the node takes its second exit on every single conversation. The visitor is
 * told there is nothing to show them; the journal says "empty result".
 *
 * One installation shipped that way and ran for weeks before anybody noticed
 * that its "question about my booking" branch had never worked once. That is
 * the whole reason this check exists, and why it fails rather than warns when a
 * scenario names a resource with no row: a scenario pointing at nothing is not
 * a preference, it is a branch that cannot work.
 *
 * Declared-but-unsynced resources that no scenario mentions are a warning
 * instead. Nothing is broken yet — but the sync has evidently not run, and the
 * next scenario to use one inherits the failure silently.
 */
final class DataResourceCheck extends DoctorCheck
{
    public static function key(): string
    {
        return 'data-resources';
    }

    public function title(): string
    {
        return 'Data resources';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        if (! (bool) config('chatbot.data_resources.enabled', true)) {
            return [DoctorFinding::pass('Data resources are switched off for this installation.')];
        }

        $declared = $this->declared();
        $live = DataResource::query()->active()->pluck('key')->all();

        $used = $this->used();
        $broken = array_values(array_diff($used, $live));

        if ($broken !== []) {
            return [DoctorFinding::fail(
                'Scenarios point at data resources that are not registered or are switched off: '.implode(', ', $broken).'.',
                'Run `php artisan chatbot:sync-resources`, and add it to the deployment next to `migrate`. '
                .'Until then those nodes take their "nothing found" exit on every conversation, and the visitor is '
                .'told there are no records.',
                $broken,
            )];
        }

        $missing = array_values(array_diff($declared, $live));

        if ($missing !== []) {
            return [DoctorFinding::warn(
                'Declared data resources have no active row: '.implode(', ', $missing).'.',
                'Run `php artisan chatbot:sync-resources`. No scenario uses them yet, so nothing is broken — but the '
                .'sync has not run, and the first scenario to use one would fail silently.',
                $missing,
            )];
        }

        if ($live === []) {
            return [DoctorFinding::pass('No data resources are declared, and no scenario asks for one.')];
        }

        return [DoctorFinding::pass(count($live).' data resources are registered, and every scenario that names one finds it.')];
    }

    /**
     * Resource keys named by nodes, whichever node kind reads records.
     *
     * Read from the stored settings rather than from the node classes: the
     * setting is called `resource` on every one of them by contract, and a host
     * node that reads records is included for free. Drafts count as well as
     * released versions — a draft is what the author is about to publish.
     *
     * @return list<string>
     */
    private function used(): array
    {
        $keys = [];

        foreach (Step::query()->get(['config']) as $step) {
            $key = trim((string) $step->setting('resource', ''));

            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @return list<string>
     */
    private function declared(): array
    {
        $keys = [];

        foreach ((array) config('chatbot.data_resources.resources', []) as $resource) {
            $key = trim((string) ($resource['key'] ?? ''));

            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }
}
