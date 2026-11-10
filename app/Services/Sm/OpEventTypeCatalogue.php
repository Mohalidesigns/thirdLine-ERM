<?php

namespace App\Services\Sm;

use App\Models\Organization;
use App\Models\RiskTaxonomy;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The OP1–OP17 Basel operational-risk event types, as a PER-TENANT `RiskTaxonomy`
 * tree (ADR 0027 D3, Amendment 1 §A1.9).
 *
 * A SERVICE, NOT INLINE SEEDER LOGIC, so provisioning can call the same method the
 * seeder does. `BusinessProcessSeeder` is the precedent for the shape: "Seed every
 * organization present in the system", idempotent, keyed on a natural key.
 *
 * WHY `RiskTaxonomy` AND NOT A CONFIG FILE OR A NEW TABLE. D3: "OP1–OP17 and COSO
 * live in `RiskTaxonomy`, the scales live in config" — the OP list is a HIERARCHY a
 * tenant extends toward CBN ORMS sub-types, not a vlist. `risk_taxonomies` already
 * has `organization_id` (NOT NULL), `name`, `description`, `framework`, `parent_id`,
 * `sort_order`, `depth` and `is_active`, and `RiskTaxonomy::FRAMEWORKS` already
 * contains `'Basel III'`, so that const does not change either.
 *
 * WHY NOT BCMS'S SYSTEM-ROW MECHANISM. `risk_taxonomies.organization_id` is NOT
 * NULL, so `$tenantIncludesGlobal` + a null organisation is unavailable here — and
 * making the column nullable for 17 rows would be a structural change to a shipped
 * table another module owns and whose add-node screen writes. Refused.
 *
 * `risk_taxonomies` HAS NO `code` COLUMN, so the OP number lives in `name`
 * ("OP7 Improper business or market practices…"). Spec 04 §2 is explicit that the
 * payload therefore carries no separate `code`: there is nothing to concatenate and
 * nothing for the client to format.
 *
 * THE LABELS ARE INSURER-FLAVOURED AND STAY VERBATIM. D1 makes the workbook the
 * specification; these are the strings a customer's existing loss-data templates and
 * signed-off scenarios already say; and a "neutralised" rewrite would be an
 * unreviewed editorial change to a regulatory vocabulary made by whoever wrote the
 * seeder. `name` is tenant-editable through the existing taxonomy screen, so a bank
 * wanting "OP13 Failures in credit or claims processes" edits its own node and keeps
 * the OP number.
 */
class OpEventTypeCatalogue
{
    /** The framework these nodes are tagged against — already in `RiskTaxonomy::FRAMEWORKS`. */
    public const FRAMEWORK = 'Basel III';

    /** The one parent node the 17 OP nodes hang from. */
    public const PARENT_NAME = 'Basel OP event types';

    /**
     * The prefix the Basel Level-1 mapping is written under, in `description`.
     *
     * The extraction's mapping goes into each child's `description` rather than into
     * a new column or a config file (§A1.9: "`description` is already there, rendered
     * and tenant-editable"), and it makes the list navigable for a bank without
     * renaming anything. Spec 04 §2 has the presenter extract `basel_level_1` from
     * it, so the format is a contract, not a comment.
     */
    public const BASEL_DESCRIPTION_PREFIX = 'Basel Level-1: ';

    /**
     * OP number => [name, Basel Level-1 parent].
     *
     * Names VERBATIM from `plans/scenario-modelling/VOCABULARY-EXTRACTION.md`
     * (LS sheet [25,13]–[41,13]), prefixed `OP{n} ` as §A1.9 freezes them. The
     * longest is OP7; §A1.9 measured it at 158 characters against `name`'s
     * `string(255)` — measured, not assumed, which is ADR 0014's whole lesson.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    public const OP_EVENT_TYPES = [
        1 => ['OP1 Internal Fraud & intentional unauthorised activity', 'Internal fraud'],
        2 => ['OP2 External Fraud & system security fraud', 'External fraud'],
        3 => ['OP3 Employee relations, diversity & discrimination', 'Employment practices & workplace safety'],
        4 => ['OP4 Safe environment', 'Employment practices & workplace safety'],
        5 => ['OP5 Loss of key staff/talent management', 'Employment practices & workplace safety'],
        6 => ['OP6 Suitability, disclosure & breach of fiduciary duty', 'Clients, products & business practices'],
        7 => ['OP7 Improper business or market practices leading to breach of laws & regulations (Antitrust, unlicensed activity, money laundering, breach of insurance laws)', 'Clients, products & business practices'],
        8 => ['OP8 Product flaws', 'Clients, products & business practices'],
        9 => ['OP9 Disasters & other events', 'Damage to physical assets'],
        10 => ['OP10 Breach of information security', 'External fraud'],
        11 => ['OP11 System disruption (unavailability, under-investment in IT or telecommunication)', 'Business disruption & system failures'],
        12 => ['OP12 Transaction, capture, execution & maintenance', 'Execution, delivery & process management'],
        13 => ['OP13 Failures in u/w or claims (non intentional) processes', 'Execution, delivery & process management'],
        14 => ['OP14 Public reporting & disclosure risk (including Financial reporting, rating issues & IR)', 'Clients, products & business practices'],
        15 => ['OP15 Project management failure', 'Execution, delivery & process management'],
        16 => ['OP16 Internal vendors misperformance or failure, incl. distribution misperformance (tied agents)', 'Execution, delivery & process management'],
        17 => ['OP17 External vendors misperformance or failure, incl. distribution misperformance (brokers)', 'Execution, delivery & process management'],
    ];

    /**
     * Ensure one organisation has the OP tree. Idempotent, and safe to re-run after
     * a tenant has edited its own nodes.
     *
     * RUNS AS THE TARGET TENANT via `TenantContext::actingAs()`, and that is not ceremony.
     * `RiskTaxonomy` carries `BelongsToOrganization`, whose global scope filters every read
     * to the AMBIENT tenant — so calling `ensureFor($orgB)` while the context holds org A
     * would find none of org B's existing nodes and create a duplicate set on every run.
     * Idempotency here depends on the scope agreeing with the organisation being seeded.
     * `actingAs` restores the previous tenant afterwards, so a caller inside a request is
     * unaffected.
     *
     * @return array{created: int, repaired: int, total: int}
     */
    public function ensureFor(Organization $organization): array
    {
        return TenantContext::actingAs((int) $organization->id, fn () => $this->ensure($organization));
    }

