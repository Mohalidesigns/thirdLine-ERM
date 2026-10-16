# Screen spec — `Bcms/Scenarios/Index` (the scenario library)

**Route:** `GET bcms/scenarios` → `bcms.scenarios.index`, `middleware('permission:bcms.exercise.view')`
(browsing the catalogue is exercise-design work, same permission `Programme.jsx` and
`Definitions` already gate on). Generating a variant requires `bcms.exercise.manage`
(reused per ADR 0023 §3 — "no catalogue entry is needed unless the spec argues for one," and it
does not: designing an exercise already implies proposing its scenario). No new permission is
introduced by this spec.

**Route-model-binding note, read before building anything else.** `bcms_scenarios` carries **no
`uuid` column** (confirmed against `database/schema/bcms-manifest.php` — the only columns are
`ai_generated, category, code, created_by, deleted_at, id, is_active, is_system_default,
ladder_level_min, name, narrative, organization_id, regulatory_drivers, suggested_injects,
suggested_objectives, summary, updated_by` plus timestamps). Every other BCMS resource route binds
on a `uuid` via `HasBcmsUuid` (`execution-workspace.md`'s own note). Scenario routes **cannot**
follow that convention and must not invent one — ADR 0023 §1 freezes the schema at one column and
two indexes, neither of them a uuid on this table. `Scenarios/{scenario}` binds on the **numeric
id**. This is the one screen in the module where a numeric id legitimately appears in a URL; state
this explicitly to `frontend-engineer` and `qa-engineer` so `ModuleActionUrlRouteKeyTest`'s
allowlist is updated with the reason recorded here, not treated as a defect to "fix" toward uuid.

**Reads:** ADR 0023 §1 (schema verdict table — no `bcms_scenario_templates` table, system rows are
what this screen browses), §3 (the whole generator contract — brief, output contract, grounding,
execution, content), §2.1 (guardrails every generated row must satisfy), work order §4 (12A-2), §9
(content pack — 10 → 42+ templates, all with injects).

**Siblings read before writing this:** `resources/js/Pages/Bcms/Bia/Index.jsx` (the plain
`filter-bar` / `data-table` shape — **BCMS has no screen that reaches for `DataGrid`, and this one
does not become the first**; the `ai_generated` sky pill on a title cell, copied verbatim);
`resources/js/Pages/Bcms/Exercises/Programme.jsx` (the `advisor.available` / `advisor.reason`
pattern for an AI feature that is off — this screen's "Generate a variant" control uses the exact
same shape, not a new one); `evidence-pack-export.md` (the `useJobProgress` note pattern for a
queued generation, "Assembling section 7 of 19…" — this screen's equivalent is "Drafting the
scenario…", no section count, because there is only one job step to report).

---

## 1. Purpose and the user

A BC officer or facilitator designing an exercise (`bcms.exercise.manage`) opens this to find a
scenario to run, or to generate a locally-grounded variant of one. The **shipped 42+ templates**
(compliance-analyst's content pack, ADR 0023 §3.5) are the everyday path — most visits end with
picking an existing, already-activated template and applying it to an occurrence from the execution
workspace, never touching AI at all. The **generator** is the secondary path, used when nobody wants
to write four to ten timed injects from scratch for a scenario specific to one branch or one recent
near-miss. In the first ten seconds a visitor must see: how many templates exist, whether they can
narrow by category and ladder level, and — only if `bcms.exercise.manage` — whether generating a new
variant is even available today. A viewer without `manage` sees the same catalogue with no generate
control, matching the module's "no apparent means to do the thing you cannot do" rule.

## 2. Layout

`AppLayout`, `PageHeader` title "Scenario library", subtitle "Pre-approved exercise scenarios, and a
way to generate a variant grounded in your own sites and processes." Header action, `can.manage`
only: "Generate a variant" (opens the brief panel, §2.2, or shows the AI-off note in its place).

**Filter bar** (`filter-bar`/`filter-group`, matching `Bia/Index.jsx`): search (name/code),
category (from `ThreatCatalogue`'s families — fire, flood, robbery/armed incident, civil unrest,
fuel/power failure, network outage/connectivity, cyber incident, pandemic — the eight Blueprint §12
families `compliance-analyst` owns the exact labels for), ladder level minimum (`LadderLevel`
options, "Any" default), status (`Any` / `Active` / `Awaiting review` — the last meaning
`is_active = false`, whether system-authored or AI-generated), and a "Mine only" toggle scoping to
`created_by = current user` for a facilitator hunting their own draft among forty tenant variants.

**Table** (`data-table`, one row per scenario):

| Column | Content |
|---|---|
| Name | `cell-title` linking to `scenario-variant-review.md`'s route; `cell-subtitle font-mono` code beneath (`{code}` or `{TEMPLATE_CODE}-V{n}` for a variant); an `ai_generated` sky pill ("AI draft") when set — **never cleared by an edit**, so a template a human has since rewritten still carries it, honestly |
| Category | plain text, from `ThreatCatalogue` |
| Ladder min | `LadderLevel::label()`, e.g. "Drill" |
| Injects | count of `suggested_injects` — "6 injects" or, for the ten legacy rows the content pack has not yet reached, "0 injects — no timed sequence yet" in muted grey, never a bare `0` |
| Status | `badge`: `is_active` → `badge-status-active` "Active"; else `badge-status-pending` "Awaiting review" |
| Origin | "System" (`is_system_default`), "Generated {date}" (`ai_generated`, not yet a tenant edit's overwrite of the label), or "Tenant" (authored by hand) |

Empty rows: "No scenarios match these filters. Clear filters, or generate a variant of one that's
close." (only the second half shown when `can.manage`).

## 2.2 The generation brief panel

Opens inline (a collapsible panel above the table, not a modal — this is a considered request, not
a quick confirm) when "Generate a variant" is clicked. Fields, matching ADR 0023 §3.1 exactly:

- **Starting template** — a searchable select of active scenarios only (system or tenant),
  required. Choosing one shows its category/ladder-level as read-only context beneath the field
  ("Inherited from the template — editable on the draft afterward").
- **Duration** — number input, minutes, 60–480, `form-input` with `min`/`max`, hint "1–8 hours."
- **Sites** — up to three, a multi-select of the tenant's own sites (tenant-bound; used **by name
  only** for grounding, per §3.3 — the panel says so: "Used to ground the narrative in real
  locations. No site data beyond the name and city/state is sent.").
- **Processes** — up to five, multi-select, same grounding note.
- **Notes** — optional textarea, ≤ 500 characters, live counter, "Anything specific you want the
  scenario to test."

Footer: "Generate" (disabled until template + duration are valid), "Cancel" (closes the panel,
discards the form). No draft is created by opening or cancelling this panel — nothing is persisted
until the job actually lands a row (§3.4).

## 3. Every state

- **Loading.** Server-rendered list; filters re-fetch via `router.get(..., {preserveState: true})`.
- **Populated, default view.** As above.
- **Empty catalogue** (should not occur once the content pack ships, but the screen does not assume
  it): "No scenario templates are loaded yet. Ask an administrator to run the reference seeder."
- **Filtered to zero.** §2's empty-row message.
- **AI off by configuration** (any of the three switches — `services.llm.enabled`,
  `bcms_settings.ai_enabled`, `bcms.ai.capabilities.scenario_generator`). "Generate a variant" is
  **shown, not hidden** — matching `Programme.jsx`'s `advisor.available` pattern — disabled, with
  the reason printed beneath in the same small muted line: "Generating a scenario is unavailable:
  {reason}. Browse and apply the existing templates below." The panel never opens. This is a
  deliberate, deployment-level "no" and reads calmly, not as a fault.
- **Generating.** The brief panel is replaced by a compact progress card: `useJobProgress` against
  the `job_run_id` the dispatch response returns, showing "Drafting the scenario… this usually takes
  40–90 seconds" with an indeterminate bar (there is one step, not several, so no "section n of m"
  count). The rest of the table stays usable — a facilitator can keep browsing while a generation
  runs in the background, and a second generation may be started only after this one lands or fails
  (one at a time per user, to avoid a confusing pile of "Drafting…" cards).
- **The local model is not reachable at all** (`ollama serve` is not running, or the endpoint
  refuses the connection outright — the gateway fails fast, typically inside a second or two, before
  the job even reaches the 40–90 second range this screen normally quotes). This is common enough
  on this deployment shape — a self-hosted model that a deployment can stop for maintenance, or
  simply has not started yet — that it earns its own copy rather than folding into a generic
  failure: **"The AI model is not running right now. Ask an administrator to start it, or write the
  scenario by hand below."** The job still writes nothing (§3.4), and this state is reached almost
  immediately, so the progress card should not linger on "Drafting…" for a fast, clean refusal — the
  card transitions to this message as soon as the gateway's own refusal comes back, not after the
  full budget has been waited out.
- **The local model is running but slow, and the request timed out** (the `'long'` budget's 90
  second ceiling — `config('services.llm.budgets.long')` — was reached with no answer, which a busy
  or resource-constrained self-hosted model can do under real load, distinctly from the "not running
  at all" case above). **"The model is taking longer than usual and did not finish in time. You can
  try again, or write the scenario by hand."** This is phrased as ordinary and retryable, never as
  an error a facilitator needs to report — a local model queueing behind another request is expected
  behaviour on this architecture, not a defect.
- **The model answered, but the output could not be used** (malformed or truncated JSON within the
  4,096-token context — ADR 0023 trap 20; the small `granite4:micro` model is more prone to this
  than a larger hosted one). **"The model's answer could not be read. Try a shorter brief, fewer
  sites, or write the scenario by hand."** — the same retry affordance as the two states above, kept
  visually distinct in wording only, not in weight: a facilitator does not need to know *which* of
  the three technical causes occurred, only that nothing was created and what to try next. All three
  above leave **no row written** (§3.4) and the brief panel's fields preserved for a retry or a
  switch to writing the scenario by hand.
- **Refused (validator rejected some or all fields).** The job still lands a row — ADR 0023 §3.2:
  "a rejected field is left empty for the human rather than repaired by guesswork." This screen's
  contribution is a toast/banner on landing: "Draft created, but {n} field(s) need your attention
  before this can be activated." linking straight into `scenario-variant-review.md`, where the
  empty fields and the validator's stated reasons render inline (this screen does not duplicate that
  detail in a list view). This is the one failure-adjacent state where a row **is** written, because
  a partially usable draft the validator has clearly marked up is more useful to a facilitator than
  nothing, per the same ADR line quoted above.
- **A generation already in flight when the page is reopened** (navigated away and back). The
  progress card resumes from the stored `job_run_id` (kept in the page's own state via a query
  param or session-scoped prop) rather than losing the in-flight request — a facilitator switching
  tabs to check something else must not have to re-submit the brief.
- **Applied-to-occurrence** is not a state of this screen — it belongs to the occurrence, not the
  library row (§7, out of scope).
- **Permission-denied.** Standard 403 for `bcms.exercise.view`; the generate control and the panel
  simply do not render for a viewer without `manage`.
- **Server/network error on filter or generate.** Standard Inertia handling; the brief panel's
  typed values are preserved.
- **Degraded network.** See §6.

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Filter/search | `GET bcms.scenarios.index` with query params | None |
| Open the generation panel | Client-side only | None |
| Submit a brief | `POST bcms.scenarios.generate` (dispatches `GenerateScenarioVariantJob` on `config('bcms.queues.sync')`, returns `{job_run_id}`) | None — nothing is created synchronously; there is nothing to confirm yet |
| View a scenario | `GET bcms.scenarios.show({scenario})` (numeric id) | None |
| Cancel the brief panel | Client-side only | None |

No destructive action exists on this screen. Activation, editing and apply-to-occurrence are
`scenario-variant-review.md`'s.

## 5. Accessibility (WCAG 2.1 AA)

- **Filter bar** carries `filter-label` text labels for every control (search, category, ladder
  level, status, "Mine only"), matching `Bia/Index.jsx` exactly — no icon-only filter.
- **The generate button's disabled state states its reason as visible text**, not only in a
  `title` attribute, per the ai-settings precedent this module already follows.
- **The progress card is `role="status"`, `aria-live="polite"`**, announcing state transitions
  ("Drafting the scenario…" → "Draft ready — 2 fields need your attention" or "The draft could not
  be produced") once each, never the indeterminate bar's animation.
- **Table rows are ordinary `<tr>`s with a text-visible status badge** (colour plus label, never
  colour alone), matching the module's badge convention throughout.
- **Keyboard path:** header "Generate a variant" → filter bar controls in visual order → table rows
  (each name cell a real link, reachable and activatable with Enter) → pagination, if present.
  Opening the brief panel moves focus to its first field (Starting template); "Cancel" returns focus
  to the "Generate a variant" button it was opened from.
- **Contrast:** existing `badge-status-active`/`badge-status-pending`, `sky-50/900` (AI draft pill)
  — no new tone introduced.

## 6. Low bandwidth (100 kbps)

- **The table is plain markup, no chart, no thumbnail.** A page of 25–50 rows of short text is a
  few kilobytes over the base layout.
- **The generation job is asynchronous by design** (ADR 0023 §3.4: "a synchronous scenario
  generation request… 40–90 s on the local model. An HTTP request that long is a timeout, not a
  feature"), so a slow connection never holds this screen open on the POST itself — the dispatch
  response is small and immediate, and the 40–90 second wait is spent polling a tiny JSON body
  (`useJobProgress`'s default 2-second interval) rather than one long request. On a stalled poll, the
  same "connection is slow — retrying" note the crisis room uses (§6) applies verbatim, backing off
  after two failures.
- **Filtering is a `GET` with `preserveState`**, not a full page reload — small payload, no
  re-fetch of the base layout.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Row count, per filter | `bcms_scenarios` query, `tenantIncludesGlobal` (system rows plus this tenant's) |
| "Injects" count per row | `count(suggested_injects)` on that row's JSON |
| Status badge | `is_active` column, read as stored |
| Origin label | `is_system_default`, `ai_generated`, `created_by` — the three already on the row, no derivation |
| Generation progress | `useJobProgress` polling `GET risk/jobs/{job_run_id}/progress`, the same `JobRun` primitive `evidence-pack-export.md` already documents |
| "AI off" reason, and which of the four failure states above is shown | A server-provided `{code, message}` pair on the job's terminal `JobRun` row — `code` distinguishes `disabled` (a switch is off), `unreachable` (gateway connection refused), `timeout` (budget exceeded) and `unusable_output` (parse/validation failure before `ScenarioDraftValidator` even runs field-by-field) — **never inferred client-side from an HTTP status or a generic error**, because guessing which of the four occurred from a bare failure is exactly how a slow-but-healthy model gets reported as "off" |

## 8. Deliberately out of scope

- **Editing a scenario's text fields** — that is `scenario-variant-review.md`'s screen, reached by
  clicking through; this list is a finder, not an editor.
- **Activating, or applying to an occurrence** — same, reached via the review screen.
- **A "my drafts" dashboard tile** — the "Mine only" filter on this same screen covers the need
  without a second surface.
- **Deleting a scenario** — no delete action exists in ADR 0023 or the work order; a scenario that
  should never be used again is left `is_active = false` rather than removed, matching the module's
  general preference for a stated state over deletion of a record with any provenance.
