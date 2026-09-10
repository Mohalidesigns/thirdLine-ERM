<?php

namespace App\Services\Bcms\Bia;

use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Process;
use App\Services\Bcms\BcmsSettings;

/**
 * The recovery-objective rules, and which of them BLOCK rather than warn.
 *
 * THE DISTINCTION IS THE WHOLE DESIGN. A rule that blocks says "this cannot be
 * true"; a rule that warns says "this is true and somebody senior should know".
 * Getting it the wrong way round in either direction is worse than having no
 * rule: block too much and assessors record fiction to get past the form, warn
 * too much and the warnings stop being read.
 *
 *   BLOCKING — arithmetic and policy the tenant has set:
 *     · RTO > MTPD. Recovering after the maximum tolerable period has passed is
 *       not a recovery objective, it is a contradiction.
 *     · A critical service above the tenant's own configured RTO ceiling. They
 *       set the ceiling; the system holds them to it.
 *     · A child process with an RTO longer than its parent's. The parent cannot
 *       resume while a step inside it is still down, so the parent's stated RTO
 *       would be untrue.
 *
 *   WARNING — a regulator's threshold, an implausibility, a gap:
 *     · An open-banking process with an RTO over 30 minutes. The CBN
 *       Operational Guidelines set that as the failover threshold. We warn and
 *       CITE, rather than block, because whether those guidelines bind this
 *       institution depends on its licence — and a system that refused to record
 *       a bank's actual RTO would be a system that stopped holding the truth.
 *     · A workaround shorter than the RTO it is supposed to cover.
 *     · An RTO the assessor has set well below the derived MTPD without saying
 *       why: not wrong, but usually a misunderstanding of which number is which.
 *
 * NOTHING HERE IS SOFTENED BY THE AI PATH. A drafted assessment passes the same
 * rules a typed one does (ADR 0010).
 */
class BiaValidator
{
    /** CBN Operational Guidelines for Open Banking — the failover threshold. */
    public const CBN_FAILOVER_THRESHOLD_MINUTES = 30;

    /** The regulatory flags on a process that pull in the 30-minute threshold. */
    public const OPEN_BANKING_FLAGS = ['open_banking', 'openbanking', 'api_provider', 'api_consumer'];

    public function __construct(private BcmsSettings $settings) {}

    /**
     * @return array{blocking: list<array{field: string, message: string, citation: ?string}>, warnings: list<array{field: string, message: string, citation: ?string}>}
     */
    public function check(BiaAssessment $assessment, ?Process $process = null): array
    {
        $process ??= $assessment->process;

        $blocking = [];
        $warnings = [];

        $mtpd = $this->hours($assessment->mtpd_hours);
        $rto = $this->hours($assessment->rto_hours);

        /* -------------------------------------------------------------- */
        /*  Blocking */
        /* -------------------------------------------------------------- */

        if ($mtpd !== null && $rto !== null && $rto > $mtpd) {
            $blocking[] = $this->issue(
                'rto_hours',
                sprintf(
                    'The recovery time objective (%s) is longer than the maximum tolerable period of disruption (%s). '
                    .'Recovering after the point the disruption becomes intolerable is not an objective.',
                    $this->duration($rto), $this->duration($mtpd)
                ),
                IsoClauseRef::Iso22301_8_2_2,
            );
        }

        $ceiling = $this->hours($this->settings->for($assessment->organization_id)->critical_service_rto_ceiling_hours);

        if ($process?->is_critical_service && $ceiling !== null && $rto !== null && $rto > $ceiling) {
            $blocking[] = $this->issue(
                'rto_hours',
                sprintf(
                    '%s is designated a critical service, and this organisation has set a ceiling of %s on a '
                    .'critical service\'s recovery time. %s is above it.',
                    $process->name, $this->duration($ceiling), $this->duration($rto)
                ),
                IsoClauseRef::Bofia_continuity,
            );
        }

        foreach ($this->parentBreaches($assessment, $process, $rto) as $breach) {
            $blocking[] = $breach;
        }

        /* -------------------------------------------------------------- */
        /*  Warnings */
        /* -------------------------------------------------------------- */

        if ($rto !== null && $this->isOpenBanking($process) && $rto * 60 > self::CBN_FAILOVER_THRESHOLD_MINUTES) {
            $warnings[] = $this->issue(
                'rto_hours',
                sprintf(
                    'This process is flagged for open banking, and the CBN sets the failover and fail-back threshold '
                    .'at %d minutes of downtime. A recovery time of %s is outside it. Record it if it is the truth — '
                    .'and raise it, because the gap is the finding.',
                    self::CBN_FAILOVER_THRESHOLD_MINUTES, $this->duration($rto)
                ),
                IsoClauseRef::Cbn_ob_threshold,
                'CBN Operational Guidelines for Open Banking in Nigeria',
            );
        }

        $workaround = $this->hours($assessment->workaround_max_duration_hours);

        if ($assessment->workaround_available && $workaround !== null && $rto !== null && $workaround < $rto) {
            $warnings[] = $this->issue(
                'workaround_max_duration_hours',
                sprintf(
                    'The workaround lasts %s but recovery is not expected for %s. There is a gap of %s during which '
                    .'neither the process nor its workaround is running.',
                    $this->duration($workaround), $this->duration($rto), $this->duration($rto - $workaround)
                ),
                IsoClauseRef::Iso22301_8_3,
            );
        }

        $derived = $this->hours($assessment->derived_mtpd_hours);

        if ($derived !== null && $mtpd !== null && abs($derived - $mtpd) > 0.01) {
            $warnings[] = $this->issue(
                'mtpd_hours',
                sprintf(
                    'The impact grid puts the point of intolerable impact at %s; this assessment records %s. That is '
                    .'a legitimate judgement — the grid proposes and the assessor decides — but the difference should '
                    .'be explained in the review.',
                    $this->duration($derived), $this->duration($mtpd)
                ),
                IsoClauseRef::Iso22317_bia_method,
            );
        }

        if ($assessment->min_staff_required !== null && (int) $assessment->min_staff_required === 0) {
            $warnings[] = $this->issue(
                'min_staff_required',
                'A minimum staffing of zero says the process runs with nobody. If it is fully automated, say so in '
                .'the MBCO; if it is not, this is the number a pandemic plan is built on.',
                IsoClauseRef::Iso22301_8_2_2,
            );
        }

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }

