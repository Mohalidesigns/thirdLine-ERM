<?php

namespace App\Services\Tprm\Reporting\Operational;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * The eight standard operational reports — FR-RPT-07.
 *
 * A LIST, NOT A SWITCH. The hub, the export route and the scheduled-report
 * command all resolve through here, so a report cannot be reachable from one
 * of the three and invisible to the others — which is how a scheduled report
 * ends up emailing a dataset nobody can open on screen.
 *
 * `forUser()` FILTERS BY THE DATA'S OWN PERMISSION, not by the reporting one.
 * A relationship owner who can read the register but was never given
 * `tprm.screening.view` does not see the screening log here, because a hub
 * that ignored the module's own gates would be the easiest way around them.
 */
class OperationalReportRegistry
{
    /** @var list<class-string<OperationalReport>> */
    private const REPORTS = [
        AssessmentStatusReport::class,
        DueDiligencePipelineReport::class,
        ContractExpiryCalendarReport::class,
        ObligationRegisterReport::class,
        SlaPerformanceReport::class,
        AccessReconciliationReport::class,
        ScreeningLogReport::class,
        EvidenceExpiryForecastReport::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<OperationalReport>
     */
    public function all(): array
    {
        return array_map(
            fn (string $class) => $this->container->make($class),
            self::REPORTS,
        );
    }

    /**
     * @return list<OperationalReport>
     */
    public function forUser(User $user): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (OperationalReport $report) => $user->can($report->permission()),
        ));
    }

    public function find(string $key): OperationalReport
    {
        foreach ($this->all() as $report) {
            if ($report->key() === $key) {
                return $report;
            }
        }

        throw new RuntimeException("No operational report is registered under [{$key}].");
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(fn (OperationalReport $report) => $report->key(), $this->all());
    }
}
