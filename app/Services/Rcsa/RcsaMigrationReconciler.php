<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;

/**
 * §13 step 4: "Compare legacy vs migrated on row count, per-BU count, and
 * residual level distribution. Sign off the reconciliation before cutover."
 *
 * A RECONCILIATION THAT ONLY EVER SAYS "MATCHED" IS DECORATION. Two of the
 * three comparisons here are expected to differ, and the report says so rather
 * than hiding it:
 *
 *   - ROW COUNTS differ by design. The universe is deduplicated by `row_hash`,
 *     so a register that states the same risk twice in one process produces one
 *     universe row. The report shows the difference AND what accounts for it,
 *     so an operator can tell "deduplicated as intended" from "two hundred rows
 *     went missing".
 *
 *   - RESIDUAL DISTRIBUTION differs because the figures are RECOMPUTED. The
 *     legacy module scored on its own arithmetic; the migration carries the
 *     inputs and lets RcsaCalculationService derive the rest, because filing
 *     legacy numbers under a v2 methodology would produce a register whose
 *     residuals do not follow from its inputs. The distribution comparison is
 *     what makes the size of that shift visible before anybody signs.
 *
 * The one comparison that must match exactly is PER-BUSINESS-UNIT coverage: a
 * unit with risks in the register and none in the universe is a migration that
 * silently skipped a unit, and a total that matches while the units do not is
 * exactly the failure a total alone cannot see.
 */
class RcsaMigrationReconciler
{
    public function __construct(private readonly RcsaLegacyInventory $inventory) {}

    /**
     * @return array<string, mixed>
     */
    public function report(int $organizationId): array
    {
        $counts = $this->counts($organizationId);
        $units = $this->perBusinessUnit($organizationId);
        $residual = $this->residualDistribution($organizationId);

        return [
            'organization_id' => $organizationId,
            'taken_at' => now()->toDateTimeString(),
            'counts' => $counts,
            'per_business_unit' => $units,
            'residual_distribution' => $residual,
            'verdict' => $this->verdict($counts, $units),
        ];
    }

    /**
     * Totals, and what accounts for the difference.
     *
     * @return array<string, mixed>
     */
    public function counts(int $organizationId): array
    {
        $legacyRisks = $this->inventory->migratableRisks($organizationId)->count();
        $legacyTotal = Risk::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->count();

        $migrated = RcsaRegisterRisk::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('legacy_risk_id')
            ->count();

        $bornInV2 = RcsaRegisterRisk::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('legacy_risk_id')
            ->count();

        $campaignIds = $this->inventory->legacyCampaignIds($organizationId);

        $legacyResponses = $this->inventory->responses($campaignIds)->count();

        $migratedLines = RcsaAssessmentLine::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('legacy_response_id')
            ->count();

        return [
            'legacy_risks_total' => $legacyTotal,
            'legacy_risks_migratable' => $legacyRisks,
            'legacy_risks_without_unit' => $legacyTotal - $legacyRisks,
            'universe_rows_migrated' => $migrated,
            'universe_rows_born_in_v2' => $bornInV2,
            // Positive means the register said something twice, or a row was
            // skipped. The exceptions report from the migration is what tells
            // an operator which.
            'unaccounted' => $legacyRisks - $migrated,
            'legacy_responses' => $legacyResponses,
            'migrated_lines' => $migratedLines,
            'responses_unaccounted' => $legacyResponses - $migratedLines,
        ];
    }

    /**
     * Per-unit coverage — the comparison that must match.
     *
     * @return list<array<string, mixed>>
     */
    public function perBusinessUnit(int $organizationId): array
    {
        $legacy = $this->inventory->migratableRisks($organizationId)
            ->selectRaw('business_unit_id, count(*) as aggregate')
            ->groupBy('business_unit_id')
            ->pluck('aggregate', 'business_unit_id');

        $migrated = RcsaRegisterRisk::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('legacy_risk_id')
            ->selectRaw('business_unit_id, count(*) as aggregate')
            ->groupBy('business_unit_id')
            ->pluck('aggregate', 'business_unit_id');

        $names = DB::table('business_units')->where('organization_id', $organizationId)->pluck('name', 'id');

        $rows = [];

        foreach ($legacy->keys()->merge($migrated->keys())->unique() as $unitId) {
            $before = (int) ($legacy[$unitId] ?? 0);
            $after = (int) ($migrated[$unitId] ?? 0);

            $rows[] = [
                'business_unit_id' => (int) $unitId,
                'business_unit' => $names[$unitId] ?? '(unknown unit)',
                'legacy' => $before,
                'migrated' => $after,
                'difference' => $after - $before,
                // A unit with legacy risks and NO migrated rows is the failure
                // this comparison exists to catch.
                'missing_entirely' => $before > 0 && $after === 0,
            ];
        }

        usort($rows, fn ($a, $b) => $a['difference'] <=> $b['difference']);

        return $rows;
    }

