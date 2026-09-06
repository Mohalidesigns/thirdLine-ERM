<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Control;
use App\Models\GeneratedReport;
use App\Models\IcaapAssessment;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\Organization;
use App\Models\RegulatoryDeadline;
use App\Models\Risk;
use App\Models\TreatmentPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * Assembles a board pack: one PDF built from an ordered set of sections.
 *
 * Sections are configurable per organization. The order and the enabled set
 * live in organizations.settings->board_pack.sections, so a bank whose board
 * wants the appetite position before the top risks can have that without a code
 * change — the "configure, don't code" principle the wider remodelling is
 * built around.
 *
 * Every section returns real data or an explicit emptiness. A section with
 * nothing behind it renders "no records for this period" rather than being
 * silently dropped: a board should be able to tell the difference between
 * "there were no loss events" and "nobody put the loss events in the pack".
 */
class BoardPackAssembler
{
    /**
     * The full catalogue, in the order a pack uses unless an organization says
     * otherwise. Cover and contents are produced by the layout itself and are
     * therefore not listed as configurable sections.
     */
    public const SECTIONS = [
        'executive_summary' => 'Executive summary',
        'risk_profile' => 'Risk profile heat map',
        'top_risks' => 'Top risks',
        'appetite_position' => 'Risk appetite position',
        'kri_dashboard' => 'Key risk indicator dashboard',
        'loss_events' => 'Loss events summary',
        'issues_ageing' => 'Issues ageing',
        'treatment_progress' => 'Treatment progress',
        'regulatory_calendar' => 'Regulatory calendar',
        'appendices' => 'Appendices',
    ];

    /**
     * Sections a pack includes when an organization has expressed no preference.
     */
    public const DEFAULT_SECTIONS = [
        'executive_summary',
        'risk_profile',
        'top_risks',
        'appetite_position',
        'kri_dashboard',
        'loss_events',
        'issues_ageing',
        'treatment_progress',
        'regulatory_calendar',
        'appendices',
    ];

    public function __construct(
        private readonly DocumentRenderer $renderer,
    ) {}

    /**
     * The section order in force for an organization, filtered to keys that
     * still exist. A stale key left over from a removed section is dropped
     * rather than fatal.
     *
     * @return list<string>
     */
    public function sectionsFor(Organization $organization): array
    {
        $configured = $organization->settings['board_pack']['sections'] ?? null;

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_SECTIONS;
        }

