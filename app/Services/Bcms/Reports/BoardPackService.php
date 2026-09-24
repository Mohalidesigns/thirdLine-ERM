<?php

namespace App\Services\Bcms\Reports;

use App\Models\Bcms\AuditLog;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Incident;
use App\Models\Bcms\IncidentNotification;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\MaturityAssessment;
use App\Models\Bcms\Plan;
use App\Models\User;
use App\Services\Bcms\Incidents\NotificationService;
use App\Services\Bcms\ResilienceKriPublisher;
use App\Services\Bcms\Strategy\GapAnalysisService;
use ThirdLine\Platform\Tenancy\TenantContext;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * The resilience board pack — `docs/bcms/screens/board-pack-preview.md`.
 *
 * NO `bcms_board_packs` TABLE (ADR 0021 §4, confirmed against `tp_board_packs`
 * being TPRM's own). This pack has no draft/review/sign-off lifecycle and no
 * persisted, editable narrative — it is generated on demand from live,
 * terminal data and logged as a `pack.exported` audit event exactly like the
 * regulatory evidence pack.
 *
 * EVERY SECTION REUSES AN EXISTING SOURCE. Nothing here is a second scorer, a
 * second KRI computation or a second findings register (§7 of the spec) —
 * this class assembles, it does not recompute.
 */
class BoardPackService
{
    public function __construct(
        private readonly GapAnalysisService $gaps,
        private readonly ResilienceKriPublisher $kris,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * REPRODUCIBILITY (phase-11-spec §6 criterion 2): calling this twice for
     * the same year, with no write in between, returns an identical array —
     * this IS "section content". Every "latest row" / "order by a single
     * non-unique column" read this composes carries a primary-key
     * tiebreaker for the same reason `ClauseComplianceMatrixService` does —
     * see its own docblock note.
     *
     * A2 (gate 1 code review #2): `as_at` — which by definition changes on
     * every call — used to be stamped INSIDE this array, which is exactly
     * the content this docblock claims is identical across two calls with
     * nothing written between them; two calls close enough together could
     * happen to agree and mask the very drift this reproducibility
     * guarantee exists to catch. `as_at` is stamped OUTSIDE this method now
     * — see `stampedPreview()`, `generatePdf()` and `generatePptx()`, which
     * add it (as `generatedAt` for the two generators) AFTER calling this.
     *
     * @return array<string, mixed>
     */
    public function preview(int $year): array
    {
        return [
            'year' => $year,
            // B2 (gate 1 code review #1, live-browser follow-up): four
            // sections below (`plan_currency`, `top_rto_gaps`,
            // `open_nonconformities`, `kris`) are CURRENT-STATE, not
            // `$year`-scoped — there is no point-in-time snapshot capability
            // for "which plans were current as of a past year" in this
            // schema. `as_at` (stamped by the caller, not here) is the one
            // instant all four were actually computed at, so the page, the
            // PDF and the PPTX can say so honestly instead of implying every
            // section was computed FOR `$year`.
            'posture_summary' => $this->postureSummary($year),
            'maturity_trend' => $this->maturityTrend($year),
            'exercise_completion' => $this->exerciseCompletion($year),
            'plan_currency' => $this->planCurrency(),
            'top_rto_gaps' => array_slice($this->gaps->analyse(null, 1)['rows'], 0, 5),
            'open_nonconformities' => $this->openNonconformities(),
            'incident_summary' => $this->incidentSummary($year),
            'kris' => $this->kris->status(),
            'management_review' => $this->latestApprovedReview($year),
        ];
    }

    /**
     * A2 (gate 1 code review #2): `preview()` plus `as_at`, for every caller
     * that renders this to a person rather than compares its content —
     * the on-screen preview (`BoardPackController::index()`). `generatePdf()`
     * and `generatePptx()` stamp their own `generatedAt` instead, right
     * beside their other layout-only variables, for the same reason.
     *
     * @return array<string, mixed>
     */
    public function stampedPreview(int $year): array
    {
        return $this->preview($year) + ['as_at' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function postureSummary(int $year): array
    {
        $maturity = MaturityAssessment::query()->latest('assessed_at')->orderByDesc('id')->first();
        $previous = $maturity === null ? null : MaturityAssessment::query()
            ->where('assessed_at', '<', $maturity->assessed_at)->latest('assessed_at')->orderByDesc('id')->first();

        $completion = $this->exerciseCompletion($year);
        $openNonconformities = $this->openNonconformities();

        $sentences = [];

        if ($maturity !== null && $maturity->overall_score !== null) {
            $direction = 'unchanged';

            if ($previous?->overall_score !== null) {
                $direction = $maturity->overall_score > $previous->overall_score ? 'up' : ($maturity->overall_score < $previous->overall_score ? 'down' : 'unchanged');
            }

            $sentences[] = "Maturity is {$maturity->overall_score}/5, {$direction} from the last assessment.";
        }

        if ($completion['planned'] !== null && $completion['planned'] > 0) {
            $sentences[] = "{$completion['completed']} of {$completion['planned']} exercises this year completed on schedule.";
        }

        $sentences[] = $this->pluralSentence(count($openNonconformities), 'nonconformity remains open.', 'nonconformities remain open.');

        return ['sentences' => $sentences];
    }

    /**
     * Live-browser follow-up to B2: "1 nonconformity(ies) remain open" is
     * grammatically wrong for the single-digit case a bank reads most
     * often. `$singular`/`$plural` are the tail of the sentence AFTER the
     * count (e.g. "nonconformity remains open." / "nonconformities remain
     * open.").
     */
    private function pluralSentence(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    /** "1 hour" / "3 hours" / "no shortfall recorded" (no unit when nothing is recorded). */
    private function hoursPhrase(int|float|null $hours): string
    {
        if ($hours === null) {
            return 'no shortfall recorded';
        }

        return 'shortfall '.$hours.' '.((float) $hours === 1.0 ? 'hour' : 'hours');
    }

    /**
     * Live-browser follow-up to B2: the page's own subtitle claimed
     * "nothing on this page is recomputed by opening it", which is false —
     * plan currency, top RTO gaps, open nonconformities and the KRI table
     * are current-state reads with no `$year` scope and no stored snapshot.
     * The screen, the PDF and the PPTX all carry this same sentence.
     */
    private function honestSubtitle(): string
    {
        return 'Maturity trend, exercise completion, the incident summary and the management review are the '
            .'year\'s own stored records. Plan currency, top RTO gaps, open nonconformities and the resilience '
            .'KRI table are live figures, as at the moment this page or export was generated.';
    }

    /** @return list<array{assessed_at: ?string, overall_score: ?float}> */
    private function maturityTrend(int $year): array
    {
        return MaturityAssessment::query()
            ->whereYear('assessed_at', $year)
            ->orderBy('assessed_at')
            ->orderBy('id')
            ->get(['assessed_at', 'overall_score', 'id'])
            ->map(fn (MaturityAssessment $a) => [
                'assessed_at' => $a->assessed_at?->toDateString(),
                'overall_score' => $a->overall_score === null ? null : (float) $a->overall_score,
            ])
            ->all();
    }

    /** @return array{planned: ?int, completed: ?int} */
    private function exerciseCompletion(int $year): array
    {
        // No unique constraint on (organization_id, year) — two approved
        // programmes for the same year is possible (one superseding the
        // other mid-year), so "the current programme" means the one most
        // recently approved, tiebroken on id for a same-instant approval —
        // matching `ClauseComplianceMatrixService::exerciseProgrammeSufficiency()`'s
        // own rule so the board pack and the compliance matrix cannot pick
        // two different programmes for the same year.
        $programme = ExerciseProgramme::query()->where('year', $year)->where('status', 'approved')
            ->orderByDesc('approved_at')->orderByDesc('id')->first();

        if ($programme === null) {
            return ['planned' => null, 'completed' => null];
        }

        $completed = ExerciseOccurrence::query()
            ->whereYear('scheduled_date', $year)
            ->where('status', 'completed')
            ->count();

        return ['planned' => (int) $programme->total_planned, 'completed' => $completed];
    }

    private function planCurrency(): ?float
    {
        $total = Plan::query()->where('status', 'approved')->count();

        if ($total === 0) {
            return null;
        }

        $current = Plan::query()->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('next_review_date')->orWhere('next_review_date', '>=', now()->toDateString()))
            ->count();

        return round($current / $total * 100, 1);
    }

    /** @return list<array<string, mixed>> */
    private function openNonconformities(): array
    {
        return Finding::query()
            ->where('classification', 'nonconformity')
            ->where('status', '!=', 'closed')
            ->with('correctiveActions')
            ->orderBy('raised_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Finding $f) => [
                'reference' => $f->reference,
                'description' => $f->description,
                'iso_clause_ref' => $f->iso_clause_ref,
                'raised_at' => $f->raised_at?->toDateString(),
                'corrective_action_count' => $f->correctiveActions->count(),
            ])
            ->all();
    }

    /**
     * Found by Phase 10's code review (same family as B17, gate 1 code
     * review #1): `is_exercise = false` on the read, not only on the write
     * — a drill's incident must never inflate the board's own incident
     * summary. Latent until something writes an exercise incident, which
     * nothing does yet, but ADR 0020 §4 requires the filter "in every
     * aggregate" regardless of what is populated today.
     *
     * @return array<string, mixed>
     */
    private function incidentSummary(int $year): array
    {
        $incidents = Incident::query()->where('is_exercise', false)->whereYear('detected_at', $year)->get();

        // ADR 0020 §2: `reporting_due_at`/`regulator_notified_at`/
        // `cbn_reference` are retired in place and never read here.
        // `bcms_incident_notifications` is where notification timeliness
        // lives now; `notified_within_due`/`notified_late_or_missing`
        // mirror `ProgrammeService::incidentReportingOutcomes()` exactly, so
        // the board pack and the management-review snapshot never disagree
        // about the same set of obligations.
        $reportableIds = $incidents->where('is_reportable', true)->pluck('id');

        $notifiedWithinDue = IncidentNotification::query()
            ->whereIn('incident_id', $reportableIds)
            ->whereNotNull('submitted_at')
            ->whereNotNull('due_at')
            ->whereColumn('submitted_at', '<=', 'due_at')
            ->count();

        $missing = $reportableIds->isEmpty() ? 0 : $this->notifications->overdueQuery()
            ->whereIn('incident_id', $reportableIds)
            ->count();

        $late = IncidentNotification::query()
            ->whereIn('incident_id', $reportableIds)
            ->whereNotNull('submitted_at')
            ->whereNotNull('due_at')
            ->whereColumn('submitted_at', '>', 'due_at')
            ->count();

        return [
            'count' => $incidents->count(),
            'by_severity' => $incidents->whereNotNull('severity')->countBy('severity')->all(),
            'notified_within_due' => $notifiedWithinDue,
            'notified_late_or_missing' => $missing + $late,
        ];
    }

    /** @return array<string, mixed>|null */
    private function latestApprovedReview(int $year): ?array
    {
        $review = ManagementReview::query()
            ->where('status', 'approved')
            ->whereYear('held_on', $year)
            ->latest('held_on')
            ->orderByDesc('id')
            ->first();

        if ($review === null) {
            return null;
        }

        return [
            'id' => $review->getKey(),
            'uuid' => $review->uuid,
            'title' => $review->title,
            'held_on' => $review->held_on?->toDateString(),
            'approved_by' => $review->approver?->name,
        ];
    }

    public function generatePdf(int $year, User $actor): string
    {
        $renderer = app(DocumentRenderer::class);
        $organization = \App\Models\Organization::query()->find(TenantContext::organizationId());

        $data = $this->preview($year);
        $data['organization'] = $organization;

        // The master layout (`reports.pdf.layout`) requires `title`,
        // `branding` and `generatedAt` unconditionally — never supplied
        // here before, so every PDF export 500'd (never exercised end to
        // end; phase-11-notes.md flags this generator "verify at
        // integration"). `preview()` carries no key named `sections`, so no
        // collision with the layout's own table-of-contents variable.
        $data['title'] = 'Resilience board pack — '.$year;
        // Live-browser follow-up to B2: "assembled from stored, approved
        // records — nothing recomputed by opening it" was false for four
        // sections (plan currency, top RTO gaps, open nonconformities, the
        // KRI table) — genuinely live figures, honestly labelled instead.
        $data['subtitle'] = $this->honestSubtitle();
        $data['branding'] = $renderer->branding($organization);
        $data['generatedAt'] = now();
        $data['generatedBy'] = $actor->name;

        $bytes = $renderer->pdf('reports.pdf.bcms-board-pack', $data);

        $this->logExport($year, 'pdf', $actor);

        return $bytes;
    }

    public function generatePptx(int $year, User $actor): string
    {
        $data = $this->preview($year);

        $slides = [
            // No dedicated title/cover slide exists to carry a subtitle, so
            // the same honesty line the PDF's subtitle and the screen's
            // header carry is prepended to the first content slide's own
            // lines instead (live-browser follow-up to B2).
            ['title' => 'Resilience posture — '.$year, 'lines' => array_merge([$this->honestSubtitle()], $data['posture_summary']['sentences'])],
            ['title' => 'Maturity trend', 'lines' => array_map(
                fn (array $m) => ($m['assessed_at'] ?? '—').': '.($m['overall_score'] ?? '—'),
                $data['maturity_trend']
            ) ?: ['No maturity assessment has been run in '.$year.'.']],
            ['title' => 'Exercise programme completion', 'lines' => [
                $data['exercise_completion']['planned'] === null
                    ? 'No approved exercise programme for '.$year.'.'
                    : $data['exercise_completion']['completed'].' of '.$data['exercise_completion']['planned'].' planned exercises completed.',
            ]],
            ['title' => 'Plan currency', 'lines' => [
                $data['plan_currency'] === null ? 'No plans on record, so currency is undefined.' : $data['plan_currency'].'% of approved plans are inside their review cycle.',
            ]],
            ['title' => 'Top RTO gaps', 'lines' => array_map(
                fn (array $r) => $r['name'].': '.$this->hoursPhrase($r['shortfall_hours'] ?? null),
                $data['top_rto_gaps']
            ) ?: ['No process currently has a strategy shortfall against its required RTO.']],
            ['title' => 'Open nonconformities', 'lines' => array_map(
                fn (array $n) => $n['reference'].' — '.$n['description'],
                $data['open_nonconformities']
            ) ?: ['No nonconformity is currently open.']],
            ['title' => 'Incident summary — '.$year, 'lines' => [
                $data['incident_summary']['count'].' incident(s) declared in '.$year.'.',
                // A8 (gate 1 code review #1): the incident count alone said
                // nothing about notification TIMELINESS, which the board
                // pack's own `incident_summary` already computes
                // (`notified_within_due`/`notified_late_or_missing`) — the
                // PDF and the JSON payload already carry it; the PPTX slide
                // was silently missing it.
                $data['incident_summary']['notified_within_due'].' notified within their regulatory due window; '
                    .$data['incident_summary']['notified_late_or_missing'].' late or missing.',
            ]],
            ['title' => 'Resilience KRI dashboard', 'lines' => array_map(
                // A8: `current_value ?? 'not linked'` conflated two
                // different states — a KRI that IS linked (adopted) but has
                // no measurement yet ALSO has `current_value === null`, and
                // was wrongly printed as "not linked". `linked` is the
                // field that actually answers "not linked"; matches the PDF
                // blade's own already-correct three-state logic.
                fn (array $k) => $k['name'].': '.($k['linked'] ? ($k['current_value'] ?? 'not yet measured') : 'not linked').' '.$k['unit'],
                $data['kris']
            )],
            ['title' => 'Management review', 'lines' => [
                $data['management_review'] === null
                    ? 'No management review has been approved for '.$year.'.'
                    : $data['management_review']['title'].', held '.$data['management_review']['held_on'].', approved by '.$data['management_review']['approved_by'].'.',
            ]],
        ];

        $bytes = app(BoardPackPptxWriter::class)->write('Resilience board pack — '.$year, $slides);

        $this->logExport($year, 'pptx', $actor);

        return $bytes;
    }

    /**
     * A2 (gate 1 code review #1): the org node in `after`, and a failed
     * write counted where `BcmsWatchdog` already looks — the board pack
     * prints every open nonconformity's FULL description (which can name
     * staff or customers; the compliance ruling on this pack keeps that, it
     * is the board's business), so this export log is the one place that
     * matters if the write itself silently fails.
     */
    private function logExport(int $year, string $format, User $actor): void
    {
        $organizationId = TenantContext::organizationId();

        try {
            AuditLog::query()->create([
                'organization_id' => $organizationId,
                'auditable_type' => 'bcms_report_pack',
                'auditable_id' => (int) $organizationId,
                'event' => 'pack.exported',
                'after' => [
                    'framework' => 'board_pack', 'period' => ['year' => $year], 'format' => $format,
                    'org_node' => [
                        'organization_id' => $organizationId,
                        'organization_name' => \App\Models\Organization::query()->find($organizationId)?->name,
                    ],
                ],
                'actor_id' => $actor->getKey(),
                'actor_label' => $actor->name,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('BCMS board pack export could not be logged', [
                'year' => $year, 'exception' => get_class($e),
            ]);

            try {
                \Illuminate\Support\Facades\Cache::add(AuditLog::AUDIT_FAILURE_CACHE_KEY, 0, now()->addDays(30));
                \Illuminate\Support\Facades\Cache::increment(AuditLog::AUDIT_FAILURE_CACHE_KEY);
            } catch (\Throwable) {
                // Nothing further to do: the Log::error above is the fallback.
            }
        }
    }
}
