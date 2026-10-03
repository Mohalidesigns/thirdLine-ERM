# Screen spec — `Bcms/Identity/Review` (the sync change review queue)

**Route:** `GET bcms/identity/runs` → `bcms.identity.runs.index`, `GET bcms/identity/runs/{run}` →
`bcms.identity.runs.show` (run-picker landing on one run's queue), `POST bcms/identity/runs/{run}/
changes/{change}/decide` → `bcms.identity.changes.decide`, `POST bcms/identity/runs/{run}/changes/
decide` → `bcms.identity.changes.bulk-decide` — all four `middleware('permission:
bcms.identity.review')`. `{run}` binds by **uuid** (`HasBcmsUuid`); `{change}` is a numeric nested
child under `->scopeBindings()` (ADR 0018 §2.4, work order §5) — **every per-row and bulk action
URL on this screen is a server-built prop**, never `route()`/`tryRoute()` called with a numeric id
in JSX, per `tests/Feature/ModuleActionUrlRouteKeyTest.php`. The presenter supplies `decide_url`
per change row and one page-level `bulk_decide_url`; the screen never constructs either.

**Reads:** `docs/adr/0018-bcms-phase-2c-is-entra-only.md` §2.3, §2.4, §3.3, §5, §6 (criteria 1–3);
`docs/bcms/phase-2c-entra-work-order.md` §5, §8. This document does not restate the schema; it
specifies the states, the interactions and what a person must never be able to do (bulk-approve a
change nobody has actually looked at, per §3.3's unconditional rule).

**Siblings read before writing this:** `resources/js/Pages/RcsaUniverse/ImportPreview.jsx` (the
tabs-over-one-staged-batch shape, the tile row of counts, the "nothing has been written yet" framing,
and the gated-publish pattern this screen's gated bulk-approve is modelled on directly — a batch
whose error rows block a blanket publish is the same shape as change rows that require
acknowledgement blocking a blanket approve). `resources/js/Pages/Bcms/Bia/Campaigns.jsx` (the
`can.manage`-gated action buttons, the "0 has a sentence, not a bare number" rule for counts like
`unassigned`). `app/Services/Bcms/CallTrees/BrokenBranchAnalyser.php` (the exact sentence shape the
call-tree impact panel below reproduces: name, what happened, how many were left unreached — never
the descendant count).

---

## 1. Purpose and the user

A `bcms.identity.review` holder — the BC Coordinator (Blueprint §13's `$riskManager` persona) —
opens this screen after a nightly sync, most often from the connector screen's "Review changes
({n} pending)" link, sometimes from a scheduled digest (out of scope for this phase — no SMTP,
ADR 0018 Consequences). In the first ten seconds they must see: **which run** they are looking at,
**how many changes of each kind** are waiting, and **which of those, if approved without reading,
would break something** — a call-tree node, a saved audience, an escalation path. This is
day-to-day continuity work, not administrator authority (ADR 0018 §7) — the review queue is
deliberately **not** filtered per business unit, because a leaver's downstream can sit in another
unit and a change set half-approved per unit leaves the roster inconsistent with the directory
(ADR 0018 §7). Nobody outside the bank ever sees this screen.

## 2. Layout

`AppLayout`, `PageHeader` title "Directory sync — review changes", subtitle
"{connector.name} · run {run.started_at, local} · {run.trigger_label}". `PageHeader` actions: a
`btn-secondary` link back to `bcms.settings.identity` labelled "Connector settings", and a run
picker (a plain `<select>` of the last 10 runs by `started_at desc`, each option labelled
"{started_at} — {status} ({pending_count} pending)") that navigates to `bcms.identity.runs.show`
for the chosen run on change — mirroring `ImportPreview.jsx`'s tab-navigation-via-`router.get`
pattern rather than a client-side filter, since each run's changes are a materially different
server response.

**Above the fold:**

1. **Run status banner**, one of:
   - `status = running`: `slate-50`, "This sync is still running — {directory_objects_read} read
     so far. Come back once it finishes; changes cannot be reviewed mid-run." No queue renders
     below; this state is terminal for the page (§3).
   - `status = failed`: `red-50`/`900`, "This run failed — {error_class}. {n} change(s) from
     before the failure are still listed below and can be reviewed; nothing after the failure was
     read." (Never a provider message.)
   - `status = partial`: `amber-50`/`900`, "This run completed with some pages unread —
     {error_class} partway through. What was read is below; a later run will pick up the rest."
   - `status = success`: no banner.
2. **Superseded-hidden note** (plain text, not a banner — informational, always shown when any
   superseded rows exist for this run): "{n} change(s) from this run were superseded by a later
   sync before anyone reviewed them and are hidden. [Show superseded]" — a disclosure, not a
   default-visible tab, because a superseded row is by definition stale and reviewing it would be
   reviewing a fact that is no longer true (ADR 0018 §2.4).

**Tile row** — five tiles, same shape as `ImportPreview.jsx`'s summary tiles: Joiners, Leavers,
Movers, Contact changes (each showing this run's count, tone-coded — grey for joiners/movers,
amber for leavers, blue for contact-changes, matching no pre-existing colour claim so a new tone
is not invented: joiners/movers plain `text-gray-800`, leavers `text-amber-700` since a leaver is
the case most likely to need attention, contact-changes `text-blue-700`), and **Needs
acknowledgement** (`text-red-700`, the tile this screen exists to make impossible to miss).

**Tabs**, same shape as `ImportPreview.jsx`'s status tabs: All / Joiners / Leavers / Movers /
Contact changes, each showing its own count badge, `pending` decision only by default (superseded
and already-decided rows are a separate, collapsed view — see below).

**The queue** — a table (not `DataGrid`: this is one run's staged output, read start-to-finish in
one sitting by one reviewer, and a full grid's column-configuration chrome is exactly the
`ImportPreview.jsx`/`CallTrees/Live.jsx` precedent for choosing a plain table over `DataGrid` for a
bounded, single-purpose list). Columns: a **row-select checkbox** (disabled and unchecked, greyed,
for any row with `requires_ack = true` — see Interactions), **Person** (`subject_name`, plus a
small "existing contact" vs "new" indicator for joiners), **Kind** (chip: Joiner grey / Leaver
amber / Mover blue / Contact change slate), **Before → After** (a compact two-line diff of the
changed fields only — not the whole record — pulled from `before_json`/`after_json`), **Call-tree
impact** (see below), **Decision** (Pending / Approved / Rejected / Auto-applied badge), and a
per-row **Approve** / **Reject** button pair (visible only while `decision = pending`).

**Call-tree impact cell**, the load-bearing element for leavers and movers whose `impact_json` is
non-empty:

> "**{subject_name}** {left / is moving}; {he/she/they} {was/were} **Tier {n}** in the
> **{tree_name}** tree with **{downstream_blocked_count}** downstream staff."

— the exact sentence shape `BrokenBranchAnalyser::headline()` already proves lands with a bank
(Phase 6 notes §2, referenced by the execution-workspace spec). `downstream_blocked_count` here is
`ImpactAssessor`'s computation of the same kind — people actually left unreached by the *current
approved tree*, never the raw descendant count (ADR 0018 §3.3's bullet list) — and the cell also
states any other impact reasons that set `requires_ack` (sole member of a must-reach node or named
deputy; static member of a saved group; a dynamic group's resolved count reaching zero; membership
in a reminder-ladder or alert audience), each as its own short line, because a leaver can trip more
than one of these at once and a reviewer needs to see all of them, not just the first that matched.
A row with an empty `impact_json` shows "No call-tree or audience impact" in plain grey text — the
unmarked, common case for most contact-changes and many joiners.

**`requires_ack` rows** are visually separated — a red-tinted left border and a small "Needs
acknowledgement" tag on the row, matching the red tile above — and sit in their own visual band
within each tab rather than interleaved, so a reviewer scanning top-to-bottom sees the easy
decisions first and the ones that need a moment's thought grouped together, without a second page
load.

**Bulk action bar**, sticky above the table on scroll: a "select all pending in this view" checkbox
(which — see Interactions — can never select a `requires_ack` row), a live count "{n} selected",
and **Approve selected** / **Reject selected** buttons, both disabled when the selection is empty.

## 3. Every state

- **Never synced (no runs exist for this organisation).** The run picker is empty and the page
  shows a single centered message: "No sync has run yet. Configure and run the connector from BCMS
  settings, then come back here to review what it finds." with a link to `bcms.settings.identity`.
  No tiles, no tabs, no table.
- **Run in progress.** Banner 1's first case. The queue does not render at all for this run — the
  page shows the banner and nothing else beneath it, because a change row created mid-run is not
  yet a complete fact (ADR 0018 §3.3: a run's output is only meaningful once `finished_at` is set).
- **Run failed / partial.** Banners as above; whatever changes were staged before the failure are
  reviewable exactly as a completed run's would be — a partial result is still real, staged data.
- **Empty queue, run succeeded, zero changes.** "This sync found no changes — the roster already
  matched the directory." Not an error; a stable estate producing nothing to review is the
  expected steady state after the first few syncs.
- **All changes already decided.** Tabs show 0 pending in each kind; a plain note replaces the
  table: "Every change from this run has been decided. [Show decided changes]" behind a disclosure
  — kept out of the default view because a queue is a worklist, not an audit log (the audit trail
  itself is §7).
- **Loading.** Server-rendered Inertia page; no client fetch on first paint. Run-picker navigation
  is a full page visit (`preserveScroll: false` — a new run's queue starts at the top).
- **Submitting a per-row decision.** The row's Approve/Reject buttons both disable the instant
  either is clicked (not just the one clicked — a double-click on Reject while Approve is mid-flight
  must not be possible), and the row shows "Deciding…" in place of the buttons until the response
  returns.
- **Submitting a bulk decision.** The bulk bar's buttons disable, label changes to "Approving
  {n}…"/"Rejecting {n}…"; the table's selected rows show a matching inline "Deciding…" state so a
  reviewer scrolled past the sticky bar can still see which rows are in flight.
- **Validation/authorisation error on a decision** (e.g. a `requires_ack` row was somehow submitted
  in a bulk payload — the tenant-bound `Rule::exists` scoped to the run catches this server-side
  even though the UI already prevents it). Renders as a small inline error on the affected row(s),
  not a page-level failure — the rest of the batch's outcome, if partially applied, is reflected in
  each row's own Decision column rather than assumed all-or-nothing.
- **Server/network error.** Standard Inertia error handling; selection state is not cleared on a
  failed bulk request, so a reviewer does not have to re-select thirty rows because the connection
  blipped.
- **Permission-denied.** A user with `bcms.identity.manage` but not `bcms.identity.review` (the two
  are deliberately independent grants, ADR 0018 §7) gets the product's standard 403 on direct
  navigation; there is no link to this screen from anywhere such a user can reach.
- **Degraded network.** See §6.
- **Concurrent reviewers.** Two BC Coordinators may both hold this screen open on the same run.
  Each decision is atomic per row server-side; if reviewer B approves a row reviewer A already
  decided a moment earlier, the response for B's action returns the row's actual current state
  (already decided, by whom) rather than silently double-applying it — the row re-renders showing
  who decided it, and B's own action shows a plain "Already decided by {name}" note rather than an
  error, since this is an expected outcome of shared review, not a fault.

## 4. Interactions and their server actions

| Action | Route (server-built prop) | Confirmation |
|---|---|---|
| Approve one change | `row.decide_url`, `POST {decision: 'approved'}` | None for a row with no impact. For a row with `impact_json` present but `requires_ack = false` (impact exists but was assessed as safe — e.g. a contact-change with no downstream reach implications), still none: the impact panel is informational, and `requires_ack` alone is what gates approval weight, not the mere presence of any impact text. |
| Reject one change | `row.decide_url`, `POST {decision: 'rejected'}` | None — rejecting is reversible in the sense that the next full run will re-detect the same fact and raise it again (ADR 0018 §2.4's `superseded` mechanic is what prevents duplicate rows, not what prevents re-raising a genuinely still-true change). |
| Acknowledge and approve a `requires_ack` row | Same `row.decide_url`, but the button reads **"Acknowledge and approve"**, not "Approve", and requires the reviewer to have read the impact panel — enforced by rendering the impact panel **expanded, not collapsed**, for every `requires_ack` row (never behind a disclosure a reviewer could approve past without opening) | A lightweight inline confirm text appears beside the button once impact is present: "This will re-parent {downstream_blocked_count} people's call-tree branch." No modal — the impact panel itself, always visible, is the confirmation; a modal restating what is already on screen would be ignored the second time a reviewer sees it that shift. |
| Reject a `requires_ack` row | Same `row.decide_url`, plain "Reject", no extra confirmation — rejecting never applies the change, so there is nothing irreversible to warn about. |
| Bulk approve selected | `bulk_decide_url`, `POST {decision: 'approved', change_ids: [...]}` | **A `requires_ack` row's checkbox is `disabled` unconditionally** (ADR 0018 §5, work order §8) — a reviewer cannot tick it at all, never mind include it in "select all"; the select-all control's own label states this: "Select all pending in this view (acknowledgement-required rows are not included)". This is a client-side control only, so the server does not trust it: `IdentitySyncController::bulkDecide()` re-partitions the submitted `change_ids` by `requires_ack` itself and refuses the whole batch's `requires_ack` members rather than accepting a mixed selection — a `change_ids` array built outside this screen (curl, an API client, a differently-written front end) is exactly the case this guards, not merely a defensive redundancy against the disabled checkbox. The ordinary (non-`requires_ack`) ids in the same batch still apply; the response's `error` flash names which ids were withheld and how many did apply, and the refusal itself is audited on the run (`identity.sync.bulk_decide_refused`) even though nothing about the withheld rows changed. Per-row `decide()` (see above) remains the only route that may apply a `requires_ack` change. |
| Bulk reject selected | `bulk_decide_url`, `POST {decision: 'rejected', change_ids: [...]}` | None beyond the button's own "{n} selected" label — rejecting is the non-destructive direction. |
| Switch run (picker) | Plain navigation, `router.get` to `bcms.identity.runs.show` for the chosen run's uuid | None — a dirty selection (checked rows) is discarded on navigation; nothing on this screen is unsaved *data*, only unsaved *selection*, so no unsaved-changes warning is needed. |
| Show superseded / Show decided (disclosures) | Client-side toggle, no request | None. |

**"Applied" is not an action on this screen — it is a consequence.** Approving a change (with or
without acknowledgement) triggers the write path (`ChangeApplier`) server-side within the same
request; the row's Decision column updates to `Approved` and, once the write completes,
to `Auto-applied`-styled but labelled distinctly as **"Applied"** (never conflated with the
`auto_applied` decision value, which is reserved for `safe_only` policy's own unattended writes —
a manually approved-then-applied row shows "Approved · applied {timestamp}" so an examiner reading
this screen later can tell a human decision from a policy-driven one). If the write itself fails
(a constraint violation, a since-deleted business unit), the row shows "Approved, not yet applied —
{error_class}" in amber, distinct from both a pending and a fully-applied row, and remains
actionable only through a later run or manual remediation outside this screen's scope.

## 5. Accessibility (WCAG 2.1 AA)

- **The run status banner is the first DOM element**, not focusable, read before anything else —
  a screen-reader user reaches "this run is still running" before encountering a queue that (in
  that state) does not exist.
- **Tabs are a proper tablist** (`role="tablist"`/`role="tab"`/`aria-selected`), each tab's
  accessible name including its count ("Leavers, 12") so a screen-reader user does not need to
  cross-reference the tile row separately.
- **The queue is a real `<table>`** with `<caption>` "Changes from this run, {n} pending" and
  `<th scope="col">` headers. Each row's checkbox has an accessible name including the person's
  name and kind ("Select: Musa Bello, leaver") — never a bare, unlabelled checkbox — and a
  `requires_ack` row's checkbox is `disabled` with `aria-describedby` pointing at a note ("Requires
  individual acknowledgement — use the row's own Approve button") **and** that same note rendered
  as visible text beside the disabled checkbox, per the disabled-control rule already established
  in `docs/tprm/screens/ai-settings.md` and `docs/bcms/screens/identity-connector.md`.
- **The call-tree impact sentence is plain text inside the row**, read in table-cell order by a
  screen reader exactly as a sighted reviewer reads it — no icon-only impact indicator exists
  anywhere on this screen; every impact is the full sentence or "No call-tree or audience impact".
- **Approve/Reject buttons carry the subject's name in their accessible name** ("Approve: Musa
  Bello" / "Reject: Musa Bello"), matching the injects-release-button rule in
  `docs/bcms/screens/execution-workspace.md`, so a reviewer tabbing rapidly through many rows in a
  large leaver batch hears which row they are about to act on.
- **"Acknowledge and approve" is never abbreviated to "Approve" for a `requires_ack` row at any
  contrast or viewport**, including 360px — the longer label is the accessibility-relevant content,
  not decoration, so it does not get truncated by a responsive layout rule.
- **The bulk-approve button's dynamic label** ("Approve {n} selected, including {k} needing
  acknowledgement") is inside the same `aria-live="polite"` region as the selection count, so a
  screen-reader user selecting rows hears the count change without needing to re-focus the button;
  this is the one live region on the page besides the run-in-progress poll indicator, deliberately
  narrow for the same alert-fatigue reason stated in the connector spec.
- **Keyboard path**: run picker → run status banner (not focusable) → superseded-note disclosure
  toggle → tile row (not focusable, each tile's label+value read as one phrase, "Leavers, 12") →
  tabs → bulk "select all" checkbox → bulk Approve/Reject buttons → queue rows in order, each row's
  checkbox then its impact panel (not focusable, plain text) then its Approve/Reject buttons →
  "Show decided changes" disclosure.
- **Contrast**: the four kind chips and the red `requires_ack` border/tag follow the existing
  tone system exactly (`*-700`/`*-800` text on `*-50`/white, never gold as body text).

## 6. Low bandwidth (100 kbps)

- **The page is one Inertia response per run selected** — switching runs is a full navigation, not
  a client-side re-fetch of a cached list, so the payload for a run with a large change set is
  paginated server-side (standard product pagination, matching `ImportPreview.jsx`'s `rows.data`/
  `rows.links` shape) rather than loading every change row for a 200-person sync at once.
- **No background poll on this screen.** Unlike the connector screen, nothing here updates itself
  while idle — a review queue is worked deliberately, not watched, so there is no 5-second tick to
  design around; a reviewer who wants a fresher view re-navigates or reloads. Concurrent-reviewer
  conflicts (§3) are resolved at the moment of action, not by polling ahead of it.
- **Decisions are small requests.** A per-row decide is a handful of bytes; a bulk decide sends
  only the array of selected `change_id`s, never the full row payload back to the server.
  Approving one hundred rows in bulk is one request, not one hundred.
- **No image or chart asset.** The impact panel is text; nothing here is dense enough to earn even
  the house SVG convention.
- **On a stalled decision request**, the row (or bulk bar) stays in its disabled "Deciding…" state
  rather than reverting, so a reviewer on a slow link is not tempted to click Approve twice and
  risk two writes racing (mitigated further server-side by the idempotent decision-state check in
  §4's "already decided" case).

## 7. Audit visibility

Every decision — per-row or bulk, approve or reject, acknowledged or not — writes an audit row
through `BcmsAuditable`: `identity.change.approved`, `identity.change.rejected`,
`identity.change.auto_applied` (ADR 0018 §3.4). This screen does not render the audit trail
itself (that is the product's existing audit-log viewer, unchanged by this phase), but the
Decision column's "Approved · applied {timestamp}" / "Approved, not yet applied" / "Auto-applied"
labels are themselves drawn from the same facts the audit row records, so what this screen shows
and what an examiner later pulls from the audit trail are the same underlying event, worded for two
different readers.

## 8. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Run picker entries, `pending_count` per run | `bcms_identity_sync_runs`, latest 10 for this organisation |
| Tile counts (joiners/leavers/movers/contact-changes) | `bcms_identity_sync_changes` grouped by `kind`, this run, `decision = pending` |
| "Needs acknowledgement" tile | Same table, `requires_ack = true and decision = 'pending'` |
| Before → After diff | `before_json` / `after_json` on the change row — only the mapped, changed fields, never the whole Graph object (ADR 0018 §2.4) |
| Call-tree impact sentence, tree name, tier, downstream count | `impact_json` on the change row, computed at run time by `ImpactAssessor` using `BrokenBranchAnalyser`'s own downstream-blocked logic — never recomputed at read time (ADR 0018 §3.3: recomputing against the current estate would let an unrelated tree edit silently change what a pending change means) |
| Decision badges | `decision` column, plus `decided_by`/`decided_at`/`applied_at`/`apply_error_class` for the expanded states |
| Superseded count | `bcms_identity_sync_changes` this run, `decision = 'superseded'` |
| Run status banner facts | `bcms_identity_sync_runs.status`, `.error_class`, `.directory_objects_read`, `.finished_at` |

**No number on this screen is a cost or a currency, and none stands in for "not yet computed."** A
run with zero changes reads as the sentence in §3, never as a bare "0" beside an unlabelled tile.

## 9. Out of scope

- **Any digest or email notification pointing at this queue** — no SMTP exists yet (ADR 0018
  Consequences); a reviewer reaches this screen only by navigating to it.
- **Per-business-unit filtering of the queue** — deliberately absent, not merely unbuilt (ADR 0018
  §7).
- **The contact directory screen** — Phase 2D; this screen shows only what one run changed, never
  a full roster listing.
- **Editing a staged change's proposed values before approving** — a reviewer approves or rejects
  what the sync detected; correcting a wrong value is a `Manual`-sourced edit made elsewhere (the
  contact-editing surface, itself Phase 2D scope) after the fact, not an inline edit here.
- **Verification campaigns, consent capture, My Emergency Profile** — Phase 2D.

## HANDOFF

**Phase:** BCMS Phase 2C (reduced) — Entra ID identity sync, screens (ADR 0018 §5, work order §8)
**Agent:** ui-designer
**Status:** Complete — spec ready for implementation once the API surface lands
**Files written:**
- `docs/bcms/screens/identity-change-review.md`

**Decisions this spec makes that are not restated elsewhere:**
- The queue is a plain paginated `<table>`, not `DataGrid` — matching the `ImportPreview.jsx`/
  `CallTrees/Live.jsx` precedent for a bounded, single-purpose staged-review list rather than a
  general-purpose grid.
- **Superseded by the shipped build (recorded here rather than left stale — phase-2c-notes.md
  §"Ruling corrected"):** this spec originally proposed letting a reviewer manually select a
  `requires_ack` row into a bulk batch, with the button label changing to name the count. The
  shipped screen instead disables that row's checkbox unconditionally — the stricter reading of
  ADR 0018 §3.2/§5 — and `IdentitySyncController::bulkDecide()` refuses (and audits) any
  `requires_ack` id that reaches it anyway, applying only the ordinary ids in the same batch. §4's
  bulk-approve row above reflects the shipped behaviour, not this original proposal.
- "Applied" is presented as a state distinct from both `approved` and `auto_applied` in the UI's
  own vocabulary, even though the underlying `decision` enum only has the latter two plus
  `pending`/`rejected`/`superseded` (ADR 0018 §2.4) — the screen reads `applied_at`/
  `apply_error_class` alongside `decision` to construct this, rather than the backend needing a
  new enum value.

**Next agent:** integrations-engineer (lead) for `app/Services/Bcms/Identity/*`, then
backend-engineer for the models/controllers/routes/presenter this spec assumes (in particular
`decide_url`/`bulk_decide_url` as server-built props, never a numeric id constructed in JSX), then
reliability-engineer for the queue/schedule, then **frontend-engineer** to build
`resources/js/Pages/Bcms/Identity/Review.jsx` against this spec and its sibling
`docs/bcms/screens/identity-connector.md`.

**Screens this phase still owes:** none — both screens named in ADR 0018 §5 are now specified.
