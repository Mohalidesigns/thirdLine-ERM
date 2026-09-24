# Screen spec — `Bcms/Dr/Systems/Index` (the IT disaster recovery register)

**Route:** `GET dr-systems` → `bcms.dr-systems.index`, `middleware('permission:bcms.dr.view')`.
Create/edit: `POST dr-systems` / `PATCH dr-systems/{system}` → `bcms.dr-systems.store` /
`bcms.dr-systems.update`, `permission:bcms.dr.manage`. Route-model-bound on the system's uuid.

**Reads:** `plans/bcms/prompts/PHASE-10-incident-crisis-itdr.md` (Scope → IT Disaster Recovery;
Screens item 4); `docs/bcms/phase-10-incident-clause-map.md` §3.1 (DRP vs BCP — a null runbook is a
gap, not a blank), §3.2 (the tier-mismatch rule — name the process, its BIA reference and both
numbers), §3.3 (cadence inherited from `bcms_processes.regulatory_flags`, resolved in PHP, never
`JSON_CONTAINS`), §3.5 (backup attestation via an audited event, no new table).

**A correction to the prompt, made explicit here because it changes what this screen links to:**
`bcms_dr_systems.application_id` points at **`bcms_applications`**, this product's own internal
application register — there is no separate Enterprise Architecture module (the migration's own
comment: "this product has no EA module (ADR 0001)"). Every reference in this spec to "the linked
application" means that table, not an external system.

**Siblings:** `resources/js/Pages/Bcms/Bia/Index.jsx` (the plain-`<table>`-plus-filters shape this
register reuses verbatim — BCMS has no screen anywhere that reaches for `DataGrid`, and this register
does not become the first); `resources/js/Pages/Bcms/Calendar/Index.jsx`'s compliance view (the
"required / completed / overdue" summary line this register's cadence column echoes).

---

## 1. Purpose and the user

An IT continuity manager, or a BC champion checking their department's systems ahead of an audit,
opens this to answer three questions at a glance: which systems are due a test and are not getting
one, which systems' recovery targets do not actually match what the business needs, and which
backups have not been verified recently enough to trust. This is a register, read most days by
someone scanning for a problem, not composing one — the layout optimises for scanning a list of up to
a few hundred rows, not for the single-system deep dive (that is the DR test record and the system's
own edit form, both linked from here).

## 2. Layout

`AppLayout`, `PageHeader` title "IT disaster recovery register", subtitle "What can fail over, how
fast, and whether that has ever been proven." Header action: "Add a system" (`can.manage`).

**Filters, above the table:** search (name/application), recovery tier (1/2/3…), DR strategy, "Show
only: tier mismatches" toggle, "Show only: overdue" toggle — both toggles map to server-side
predicates, never a client-side filter over an unpaginated list, because the register is expected to
run into the hundreds at a large bank.

**Summary line above the table** (not tiles — this register states its headline facts as one
sentence, matching the Calendar compliance view's own prose-summary convention rather than the
Findings register's tile row, because the two facts that matter here are inherently comparative, not
independent counts): "{n} systems · {m} tier mismatches · {k} overdue for their next test · {j} with a
backup not verified in the last {contact_verification_days-equivalent window}."

