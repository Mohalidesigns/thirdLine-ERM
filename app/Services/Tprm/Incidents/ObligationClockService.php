<?php

namespace App\Services\Tprm\Incidents;

use App\Enums\Tprm\Regulator;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\Incident;
use App\Models\Tprm\TprmSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The regulatory clock engine — TRD §6.12, AC-07.
 *
 * TWO CLOCKS, INDEPENDENTLY ASSESSED, ON ONE INCIDENT. A vendor breach
 * touching customer personal data at a bank starts both: NDPC because personal
 * data was involved, CBN because it meets the cyber-incident definition. They
 * are not alternatives and neither replaces the other, which is why the
 * incident carries two deadline columns rather than one.
 *
 * THE CLOCK STARTS AT `reported_to_us_at`, NOT AT `detected_at`. NDPA §40(2)
 * gives the controller 72 hours from BECOMING AWARE, and we became aware when
 * the vendor told us — which is exactly why Phase 8 made that timestamp
 * write-once. A clock started from the vendor's own detection time would run
 * against a period during which the bank could not have acted, and would
 * often already be expired at the moment it was created.
 *
 * NOTHING HERE TRANSMITS ANYTHING. It assesses, it computes deadlines, it
 * escalates internally, and it assembles a draft. A named officer sends the
 * notification and records that they did; see `NotificationDraftService`.
 *
 * ASSESSMENT IS IDEMPOTENT AND NEVER RETRACTS A LIVE CLOCK. Re-running it on
 * an incident whose facts have been corrected can ADD a clock, and can revise
 * a deadline that has not been met — but a clock already reported against is
 * left alone. A module that could quietly decide an incident it had already
 * escalated was not reportable after all would be worse than one that never
 * assessed at all.
 */
class ObligationClockService
{
    /**
     * The CBN cyber-incident definition — Framework Appendix I.
     *
     * FOUR LIMBS, ANY ONE OF WHICH IS ENOUGH. Only the first needs the
     * materiality figure; a data breach, a core-banking outage or a website
     * defacement is reportable on its own terms, which is why an unset
     * shareholders'-funds figure leaves the assessment undetermined only when
     * NO other limb is met.
     */
    public const CBN_LIMBS = [
        'material_financial_loss' => 'Financial loss exceeding 0.01% of shareholders\' funds',
        'data_breach' => 'A breach of customer or institutional data',
        'core_banking_outage' => 'An outage of the core banking application or channel',
        'website_defacement' => 'Defacement of the institution\'s website',
    ];

    /**
     * Assess both clocks and write the outcome onto the incident.
     *
     * @return array{ndpc: ClockAssessment, cbn: ClockAssessment}
     */
    public function assess(Incident $incident): array
    {
        $assessments = [
            'ndpc' => $this->assessNdpc($incident),
            'cbn' => $this->assessCbn($incident),
        ];

        DB::transaction(function () use ($incident, $assessments): void {
            foreach ($assessments as $assessment) {
                $this->apply($incident, $assessment);
            }
        });

        return $assessments;
    }

    /**
     * NDPA §40 — 72 hours from becoming aware of a personal-data breach.
     *
     * ONE LIMB AND NO MATERIALITY TEST. The Act does not scale the obligation
     * to the size of the breach; a single record is a breach. §40(2) allows a
     * later notification with reasons, which is a defence for being late
     * rather than a reason not to start the clock.
     */
    public function assessNdpc(Incident $incident): ClockAssessment
    {
        if (! $incident->personal_data_involved) {
            return ClockAssessment::notReportable(
                Regulator::Ndpc,
                'No personal data is recorded as involved, so NDPA §40 is not engaged.',
            );
        }

        $start = $this->clockStart($incident);

        if ($start === null) {
            return ClockAssessment::undetermined(
                Regulator::Ndpc,
                'The incident has no recorded time of notification to us, so the 72 hours cannot be counted '
                .'from anywhere.',
            );
        }

        return ClockAssessment::reportable(
            Regulator::Ndpc,
            ['personal_data_breach'],
            sprintf(
                'Personal data is involved, so NDPA §40(2) requires notification to the NDPC within %d hours '
                .'of becoming aware. We became aware at %s, when the processor notified us (§40(1)).',
                Regulator::Ndpc->hours(),
                $start->toDayDateTimeString(),
            ),
            $start->copy()->addHours(Regulator::Ndpc->hours()),
        );
    }

