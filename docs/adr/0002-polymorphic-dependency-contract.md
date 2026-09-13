# ADR 0002 — The polymorphic dependency contract

**Status:** Accepted · **Date:** 2026-09-07 · **Phase:** BCMS Phase 0 · **Author:** architect
**Consumers:** Track A (BIA), Track B (exercise scope), Track D (DR tiers)

## Context

`bcms_dependencies` records what a business process needs in order to run:
applications, vendors, sites, people, equipment, data sets and other processes.
Blueprint §9.1 specifies seven types. Seven types across four owning modules is
exactly the shape that produces string-typed morphs — `dependable_type =
'App\Models\Tprm\ThirdParty'` written into a customer's database, and then a
namespace rename in a later refactor quietly orphaning ten thousand rows.

## Decision

**A registered morph map with seven short keys, and no class names in the
database.** Registered in `AppServiceProvider::boot()` via
`Relation::enforceMorphMap()` — *enforce*, not `morphMap`, so an unregistered
class throws at write time instead of silently persisting an FQCN.

**One map for the whole application.** `Relation::enforceMorphMap()` takes a
single map and a second call replaces the first, so the seven types are
registered in `App\Support\MorphTypes` beside every other morph in the
product, not in a BCMS-local map.

**Which means the stored keys are not the blueprint's words.** Blueprint §9.1
writes `applications`, `vendors`, `users` and so on; `MorphTypes`' convention is
singular snake_case, and `users` would be a *second* alias for a class that
already has `user`. `getMorphClass()` returns the first match, so two aliases
for one class is a coin toss written into customer data. The blueprint's names
survive as the enum case names.

| Blueprint name | Stored alias | Class | Owner |
|---|---|---|---|
| `applications` | `bcms_application` | `App\Models\Bcms\Application` | BCMS (seam — see ADR 0001) |
| `vendors` | `tprm_third_party` | `App\Models\Tprm\ThirdParty` | TPRM |
| `sites` | `bcms_site` | `App\Models\Bcms\Site` | BCMS (seam) |
| `users` | `user` | `App\Models\User` | platform (alias pre-existing) |
| `equipment` | `bcms_equipment` | `App\Models\Bcms\Equipment` | BCMS (seam) |
| `data_sets` | `bcms_data_set` | `App\Models\Bcms\DataSet` | BCMS (seam) |
| `processes` | `bcms_process` | `App\Models\Bcms\Process` | BCMS |

`bcms_process` is prefixed because `business_process` is already in the map and
means the organisation's own process catalogue, which is a different object
(ADR 0001).

`vendors` resolves to `ThirdParty`, the legal entity, **not** `Engagement`.
TPRM's own rule is that risk is assessed at the engagement; a BIA dependency is
a different statement — "this process cannot run without Interswitch" — and it
survives the engagement being renegotiated or re-papered. Where an assessor
needs the engagement, `bcms_dependencies.external_ref` carries it.

`App\Enums\Bcms\DependencyType` is the single declaration of the seven types.
`Phase0FoundationsTest` asserts that its map is a subset of `MorphTypes::map()`
with identical classes, so the two cannot drift apart.

## Consequences

- A class rename does not touch customer data.
- `Phase0FoundationsTest::every_dependency_type_round_trips()` asserts all
  seven resolve, which is Gate G0 acceptance criterion 6.
- Repointing `applications` at a future EA model is a one-line change in the
  enum plus a data migration of `dependable_id`, not a rewrite.
- A morph type BCMS does not own (`vendors`, `users`) must be read through a
  null-safe accessor. A vendor soft-deleted in TPRM leaves a dependency row
  pointing at nothing, and the BIA screen must say "vendor no longer in the
  register", not blank.
