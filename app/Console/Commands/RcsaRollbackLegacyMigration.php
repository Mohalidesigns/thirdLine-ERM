<?php

namespace App\Console\Commands;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaRegisterControl;
use App\Models\Rcsa\RcsaRegisterRisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * §13 step 7: "Flag flip back plus a documented data-reverse script, valid for
 * the first 30 days."
 *
 * IT REMOVES ONLY WHAT THE MIGRATION CREATED. Every row it touches is selected
 * by a non-null legacy id, which is why P8 added those columns before writing
 * anything. A universe risk typed into the screen, a cycle somebody opened, an
 * assessment a unit has been filling in — all of them have a null legacy id and
 * none of them are reachable from here.
 *
 * IT REFUSES TO TOUCH WORK DONE SINCE. A migrated universe row that has been
 * assessed in a REAL cycle is no longer only migration output — deleting it
 * would take a live assessment's provenance with it. Those are reported and
 * left, which is the difference between a rollback and a truncation.
 *
 * NOTHING LEGACY IS TOUCHED IN EITHER DIRECTION. The legacy module was never
 * modified by the migration — it reads the enterprise register, which the
 * migration only ever read too — so reversing it is a matter of removing v2
 * rows and flipping the flag. There is nothing to restore.
 *
 * THE THIRTY DAYS ARE A POLICY, NOT A LOCK. The command warns past the window
 * and continues, because an operator who genuinely needs to reverse on day
 * thirty-five should not be reduced to writing DELETEs by hand — which is
 * strictly more dangerous than running this.
 */
class RcsaRollbackLegacyMigration extends Command
{
    protected $signature = 'rcsa:rollback-legacy-migration
        {--organization= : The tenant to reverse.}
        {--commit : Actually delete. Without it, this reports what would go.}';

    protected $description = 'Reverse the P8 legacy migration for one tenant, removing only rows the migration created';

    /** §13's window. Advisory — see the class comment. */
    public const WINDOW_DAYS = 30;

    public function handle(): int
    {
        $organizationId = (int) $this->option('organization');

        if ($organizationId === 0) {
            $this->error('Name a tenant with --organization.');

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');

        TenantContext::set($organizationId);

        $plan = $this->plan($organizationId);

        $this->table(['What', 'Count'], [
            ['Historical lines', $plan['lines']],
            ['Legacy assessments', $plan['assessments']],
            ['Legacy cycles', $plan['cycles']],
            ['Universe controls', $plan['controls']],
            ['Universe risks', $plan['risks']],
            ['Universe risks KEPT (assessed in a real cycle)', $plan['kept']],
        ]);

        if ($plan['oldest'] !== null) {
            $age = (int) round(now()->diffInDays($plan['oldest'], false)) * -1;

            $this->line("  The migration ran {$age} day(s) ago.");

            if ($age > self::WINDOW_DAYS) {
                $this->warn(sprintf(
                    '  That is past the %d-day reversal window in the runbook. Continuing anyway — but the '
                    .'assumption behind the window is that nobody has built on the migrated data yet, and '
                    .'after this long somebody probably has.',
                    self::WINDOW_DAYS,
                ));
            }
        }

        if ($plan['kept'] > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '  %d migrated universe risk(s) have been assessed in a real cycle and will be LEFT IN PLACE. '
                .'Deleting them would take a live assessment\'s provenance with them.',
                $plan['kept'],
            ));
        }

        if (! $commit) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was deleted. Re-run with --commit.');

            TenantContext::clear();

            return self::SUCCESS;
        }

        $this->reverse($organizationId);

        $this->newLine();
        $this->info('Reversed. Turn the `rcsa_v2` flag back off for this tenant to complete the rollback.');

        TenantContext::clear();

        return self::SUCCESS;
    }

    /**
     * What would go, without going.
     *
     * @return array<string, mixed>
     */
    private function plan(int $organizationId): array
    {
        $keptRiskIds = $this->risksToKeep($organizationId);

        return [
            'lines' => RcsaAssessmentLine::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)->whereNotNull('legacy_response_id')->count(),
            'assessments' => RcsaAssessment::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereIn('cycle_id', $this->legacyCycleIds($organizationId))->count(),
            'cycles' => count($this->legacyCycleIds($organizationId)),
            'controls' => RcsaRegisterControl::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereNotNull('legacy_control_id')
                ->whereNotIn('register_risk_id', $keptRiskIds ?: [0])
                ->count(),
            'risks' => RcsaRegisterRisk::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereNotNull('legacy_risk_id')
                ->whereNotIn('id', $keptRiskIds ?: [0])
                ->count(),
            'kept' => count($keptRiskIds),
            'oldest' => RcsaRegisterRisk::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereNotNull('legacy_risk_id')
                ->min('created_at'),
        ];
    }

    private function reverse(int $organizationId): void
    {
        $keptRiskIds = $this->risksToKeep($organizationId);
        $cycleIds = $this->legacyCycleIds($organizationId);

        DB::transaction(function () use ($organizationId, $keptRiskIds, $cycleIds) {
            // Children first, and forceDelete rather than delete: a soft-deleted
            // migration row would still hold its legacy id, so a re-run would
            // skip the risk it came from and the migration would be neither
            // done nor undone.
            RcsaAssessmentLine::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereNotNull('legacy_response_id')
                ->forceDelete();

            RcsaAssessment::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereIn('cycle_id', $cycleIds ?: [0])
                ->forceDelete();

            RcsaCycle::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereNotNull('legacy_campaign_id')
                ->forceDelete();

            RcsaRegisterControl::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereNotNull('legacy_control_id')
                ->whereNotIn('register_risk_id', $keptRiskIds ?: [0])
                ->forceDelete();

            RcsaRegisterRisk::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereNotNull('legacy_risk_id')
                ->whereNotIn('id', $keptRiskIds ?: [0])
                ->forceDelete();
        });
    }

    /**
     * Migrated universe risks that a REAL cycle has since assessed.
     *
     * @return list<int>
     */
    private function risksToKeep(int $organizationId): array
    {
        $legacyCycles = $this->legacyCycleIds($organizationId);

        return RcsaRegisterRisk::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('legacy_risk_id')
            ->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('rcsa_assessment_lines')
                ->join('rcsa_assessments', 'rcsa_assessments.id', '=', 'rcsa_assessment_lines.assessment_id')
                ->whereColumn('rcsa_assessment_lines.register_risk_id', 'rcsa_register_risks.id')
                ->when($legacyCycles !== [], fn ($j) => $j->whereNotIn('rcsa_assessments.cycle_id', $legacyCycles)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function legacyCycleIds(int $organizationId): array
    {
        return RcsaCycle::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('legacy_campaign_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
