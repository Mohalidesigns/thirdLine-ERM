# ADR 0011 — The Phase 3 plan-binding columns, and the table Phase 3 did not add

**Status:** Accepted · **Date:** 2026-09-08 · **Phase:** BCMS Phase 3 · **Author:** architect
**Requested by:** backend-engineer (lead, Phase 3) · **Broadcast to:** Tracks B, C, D, E

## Context

ADR 0009 closed with a challenge to whoever wrote this file:

> If Phase 3 needs a third, the architect should ask whether the freeze is
> holding or whether Phase 0 under-specified.

So: the freeze is holding. This is the third ADR against it and the smallest —
**five columns, no new tables, nothing renamed, nothing dropped.** The trend is
the right way round: fifteen changes in Phase 1, eight in Phase 2, five here.

More usefully, the question forced a rejection that would otherwise have been a
sixth change. Phase 3's criterion 7 wants per-reader acknowledgement of a plan,
exportable as clause 7.4 evidence, and the obvious move was a
`bcms_plan_acknowledgements` table. It was drafted — `plan_id`, `user_id`,
`acknowledged_at`, a snapshotted name and role, an IP address, a unique pair —
and then compared against `bcms_plan_attestations`, which Phase 1 built and
which has every one of those columns for the same reason. Its docblock talks
about boards; its *schema* says "a dated, signed attestation of a plan", and a
read-acknowledgement is exactly that. The `attestation_type` column exists to
tell kinds apart and already documents itself as `board|executive|owner`.

A second table would have been a second export path, a second retention rule and
a second answer to "who has signed what", built because the first table's
comment mentioned directors. **Reader acknowledgement is
`attestation_type = 'read'`.** ISO 22301 clause 7.4 is the clause ref on those
rows; clause 5.2 stays on the board ones.

That reuse also gets the annual cycle for free. The unique key is
`(plan_id, attestation_type, period_year, attested_by)`, so re-acknowledging a
plan in a new year records a new act rather than overwriting last year's — which
is what an annual re-attestation cycle needs and what a bare
`unique(plan_id, user_id)` would have quietly prevented.

## Decision

### `bcms_plans`

| Column | Why |
|---|---|
| `review_frequency_months` (unsigned small int, nullable) | The phase prompt names it beside `effective_from` and `next_review_date`, and the two existing columns cannot produce it. Without a frequency, `next_review_date` is retyped by hand at every supersession, and criterion 6's stale list becomes a list of the plans somebody remembered to date. Nullable: a plan with no declared cycle gets no review date rather than a cycle we invented (development standard §5). |
| `distribution_rule` (json, nullable) | Criterion 7 is a **percentage** — "3 of 12 acknowledged" — and a percentage needs a denominator. The attestation rows are the numerator; this is the population. It holds an `AudienceRule` in the grammar frozen by ADR 0003 and resolved by the existing `AudienceResolver`, so the distribution list is the same object the EMNS console will target in Phase 7 rather than a second way of saying "the crisis team". A `bcms_plan_distributions` join table was the alternative and would have had to be re-resolved by hand every time somebody joined a department. |

### `bcms_plan_sections`

| Column | Why |
|---|---|
| `last_verified_at` (timestamp, nullable) | The prompt: bound sections render live **with a "last verified" stamp**. A rendered figure with no date beside it is the laminated binder this module exists to replace. Null means never assembled, and the screen says so rather than printing today's date. |
| `source_fingerprint` (string 64, nullable) | The drift mechanism, and the reason criterion 2 is testable rather than aspirational. It is a SHA-256 of the canonical payload the binding resolved to at the last assembly. Drift detection is then a re-resolve and a string compare — no diffing, no per-source change tracking, and no observer on eight upstream tables. The alternative was `updated_at` watermarks on every bound source, which cannot see a change that reverts, cannot see a *deleted* dependency at all, and needs new code in every module Phase 3 binds to. |
| `needs_review` (boolean, default false) | What the fingerprint comparison writes, and what the builder highlights. |

## What this ADR deliberately does not add

**A `review_required` column on `bcms_plans`.** It is
`EXISTS (SELECT 1 FROM bcms_plan_sections WHERE plan_id = ? AND needs_review)`.
Storing it creates two facts that can disagree, and the one that would be wrong
is the one the dashboard reads.

**A `bcms_plan_templates` table.** The twelve templates are a code content pack
(`App\Support\Bcms\PlanTemplates`), the same shape as `App\Support\Bcms\ModuleSections`.
A template is consumed once, at plan creation, and from that moment the plan owns
its sections; nothing reads a template again. A table would be a tenant-editable copy of code
that nothing downstream depends on, plus a seeder to keep it current, plus a
migration every time a standard changes. If a customer asks to author their own
templates, that is a table and an ADR then.

**Anything for the offline bundle.** `offline_bundle_generated_at` and
`offline_bundle_path` came out of Phase 0 (see that migration's own comment,
which anticipated exactly this). The bundle is assembled from live data at
request time; it is a file, not a schema.

**Anything for plan activation.** `bcms_plan_activations` is complete and Phase 3
only builds its service and read views. Phase 10 writes to it.

## Consequences

- The manifest is regenerated in the same commit, as ADR 0008 established.
- `bcms_plan_attestations` now carries two kinds of record. `PolicyService::attest()`
  keeps its `board` default and its clause 5.2 ref; the acknowledgement path is a
  separate method with its own statement and clause ref. Anything that counts
  attestations **must filter on `attestation_type`** — a board-attestation count
  that silently includes every branch manager who ticked "I have read this" is a
  governance number that is wrong.
- No column another track reads has changed. Nothing needs rebasing.
- Three ADRs in four phases, shrinking each time, and this one net-removed a
  table from the design. The freeze is doing its job: it is not stopping changes,
  it is making each one get argued.
