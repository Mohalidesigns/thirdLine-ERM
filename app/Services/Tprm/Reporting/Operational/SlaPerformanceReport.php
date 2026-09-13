<?php

namespace App\Services\Tprm\Reporting\Operational;

use App\Models\Tprm\Sla;
use App\Models\Tprm\SlaMeasurement;
use App\Services\Tprm\Contracts\SlaService;
use App\Services\Tprm\Reporting\Concerns\StatesAbsence;

/**
 * How each service level actually performed — FR-RPT-07.
 *
 * A MISSING MEASUREMENT IS REPORTED, NOT SKIPPED. `SlaService::missingPeriods()`
 * exists because the failure mode of SLA reporting is silence: a vendor that
 * stops submitting its numbers shows as no breaches, which reads identically
 * to a vendor meeting every target. The unmeasured-period count sits next to
 * the breach count for that reason, and a target with no measurements at all
 * says so rather than reporting 100%.
 *
 * SERVICE CREDITS ARE SHOWN AS CLAIMED AND AS RECEIVED. They are routinely
 * different, and the gap between them is a contract-management finding that no
 * single "credits" column would surface.
 */
class SlaPerformanceReport implements OperationalReport
{
    use StatesAbsence;

    /** How far back the performance window looks. */
    private const LOOKBACK_MONTHS = 12;

    public function __construct(private readonly SlaService $slas) {}

    public function key(): string
    {
        return 'sla-performance';
    }

    public function title(): string
    {
        return 'SLA performance';
    }

    public function description(): string
    {
        return 'Every service level over the last twelve months: measurements taken, breaches, consecutive '
            .'breaches, periods never measured, and credits claimed against credits received.';
    }

    public function permission(): string
    {
        return 'tprm.contract.view';
    }

    public function headers(): array
    {
        return [
            'Engagement', 'Provider', 'Contract', 'Metric', 'Target', 'Window', 'Measured periods',
            'Breaches', 'Consecutive breaches', 'Periods never measured', 'Latest period',
            'Latest value', 'Latest verdict', 'Credits claimed', 'Credits received', 'Active',
        ];
    }

    public function rows(): array
    {
        $slas = Sla::query()
            ->with([
                'engagement:id,reference,third_party_id,currency',
                'engagement.thirdParty:id,legal_name',
                'contract:id,reference',
                'measurements',
            ])
            ->get();

        return $slas->map(function (Sla $sla) {
            $window = $sla->measurements
                ->filter(fn (SlaMeasurement $m) => $m->period_end !== null
                    && $m->period_end->gte(now()->subMonths(self::LOOKBACK_MONTHS)))
                ->sortByDesc(fn (SlaMeasurement $m) => $m->period_end);

            $latest = $window->first();

            return [
                $this->labelOf($sla->engagement, 'reference', 'Not linked'),
                $this->labelOf($sla->engagement?->thirdParty, 'legal_name', 'Not recorded'),
                $this->labelOf($sla->contract, 'reference', 'Not linked'),
                $sla->metric_name,
                $sla->targetLabel(),
                $sla->measurement_window ? ucwords(str_replace('_', ' ', $sla->measurement_window)) : 'Not set',
                $window->count(),
                $window->where('is_breach', true)->count(),
                $this->slas->consecutiveBreaches($sla),
                // Silence is the failure mode this column exists for.
                count($this->slas->missingPeriods($sla, self::LOOKBACK_MONTHS)),
                $latest?->period_end?->toDateString() ?? 'Never measured',
                $latest === null ? '' : (float) $latest->actual_value,
                $this->verdict($latest, $window->count()),
                $this->money($window->sum(fn (SlaMeasurement $m) => (int) ($m->credit_claimed_minor ?? 0))),
                $this->money($window->sum(fn (SlaMeasurement $m) => (int) ($m->credit_received_minor ?? 0))),
                $sla->is_active ? 'Yes' : 'No',
            ];
        })->values()->all();
    }

    public function notes(): array
    {
        return [
            'Window' => 'The last '.self::LOOKBACK_MONTHS.' months',
            'Periods never measured' => 'Windows that fell due and against which nothing was recorded',
            'Credits' => 'Shown in major units, as claimed and as received',
        ];
    }

    private function verdict(?SlaMeasurement $latest, int $measured): string
    {
        if ($measured === 0) {
            // Not "met". A target nobody measured has no verdict, and
            // reporting one as passing is how a vendor stops submitting.
            return 'No measurements in the window';
        }

        return $latest?->is_breach ? 'Breach' : 'Met';
    }

    private function money(int $minor): string
    {
        return $minor === 0 ? 'None' : number_format($minor / 100, 2, '.', ',');
    }
}