**Table columns:** Name (linked to the system's own show/edit view — out of scope, see §8), Linked
application, Recovery tier, RTO target / RPO target, DR strategy, Last test (date, actual RTO vs
target — inline, e.g. "62m / 30m target" in rose if it breached), Next test due (date, rose if past),
Backup verified (relative time, rose past the currency window), and a **Tier mismatch** column that is
either blank or a named badge — **never a bare "mismatch" chip**. Per the clause map's own
instruction ("tier mismatch as a bare badge is not actionable"), the badge's visible text names the
process and both numbers: "30-min RTO needed by {process name} (BIA #{ref}) vs 24h tier" — the badge
itself is the sentence, not a link to somewhere the sentence lives, because this is exactly the kind
of fact a reader must not have to click through to get.

A DR system with **no linked runbook plan** (`failover_runbook_plan_id is null`) shows a small
`amber-50` inline note under its name in the table: "No runbook" — per the clause map's rule that this
is a register entry with no procedure, in the same visual register the call-tree screens use for a
must-reach node with no deputy, not hidden until someone opens the row.

## 3. Every state

- **Loading.** Server-rendered; paginated (`Pagination`, matching `Bia/Index.jsx`).
- **Empty register** (no systems entered yet). "No DR systems recorded yet. Add the first one, or
  check that this deployment's IT DR data hasn't been left in a spreadsheet instead." — never a blank
  table with header row only.
- **Filtered to zero rows.** "No systems match this filter." distinct wording from the true-empty
  state, so a manager filtering to "tier mismatches" does not mistake "none currently" for "the
  register is empty."
- **A system with `recovery_tier` or targets unset.** Renders "not set" in the relevant cells rather
  than a blank — an unset target is a gap to close, not a zero.
- **A system whose linked application has been deactivated** (`bcms_applications.is_active = false`).
  Name renders with a small "application retired" note — the DR system row is not hidden, because a
  retired application's DR arrangement is exactly the kind of stale entry this register exists to
  surface, not to quietly drop.
- **Permission-denied.** Standard 403 for `bcms.dr.view`; `can.manage` absent hides "Add a system" and
  every row's edit affordance.
- **Server/network error.** Standard handling.
- **Degraded network.** See §6.

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Filter / search | `GET bcms.dr-systems.index` with query params, `preserveState` | None |
| Add a system | Opens the create form (a `DynamicForm`-shaped screen or panel, backend-engineer's choice of exact surface; this spec requires only that `application_id`, `recovery_tier`, both targets, `dr_strategy`, `failover_runbook_plan_id` and the backup fields are present) → `POST bcms.dr-systems.store` | None |
| Edit a system | Same form, pre-filled → `PATCH bcms.dr-systems.update` | None |
| Record a backup attestation | `POST bcms.dr-systems.{system}.backup-attestation` (`can.manage` — see the note below on a possible narrower permission) | A short statement-of-fact field, required ("Confirm: backups for this system completed and were verified as usable on {date}") — the statement text becomes the audit-log `after` payload per the clause map's actor-time-statement minimum; no further modal, since the statement field is itself the deliberate act. |
| Open a system's test history | Link to `dr-test-record.md`'s index for that system | — |
| Open the tier-mismatch check | `GET bcms.dr-systems.tier-mismatches` — folded into this same screen as the "tier mismatches" filter toggle rather than a separate page, so a reader never has to choose between two registers for the same fact | — |

**A permission gap worth naming to the backend engineer:** the catalog has no permission narrower
than `bcms.dr.manage` for recording a backup attestation specifically. `bcms.dr.manage` ("Maintain DR
systems, their targets and their runbook links") reads as an edit-the-register grant; an attestation
is closer in spirit to `bcms.dr.test.record` ("Record a DR test result...") in that it is an
operational fact being asserted about a system, not a change to the system's configuration. This
spec gates attestation on `bcms.dr.manage` because that is what exists today, but flags for the
architect/backend-engineer that a bank may reasonably want the person who attests backups to differ
from whoever edits recovery targets — the same separation the module already draws between managing a
DR system and recording its test results. Not blocking; noted so it is a decision, not an oversight.

## 5. Accessibility (WCAG 2.1 AA)

- **The tier-mismatch badge's full sentence is the accessible name of the cell**, not a title/tooltip
  — a screen reader reading the row hears the process, the reference and both numbers as part of the
  row, matching the product-wide rule that nothing evidence-bearing hides behind a hover.
- **"No runbook" is a text note, not an icon-only warning triangle.**
- **Sortable column headers** (if the backend engineer implements sort) use `aria-sort` and a visible
  indicator, not colour alone.
- **Keyboard path:** filters (search, tier, strategy, two toggles) in order → summary line (not
  focusable) → table (each row's name link, then its inline actions) → Pagination.
- **Contrast:** rose for breach/overdue, amber for "no runbook"/approaching-due, matching the module's
  existing severity palette exactly.

## 6. Low bandwidth (100 kbps)

- **Server-side pagination and filtering** — never the whole register fetched and sliced client-side,
  matching `Bia/Index.jsx`.
- **No chart.** The register is entirely tabular text; there is nothing here dense enough to earn even
  the house SVG convention (unlike, say, the year heat grid, which represents genuinely two-
  dimensional density).
- **The attestation form posts a small payload** (a date and a short statement) — no attachment.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Recovery tier, RTO/RPO targets, strategy, last-test actuals | `bcms_dr_systems` columns directly |
| Next test due | Written by the cadence-inheritance service at save/recalculation time (clause map §3.3), from `bcms_processes.regulatory_flags` resolved through `bcms_dependencies`/`bcms_applications` — **read here, never recomputed client-side** |
| Tier-mismatch badge's process name, BIA reference and two numbers | The minimum approved `bcms_bia_assessments.rto_hours` across processes depending on this system's application (via `bcms_dependencies.assessment_id`), compared against `rto_target_hours`/the tier ceiling — computed server-side per clause map §3.2, draft-BIAs excluded |
| Backup-verified currency | `last_backup_verified_at` against the module's configured currency window (`config/bcms.php`, the same pattern `contact_verification_days` already uses) |
| "No runbook" note | `failover_runbook_plan_id is null` |
| Summary line counts | Server-computed aggregates over the same filtered/unfiltered query, not four separate round trips |

## 8. Deliberately out of scope

- **The single-system detail/edit screen's own full layout** — this spec covers the register; the
  create/edit form's exact field arrangement is left to the backend/frontend engineers to build as a
  standard form, since the clause map does not ask for anything beyond the columns listed.
- **The DR ingestion webhook** — machine-to-machine, no screen; see `dr-test-record.md` §4 for where
  ingested results surface.
- **A real invocation's evidence** — never appears in this register (the clause map's §3.4 rule); see
  `dr-failover-failback-record.md`.
- **A `bcms_backup_attestations` table's own history view** — there is no such table (ADR 0020 §4);
  the attestation's evidence is the audit log entry, and this screen shows only the latest
  `last_backup_verified_at`, not a full attestation history browser, which would require a new table
  this ADR deliberately declined to add.
