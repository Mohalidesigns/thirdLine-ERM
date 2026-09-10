<?php

namespace App\Services\Tprm\Contracts;

use App\Models\Tprm\Sla;
use App\Models\Tprm\SlaMeasurement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recording service level measurements and detecting breaches — FR-CTR-07.
 *
 * THE BREACH VERDICT IS COMPUTED ONCE, AT WRITE, AND THEN STORED. Renegotiating
 * a target next year must not retrospectively un-breach last year, and a view
 * that recomputed against today's target would silently rewrite the
 * service-credit history the moment somebody edited an SLA row. This is the
 * same rule the module applies to every score it stores.
 *
 * A REPEATED MISS IS THE FINDING, NOT A SINGLE ONE. One month 0.05% below a
 * 99.9% target is a low-severity miss; three consecutive months is a pattern,
 * and it is the pattern that belongs in front of a relationship owner. So the
 * severity of one measurement is deliberately conservative and the register
 * counts consecutive breaches separately.
 */
class SlaService
{
    /**
     * Record a measurement, computing its breach verdict from the SLA's own
     * operator and target.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(Sla $sla, array $attributes, ?int $userId = null): SlaMeasurement
    {
        $actual = (float) $attributes['actual_value'];

        return DB::transaction(function () use ($sla, $attributes, $actual, $userId) {
            $measurement = SlaMeasurement::updateOrCreate(
                [
                    'sla_id' => $sla->getKey(),
                    'period_start' => $attributes['period_start'],
                    'period_end' => $attributes['period_end'],
                ],
                [
                    'organization_id' => $sla->organization_id,
                    'actual_value' => $actual,
                    'credit_claimed_minor' => $attributes['credit_claimed_minor'] ?? null,
                    'credit_received_minor' => $attributes['credit_received_minor'] ?? null,
                    'currency' => $attributes['currency'] ?? null,
                    'notes' => $attributes['notes'] ?? null,
                    'evidence_document_id' => $attributes['evidence_document_id'] ?? null,
                    'entered_by' => $userId,
                ]
            );

            // Not fillable: a form that could set these could record a
            // breaching month as compliant.
            $measurement->forceFill([
                'is_breach' => $sla->isBreach($actual),
                'breach_severity' => $sla->proposedSeverity($actual),
            ])->save();

            return $measurement->refresh();
        });
    }

    /**
     * Import a batch of measurements — the XLSX/CSV path.
     *
     * Rows are keyed by the SLA's `metric_code` rather than by id, because a
     * vendor's monthly report names its metrics and knows nothing about our
     * primary keys. An unmatched code is REPORTED, not dropped: a report whose
     * metric was renamed would otherwise import as a silent partial success.
     *
     * @param  list<array{metric_code: string, period_start: string, period_end: string, actual_value: float|string}>  $rows
     * @return array{recorded: int, breaches: int, unmatched: list<string>}
     */
    public function import(int $engagementId, array $rows, ?int $userId = null): array
    {
        $slas = Sla::query()
            ->where('engagement_id', $engagementId)
            ->active()
            ->get()
            ->keyBy('metric_code');

        $recorded = 0;
        $breaches = 0;
        $unmatched = [];

        foreach ($rows as $row) {
            $code = (string) ($row['metric_code'] ?? '');
            $sla = $slas->get($code);

            if ($sla === null) {
                $unmatched[] = $code;

                continue;
            }

            $measurement = $this->record($sla, [
                'period_start' => $row['period_start'],
                'period_end' => $row['period_end'],
                'actual_value' => $row['actual_value'],
            ], $userId);

            $recorded++;
            $breaches += $measurement->is_breach ? 1 : 0;
        }

        return [
            'recorded' => $recorded,
            'breaches' => $breaches,
            'unmatched' => array_values(array_unique(array_filter($unmatched))),
        ];
    }

    /**
     * How many periods in a row this SLA has breached, most recent first.
     *
     * Counted from consecutive measurements rather than from a stored column,
     * because a gap in reporting is not a compliant month — a provider that
     * stops reporting after two bad months would otherwise reset the streak by
     * saying nothing.
     */
    public function consecutiveBreaches(Sla $sla): int
    {
        $streak = 0;

        foreach ($this->history($sla) as $measurement) {
            if (! $measurement->is_breach) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /**
     * Measurements newest first — the trend the chart draws.
     *
     * @return Collection<int, SlaMeasurement>
     */
    public function history(Sla $sla, int $limit = 24): Collection
    {
        return $sla->measurements()
            ->orderByDesc('period_end')
            ->limit($limit)
            ->get();
    }

    /**
     * Periods where a measurement should exist and does not.
     *
     * A missing month is not a compliant month, and this is what makes that
     * visible: a provider whose reporting quietly stops is a provider whose
     * SLA nobody is enforcing, and it looks identical to a perfect record on
     * any dashboard that only draws the points it has.
     *
     * @return list<string>
     */
    public function missingPeriods(Sla $sla, int $lookbackMonths = 12): array
    {
        if ($sla->measurement_window !== 'monthly') {
            // Only the monthly case is computed. A quarterly or annual window
            // has too few points for an absence to be inferred safely, and a
            // wrong "missing period" on a register is worse than none.
            return [];
        }

        $have = $sla->measurements()
            ->orderByDesc('period_end')
            ->limit($lookbackMonths + 1)
            ->pluck('period_start')
            ->map(fn ($date) => Carbon::parse($date)->format('Y-m'))
            ->flip();

        $missing = [];
        $cursor = now()->startOfMonth()->subMonth();

        for ($i = 0; $i < $lookbackMonths; $i++) {
            if (! $have->has($cursor->format('Y-m'))) {
                $missing[] = $cursor->format('Y-m');
            }

            $cursor->subMonth();
        }

        return $missing;
    }

    /**
     * The service-credit register: what was earned, claimed and actually
     * received.
     *
     * The gap between claimed and received across a year is the number worth
     * putting in front of a relationship owner — it is the part of a penalty
     * regime that quietly stops working.
     *
     * @return array<string, mixed>
     */
    public function creditRegister(int $engagementId): array
    {
        $measurements = SlaMeasurement::query()
            ->whereIn('sla_id', Sla::query()->where('engagement_id', $engagementId)->select('id'))
            ->breaches()
            ->with('sla:id,metric_code,metric_name,unit,credit_formula')
            ->orderByDesc('period_end')
            ->get();

        $claimed = $measurements->sum(fn (SlaMeasurement $m) => $m->credit_claimed_minor ?? 0);
        $received = $measurements->sum(fn (SlaMeasurement $m) => $m->credit_received_minor ?? 0);

        return [
            'breaches' => $measurements->count(),
            // Breaches for which nothing was claimed at all — the commonest
            // and least visible failure in a penalty regime.
            'unclaimed' => $measurements->whereNull('credit_claimed_minor')->count(),
            'claimed_minor' => $claimed,
            'received_minor' => $received,
            'shortfall_minor' => max(0, $claimed - $received),
            'currency' => $measurements->first()?->currency,
            'rows' => $measurements->map(fn (SlaMeasurement $m) => [
                'id' => $m->getKey(),
                'metric' => $m->sla?->metric_name,
                'metric_code' => $m->sla?->metric_code,
                'period' => $m->period_start?->toDateString().' to '.$m->period_end?->toDateString(),
                'actual' => (float) $m->actual_value,
                'severity' => $m->breach_severity,
                'claimed_minor' => $m->credit_claimed_minor,
                'received_minor' => $m->credit_received_minor,
                'shortfall_minor' => $m->creditShortfallMinor(),
            ])->values()->all(),
        ];
    }
}
