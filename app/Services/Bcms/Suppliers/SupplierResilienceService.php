<?php

namespace App\Services\Bcms\Suppliers;

use App\Enums\Bcms\DependencyType;
use App\Models\Bcms\Dependency;
use App\Models\Tprm\BcpTest;
use App\Models\Tprm\ConcentrationAnalysis;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use Illuminate\Support\Collection;

/**
 * A thin, read-through layer over TPRM — phase-11-spec §4. NO NEW VENDOR
 * TABLE. Every fact below is read from TPRM's own tables (`tp_bcp_tests`,
 * `tp_engagements`, `tp_business_functions`, `tp_concentration_analyses`); the
 * one thing this class computes itself is which vendors are BCMS-critical, a
 * question TPRM's own tiering does not answer because it is about risk to the
 * bank generally, not about which vendors THIS continuity programme depends
 * on.
 *
 * THE SEAM: a BCMS dependency names a THIRD PARTY; TPRM's continuity evidence
 * hangs off an ENGAGEMENT. A vendor with more than one qualifying engagement
 * is EVIDENCE-AMBIGUOUS and this class lists every qualifying engagement
 * rather than guessing which one the dependency "really" means (§4(5)).
 */
class SupplierResilienceService
{
    /**
     * BCMS-critical vendors: those appearing as a dependency of a Tier-1 or
     * critical-service process — the BCMS-relevance filter, distinct from
     * TPRM's own tiering.
     *
     * @return Collection<int, Dependency>
     */
    public function criticalDependencies(): Collection
    {
        return Dependency::query()
            ->where('dependable_type', DependencyType::Vendors->value)
            ->whereHas('assessment.process', fn ($q) => $q->where('criticality_tier', 1)->orWhere('is_critical_service', true))
            ->with(['assessment.process:id,name,criticality_tier,is_critical_service'])
            ->get();
    }

