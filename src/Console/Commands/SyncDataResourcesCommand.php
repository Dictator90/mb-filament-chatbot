<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Commands;

use Illuminate\Console\Command;
use MB\FilamentChatbot\Data\DataResourceSync;
use MB\FilamentChatbot\Models\DataResource;
use Throwable;

/**
 * Writes the declared data resources into the table the reader consults.
 *
 * Meant for a deployment pipeline, next to `migrate --force`. Without it a host
 * has to write these four lines itself, and the one that wrote them into a
 * service provider runs a write on every boot of every worker and every
 * request; the one that forgot them entirely has a config file full of
 * declarations that nothing reads, and scenarios answering "unknown resource"
 * to customers.
 *
 * **A declaration removed from the file is not revoked here**, and the listing
 * at the end is where that shows. Two kinds of row share the table — the ones
 * the file owns and the ones somebody built in the panel — and no column tells
 * them apart, so deleting what was not declared would take the panel's work
 * with it. Revoking is `is_active => false` in the file: a line in a diff
 * somebody reviews, rather than a deletion that leaves nothing behind.
 */
class SyncDataResourcesCommand extends Command
{
    protected $signature = 'chatbot:sync-resources {--list : Show every declared resource afterwards}';

    protected $description = 'Write the data resources declared in config into the database';

    public function handle(DataResourceSync $sync): int
    {
        try {
            $synced = $sync->sync();
        } catch (Throwable $exception) {
            /*
             * Reported rather than thrown, because this runs in a deployment:
             * a stack trace scrolling past a release script says less about a
             * misspelled key than one sentence naming it does.
             */
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($synced === 0) {
            $this->components->warn('Nothing is declared under chatbot.data_resources.resources.');

            return self::SUCCESS;
        }

        $this->components->info("Wrote {$synced} declared data ".str('resource')->plural($synced).'.');

        if ($this->option('list')) {
            $this->table(
                ['Key', 'Model', 'Modes', 'Active'],
                DataResource::query()->orderBy('key')->get()->map(fn (DataResource $resource): array => [
                    $resource->key,
                    $resource->model,
                    implode(', ', (array) $resource->modes),
                    $resource->is_active ? 'yes' : 'no',
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
