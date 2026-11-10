<?php

namespace Database\Seeders\Sm;

use App\Models\Organization;
use App\Services\Sm\OpEventTypeCatalogue;
use Illuminate\Database\Seeder;

/**
 * Everything Scenario Modelling needs before a user can do anything (ADR 0027 §A1.9).
 *
 * Placement mirrors `database/seeders/Bcms/BcmsReferenceSeeder.php`.
 *
 * ── ONE THING SEEDED, AND IT IS THE OP TREE ───────────────────────────────────
 *
 * COSO COMPONENTS ARE NOT SEEDED IN PHASE 1. D3 names them, but they belong to
 * `sm_scorecards` (Phase 2) and NO PHASE 1 SCREEN READS THEM. `BcmsReferenceSeeder`'s
 * docblock is the standing reason: a seeder that writes data no screen reads is data a
 * customer did not ask for.
 *
 * ── PER TENANT, NOT SYSTEM ROWS ───────────────────────────────────────────────
 *
 * BCMS's system-row mechanism is UNAVAILABLE here. `risk_taxonomies.organization_id` is
 * NOT NULL and `RiskTaxonomy` does not set `$tenantIncludesGlobal`, so the
 * `organization_id = null` + `TenantContext::clear()` trick would violate the column — and
 * making it nullable for 17 rows would be a structural change to a shipped table the
 * Regulatory Compliance module owns and whose add-node screen writes. Refused (§A1.9).
 *
 * So each tenant gets its own copy. That is not duplication for its own sake: `name` is
 * tenant-editable through the existing taxonomy screen, so a bank wanting "OP13 Failures in
 * credit or claims processes" edits its own node and keeps the OP number.
 *
 * ── NEW TENANTS ───────────────────────────────────────────────────────────────
 *
 * `scripts/deploy.sh` runs `RolesAndPermissionsSeeder` ONLY — no module seeder — and no
 * code path in `app/`, `database/` or `packages/` creates an `Organization` (verified by
 * grep): tenants are provisioned OUTSIDE this repository. So `OpEventTypeCatalogue::ensureFor()`
 * stands alone and Phase 1 also exposes `php artisan sm:seed-op-event-types {--organization=}`.
 * [verify at integration] that provisioning calls one of the two; until then a new tenant's
 * OP picker is empty, which is a VISIBLE gap — spec 04 §5 renders an amber callout — and not
 * a silent one.
 *
 *     php artisan db:seed --class="Database\\Seeders\\Sm\\SmReferenceSeeder"
 */
class SmReferenceSeeder extends Seeder
{
    public function __construct(private readonly OpEventTypeCatalogue $catalogue) {}

    public function run(): void
    {
        // "Seed every organization present in the system" — the shape
        // `BusinessProcessSeeder` set, keyed on a natural key so re-running adds new nodes
        // and repairs parent links without duplicating or resurrecting anything.
        $organizations = Organization::query()->orderBy('id')->get();

        foreach ($organizations as $organization) {
            $result = $this->catalogue->ensureFor($organization);

            $this->command?->line(sprintf(
                '%s: %d OP event types present, %d created, %d parent links repaired.',
                $organization->name,
                $result['total'],
                $result['created'],
                $result['repaired']
            ));
        }

        if ($organizations->isEmpty()) {
            $this->command?->warn('No organisations exist yet, so there was nothing to seed.');
        }
    }
}