        return array_values(array_filter(
            $configured,
            fn ($key) => is_string($key) && array_key_exists($key, self::SECTIONS)
        ));
    }

    /**
     * Persist a new section order for an organization.
     *
     * @param  list<string>  $sections
     */
    public function configureSections(Organization $organization, array $sections): void
    {
        $valid = array_values(array_filter(
            $sections,
            fn ($key) => is_string($key) && array_key_exists($key, self::SECTIONS)
        ));

        $organization->update([
            'settings' => array_merge($organization->settings ?? [], [
                'board_pack' => array_merge(
                    (array) ($organization->settings['board_pack'] ?? []),
                    ['sections' => $valid]
                ),
            ]),
        ]);
    }

    /**
     * Build the PDF bytes and everything the caller needs to file them.
     *
     * @return array{content: string, mime: string, extension: string, sections: list<array<string,mixed>>, as_at: CarbonImmutable}
     */
    public function build(Organization $organization, ?CarbonImmutable $asAt = null, ?User $generatedBy = null, int $version = 1): array
    {
        $asAt = $asAt ?? CarbonImmutable::now();
        $orgId = $organization->id;

        $keys = $this->sectionsFor($organization);

        $sections = [];
        foreach ($keys as $key) {
            $sections[] = [
                'key' => $key,
                'anchor' => 'section-'.str_replace('_', '-', $key),
                'title' => self::SECTIONS[$key],
                'data' => $this->sectionData($key, $orgId, $asAt),
            ];
        }

        $content = $this->renderer->pdf('reports.pdf.board-pack', [
            'title' => 'Board Risk Report',
            'subtitle' => 'Enterprise risk position for Board review',
            'organization' => $organization,
            'branding' => $this->renderer->branding($organization),
            'generatedAt' => CarbonImmutable::now(),
            'generatedBy' => $generatedBy?->name,
            'periodAsAt' => $asAt,
            'periodLabel' => $asAt->format('F Y'),
            'version' => 'v'.$version,
            'sections' => $sections,
        ]);

        return [
            'content' => $content,
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'sections' => $sections,
            'as_at' => $asAt,
        ];
    }

    /**
     * The next version number for this organization's board packs.
     */
    public function nextVersion(int $orgId): int
    {
        $latest = GeneratedReport::query()
            ->where('organization_id', $orgId)
            ->where('report_type', 'board_pack')
            ->max('version');

        return (int) $latest + 1;
    }

    /* ------------------------------------------------------------------ */
    /*  Section data */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string,mixed>
     */
    private function sectionData(string $key, int $orgId, CarbonImmutable $asAt): array
    {
        return match ($key) {
            'executive_summary' => $this->executiveSummary($orgId, $asAt),
            'risk_profile' => $this->riskProfile($orgId),
            'top_risks' => $this->topRisks($orgId),
            'appetite_position' => $this->appetitePosition($orgId),
            'kri_dashboard' => $this->kriDashboard($orgId),
            'loss_events' => $this->lossEvents($orgId, $asAt),
            'issues_ageing' => $this->issuesAgeing($orgId, $asAt),
            'treatment_progress' => $this->treatmentProgress($orgId, $asAt),
            'regulatory_calendar' => $this->regulatoryCalendar($orgId, $asAt),
            'appendices' => $this->appendices($orgId, $asAt),
            default => [],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function executiveSummary(int $orgId, CarbonImmutable $asAt): array
    {
        $risks = Risk::where('organization_id', $orgId)->where('status', 'active');

        $total = (clone $risks)->count();
        $critical = (clone $risks)->where('residual_rating', 'Critical')->count();
        $high = (clone $risks)->where('residual_rating', 'High')->count();

        $openIssues = Issue::where('organization_id', $orgId)
            ->whereIn('issue_status', ['OPEN', 'IN_PROGRESS', 'OVERDUE'])
            ->count();

        $ytdLossKobo = (int) LossEvent::where('organization_id', $orgId)
            ->whereYear('date_of_loss', $asAt->year)
            ->sum('gross_loss_amount_kobo');

        $overdueTreatments = TreatmentPlan::where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('target_date')
            ->whereDate('target_date', '<', $asAt->toDateString())
            ->count();

        $redKris = KeyRiskIndicator::where('organization_id', $orgId)
            ->where('current_status', 'red')
            ->count();

        $latestIcaap = IcaapAssessment::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->first();

        return [
            'total_risks' => $total,
            'critical_risks' => $critical,
            'high_risks' => $high,
            'open_issues' => $openIssues,
            'ytd_loss_kobo' => $ytdLossKobo,
            'overdue_treatments' => $overdueTreatments,
            'red_kris' => $redKris,
            'car_actual' => $latestIcaap?->car_actual !== null ? round((float) $latestIcaap->car_actual, 2) : null,
            'car_minimum' => $latestIcaap?->cbn_minimum_car !== null ? round((float) $latestIcaap->cbn_minimum_car, 2) : null,
            'icaap_period' => $latestIcaap?->period,
        ];
    }

    /**
     * A 5x5 residual heat map counted straight off the register.
     *
     * @return array<string,mixed>
     */
    private function riskProfile(int $orgId): array
    {
        $rows = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereNotNull('residual_likelihood')
            ->whereNotNull('residual_impact')
            ->select('residual_likelihood', 'residual_impact', DB::raw('COUNT(*) as c'))
            ->groupBy('residual_likelihood', 'residual_impact')
            ->get();

        $grid = [];
        for ($likelihood = 5; $likelihood >= 1; $likelihood--) {
            for ($impact = 1; $impact <= 5; $impact++) {
                $grid[$likelihood][$impact] = 0;
            }
        }

        $plotted = 0;
        foreach ($rows as $row) {
            $likelihood = (int) $row->residual_likelihood;
            $impact = (int) $row->residual_impact;
            if (isset($grid[$likelihood][$impact])) {
                $grid[$likelihood][$impact] = (int) $row->c;
                $plotted += (int) $row->c;
            }
        }

        // Risks missing a residual score cannot be plotted. Saying how many is
        // the difference between an empty-looking map and a misleading one.
        $unplotted = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('residual_likelihood')->orWhereNull('residual_impact'))
            ->count();

        return ['grid' => $grid, 'plotted' => $plotted, 'unplotted' => $unplotted];
    }

    /**
     * @return array<string,mixed>
     */
    private function topRisks(int $orgId, int $limit = 15): array
    {
        $risks = Risk::where('organization_id', $orgId)
            ->where('status', 'active')
            ->with(['category', 'riskOwner', 'businessUnit'])
            ->orderByDesc('residual_score')
            ->orderByDesc('inherent_score')
            ->limit($limit)
            ->get();

        return ['risks' => $risks, 'limit' => $limit];
    }

    /**
     * @return array<string,mixed>
     */
    private function appetitePosition(int $orgId): array
    {
        $appetites = \App\Models\RiskAppetite::where('organization_id', $orgId)
            ->with('category')
            ->get();

        return ['appetites' => $appetites];
    }

    /**
     * @return array<string,mixed>
     */
    private function kriDashboard(int $orgId): array
    {
        $kris = KeyRiskIndicator::where('organization_id', $orgId)
            ->with('owner')
            ->orderByRaw("CASE current_status WHEN 'red' THEN 1 WHEN 'yellow' THEN 2 WHEN 'amber' THEN 2 ELSE 3 END")
            ->get();

        return [
            'kris' => $kris,
            'red' => $kris->where('current_status', 'red')->count(),
            'amber' => $kris->whereIn('current_status', ['amber', 'yellow'])->count(),
            'green' => $kris->where('current_status', 'green')->count(),
            'unmeasured' => $kris->whereNull('current_status')->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function lossEvents(int $orgId, CarbonImmutable $asAt): array
    {
        $yearStart = $asAt->startOfYear();

        $events = LossEvent::where('organization_id', $orgId)
            ->whereBetween('date_of_loss', [$yearStart->toDateString(), $asAt->toDateString()])
            ->orderByDesc('gross_loss_amount_kobo')
            ->get();

        $byCategory = $events
            ->groupBy(fn ($e) => $e->basel_l1_category ?? 'Unclassified')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'gross_kobo' => (int) $group->sum('gross_loss_amount_kobo'),
                'recovered_kobo' => (int) $group->sum('actual_recovery_kobo'),
            ])
            ->sortByDesc('gross_kobo');

        return [
            'count' => $events->count(),
            'gross_kobo' => (int) $events->sum('gross_loss_amount_kobo'),
            'recovered_kobo' => (int) $events->sum('actual_recovery_kobo'),
            'by_category' => $byCategory,
            'largest' => $events->take(10),
            'period_start' => $yearStart,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function issuesAgeing(int $orgId, CarbonImmutable $asAt): array
    {
        $issues = Issue::where('organization_id', $orgId)
            ->whereIn('issue_status', ['OPEN', 'IN_PROGRESS', 'OVERDUE'])
            ->with('owner')
            ->get();

        // Age buckets measured from when the issue was raised.
        $buckets = ['0-30 days' => 0, '31-60 days' => 0, '61-90 days' => 0, 'Over 90 days' => 0];

        foreach ($issues as $issue) {
            $raised = $issue->created_at ? CarbonImmutable::parse($issue->created_at) : null;
            if (! $raised) {
                continue;
            }
            $age = $raised->diffInDays($asAt);
            $bucket = match (true) {
                $age <= 30 => '0-30 days',
                $age <= 60 => '31-60 days',
                $age <= 90 => '61-90 days',
                default => 'Over 90 days',
            };
            $buckets[$bucket]++;
        }

        // remediation_due_date is the canonical column; target_resolution_date
        // is one of the 200038 duplicates that is no longer written.
        $overdue = $issues->filter(function ($issue) use ($asAt) {
            $due = $issue->remediation_due_date;

            return $due !== null && CarbonImmutable::parse($due)->lessThan($asAt);
        });

        return [
            'total_open' => $issues->count(),
            'buckets' => $buckets,
            'overdue' => $overdue->count(),
            'oldest' => $issues->sortBy('created_at')->take(10),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function treatmentProgress(int $orgId, CarbonImmutable $asAt): array
    {
        $plans = TreatmentPlan::where('organization_id', $orgId)->with(['owner', 'risk'])->get();

        $overdue = $plans->filter(fn ($p) => ! in_array($p->status, ['completed', 'cancelled'], true)
            && $p->target_date !== null
            && CarbonImmutable::parse($p->target_date)->lessThan($asAt));

        return [
            'total' => $plans->count(),
            'completed' => $plans->where('status', 'completed')->count(),
            'in_progress' => $plans->where('status', 'in_progress')->count(),
            'not_started' => $plans->whereIn('status', ['draft', 'planned', 'pending'])->count(),
            'overdue' => $overdue->count(),
            'overdue_plans' => $overdue->sortBy('target_date')->take(10),
            'completion_pct' => $plans->count() > 0
                ? round($plans->where('status', 'completed')->count() / $plans->count() * 100, 1)
                : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function regulatoryCalendar(int $orgId, CarbonImmutable $asAt): array
    {
        $deadlines = RegulatoryDeadline::where('organization_id', $orgId)
            ->with(['responsible', 'filings'])
            ->orderBy('deadline_date')
            ->get();

        return [
            'upcoming' => $deadlines->filter(fn ($d) => $d->deadline_date !== null
                && $d->deadline_date->betweenIncluded($asAt, $asAt->addDays(90))),
            'overdue' => $deadlines->filter(fn ($d) => $d->isOverdue()),
            'total' => $deadlines->count(),
        ];
    }

    /**
     * Provenance, not padding: what the pack was built from, so a reader can
     * tell how much of the register was actually populated when it ran.
     *
     * @return array<string,mixed>
     */
    private function appendices(int $orgId, CarbonImmutable $asAt): array
    {
        return [
            'counts' => [
                'Active risks' => Risk::where('organization_id', $orgId)->where('status', 'active')->count(),
                'Controls' => Control::where('organization_id', $orgId)->count(),
                'Controls with an effectiveness rating' => Control::where('organization_id', $orgId)
                    ->whereNotNull('effectiveness_rating')->count(),
                'Key risk indicators' => KeyRiskIndicator::where('organization_id', $orgId)->count(),
                'Treatment plans' => TreatmentPlan::where('organization_id', $orgId)->count(),
                'Open issues' => Issue::where('organization_id', $orgId)
                    ->whereIn('issue_status', ['OPEN', 'IN_PROGRESS', 'OVERDUE'])->count(),
                'Loss events year to date' => LossEvent::where('organization_id', $orgId)
                    ->whereYear('date_of_loss', $asAt->year)->count(),
                'Approvals awaiting a decision' => ApprovalRequest::where('organization_id', $orgId)
                    ->where('status', 'pending')->count(),
            ],
            'basis' => 'All figures are counted from the risk register as at the position date on the cover. '
                .'Monetary amounts are stored in kobo and shown in naira.',
        ];
    }
}
