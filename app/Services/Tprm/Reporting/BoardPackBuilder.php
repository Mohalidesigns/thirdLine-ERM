<?php

namespace App\Services\Tprm\Reporting;

use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\RiskTier;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ExitPlan;
use App\Models\Tprm\Finding;
use App\Models\Tprm\Incident;
use App\Models\Tprm\Waiver;
use App\Services\Tprm\Graph\ConcentrationAnalyzer;
use App\Services\Tprm\Reporting\Concerns\StatesAbsence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The figures a Board and Risk Committee pack prints — FR-RPT-05.
 *
 * IT COMPUTES AND RETURNS; IT NEVER PERSISTS. `BoardPackService` owns the
 * snapshot. Phase 7 shipped `ConcentrationAnalyzer::analyse()` writing a row
 * every time a screen rendered, which meant the run history the board compares
 * quarters from filled up with page views; the separation here is the lesson
 * from that.
 *
 * EVERY SECTION LEADS WITH ITS GAP RATHER THAN ITS TOTAL. A committee does not
 * need to be told it has 214 vendors; it needs to be told that nine Critical
 * engagements are past their assessment date and four have no exit plan. The
 * shapes below reflect that: counts of the population come second.
 *
 * NOTHING HERE INVENTS A NUMBER. An engagement with no residual score is
 * reported as unscored and excluded from averages rather than counted as
 * zero — a portfolio average dragged down by vendors nobody has assessed reads
 * as a safe portfolio, which is the reading `NoFabricatedNumbersTest` exists
 * to prevent.
 */
class BoardPackBuilder
{
    use StatesAbsence;

    /** Evidence expiring inside this window is on the pack. */
    private const EVIDENCE_HORIZON_DAYS = 90;

    public function __construct(private readonly ConcentrationAnalyzer $concentration) {}

    /**
     * The whole pack, as a plain array ready to be frozen onto a row.
     *
     * @return array<string, mixed>
     */
    public function figures(?CarbonImmutable $asAt = null): array
    {
        $asAt = $asAt ?? CarbonImmutable::now();
        $engagements = $this->liveEngagements();

        return [
            'as_at' => $asAt->toDateString(),
            'engine_version' => (string) config('tprm.engine_version'),
            'portfolio' => $this->portfolio($engagements),
            'top_exposures' => $this->topExposures($engagements),
            'critical_functions' => $this->criticalFunctionDependencies($engagements),
            'concentration' => $this->concentrationPosition(),
            'findings' => $this->findings(),
            'overdue_assessments' => $this->overdueAssessments($engagements, $asAt),
            'expiring_evidence' => $this->expiringEvidence($asAt),
            'incidents' => $this->incidentsAndLosses($asAt),
            'exit_readiness' => $this->exitReadiness($engagements),
            'overrides_and_waivers' => $this->overridesAndWaivers(),
        ];
    }

    /* ================================================================== */

    /**
     * @param  Collection<int, Engagement>  $engagements
     * @return array<string, mixed>
     */
    private function portfolio(Collection $engagements): array
    {
        $byTier = [];
        foreach (RiskTier::cases() as $tier) {
            $byTier[$tier->value] = [
                'label' => $tier->label(),
                'count' => $engagements->where('effective_tier', $tier)->count(),
            ];
        }

        $untiered = $engagements->whereNull('effective_tier')->count();

        $scored = $engagements->filter(fn (Engagement $e) => $e->residual_score !== null);

        return [
            'total' => $engagements->count(),
            'by_tier' => array_values($byTier),
            // Not folded into "Low". An engagement nobody has tiered has not
            // been found to be low risk; it has not been looked at.
            'untiered' => $untiered,
            'by_category' => $engagements
                ->groupBy(fn (Engagement $e) => $this->labelOf($e->thirdParty?->category, 'name', 'Not classified'))
                ->map(fn (Collection $group, string $name) => [
                    'category' => $name,
                    'count' => $group->count(),
                    'critical_or_high' => $group->filter(fn (Engagement $e) => in_array(
                        $e->effective_tier,
                        [RiskTier::Critical, RiskTier::High],
                        true
                    ))->count(),
                ])
                ->sortByDesc('count')
                ->values()
                ->all(),
            'scored' => $scored->count(),
            'unscored' => $engagements->count() - $scored->count(),
            // Averaged over SCORED engagements only. Including unscored ones
            // as zero would report a safer portfolio the less work was done.
            'mean_residual' => $scored->isEmpty()
                ? null
                : round($scored->avg(fn (Engagement $e) => (float) $e->residual_score), 1),
            'supports_critical_function' => $engagements->where('supports_critical_function', true)->count(),
            'material_outsourcing' => $engagements->where('is_material_outsourcing', true)->count(),
        ];
    }

