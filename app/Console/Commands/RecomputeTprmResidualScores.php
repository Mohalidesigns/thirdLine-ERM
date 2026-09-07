<?php

namespace App\Console\Commands;

use App\Events\Tprm\EngagementScoreInvalidated;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\RiskAcceptance;
use App\Services\Tprm\Findings\RiskAcceptanceService;
use Illuminate\Console\Command;

/**
 * The nightly scoring sweep — FR-SCR and the phase's recomputation triggers.
 *
 * CHANGED ENGAGEMENTS ONLY, and the definition of "changed" is the whole
 * design. Recomputing every engagement nightly would write a score run per
 * vendor per day, which turns a table meant to record decisions into a table
 * recording the passage of time — and makes the run history useless for the
 * question it exists to answer, which is "what moved this score".
 *
 * Four things change a score without anybody touching the record, and they are
 * the four this sweep looks for:
 *
 *   A RISK ACCEPTANCE THAT LAPSED overnight. The finding reopens and returns
 *   to full weight. This is the reason the mandatory expiry is worth having.
 *   A FINDING THAT CROSSED ITS SLA, or crossed twice it — the ×1.5 multiplier
 *   in TRD §7.5 applies from a date, and nothing else marks the day it starts.
 *   EVIDENCE THAT EXPIRED, which is a signal in its own right.
 *   AN ASSESSMENT THAT WENT OVERDUE, likewise.
 *
 * An engagement that none of those touched keeps yesterday's score and yesterday's
 * run, which is correct: nothing about it changed.
 */
class RecomputeTprmResidualScores extends Command
{
    protected $signature = 'tprm:recompute-scores
        {--all : Recompute every active engagement rather than only those whose inputs changed}
        {--dry-run : Report what would be recomputed without writing}';

    protected $description = 'Reopen lapsed risk acceptances and recompute residual scores for engagements whose inputs changed';

    public function handle(RiskAcceptanceService $acceptances): int
    {
        if (! config('features.tprm')) {
            $this->comment('TPRM is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        // FIRST, because reopening a finding changes what the recomputation
        // below will see. Running these the other way round would score the
        // engagement, then reopen the finding, and leave the score a day
        // behind the register.
        $reopened = $dryRun
            ? RiskAcceptance::query()->lapsed()->count()
            : $acceptances->reopenLapsed()->count();

        $engagements = $this->option('all')
            ? $this->allActive()
            : $this->changed();

        if ($dryRun) {
            $this->info(sprintf(
                '[dry run] %d lapsed acceptance(s) would reopen and %d engagement(s) would be rescored.',
                $reopened,
                $engagements->count(),
            ));

            return self::SUCCESS;
        }

        $scored = 0;

        foreach ($engagements as $engagement) {
            // Through the event rather than the service directly, so the
            // nightly path and the in-request path go through exactly the same
            // listener — and a defect in one is a defect in both rather than
            // in whichever nobody exercises.
            EngagementScoreInvalidated::dispatch($engagement, EngagementScoreInvalidated::SCHEDULED);
            $scored++;
        }

        $this->info(sprintf(
            '%d lapsed acceptance(s) reopened, %d engagement(s) rescored.',
            $reopened,
            $scored,
        ));

        return self::SUCCESS;
    }

    /**
     * Engagements whose scoring inputs changed since the last run.
     *
     * @return \Illuminate\Support\Collection<int, Engagement>
     */
    private function changed()
    {
        $ids = collect();

        // A finding that crossed its target date, or twice its SLA, today.
        // Both edges matter and they are different days.
        $ids = $ids->merge(
            Finding::query()->withoutGlobalScopes()->open()
                ->whereNotNull('target_date')
                ->whereDate('target_date', now()->subDay()->toDateString())
                ->pluck('engagement_id')
        );

        $ids = $ids->merge(
            Finding::query()->withoutGlobalScopes()->open()
                ->whereNotNull('sla_days')
                ->whereNotNull('identified_at')
                ->get()
                ->filter(fn (Finding $finding) => $finding->isOverdueBeyond()
                    && $finding->identified_at->copy()->startOfDay()
                        ->addDays($finding->sla_days * 2)
                        ->isSameDay(now()->subDay()))
                ->pluck('engagement_id')
        );

        // Acceptances that lapsed — reopened above, and their engagements need
        // the score to say so.
        $ids = $ids->merge(
            RiskAcceptance::query()->withoutGlobalScopes()
                ->whereDate('expires_at', '>=', now()->subDays(2)->toDateString())
                ->whereDate('expires_at', '<', now()->toDateString())
                ->join('tp_findings', 'tp_findings.id', '=', 'tp_risk_acceptances.finding_id')
                ->pluck('tp_findings.engagement_id')
        );

        // Evidence that expired yesterday.
        $expired = Document::query()->withoutGlobalScopes()
            ->whereNotNull('valid_to')
            ->whereDate('valid_to', now()->subDay()->toDateString())
            ->where('is_superseded', false)
            ->where('owner_type', Document::OWNER_ENGAGEMENT)
            ->pluck('owner_id');

        $ids = $ids->merge($expired);

        // An assessment that went overdue yesterday.
        $ids = $ids->merge(
            Engagement::query()->withoutGlobalScopes()
                ->whereNotNull('next_assessment_due')
                ->whereDate('next_assessment_due', now()->subDay()->toDateString())
                ->pluck('id')
        );

        return Engagement::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $ids->filter()->unique()->values())
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Engagement>
     */
    private function allActive()
    {
        return Engagement::query()
            ->withoutGlobalScopes()
            ->whereNotIn('status', ['terminated', 'archived', 'draft'])
            ->get();
    }
}
