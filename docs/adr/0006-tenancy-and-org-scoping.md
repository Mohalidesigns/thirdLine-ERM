# ADR 0006 — Tenancy and org-hierarchy scoping in BCMS

**Status:** Accepted · **Date:** 2026-09-07 · **Phase:** BCMS Phase 0 · **Author:** architect
**Consumers:** all tracks

## Context

Blueprint §9 opens with "every table carries `tenant_id`". This product does
not have a `tenant_id` anywhere. Tenancy is
`ThirdLine\Platform\Tenancy\BelongsToOrganization`, a global scope on
`organization_id`, and `TenancyIsolationTest` is the guard that actually proves
a cross-tenant read returns nothing (development standard §7). TPRM made the
same call in its Phase 0 and the reasoning is unchanged: a `tenant_id` column
would sit outside the global scope and outside the guard, so it would look like
tenancy and prove nothing.

Org-hierarchy scoping is a second, narrower question. A branch manager in Kano
must see the Kano drill calendar and not Lagos's; a group risk officer sees
both. Orchestration §5 assigns the trait to Track A and forbids any track
writing its own.

## Decision

**1. `organization_id`, `BelongsToOrganization`, on every `bcms_*` table** that
belongs to a tenant. Reference tables that ship system-owned rows carry a
NULLABLE `organization_id` and set `protected bool $tenantIncludesGlobal = true`
on the model — the mechanism `OrganizationScope` already implements for the
shared question library and the default notification templates, and the one
`tp_document_types` uses. BCMS does not add a second one.

> The system-owned row is the trap that cost the TPRM build two sessions. A
> `null` `organization_id` is invisible to `BelongsToOrganization`'s global
> scope unless the model opts in, so a seeder that writes one and a screen that
> reads it disagree forever, silently. The BCMS tables that seed system rows are
> `bcms_exercise_types`, `bcms_readiness_templates` (and its tasks),
> `bcms_blackout_periods`, `bcms_alert_templates`, `bcms_scenarios` and
> `bcms_training_curricula`; each is covered by
> `Phase0FoundationsTest::system_owned_reference_rows_are_visible_to_a_tenant()`.
>
> `bcms_clause_refs` is the one reference table with **no `organization_id`
> column at all**. ISO 22301 clause 8.5 does not vary by customer, and a
> per-tenant copy would let one tenant's edit change what a clause means in
> their evidence pack.

**2. Org scoping is `business_unit_id` plus the existing hierarchy, not a new
tree.** Blueprint's `org_node_id` maps to `business_units.id`. `business_units`
is already a self-parented tree with `parent_id`; `entities` is the legal
layer above it. BCMS adds no third tree.

`App\Models\Bcms\Concerns\ScopedToOrgHierarchy` is the shared trait
Orchestration §5 names, and **it resolves nothing itself**. This product already
has the engine: `business_unit_user` (assignments, plural, with
`includes_descendants`), the `rcsa_scope.all_units` permission, and
`App\Support\Rcsa\RcsaScope`, whose own migration says the pivot is
deliberately general and that "a second module needing it should not invent a
second table". BCMS is the second module. The trait delegates unit resolution
and adds one thing: the **null arm**. `RcsaScope::apply()` is a plain `whereIn`
because an RCSA assessment always belongs to a unit; a BCMS row often does not,
and the group BCP must be visible to every branch that has to follow it.

(The engine's namespace is now wrong for what it does. Renaming it is a refactor
of RCSA's call sites, not a Phase 0 decision, and is recorded as a known gap.)

**3. Sites are not org nodes.** `bcms_sites` is a facility register — eight
branches and three data centres in the demo tenant — and a site *belongs to* a
business unit. A branch is both, and carries both ids. Conflating them would
make "the Kano branch" and "the Kano building" the same row, and a fire drill
is about the building while a call tree is about the department.

**4. Every route carries a permission** (development standard §2), declared
once in `App\Authorization\RiskPermissionCatalog`, and the whole module sits
behind the `bcms` feature flag, which aborts 404 rather than 403.

## Consequences

- `TenancyIsolationTest` and `RouteAuthorizationTest` cover BCMS the day the
  first model and the first route land, with no BCMS-specific opt-in.
- A track that needs a different visibility rule raises an ADR. It does not
  write a second trait.
- The `organization_id IS NULL` reference pattern means every BCMS query that
  reads reference data must go through the model scope. A raw
  `DB::table('bcms_exercise_types')` in a service is a review rejection.