    /**
     * @param  Collection<int, Engagement>  $engagements
     * @return list<array<string, mixed>>
     */
    private function topExposures(Collection $engagements, int $limit = 10): array
    {
        return $engagements
            ->filter(fn (Engagement $e) => $e->residual_score !== null)
            ->sortByDesc(fn (Engagement $e) => (float) $e->residual_score)
            ->take($limit)
            ->map(fn (Engagement $e) => [
                'reference' => $e->reference,
                'provider' => $this->labelOf($e->thirdParty, 'legal_name', 'Not recorded'),
                'service' => $e->name,
                'tier' => $e->effective_tier?->label() ?? 'Not tiered',
                'residual_score' => (float) $e->residual_score,
                'residual_band' => $e->residual_band?->label() ?? 'Not scored',
                'supports_critical_function' => (bool) $e->supports_critical_function,
                'annual_spend_major' => $e->annual_spend_minor === null
                    ? null
                    : round($e->annual_spend_minor / 100, 2),
                'currency' => $e->currency,
            ])
            ->values()
            ->all();
    }

    /**
     * Which critical functions depend on which providers — the dependency map.
     *
     * @param  Collection<int, Engagement>  $engagements
     * @return list<array<string, mixed>>
     */
    private function criticalFunctionDependencies(Collection $engagements): array
    {
        $map = [];

        foreach ($engagements as $engagement) {
            foreach ($engagement->businessFunctions as $function) {
                if (! $function->isCriticalOrImportant()) {
                    continue;
                }

                $code = (string) $function->function_code;

                $map[$code] ??= [
                    'function_code' => $code,
                    'function' => (string) $function->name,
                    'criticality' => ucwords(str_replace('_', ' ', (string) $function->criticality)),
                    'rto_hours' => $function->rto_hours,
                    'providers' => [],
                ];

                $map[$code]['providers'][] = [
                    'provider' => $this->labelOf($engagement->thirdParty, 'legal_name', 'Not recorded'),
                    'engagement' => $engagement->reference,
                    'tier' => $engagement->effective_tier?->label() ?? 'Not tiered',
                ];
            }
        }

        foreach ($map as $code => $entry) {
            $map[$code]['provider_count'] = count($entry['providers']);
            // A critical function served by exactly one provider is the line a
            // committee reads first.
            $map[$code]['single_provider'] = count($entry['providers']) === 1;
        }

        return array_values($map);
    }

    /**
     * @return array<string, mixed>
     */
    private function concentrationPosition(): array
    {
        $result = $this->concentration->analyse((int) TenantContext::organizationId());

        return [
            'hhi' => $result['hhi'],
            'band' => $this->concentration->bandLabel((float) $result['hhi']),
            'band_edges' => $this->concentration->bandEdges(),
            'clusters' => collect($result['clusters'] ?? [])
                ->sortByDesc('share')
                ->take(10)
                ->map(fn ($cluster) => [
                    'name' => $cluster['name'] ?? 'Unnamed group',
                    'share' => $cluster['share'] ?? null,
                    'engagements' => $cluster['engagement_count'] ?? count($cluster['engagement_references'] ?? []),
                    'critical_functions' => $cluster['critical_function_count'] ?? null,
                ])
                ->values()
                ->all(),
            'single_points_of_failure' => $result['single_points_of_failure'] ?? [],
        ];
    }

    /**
     * Open findings by severity and by age.
     *
     * AGE IS MEASURED FROM `identified_at`, NOT FROM THE TARGET DATE. A finding
     * whose target date has been moved three times is old, and reporting its
     * age from the latest target would show a programme that is always nearly
     * done.
     *
     * @return array<string, mixed>
     */
    private function findings(): array
    {
        $open = Finding::query()
            ->with('thirdParty:id,legal_name')
            ->get()
            ->filter(fn (Finding $finding) => $finding->status->isOpen());

        $buckets = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '91-180' => 0, 'over_180' => 0];
        $ages = [];

