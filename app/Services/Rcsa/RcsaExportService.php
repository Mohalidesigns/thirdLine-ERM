<?php

namespace App\Services\Rcsa;

use App\Models\BusinessUnit;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaExportJob;
use App\Models\User;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * The authorised bulk download of §10 — what comes out, for whom, and whether
 * it happens now or on a queue.
 *
 * THE EXPORT LOG IS A CONTROL, NOT TELEMETRY. A completed RCSA is the bank's
 * operational risk profile in one file. Every export writes a
 * `rcsa_export_jobs` row with the user, the filters verbatim, the row count,
 * the IP and the time — before the file exists, so a failure is logged too. An
 * export that produced nothing and an export nobody recorded look identical
 * otherwise.
 *
 * THE THRESHOLD IS ROWS, NOT A GUESS AT SECONDS. Under `SYNC_LIMIT` lines the
 * file is built in the request and streamed; over it, the work goes to a queue
 * and the user is sent a link. PhpSpreadsheet holds the whole workbook in
 * memory, so the number that matters is how many rows are being laid out, and
 * a bank running a full-estate export across four cycles is the case that
 * would otherwise time out behind a spinner.
 *
 * BUSINESS-UNIT SCOPING IS SEAMED HERE AND FILLED IN P7, exactly as it is on
 * every RCSA policy. `reachableUnitIds()` is the single place §11's rule — a
 * risk champion in Retail must not be able to export Treasury's assessment —
 * will land, and every query in this class already routes through it. It
 * returns null today, meaning "every unit in the tenant", which is what the
 * policies also currently mean; making it return the user's own subtree before
 * P7 has built the assignment model would silently empty the export for every
 * user whose `business_unit_id` is null, which is most of them.
 */
class RcsaExportService
{
    /**
     * Lines above which the export is queued rather than streamed.
     *
     * Two thousand is roughly a full cycle for a mid-sized bank — twenty units
     * at a hundred risks — and comfortably inside what PhpSpreadsheet lays out
     * in a request. Beyond it the user gets a link instead of a spinner.
     */
    public const SYNC_LIMIT = 2000;

    /** How long a generated file stays collectable (§10.2). */
    public const LINK_TTL_HOURS = 48;