    /**
     * CBN Cyber Framework Appendix I — 24 hours.
     */
    public function assessCbn(Incident $incident): ClockAssessment
    {
        $setting = TprmSetting::forOrganization((int) $incident->organization_id);

        $triggers = [];

        if ($incident->personal_data_involved || $incident->type === 'data_breach') {
            $triggers[] = 'data_breach';
        }

        if ($incident->type === 'outage' && $incident->customer_impact) {
            $triggers[] = 'core_banking_outage';
        }

        if ($incident->type === 'defacement') {
            $triggers[] = 'website_defacement';
        }

        $materiality = $this->materialityLimb($incident, $setting);

        if ($materiality === true) {
            $triggers[] = 'material_financial_loss';
        }

        $start = $this->clockStart($incident);

        if ($triggers !== [] && $start !== null) {
            return ClockAssessment::reportable(
                Regulator::Cbn,
                $triggers,
                sprintf(
                    'Meets the CBN cyber-incident definition (%s), so Appendix I requires a report to the '
                    .'Director, Banking Supervision within %d hours of becoming aware, at %s.',
                    implode('; ', array_map(fn (string $limb): string => self::CBN_LIMBS[$limb], $triggers)),
                    Regulator::Cbn->hours(),
                    $start->toDayDateTimeString(),
                ),
                $start->copy()->addHours(Regulator::Cbn->hours()),
            );
        }

        /*
         * UNDETERMINED ONLY WHERE MATERIALITY WAS THE LAST LIMB LEFT. If a
         * data breach or an outage already triggered it, the missing figure
         * changes nothing; if nothing else triggered it and the loss cannot be
         * tested, a person has to decide and must be told so.
         */
        if ($materiality === null) {
            return ClockAssessment::undetermined(
                Regulator::Cbn,
                'A loss of '.$this->money($incident).' is recorded, but the 0.01% materiality test cannot be '
                ."run: this organisation's shareholders' funds have not been set. Record them in the TPRM "
                .'settings, or decide reportability by hand — the twenty-four hours run either way.',
            );
        }

        if ($start === null) {
            return ClockAssessment::undetermined(
                Regulator::Cbn,
                'The incident has no recorded time of notification to us, so the twenty-four hours cannot be '
                .'counted from anywhere.',
            );
        }

        return ClockAssessment::notReportable(
            Regulator::Cbn,
            'None of the four limbs of the CBN cyber-incident definition is met on the facts recorded.',
        );
    }

    /**
     * The materiality limb: true, false, or null for uncomputable.
     */
    private function materialityLimb(Incident $incident, TprmSetting $setting): ?bool
    {
        if ($incident->estimated_loss_minor === null) {
            // No loss recorded is not the same as no loss, but it is not a
            // basis for reporting either, and the other limbs still apply.
            return false;
        }

        $threshold = $setting->cbnMaterialityThresholdMinor();

        if ($threshold === null) {
            return null;
        }

        return (int) $incident->estimated_loss_minor > $threshold;
    }

    /**
     * Write an assessment onto the incident.
     *
     * A CLOCK ALREADY REPORTED AGAINST IS NEVER RETRACTED OR MOVED. See the
     * class comment: a module that could decide an incident it had already
     * escalated was not reportable after all is worse than one that never
     * assessed.
     */
    private function apply(Incident $incident, ClockAssessment $assessment): void
    {
        $regulator = $assessment->regulator;

        if ($incident->{$regulator->reportedColumn()} !== null) {
            return;
        }

        $incident->forceFill([
            $regulator->reportableColumn() => $assessment->reportable,
            $regulator->deadlineColumn() => $assessment->deadlineAt,
        ])->save();

        $this->audit($incident, 'incident_clock_assessed', $assessment->toArray());
    }

