<?php

declare(strict_types=1);

namespace MB\FilamentChatbot\Console\Doctor;

use MB\FilamentChatbot\Registry\ChatbotRegistry;

/**
 * Runs every check and reduces the answers to one verdict.
 *
 * The built-in list lives here rather than in the service provider so that a
 * host adding its own check does not have to know how the package registers
 * its own — it registers into the same registry every other extension point
 * uses, and its check appears in the report after the package's.
 *
 * Order is the order of the list, and the list starts with the schema. Nothing
 * below it can say anything useful when its tables are missing, and a report
 * that opens with six exceptions about SQL buries the one line that explains
 * all of them.
 */
final class Doctor
{
    /**
     * @var list<class-string<DoctorCheck>>
     */
    protected const array BUILT_IN = [
        SchemaCheck::class,
        StepTypeCheck::class,
        ScenarioCheck::class,
        BotScenarioCheck::class,
        DataResourceCheck::class,
        QueueCheck::class,
        OperatorBellCheck::class,
        RateLimitStoreCheck::class,
        AssetsCheck::class,
        WidgetCheck::class,
        OutboundHttpCheck::class,
        AiGatewayCheck::class,
    ];

    public function __construct(protected readonly ChatbotRegistry $registry) {}

    /**
     * Every check, the package's own first.
     *
     * A host check that claims a built-in key replaces it, matching how step
     * types behave: overriding one check of a package you cannot fork is a real
     * need, and the alternative is a host copying the whole command.
     *
     * @return list<class-string<DoctorCheck>>
     */
    public function checks(): array
    {
        $checks = [];

        foreach ([...self::BUILT_IN, ...array_values($this->registry->doctorChecks())] as $check) {
            $checks[$check::key()] = $check;
        }

        return array_values($checks);
    }

    /**
     * Runs the checks and returns what each of them found.
     *
     * @param  list<string>  $only  keys to run, or an empty list for all of them
     * @return list<array{key: string, title: string, findings: list<DoctorFinding>}>
     */
    public function run(array $only = [], bool $offline = false): array
    {
        $report = [];

        foreach ($this->checks() as $class) {
            if ($only !== [] && ! in_array($class::key(), $only, true)) {
                continue;
            }

            $check = app($class);
            $check->offline = $offline;

            $report[] = [
                'key' => $class::key(),
                'title' => $check->title(),
                'findings' => $check->safely(),
            ];
        }

        return $report;
    }

    /**
     * The loudest thing in the report.
     *
     * What the exit code is decided on: a deployment pipeline needs one answer,
     * and it has to be the worst one rather than the last one.
     *
     * @param  list<array{key: string, title: string, findings: list<DoctorFinding>}>  $report
     */
    public function worst(array $report): DoctorStatus
    {
        $worst = DoctorStatus::Pass;

        foreach ($report as $entry) {
            foreach ($entry['findings'] as $finding) {
                if ($finding->status->isWorseThan($worst)) {
                    $worst = $finding->status;
                }
            }
        }

        return $worst;
    }
}