    /**
     * @return array{created: int, repaired: int, total: int}
     */
    private function ensure(Organization $organization): array
    {
        $parent = $this->ensureParent($organization);

        $created = 0;
        $repaired = 0;

        foreach (self::OP_EVENT_TYPES as $number => [$name, $baselLevel1]) {
            $existing = RiskTaxonomy::query()
                ->where('organization_id', $organization->id)
                ->where('framework', self::FRAMEWORK)
                ->where('name', $name)
                ->first();

            if ($existing === null) {
                RiskTaxonomy::create([
                    'organization_id' => $organization->id,
                    'name' => $name,
                    'description' => self::BASEL_DESCRIPTION_PREFIX.$baselLevel1,
                    'framework' => self::FRAMEWORK,
                    'parent_id' => $parent->id,
                    'sort_order' => $number,
                    'depth' => 1,
                    'is_active' => true,
                ]);

                $created++;

                continue;
            }

            if ($this->repair($existing, $parent->id, $number)) {
                $repaired++;
            }
        }

        return [
            'created' => $created,
            'repaired' => $repaired,
            'total' => count(self::OP_EVENT_TYPES),
        ];
    }

    /**
     * The OP nodes for a tenant, in OP-number order, for a picker.
     *
     * Reads THE TAXONOMY, never this const (spec 02 §5.4): "the picker reads the
     * taxonomy, never a constant, or an extension would be invisible." A tenant's
     * CBN ORMS sub-types therefore appear without a code change.
     *
     * Runs as the tenant for the same reason `ensureFor()` does: an ambient context
     * holding a different organisation would make the global scope return nothing, and an
     * empty picker is a go-live gap that reads as "this tenant has no OP types" rather than
     * as a bug.
     *
     * @return \Illuminate\Support\Collection<int, RiskTaxonomy>
     */
    public function nodesFor(int $organizationId): \Illuminate\Support\Collection
    {
        return TenantContext::actingAs($organizationId, fn () => RiskTaxonomy::query()
            ->where('organization_id', $organizationId)
            ->where('framework', self::FRAMEWORK)
            ->where('name', 'like', 'OP%')
            ->whereNotNull('parent_id')
            ->where('is_active', true)
            ->with('parent')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get());
    }

    /**
     * The Basel Level-1 name a node's description carries, or null.
     *
     * Null when a tenant has edited the description — spec 04 §2: the picker then
     * falls back to a flat list rather than inventing a grouping.
     */
    public static function baselLevel1From(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $prefix = self::BASEL_DESCRIPTION_PREFIX;

        if (! str_starts_with($description, $prefix)) {
            return null;
        }

        $value = trim(substr($description, strlen($prefix)));

        return $value === '' ? null : $value;
    }

    /**
     * The parent node, created once per tenant.
     *
     * ONE PARENT with 17 children rather than 17 loose roots: the admin screen is a
     * tree (`RiskTaxonomy::children()` orders by `sort_order`), 17 roots beside a
     * tenant's own nodes is unusable, and a parent gives the scenario form one
     * indexed query on the existing `index(organization_id, parent_id)`.
     */
    private function ensureParent(Organization $organization): RiskTaxonomy
    {
        $parent = RiskTaxonomy::query()
            ->where('organization_id', $organization->id)
            ->where('framework', self::FRAMEWORK)
            ->where('name', self::PARENT_NAME)
            ->whereNull('parent_id')
            ->first();

        if ($parent !== null) {
            return $parent;
        }

        return RiskTaxonomy::create([
            'organization_id' => $organization->id,
            'name' => self::PARENT_NAME,
            'description' => 'The Basel operational-risk event types this organisation maps scenarios to. '
                .'Rename a node to suit your own vocabulary; the OP number is what the scenario record cites.',
            'framework' => self::FRAMEWORK,
            'parent_id' => null,
            'sort_order' => 0,
            'depth' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Repair the STRUCTURAL columns only, and only where they are unset.
     *
     * §A1.9: "then update only `parent_id`/`depth`/`sort_order` where they are
     * null/0. **Do not use `updateOrCreate` with `is_active` in the attributes
     * half** — that re-activates a node a tenant retired." The same discipline
     * covers `name` and `description`: a tenant that renamed OP13 keeps the rename,
     * and re-running adds new nodes and repairs parent links without duplicating or
     * resurrecting anything.
     */
    private function repair(RiskTaxonomy $node, int $parentId, int $sortOrder): bool
    {
        $dirty = false;

        if ($node->parent_id === null) {
            $node->parent_id = $parentId;
            $dirty = true;
        }

        if ((int) $node->depth !== 1) {
            $node->depth = 1;
            $dirty = true;
        }

        if ((int) $node->sort_order === 0) {
            $node->sort_order = $sortOrder;
            $dirty = true;
        }

        if ($dirty) {
            $node->save();
        }

        return $dirty;
    }
}