    /** May this assessment be submitted? */
    public function canSubmit(BiaAssessment $assessment, ?Process $process = null): bool
    {
        return $this->check($assessment, $process)['blocking'] === [];
    }

    /* ------------------------------------------------------------------ */

    /**
     * A child process cannot take longer to recover than its parent.
     *
     * Checked against the parent's APPROVED assessment only. A parent whose own
     * BIA is still a draft has no stated RTO to breach, and blocking a child on
     * a number that may change tomorrow would stall a campaign for a reason the
     * assessor cannot fix.
     *
     * @return list<array{field: string, message: string, citation: ?string}>
     */
    private function parentBreaches(BiaAssessment $assessment, ?Process $process, ?float $rto): array
    {
        if ($process?->parent_process_id === null || $rto === null) {
            return [];
        }

        $parentRto = BiaAssessment::query()
            ->where('process_id', $process->parent_process_id)
            ->where('status', 'approved')
            ->orderByDesc('approved_at')
            ->value('rto_hours');

        if ($parentRto === null || $rto <= (float) $parentRto) {
            return [];
        }

        return [$this->issue(
            'rto_hours',
            sprintf(
                'The parent process recovers in %s and this one claims %s. The parent cannot resume while a step '
                .'inside it is still down, so one of the two numbers is wrong.',
                $this->duration((float) $parentRto), $this->duration($rto)
            ),
            IsoClauseRef::Iso22301_8_2_2,
        )];
    }

    private function isOpenBanking(?Process $process): bool
    {
        $flags = array_map(
            fn ($f) => strtolower(str_replace([' ', '-'], '_', (string) $f)),
            $process === null ? [] : (array) ($process->regulatory_flags ?? [])
        );

        return array_intersect($flags, self::OPEN_BANKING_FLAGS) !== [];
    }

    /** @return array{field: string, message: string, citation: ?string} */
    private function issue(string $field, string $message, IsoClauseRef $clause, ?string $citation = null): array
    {
        return [
            'field' => $field,
            'message' => $message,
            'clause_ref' => $clause->value,
            'citation' => $citation,
        ];
    }

    private function hours(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    /** Hours as something a human reads: "30 minutes", "4 hours", "2 days". */
    private function duration(float $hours): string
    {
        if ($hours < 1) {
            return round($hours * 60).' minutes';
        }

        if ($hours < 48) {
            return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.').($hours == 1.0 ? ' hour' : ' hours');
        }

        return rtrim(rtrim(number_format($hours / 24, 1, '.', ''), '0'), '.').' days';
    }
}
