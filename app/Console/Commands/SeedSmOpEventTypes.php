<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Sm\OpEventTypeCatalogue;
use Illuminate\Console\Command;

/**
 * Seeds the Basel OP1–OP17 event-type tree into `risk_taxonomies` (ADR 0027 §A1.9).
 *
 * WHY A COMMAND AND NOT ONLY A SEEDER. `scripts/deploy.sh` runs
 * `RolesAndPermissionsSeeder` ONLY — no module seeder — and NO CODE PATH IN THIS REPOSITORY
 * CREATES AN `Organization` (verified by grep across app/, database/ and packages/): tenants
 * are provisioned outside it. So a tenant created after the last deploy would have an empty OP
 * picker and no way to fill it short of running a seeder by hand against every organisation.
 * `OpEventTypeCatalogue::ensureFor()` is the same code either way; this command is the handle
 * an operator can reach.
 *
 * [verify at integration] that provisioning calls this command or the seeder. Until it does, a
 * new tenant's OP picker is empty — a VISIBLE gap, because spec 04 §5 renders an amber callout
 * ("No Basel III OP event types are configured for your organisation…") rather than a silent
 * one, and the create form is not blocked by it.
 *
 * WITHOUT `--organization` IT SEEDS EVERY TENANT, which is what `SmReferenceSeeder` does and
 * what a fresh deployment wants. WITH it, exactly one — the shape an operator needs when a
 * single tenant was provisioned after the last deploy. Note this is deliberately looser than
 * `kri:import-library`, which refuses to default to "all": that command creates three hundred
 * KRIs and a set of departments, which is a change to a bank's risk framework, whereas this one
 * writes 18 idempotent reference rows per tenant and never overwrites an edit.
 *
 * SAFE TO RE-RUN. `firstOrCreate` semantics on (organization_id, framework, name), then only
 * `parent_id`/`depth`/`sort_order` repaired where they are null/0 — and NEVER `is_active`,
 * because re-activating a node a tenant retired is the one line that could undo their edit.
 */
class SeedSmOpEventTypes extends Command
{
    protected $signature = 'sm:seed-op-event-types
        {--organization= : Seed only this organisation id. Omit to seed every tenant.}';

    protected $description = 'Seed the Basel OP1-OP17 event-type tree into risk_taxonomies under framework "Basel III"';

    public function handle(OpEventTypeCatalogue $catalogue): int
    {
        $requested = $this->option('organization');

        if ($requested !== null) {
            $organization = Organization::query()->find($requested);

            if ($organization === null) {
                $this->error("No organisation with id {$requested}.");

                return self::FAILURE;
            }

            $organizations = collect([$organization]);
        } else {
            $organizations = Organization::query()->orderBy('id')->get();
        }

        if ($organizations->isEmpty()) {
            $this->warn('No organisations exist yet, so there was nothing to seed.');

            return self::SUCCESS;
        }

        foreach ($organizations as $organization) {
            $result = $catalogue->ensureFor($organization);

            $this->line(sprintf(
                '%s: %d OP event types present, %d created, %d parent links repaired.',
                $organization->name,
                $result['total'],
                $result['created'],
                $result['repaired']
            ));
        }

        return self::SUCCESS;
    }
}
