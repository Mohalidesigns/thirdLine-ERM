# Screen spec — `Bcms/Incidents/Declare` (incident declaration and severity matrix)

**Route:** `GET incidents/declare` → `bcms.incidents.declare-form`, `middleware('permission:bcms.incident.declare')`
(a form, not a stub — the activation criteria and the two reportability questions are shown before
anything is typed, per §1.1/§2.3 below). Submission: `POST incidents` → `bcms.incidents.store`,
same permission. Route-model-bound on uuid thereafter (`GET incidents/{incident}` →
`bcms.incidents.show`, `permission:bcms.incident.view` — the read screen that follows declaration
redirects into the crisis room, `crisis-room.md`, rather than staying here).

**Reads:** `plans/bcms/prompts/PHASE-10-incident-crisis-itdr.md` (Scope → Incident management,
screen 1); `docs/bcms/phase-10-incident-clause-map.md` §1.1 (the lifecycle — detection distinct
from declaration, `detected_at <= declared_at` enforced), §1.3 (the severity/activation matrix,
tenant-configurable over DORA Art. 18 criteria), §2.1 (the "unknown" reportability answer, the
re-prompt rule), §2.3 (fields that must exist at T+0); `docs/adr/0020-...md` §2 point 1 (classifying
personal data IS creating the NDPA notification row — this screen asks the question, the crisis
room is where the answer changing later creates or updates the obligation).