    /**
     * The continuity-currency view: one row per BCMS-critical vendor.
     *
     * @return list<array<string, mixed>>
     */
    public function continuityView(): array
    {
        $byVendor = $this->criticalDependencies()->groupBy('dependable_id');

        $rows = [];

        foreach ($byVendor as $vendorId => $dependencies) {
            /** @var Dependency $first */
            $first = $dependencies->first();
            $vendor = ThirdParty::withTrashed()->find($vendorId);

            $processes = $dependencies->map(function (Dependency $d) {
                $process = $d->assessment?->process;

                return $process === null ? null : [
                    'name' => $process->name,
                    'tier' => $process->criticality_tier,
                    'is_critical_service' => (bool) $process->is_critical_service,
                ];
            })->filter()->values()->all();

            $qualifyingEngagements = Engagement::query()
                ->where('third_party_id', $vendorId)
                ->where('supports_critical_function', true)
                ->get();

            $rows[] = [
                'vendor_id' => (int) $vendorId,
                'vendor_name' => $vendor?->trashed() === false || $vendor !== null
                    ? ($vendor->legal_name ?? $vendor->trading_name)
                    : null,
                'vendor_label' => $first->dependableLabel(),
                'depended_on_by' => $processes,
                'evidence_ambiguous' => $qualifyingEngagements->count() > 1,
                'qualifying_engagement_count' => $qualifyingEngagements->count(),
                'engagements' => $qualifyingEngagements->map(fn (Engagement $e) => $this->engagementRow($e))->all(),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function engagementRow(Engagement $engagement): array
    {
        // A6: a tiebreaker on `id` — two tests dated the same day (a genuine
        // possibility a seeded fixture can produce as easily as a busy
        // vendor-management week) otherwise leave the database free to
        // return either side of the tie.
        $latest = BcpTest::query()
            ->where('engagement_id', $engagement->getKey())
            ->orderByDesc('test_date')
            ->orderByDesc('id')
            ->first();

        // The engagement/function pivot carries no RTO column of its own; the
        // commitment is the business function's own required RTO — the
        // strictest one, where an engagement supports more than one function.
        $committedRtoHours = $engagement->businessFunctions()->min('rto_hours');

        return [
            'engagement_id' => $engagement->getKey(),
            'engagement_reference' => $engagement->reference,
            'engagement_name' => $engagement->name,
            'committed_rto_hours' => $committedRtoHours === null ? null : (int) $committedRtoHours,
            'latest_test' => $latest === null ? null : [
                'id' => $latest->getKey(),
                'test_date' => $latest->test_date?->toDateString(),
                'test_type' => $latest->test_type,
                'outcome' => $latest->outcome,
                'our_participation' => (bool) $latest->our_participation,
                'rto_achieved_hours' => $latest->rto_achieved_hours,
                'rto_breach' => $committedRtoHours !== null && $latest->rto_achieved_hours !== null
                    && $latest->rto_achieved_hours > $committedRtoHours,
                'next_due_at' => $latest->next_due_at?->toDateString(),
                'is_overdue' => $latest->isOverdue(),
                'evidence_document_id' => $latest->evidence_document_id,
            ],
        ];
    }

    /**
     * The chase list: BCMS-critical vendors whose latest evidence (across
     * every qualifying engagement) is overdue, missing a next-due date
     * altogether, or altogether missing.
     *
     * A6: takes an already-computed `continuityView()` where the caller has
     * one — `SupplierResilienceController::index()` used to build the
     * continuity view up to three times over on one page render (once
     * directly, once again inside this method, and again inside
     * `attestationRate()` on the attestation-write path). `null` preserves
     * the original one-call convenience for every other caller.
     *
     * @param  list<array<string, mixed>>|null  $continuityView
     * @return list<array{vendor_id: int, vendor_label: string, reason: string}>
     */
    public function chaseList(?array $continuityView = null): array
    {
        $chase = [];

        foreach ($continuityView ?? $this->continuityView() as $row) {
            $tests = collect($row['engagements'])->pluck('latest_test')->filter();

            if ($tests->isEmpty()) {
                $chase[] = ['vendor_id' => $row['vendor_id'], 'vendor_label' => $row['vendor_label'], 'reason' => 'No BCP test on file.'];

                continue;
            }

            if ($tests->contains(fn ($t) => $t['is_overdue'])) {
                $chase[] = ['vendor_id' => $row['vendor_id'], 'vendor_label' => $row['vendor_label'], 'reason' => 'Continuity evidence is past its next-due date.'];

                continue;
            }

            // A8: `next_due_at` is optional on `tp_bcp_tests` — a test
            // recorded with no due date is not overdue by `isOverdue()`'s
            // own definition ("has it passed its due date") and would
            // otherwise count as current for ever, which is a false
            // compliance signal nobody set out to give: evidence with no
            // expiry cannot be confirmed current, only assumed so. Ruled
            // (development standard's "honest red over a fabricated green"):
            // treated the same as overdue for chase-list and
            // attestation-rate purposes, named with its own reason so it
            // reads differently from a genuinely lapsed test.
            if ($tests->contains(fn ($t) => ! self::testIsCurrent($t))) {
                $chase[] = ['vendor_id' => $row['vendor_id'], 'vendor_label' => $row['vendor_label'], 'reason' => 'Continuity evidence has no next-due date on file and cannot be confirmed current.'];
            }
        }

        return $chase;
    }

    /**
     * The percentage of BCMS-critical vendors with current continuity
     * evidence — what `BCMS-VENDOR-ATTEST` measures. NULL over an empty
     * dependency graph, never zero (development standard §5).
     *
     * @param  list<array<string, mixed>>|null  $continuityView  reuse an
     *                                                           already-computed view (A6) rather than a second one
     */
    public function attestationRate(?array $continuityView = null): ?float
    {
        $vendors = $continuityView ?? $this->continuityView();

        if ($vendors === []) {
            return null;
        }

        $current = collect($vendors)->filter(function (array $row) {
            $tests = collect($row['engagements'])->pluck('latest_test')->filter();

            return $tests->isNotEmpty() && $tests->every(fn ($t) => self::testIsCurrent($t));
        })->count();

        return round($current / count($vendors) * 100, 1);
    }

    /**
     * A8: a test is current only when it is BOTH not overdue AND carries a
     * next-due date at all — a null `next_due_at` is never treated as
     * "current for ever".
     *
     * @param  array<string, mixed>  $test  one `latest_test` array from `engagementRow()`
     */
    private static function testIsCurrent(array $test): bool
    {
        return $test['next_due_at'] !== null && ! $test['is_overdue'];
    }

    /**
     * Vendors that are a dependency of more than one Tier-1/critical-service
     * process — the concentration read-through, cross-referenced against
     * TPRM's own latest concentration run. No utilisation percentage: TPRM's
     * concentration bands have no shareholders'-funds figure behind them
     * (a named, deliberate go-live gap), so only counts and named processes
     * are reported here.
     *
     * @return array{vendors: list<array<string, mixed>>, tprm_analysis: array<string, mixed>|null}
     */
    public function concentration(): array
    {
        $byVendor = $this->criticalDependencies()->groupBy('dependable_id')
            ->filter(fn (Collection $deps) => $deps->pluck('assessment.process.id')->unique()->count() > 1);

        $vendors = $byVendor->map(function (Collection $deps, $vendorId) {
            $vendor = ThirdParty::withTrashed()->find($vendorId);
            /** @var Dependency $first */
            $first = $deps->first();

            $processes = $deps->map(fn (Dependency $d) => $d->assessment?->process?->name)->filter()->unique()->values()->all();

            return [
                'vendor_id' => (int) $vendorId,
                'vendor_label' => $vendor !== null ? ($vendor->legal_name ?? $vendor->trading_name) : $first->dependableLabel(),
                'process_count' => count($processes),
                'processes' => $processes,
            ];
        })->values()->all();

        $latestRun = ConcentrationAnalysis::query()->orderByDesc('run_at')->first();

        return [
            'vendors' => $vendors,
            'tprm_analysis' => $latestRun === null ? null : [
                'run_at' => $latestRun->run_at?->toIso8601String(),
                'dimension' => $latestRun->dimension,
                'hhi' => $latestRun->hhi === null ? null : (float) $latestRun->hhi,
            ],
        ];
    }
}
