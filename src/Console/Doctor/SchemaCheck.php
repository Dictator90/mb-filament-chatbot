<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use Illuminate\Support\Facades\Schema;

/**
 * Are the package's own tables in the database.
 *
 * First check in the report, because everything after it reads from those
 * tables: a missing one turns every later check into an exception about a
 * table, and the person reading has to work backwards from "no such table:
 * chatbot_scenarios" to "the migrations were never run".
 *
 * The list is written out rather than derived from the migration filenames. A
 * published copy of a migration keeps the table and loses the filename, so
 * reading the directory would report a healthy installation as broken on
 * exactly the hosts that followed the publishing instructions.
 */
final class SchemaCheck extends DoctorCheck
{
    /**
     * @var list<string>
     */
    protected const array TABLES = [
        'chatbot_bots',
        'chatbot_scenarios',
        'chatbot_steps',
        'chatbot_scenario_versions',
        'chatbot_conversations',
        'chatbot_messages',
        'chatbot_runs',
        'chatbot_run_steps',
        'chatbot_knowledge_entries',
        'chatbot_quick_replies',
        'chatbot_data_resources',
        'chatbot_quality_tests',
        'chatbot_budget_periods',
    ];

    public static function key(): string
    {
        return 'schema';
    }

    public function title(): string
    {
        return 'Database schema';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        $missing = array_values(array_filter(
            self::TABLES,
            static fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missing !== []) {
            return [DoctorFinding::fail(
                count($missing).' of the package\'s '.count(self::TABLES).' tables are missing.',
                'Run php artisan migrate. If the migrations were published, check that the published copies were not edited out.',
                $missing,
            )];
        }

        return [DoctorFinding::pass('All '.count(self::TABLES).' package tables are present.')];
    }
}