    public function __construct(
        private readonly RcsaCalculationService $calculator,
        private readonly RcsaAuditRecorder $audit,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  What comes out */
    /* ------------------------------------------------------------------ */

    /**
     * The lines an export would contain.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RcsaAssessmentLine>
     */
    public function query(array $filters, ?User $viewer = null): Builder
    {
        $units = $this->reachableUnitIds($viewer);

        return RcsaAssessmentLine::query()
            ->with([
                'assessor:id,name',
                'actionPlans.owner:id,name',
                'assessment:id,cycle_id,business_unit_id,status,submitted_at,submitted_by,reviewer_id',
                'assessment.cycle:id,name,period_start,period_end',
                'assessment.reviewer:id,name',
                'assessment.submitter:id,name',
            ])
            ->when($units !== null, fn ($q) => $q->whereIn('business_unit_id', $units))
            ->when(filled($filters['cycle'] ?? null), fn ($q) => $q->whereHas(
                'assessment',
                fn ($a) => $a->where('cycle_id', $filters['cycle']),
            ))
            ->when(filled($filters['business_units'] ?? null), fn ($q) => $q->whereIn(
                'business_unit_id',
                (array) $filters['business_units'],
            ))
            ->when(filled($filters['risk_category'] ?? null), fn ($q) => $q->where('risk_category', $filters['risk_category']))
            ->when(filled($filters['inherent_level'] ?? null), fn ($q) => $q->where('inherent_level', $filters['inherent_level']))
            ->when(filled($filters['residual_level'] ?? null), fn ($q) => $q->where('residual_level', $filters['residual_level']))
            ->when(filled($filters['treatment'] ?? null), fn ($q) => $q->where('risk_treatment', $filters['treatment']))
            ->when(filled($filters['assessment_status'] ?? null), fn ($q) => $q->whereHas(
                'assessment',
                fn ($a) => $a->where('status', $filters['assessment_status']),
            ))
            // Appetite IS a column now (§14 Q4). It used to be a comparison
            // against the methodology's ceiling, filtered by listing the band
            // names above it — which forced this filter to pick one band list
            // for an export spanning several cycles, and could not express a
            // ceiling that varies by category at all. The engine stores the
            // answer per line; both filters read it.
            //
            // An unscored line (`above_appetite` NULL) matches NEITHER, which
            // is the same behaviour the NOT IN had and the right one: a line
            // with no residual band is not within appetite, it is unanswered.
            ->when(($filters['appetite'] ?? null) === 'above',
                fn ($q) => $q->where('above_appetite', true))
            ->when(($filters['appetite'] ?? null) === 'within',
                fn ($q) => $q->where('above_appetite', false))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereHas(
                'assessment',
                fn ($a) => $a->whereDate('submitted_at', '>=', $filters['from']),
            ))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereHas(
                'assessment',
                fn ($a) => $a->whereDate('submitted_at', '<=', $filters['to']),
            ))
            // The workbook's own order: unit, then the risk number within it.
            ->orderBy('business_unit_name')
            ->orderBy('risk_no');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters, ?User $viewer = null): int
    {
        return $this->query($filters, $viewer)->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, RcsaAssessmentLine>
     */
    public function lines(array $filters, ?User $viewer = null): Collection
    {
        return $this->query($filters, $viewer)->get();
    }

    /* ------------------------------------------------------------------ */
    /*  The log */
    /* ------------------------------------------------------------------ */

    /**
     * Record the intent to export, before anything is generated.
     *
     * @param  array<string, mixed>  $filters
     */
    public function log(User $actor, array $filters, int $rowCount, ?Request $request = null, string $status = RcsaExportJob::QUEUED): RcsaExportJob
    {
        $export = RcsaExportJob::create([
            'organization_id' => $actor->organization_id,
            'user_id' => $actor->id,
            // Verbatim, so the log answers "what did they take" rather than
            // merely "they exported something".
            'filters' => $filters,
            'format' => 'xlsx',
            'status' => $status,
            'row_count' => $rowCount,
            'ip_address' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 512),
            'expires_at' => now()->addHours(self::LINK_TTL_HOURS),
        ]);

        // Into the estate-wide trail as well (§11). `rcsa_export_jobs` is the
        // operational log — status, link, download count — and this is the
        // tamper-evident one an auditor reads beside every other module's
        // activity.
        $this->audit->export($export, $rowCount, $actor);

        return $export;
    }

    /**
     * Whether this export is small enough to build in the request.
     */
    public function isSynchronous(int $rowCount): bool
    {
        return $rowCount <= self::SYNC_LIMIT;
    }

    /* ------------------------------------------------------------------ */
    /*  Scoping (§11) */
    /* ------------------------------------------------------------------ */

    /**
     * The business units this user may export.
     *
     * NULL MEANS "EVERY UNIT IN THE TENANT" and only `rcsa_scope.all_units`
     * produces it. P6 left this returning null unconditionally and routed every
     * query in the class through it; P7 is that one line — which is the whole
     * point of having put the seam here rather than inlining a filter into
     * eight `when()` clauses.
     *
     * §11's rule is specifically about export: "a risk champion in Retail
     * Operations must not be able to read Treasury's assessment, OR EXPORT IT."
     * A module that scoped its screens and not its download would have moved
     * the leak rather than closed it.
     *
     * @return list<int>|null
     */
    public function reachableUnitIds(?User $user): ?array
    {
        return app(RcsaScope::class)->unitIdsFor($user);
    }

    /**
     * Every unit in the tenant, for the filter's select.
     *
     * @return list<array{id: int, name: string}>
     */
    public function unitOptions(?User $viewer = null): array
    {
        $units = $this->reachableUnitIds($viewer);

        return BusinessUnit::query()
            ->when($units !== null, fn ($q) => $q->whereIn('id', $units))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (BusinessUnit $unit) => ['id' => (int) $unit->id, 'name' => (string) $unit->name])
            ->all();
    }
}