    /* ------------------------------------------------------------------ */
    /*  Countdown */
    /* ------------------------------------------------------------------ */

    /**
     * The live state of one clock.
     *
     * `elapsed_pct` IS CLAMPED AT 100 AND `hours_remaining` GOES NEGATIVE. A
     * bar that stops at full and a number that keeps counting past zero say
     * different, both-true things: the window is gone, and it went ninety
     * hours ago.
     *
     * @return array<string, mixed>|null
     */
    public function countdown(Incident $incident, Regulator $regulator, ?Carbon $asOf = null): ?array
    {
        $deadline = $incident->{$regulator->deadlineColumn()};

        if (! $incident->{$regulator->reportableColumn()} || $deadline === null) {
            return null;
        }

        $asOf ??= Carbon::now();
        $start = $this->clockStart($incident);

        if ($start === null) {
            return null;
        }

        $total = max(1, $start->diffInSeconds($deadline, true));
        $elapsed = $start->diffInSeconds($asOf, false);

        $reportedAt = $incident->{$regulator->reportedColumn()};

        return [
            'regulator' => $regulator->value,
            'label' => $regulator->shortLabel(),
            'citation' => $regulator->citation(),
            'started_at' => $start->toIso8601String(),
            'deadline_at' => $deadline->toIso8601String(),
            'reported_at' => $reportedAt?->toIso8601String(),
            'elapsed_pct' => min(100.0, round(($elapsed / $total) * 100, 1)),
            'hours_remaining' => round(($deadline->getTimestamp() - $asOf->getTimestamp()) / 3600, 1),
            'breached' => $reportedAt === null && $asOf->isAfter($deadline),
            // Met LATE is still met, and the register must not report it as a
            // live breach once the notification has gone.
            'met_late' => $reportedAt !== null && $reportedAt->isAfter($deadline),
            'state' => match (true) {
                $reportedAt !== null => 'reported',
                $asOf->isAfter($deadline) => 'breached',
                ($elapsed / $total) >= 0.8 => 'critical',
                ($elapsed / $total) >= 0.5 => 'warning',
                default => 'running',
            },
        ];
    }

    /**
     * Every live clock on an incident.
     *
     * @return list<array<string, mixed>>
     */
    public function countdowns(Incident $incident, ?Carbon $asOf = null): array
    {
        return array_values(array_filter([
            $this->countdown($incident, Regulator::Ndpc, $asOf),
            $this->countdown($incident, Regulator::Cbn, $asOf),
        ]));
    }

    /**
     * When the clock starts.
     *
     * `reported_to_us_at` first, because that is when we became aware and it
     * is write-once. `detected_at` is the VENDOR's awareness and is not our
     * clock; it is used only where the incident was recorded internally and
     * nobody told us, in which case our own detection is our awareness.
     */
    public function clockStart(Incident $incident): ?Carbon
    {
        $start = $incident->reported_to_us_at ?? $incident->detected_at;

        // Normalised to Illuminate's Carbon rather than the base library's,
        // which is what the casts return and what every caller expects.
        return $start === null ? null : Carbon::instance($start);
    }

    private function money(Incident $incident): string
    {
        return sprintf(
            '%s %s',
            $incident->currency ?? '',
            number_format(((int) $incident->estimated_loss_minor) / 100, 2),
        );
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(Incident $incident, string $event, array $after): void
    {
        AuditLog::create([
            'organization_id' => $incident->organization_id,
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->getKey(),
            'event' => $event,
            'actor_type' => 'system',
            'actor_id' => null,
            'before' => null,
            'after' => $after,
        ]);
    }
}