        foreach ($open as $finding) {
            $days = (int) $finding->identified_at->diffInDays(now());
            $ages[] = $days;

            $bucket = match (true) {
                $days <= 30 => '0-30',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                $days <= 180 => '91-180',
                default => 'over_180',
            };

            $buckets[$bucket]++;
        }

        $bySeverity = [];
        foreach (FindingSeverity::cases() as $severity) {
            $bySeverity[] = [
                'severity' => $severity->label(),
                'count' => $open->filter(fn (Finding $f) => $f->severity === $severity)->count(),
            ];
        }

        $overdue = $open->filter(
            fn (Finding $finding) => $finding->target_date !== null && $finding->target_date->isPast()
        );

        return [
            'open' => $open->count(),
            'by_severity' => $bySeverity,
            'by_age' => $buckets,
            'mean_age_days' => $ages === [] ? null : (int) round(array_sum($ages) / count($ages)),
            'overdue' => $overdue->count(),
            'oldest' => $open
                ->sortBy(fn (Finding $f) => $f->identified_at)
                ->take(5)
                ->map(fn (Finding $f) => [
                    'reference' => $f->reference,
                    'title' => $f->title,
                    'provider' => $this->labelOf($f->thirdParty, 'legal_name', 'Not recorded'),
                    'severity' => $f->severity->label(),
                    'age_days' => (int) $f->identified_at->diffInDays(now()),
                    'target_date' => $f->target_date?->toDateString(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Engagement>  $engagements
     * @return array<string, mixed>
     */
    private function overdueAssessments(Collection $engagements, CarbonImmutable $asAt): array
    {
        $overdue = $engagements->filter(
            fn (Engagement $e) => $e->next_assessment_due !== null && $e->next_assessment_due->lt($asAt)
        );

        $neverDue = $engagements->filter(fn (Engagement $e) => $e->next_assessment_due === null);

        return [
            'overdue' => $overdue->count(),
            // An engagement with no assessment cadence is not compliant with
            // one; it has never been given one. Counted separately.
            'no_cadence_set' => $neverDue->count(),
            'critical_or_high_overdue' => $overdue->filter(fn (Engagement $e) => in_array(
                $e->effective_tier,
                [RiskTier::Critical, RiskTier::High],
                true
            ))->count(),
            'worst' => $overdue
                ->sortBy(fn (Engagement $e) => $e->next_assessment_due)
                ->take(10)
                ->map(fn (Engagement $e) => [
                    'reference' => $e->reference,
                    'provider' => $this->labelOf($e->thirdParty, 'legal_name', 'Not recorded'),
                    'tier' => $e->effective_tier?->label() ?? 'Not tiered',
                    'due' => $e->next_assessment_due->toDateString(),
                    'days_overdue' => (int) $e->next_assessment_due->diffInDays($asAt),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expiringEvidence(CarbonImmutable $asAt): array
    {
        $horizon = $asAt->addDays(self::EVIDENCE_HORIZON_DAYS);

        $documents = Document::query()
            ->with('documentType:id,name,is_assurance_evidence')
            ->where('is_superseded', false)
            ->whereNotNull('valid_to')
            ->whereDate('valid_to', '<=', $horizon->toDateString())
            ->orderBy('valid_to')
            ->get();

        $expired = $documents->filter(fn (Document $d) => $d->valid_to->lt($asAt->startOfDay()));

        return [
            'horizon_days' => self::EVIDENCE_HORIZON_DAYS,
            'expiring' => $documents->count() - $expired->count(),
            // Already expired is not "expiring soon". It is a control that is
            // currently unevidenced, and the assurance coefficient has already
            // decayed for it.
            'already_expired' => $expired->count(),
            'items' => $documents->take(15)->map(fn (Document $d) => [
                'title' => $d->title,
                'type' => $this->labelOf($d->documentType, 'name', 'Not classified'),
                'owner' => $d->ownerLabel(),
                'valid_to' => $d->valid_to->toDateString(),
                'days' => (int) $asAt->startOfDay()->diffInDays($d->valid_to, false),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentsAndLosses(CarbonImmutable $asAt): array
    {
        $since = $asAt->subMonths(12);

        $incidents = Incident::query()
            ->with('thirdParty:id,legal_name')
            ->whereNotNull('reported_to_us_at')
            ->where('reported_to_us_at', '>=', $since)
            ->orderByDesc('reported_to_us_at')
            ->get();

        $lossMinor = (int) $incidents->sum(fn (Incident $i) => (int) ($i->estimated_loss_minor ?? 0));

        return [
            'window_months' => 12,
            'count' => $incidents->count(),
            'personal_data' => $incidents->where('personal_data_involved', true)->count(),
            'reported_to_a_regulator' => $incidents->filter(
                fn (Incident $i) => $i->cbn_reported_at !== null || $i->ndpc_reported_at !== null
            )->count(),
            'estimated_loss_major' => $lossMinor > 0 ? round($lossMinor / 100, 2) : null,
            // Incidents whose loss was never quantified. A total that hid them
            // would understate exactly the number a board asks about.
            'loss_not_quantified' => $incidents->filter(
                fn (Incident $i) => $i->estimated_loss_minor === null
            )->count(),
            'posted_to_the_loss_register' => $incidents->whereNotNull('erm_loss_event_id')->count(),
            'recent' => $incidents->take(10)->map(fn (Incident $i) => [
                'reference' => $i->reference,
                'provider' => $this->labelOf($i->thirdParty, 'legal_name', 'Not recorded'),
                'title' => $i->title,
                'reported_to_us' => $i->reported_to_us_at->toDateString(),
                'severity' => $i->severity ? ucfirst($i->severity) : 'Not graded',
                'estimated_loss_major' => $i->estimated_loss_minor === null
                    ? null
                    : round($i->estimated_loss_minor / 100, 2),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Engagement>  $engagements
     * @return array<string, mixed>
     */
    private function exitReadiness(Collection $engagements): array
    {
        $required = $engagements->filter(
            fn (Engagement $e) => (bool) $e->exit_plan_required || $e->effective_tier === RiskTier::Critical
        );

        $plans = ExitPlan::query()
            ->whereIn('engagement_id', $required->map(fn (Engagement $e) => $e->getKey())->all())
            ->get()
            ->keyBy('engagement_id');

        $withoutPlan = $required->reject(fn (Engagement $e) => $plans->has($e->getKey()));
        $neverTested = $plans->filter(fn (ExitPlan $p) => ! $p->hasBeenTested());
        $stale = $plans->filter(
            fn (ExitPlan $p) => $p->hasBeenTested() && $p->testIsOverdue()
        );

        return [
            'require_a_plan' => $required->count(),
            // The first number, deliberately: a dashboard of traffic lights
            // over the plans that exist flatters a programme that has written
            // three and needs thirty.
            'no_plan' => $withoutPlan->count(),
            'never_tested' => $neverTested->count(),
            'stale' => $stale->count(),
            'current' => $plans->count() - $neverTested->count() - $stale->count(),
            'gaps' => $withoutPlan->take(10)->map(fn (Engagement $e) => [
                'reference' => $e->reference,
                'provider' => $this->labelOf($e->thirdParty, 'legal_name', 'Not recorded'),
                'tier' => $e->effective_tier?->label() ?? 'Not tiered',
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function overridesAndWaivers(): array
    {
        $waivers = Waiver::query()->with('requester:id,name', 'approver:id,name')->get();

        $active = $waivers->filter(fn (Waiver $w) => $w->status === Waiver::STATUS_APPROVED);

        $byType = [];
        foreach ($active->groupBy('type') as $type => $group) {
            $byType[] = [
                'type' => ucwords(str_replace('_', ' ', (string) $type)),
                'count' => $group->count(),
            ];
        }

        $expired = $active->filter(
            fn (Waiver $w) => $w->expires_at !== null && $w->expires_at->isPast()
        );

        return [
            'approved' => $active->count(),
            'by_type' => $byType,
            'pending' => $waivers->filter(fn (Waiver $w) => $w->status === Waiver::STATUS_REQUESTED)->count(),
            // An expired waiver that nobody withdrew is an exception still
            // sitting in the register with no authority behind it.
            'lapsed_but_not_withdrawn' => $expired->count(),
            'no_expiry_set' => $active->filter(fn (Waiver $w) => $w->expires_at === null)->count(),
        ];
    }

    /**
     * @return Collection<int, Engagement>
     */
    private function liveEngagements(): Collection
    {
        return Engagement::query()
            ->with([
                'thirdParty:id,legal_name,category_id',
                'thirdParty.category:id,name',
                'businessFunctions',
            ])
            ->get()
            ->filter(fn (Engagement $engagement) => $engagement->status->isLive())
            ->values();
    }
}