**Siblings:** `resources/js/Pages/Bcms/Bia/Workspace.jsx` (the `blocking[]`/banner pattern reused for
"cannot declare without these fields"); `resources/js/Pages/Bcms/Plans/Show.jsx` (the activation
reason modal this screen's own "why is this a sev1" pattern mirrors); `resources/js/Pages/Bcms/
Calendar/Index.jsx` (the reschedule-modal's "reason before date" ordering, echoed here as "criterion
before severity" — the declaring officer sees *why* a severity applies before ticking it, never the
reverse).

---

## 1. Purpose and the user

Whoever first recognises that something has become an incident — a duty manager, a BC champion, a
SOC analyst — opens this screen at the worst possible moment to be filling in a form: minutes into a
branch flood, an hour into a core-banking outage, the instant a phishing report turns out to involve
customer records. In the first ten seconds they must see: what severity this looks like and why,
what plan(s) that severity would activate, and that they are allowed to answer "unknown" to the two
reportability questions rather than guess. This screen's job is to get a real event captured and a
plan proposed for activation in under two minutes, not to collect a complete record — the crisis room
fills in everything else afterwards.

## 2. Layout

`AppLayout`, `PageHeader` title "Declare an incident", no subtitle chrome — the form starts
immediately below.

**Above the form, always visible, never scrolled past:** a single-line reassurance,
`slate-50` background: "You do not need to know everything yet. `Detected` and `Declared` times and
an initial severity are enough to start; everything else can be added from the crisis room." This is
stated because the clause map's own field list (§2.3) is long, and a form that reads as needing
completion before submission is the wrong shape for the moment.

**Section 1 — What and when.**
- `title` (single line, required).
- `incident_type` select: cyber / power / flood / fire / civil_unrest / supplier / pandemic /
  system / other.
- `detected_at` (datetime, defaults to now, editable backward — the clause map's non-negotiable rule
  that the regulatory clock runs from detection, never declaration, so a late-discovered incident
  must be able to record when it actually started).
- `declared_at` (datetime, read-only, server-stamped at submission — this is "now", not editable,
  because the declaration moment is a fact about this form's own submission, not a field to backdate).
- `business_unit_id`, `site_id` (selects, either optional).
- `impacted_processes` (multi-select against the process register, searchable).

**Section 2 — Severity, shown as a live-scored matrix, not a bare select.** The four bands
(sev1..sev4) render as four cards side by side (stacked on narrow viewports), each showing its
default trigger condition from §1.3 of the clause map verbatim, with a small "default — your
organisation has not customised this" tag when the tenant has not overridden it (clause map §1.3's
closing rule). As the officer answers the fields below, the matching card **highlights itself** and
shows *which* trigger fired ("A critical service is forecast down past its BIA RTO" — named, not just
"sev1 selected"); the officer can still override the suggested card, but overriding one requires the
same short reason field the crisis room's later re-grading uses (§1.3's "raised or lowered only with
a reason" rule applied from the first moment, not just after declaration) — because a severity
picked without a stated reason is exactly how, per the clause map, "a sev1 becomes a sev3 by the time
the board sees it."

**Section 3 — The two reportability questions, answered explicitly, "unknown" a real button, not a
placeholder:**
1. *Is this a cyber/operational incident that may be reportable to the CBN?* — Yes / No / **Unknown**.
2. *Does this involve personal data?* — Yes / No / **Unknown**.

Each question shows one line of guidance beneath it in muted text (clause map §2.3): the CBN one
notes "reported within 24 hours of detection where yes"; the personal-data one notes "answering yes
starts the NDPC's 72-hour clock from now — you can change this later without losing time, because the
clock runs from when you first became aware, not from when you confirm it." Answering **Unknown**
does not block submission — the crisis room's countdown panel then shows a standing prompt to
resolve it (§3 of `crisis-room.md`), because the clause map requires the re-prompt rather than a
one-off skip.

**Section 4 — Activation, shown but never forced.** If a `bcms_plans` row with `plan_type = bcp` (or
`drp`, if the incident type is IT-flavoured) matches the chosen business unit/process, its activation
criteria render as a read-only quoted block — "the conditions that justify declaration, shown to the
declaring officer at the moment of decision" (prompt's own words) — with a checkbox "Activate this
plan on declaration" (default checked when the matched severity's activation level is `partial` or
`full`, unchecked otherwise, always overridable). No matching plan: "No continuity plan is linked to
this process yet. Declare the incident anyway — a plan can be activated from the crisis room once one
exists."

**Footer:** one button, "Declare incident", full width on mobile, large — this is a single-purpose
screen and a second competing action would slow the one that matters.

## 3. Every state

- **Loading.** Server-rendered; the severity matrix's highlighted card recomputes client-side against
  server-supplied thresholds (never a second copy of the matrix logic — the thresholds and the
  currently-matched band both arrive as props, and the client only re-evaluates the *comparison*
  against what the officer has typed, not the rule set itself).
- **No plan matches the selected process/unit.** Section 4 shows the "no continuity plan is linked"
  message above; declaration is not blocked by it.
- **Severity overridden from the suggested band.** The reason field appears inline, required,
  minimum length matching the crisis-room re-grade form so the two never diverge.
- **Validation error.** Per-field, `role="alert"`, beneath the control — title, detected_at (must be
  ≤ now and ≤ declared_at), and the override-reason field are the three checked server-side.
- **Submit succeeds.** Redirects straight into the crisis room (`bcms.incidents.show`), which is
  where every subsequent action happens — this screen is never revisited for the same incident.
- **Submit fails (network).** Standard Inertia handling; every typed field is retained.
  Section 2's severity selection and Section 3's answers (including "Unknown") persist exactly as
  set — an officer re-submitting after a dropped connection must not have to re-answer whether they
  know if personal data is involved.
- **Permission-denied.** Standard 403; a user without `bcms.incident.declare` who holds
  `bcms.incident.view` sees the incidents list (a future register screen, not specified here — see
  §8) rather than this form, with no link into it.
- **Degraded network.** See §6.

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Declare incident | `POST bcms.incidents.store` | None — declaring is not itself irreversible; the incident can be cancelled from the crisis room with its own reason, and declaring under-informed is exactly the case this screen is built to accept rather than block. |
| Override the suggested severity | Client-side toggle, submitted as part of the same `POST` | None to toggle; the reason field is required by the server, not merely the client. |
| Answer a reportability question `Unknown` | Client-side, submitted as part of the same `POST` | None — this is the point of the option. |
| Toggle plan activation | Client-side checkbox, submitted as part of the same `POST`, which (when checked) additionally calls the same service `bcms.plans.activate` uses internally with `incident_id` set — **not `is_exercise`**, because nothing that reaches this route is a drill (a crisis simulation runs through the exercise engine, per `execution-workspace.md`'s own rule that a live-looking incident screen is never how a drill is run) | None beyond the single Declare submit — activation is bundled into the one action, not a second confirm, because splitting "declare" and "activate" into two clicks at this exact moment is the friction the prompt's "one-click plan activation" line is written against. |

No numeric id is ever spliced into a URL from this screen: the redirect target is a server-built
`Location` to `bcms.incidents.show` with the new incident's uuid, matching `ModuleActionUrlRouteKeyTest`.

## 5. Accessibility (WCAG 2.1 AA)

- **The severity matrix is not colour-coded to the exclusion of text.** Each of the four cards
  carries its band name, its default trigger sentence and (when matched) the word "Suggested" as
  visible text, plus a border-and-background treatment — never a colour dot alone.
- **The matrix's live "this is why" line is `aria-live="polite"`**, so a screen-reader user typing
  into the fields above hears which card just became suggested, without every keystroke re-announcing
  the whole matrix.
- **"Unknown" is a real, labelled third option in each reportability question's radiogroup** — not a
  greyed-out default state a screen reader would skip past. `aria-label="CBN reportability: unknown"`
  etc.
- **Keyboard path:** reassurance line (not focusable) → Section 1 fields in order → Section 2's four
  matrix cards as a `radiogroup` (arrow-key navigable, matching the observer-scoring screen's existing
  pattern) → the override-reason field (only in the tab order when shown) → Section 3's two
  three-option radiogroups → Section 4's activation checkbox and quoted criteria (criteria text itself
  not focusable — it is read reference material, not a control) → Declare button.
- **Contrast:** the four severity cards use the module's existing severity palette (sev1 rose, sev2
  amber, sev3 slate, sev4 slate-lighter) at the already-audited tint/ink pairing used on
  `Findings/Index.jsx`'s severity chips — no new shade introduced.

## 6. Low bandwidth (100 kbps)

- **The whole screen is one page load, one submit.** No autosave, no partial-save endpoint — a
  half-typed declaration on a bad connection is not worth persisting server-side when the entire form
  fits in one browser tab's memory and Inertia's own failed-request state retention already protects
  it.
- **The severity matrix and activation-criteria text are static markup**, no chart, no icon beyond the
  product's existing glyph set — this screen costs the same, in bytes, as any other text form in the
  product.
- **On submit failure, the button reads "Retry — nothing was lost"** rather than reverting to its
  default label, so an officer on a flaky connection at a flooded branch is not tempted to assume the
  incident was never recorded and declare it twice.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| The four severity bands' default trigger text | `config/bcms.php` (or the tenant's `bcms_settings` override once one exists) — shown with the "default" tag when unmodified, per clause map §1.3 |
| The suggested severity | Computed server-side against the officer's typed answers (critical-service status from the process register, the CBN 30-minute Open Banking threshold, life-safety flag) and returned as a prop the client only compares against, never re-derives |
| The matched plan's activation criteria | `bcms_plans.activation_criteria` (or the plan's stored text field of the same purpose) for the plan matched on business unit/process and `status = approved` |
| `detected_at`/`declared_at` | User-entered / server-stamped at submission, written to `bcms_incidents` |

## 8. Deliberately out of scope

- **The incidents list/register screen** (browsing past incidents, filtering by status/severity) —
  a separate screen this phase's backend needs but which the prompt does not name explicitly among
  its five; not specified here, and the crisis room links out to individual incidents by uuid only,
  never by a bare index this document defines.
- **Editing an already-declared incident's core facts** (title, type, detected_at correction after
  the fact) — that is a crisis-room action (`crisis-room.md`), logged as a decision entry, not a
  re-run of this form.
- **The severity matrix's tenant-configuration screen** (editing the default triggers themselves) —
  an administration screen, not specified here.
- **Cancelling a declared incident** (a false alarm) — a crisis-room action with its own reason and
  confirmation, not this screen's concern.
