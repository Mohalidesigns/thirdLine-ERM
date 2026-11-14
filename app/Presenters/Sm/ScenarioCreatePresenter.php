<?php

namespace App\Presenters\Sm;

use App\Models\BusinessUnit;
use App\Models\Sm\Scenario;
use App\Services\Sm\OpEventTypeCatalogue;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The create form's payload (spec 04 §2) — THE LIGHTEST IN THE MODULE.
 *
 * FOUR FIELDS AND NO MORE. Spec 04 §1: everything else the module collects lives on the
 * Overview & Governance tab, and a create form that duplicated it would be a second copy of
 * fifteen fields with their own requiredness rules, option lists and messages — the two
 * would drift, and the drift would be invisible until a user's answers disagreed depending
 * on which door they came through. So this presenter sends NO `weightings`, NO `currencies`,
 * NO `fxRates` and NO risk-search URL: none of those fields is on this form, and shipping
 * their option lists would be dead bytes on the slowest link in the product (spec 01 §0.4).
 *
 * ── NO REFERENCE, PROVISIONAL OR OTHERWISE ────────────────────────────────────
 *
 * Addendum-2 and spec 04 §4: the sequence is `max + 1`, read at write time, so any number
 * shown BEFORE the write is a guess — two designers creating at the same moment are both
 * shown the same provisional reference and one of them is saved with a different one. The
 * screen would have displayed a reference that never existed, which is standard §5's defect
 * class in the one field a user is most likely to copy into an email before saving. The row
 * reads "Assigned when you save" and that is the only text it ever carries before the save.
 *
 * `Quantification\ScenarioService::nextReference()` exists and would supply such a number;
 * spec 04 §4 says do not call it from this page, and this presenter does not.
 *
 * ── `nature` ARRIVES PRE-SET TO `local`, NOT NULL ─────────────────────────────
 *
 * §A1.2 makes the column NOT NULL default `'local'` — "a classification the designer knows
 * at creation, where `local` is the narrowest claim", in the shape of `risks.status`
 * defaulting `'draft'`. So the form opens on a real value rather than a "Choose…" the user
 * has to clear, and the default is the narrowest assertion available.
 */
class ScenarioCreatePresenter
{
    public function __construct(private readonly OpEventTypeCatalogue $opEvents) {}

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $organizationId = TenantContext::organizationId();

        return [
            // `business_units`, NOT `entities` (§A1.3). Spec 04 §5: Create is NOT blocked
            // when this list is empty — §A1.2 row 10 makes the column nullable and §A1.8's
            // frozen guard does not list it, so blocking would enforce a rule the server
            // does not have.
            'businessUnits' => $this->businessUnits($organizationId),

            // Reads THE TAXONOMY, never the const (spec 02 §5.4): a tenant's CBN ORMS
            // extensions appear because the query reads their own nodes. §A1.9 records that
            // no taxonomy seeder existed before Phase 1 and that tenants are provisioned
            // outside this repository, so an EMPTY list is a live go-live gap rather than a
            // theoretical one — spec 04 §5 renders the amber callout and does not block.
            'opEventTypes' => $this->opEventTypes($organizationId),

            'natures' => $this->natures(),

            // `values` uses the FROZEN COLUMN NAMES as field codes, so the 422 keys, the
            // Form Request rules and the workspace's `gate.missing[].field` anchors are one
            // vocabulary across three surfaces. A create form that posts `scenario_name`
            // while the workspace posts `name` is how a module ends up with two validation
            // rule sets.
            'values' => [
                'name' => '',
                'business_unit_id' => null,
                'nature' => Scenario::DEFAULT_NATURE,
                'risk_taxonomy_node_id' => null,
            ],

            // NO `reference` KEY. See the class docblock.
            'urls' => [
                'store' => route('scenario.store'),
                'cancel' => route('scenario.index'),
            ],
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function businessUnits(int $organizationId): array
    {
        return BusinessUnit::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (BusinessUnit $unit) => ['id' => (int) $unit->id, 'name' => (string) $unit->name])
            ->all();
    }

    /**
     * `name` IS THE WHOLE LABEL — "OP7 Improper business or market practices…" verbatim
     * (§A1.9). `risk_taxonomies` has NO `code` column, so there is no separate code to
     * concatenate and no client-side formatting of it.
     *
     * `basel_level_1` is extracted HERE, from the node's `description`, which is where
     * §A1.9 puts the mapping ("Basel Level-1: Clients, products & business practices"). It
     * is null when a tenant has edited that description, and the picker then falls back to
     * a flat list (spec 04 §2). `parent` is sent alongside it so the UI can group either
     * way — spec 02 §5.4 requires the control to "work with either seeding choice".
     *
     * @return list<array{id: int, name: string, parent: array{id: int, name: string}|null, basel_level_1: string|null}>
     */
    private function opEventTypes(int $organizationId): array
    {
        return $this->opEvents->nodesFor($organizationId)
            ->map(fn ($node) => [
                'id' => (int) $node->id,
                'name' => (string) $node->name,
                'parent' => $node->parent === null
                    ? null
                    : ['id' => (int) $node->parent->id, 'name' => (string) $node->parent->name],
                'basel_level_1' => OpEventTypeCatalogue::baselLevel1From($node->description),
            ])
            ->all();
    }

    /** @return list<array{value: string, label: string}> */
    private function natures(): array
    {
        $out = [];

        foreach (Scenario::NATURES as $value => $label) {
            $out[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $out;
    }
}