    /**
     * Residual levels before and after, which are EXPECTED to differ.
     *
     * "Before" is the legacy register's own `residual_rating`, which is the
     * only residual the old module had. "After" is what the v2 engine computed
     * from the inputs that were migrated. The two are different questions and
     * the report labels them as such — a reader who thinks this is a
     * discrepancy has been told the wrong thing.
     *
     * @return array<string, mixed>
     */
    public function residualDistribution(int $organizationId): array
    {
        $legacy = Risk::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('residual_rating')
            ->selectRaw('residual_rating as level, count(*) as aggregate')
            ->groupBy('residual_rating')
            ->pluck('aggregate', 'level');

        $migrated = RcsaAssessmentLine::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('legacy_response_id')
            ->whereNotNull('residual_level')
            ->selectRaw('residual_level as level, count(*) as aggregate')
            ->groupBy('residual_level')
            ->pluck('aggregate', 'level');

        $levels = $legacy->keys()
            ->map(fn ($l) => $this->normaliseLevel((string) $l))
            ->merge($migrated->keys()->map(fn ($l) => $this->normaliseLevel((string) $l)))
            ->unique()
            ->values();

        $rows = [];

        foreach ($levels as $level) {
            $rows[] = [
                'level' => $level,
                'legacy' => (int) $legacy->filter(fn ($_, $k) => $this->normaliseLevel((string) $k) === $level)->sum(),
                'migrated' => (int) $migrated->filter(fn ($_, $k) => $this->normaliseLevel((string) $k) === $level)->sum(),
            ];
        }

        return [
            'note' => 'These are expected to differ. The migration carries the INPUTS and lets the v2 engine '
                .'recompute, so a residual here follows from its own likelihood, impact and control rating '
                .'rather than from the legacy module\'s arithmetic.',
            'rows' => $rows,
        ];
    }

    /**
     * Whether this reconciliation can be signed off.
     *
     * @param  array<string, mixed>  $counts
     * @param  list<array<string, mixed>>  $units
     * @return array<string, mixed>
     */
    private function verdict(array $counts, array $units): array
    {
        $blockers = [];
        $triage = [];

        foreach ($units as $unit) {
            if ($unit['missing_entirely']) {
                $blockers[] = sprintf(
                    '%s has %d risk(s) in the register and none in the universe.',
                    $unit['business_unit'],
                    $unit['legacy'],
                );
            }
        }

        if ($counts['universe_rows_migrated'] === 0 && $counts['legacy_risks_migratable'] > 0) {
            $blockers[] = 'Nothing has been migrated yet — run rcsa:migrate-legacy --commit first.';
        }

        // NOT A BLOCKER, AND THE DISTINCTION MATTERS. Some legacy rows cannot
        // be migrated by anybody — a response whose risk was deleted years ago
        // has nothing to attach to, and no amount of re-running fixes it. A
        // verdict that stayed red for those would be a verdict every operator
        // learns to override, which is worse than one that never went red.
        // They are surfaced as work the sign-off must acknowledge instead.
        if ($counts['responses_unaccounted'] > 0) {
            $triage[] = sprintf(
                '%d legacy response(s) produced no assessment line. Check the exceptions report from the '
                .'migration: each one names the row and why. Sign off only once somebody has agreed each is '
                .'genuinely unmappable rather than a bug.',
                $counts['responses_unaccounted'],
            );
        }

        if ($counts['unaccounted'] > 0) {
            $triage[] = sprintf(
                '%d migratable risk(s) produced no universe row. Some will be duplicates the universe '
                .'deduplicated on purpose; the exceptions report says which.',
                $counts['unaccounted'],
            );
        }

        return [
            'signable' => $blockers === [],
            'blockers' => $blockers,
            'requires_triage' => $triage,
            'note' => $blockers !== []
                ? 'Do not sign off. Each blocker below is a whole business unit that exists in the legacy '
                    .'module and not in v2.'
                : ($triage === []
                    ? 'Per-unit coverage is complete and every legacy row is accounted for. Row-count and '
                        .'residual-distribution differences are explained above and are expected.'
                    : 'Per-unit coverage is complete. Nothing blocks sign-off, but the items below need a '
                        .'human to agree them first.'),
        ];
    }

    /**
     * `Very High`, `very_high` and `VERY HIGH` are one level.
     */
    private function normaliseLevel(string $level): string
    {
        return str_replace(' ', '_', mb_strtolower(trim($level)));
    }
}
