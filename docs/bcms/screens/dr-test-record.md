# Screen spec — `Bcms/Dr/Tests/Show` and `Index` (the DR test record)

**Routes:** `GET dr-systems/{system}/tests` → `bcms.dr-systems.tests.index`,
`permission:bcms.dr.view` (per-system test history, linked from the register). `GET dr-tests/{test}`
→ `bcms.dr-tests.show`, same permission. `POST dr-systems/{system}/tests` → `bcms.dr-systems.tests.
store`, `permission:bcms.dr.test.record`. Ingestion (machine-to-machine, no screen):
`POST dr-tests/ingest/{provider}` → `bcms.dr-tests.ingest`, authenticated per Phase 7's inbound
token contract, **not** behind an Inertia permission — see §8.

**Reads:** `docs/bcms/phase-10-incident-clause-map.md` §3.4 (a real invocation is never written
here — the boundary this screen exists to hold), §3.6 (ingested provider results — idempotent on
`(provider, external_test_id)`, `met_objectives` never accepted from the provider); ADR 0020 §3
("provider DR results go in `bcms_dr_tests.evidence` (json). No table.").

**Siblings:** `resources/js/Pages/Bcms/Findings/Index.jsx` (a DR-breach test raises a finding exactly
the way this register already produces one for other sources — this screen's "raise a finding" inline
control matches that form precisely, not a redesign); `docs/bcms/screens/aar-builder.md` §2, section 4
(the "computed, never a typeable checkbox" treatment for `threshold_breached`, reused here verbatim
for `met_objectives`' breach state).

---

## 1. Purpose and the user

An IT continuity engineer records the outcome of a **planned** DR exercise — a failover drill, a
backup-restore test, a tabletop walkthrough of the runbook — right after it finishes, or ingests the
result automatically from Zerto/Veeam/Azure Site Recovery. A compliance officer later opens the same
record to check whether the objective was met and, if not, what corrective action followed. The one
fact this screen must never blur: **this is for a test that was planned and ran on a schedule.** An
unplanned real outage does not get a row here — see `dr-failover-failback-record.md` for that case —
and this screen's own empty and create states say so, rather than leaving it to the reader to infer.

## 2. Layout

**Index** (`bcms.dr-systems.tests.index`), reached from the register: `AppLayout`, `PageHeader` title
`Test history — {system.name}`. A plain table (matching `Bia/Index.jsx`'s convention, no `DataGrid`):
test date, type (failover / failback / backup_restore / tabletop / component — chip, text always
shown), actual vs target RTO/RPO (side by side, rose if breached, matching the AAR builder's "target
beside the actual, never the actual alone" rule), met objectives (chip), rollback required (chip,
only shown when true — a false value is the unremarkable case and does not need a chip at all,
following the same "a zero must read as a fact, not decoration" instinct applied to a boolean instead
of a number), source (Recorded manually / Ingested from {provider}).

An **"unpaired failover" banner** at the top of the index, shown when the most recent `test_type =
failover` row for this system has no corresponding `failback` row within a reasonable window (the
backend engineer's own threshold, e.g. the same period a customer's runbook specifies): "A failover
with no recorded failback is half a test. `DRFAILBACK` is a distinct type — record it once failback
runs." — the clause map's own line, rendered as guidance rather than left implicit.

**Show** (`bcms.dr-tests.show`), one test's full record: test date, type, target vs actual RTO/RPO
with the arithmetic shown ("62 min actual vs 30 min target — breached by 32 min", matching the AAR
builder's DR-metric presentation exactly), `met_objectives` (**computed and displayed, never a
typeable field** — see §4), `rollback_required`, issues (a short list), notes, evidence (see below),
and — where the row came from an ingestion — a read-only "Ingested" panel: `provider`,
`external_test_id`, `received_at`, and a link to view the raw payload (collapsed by default, a plain
`<pre>` of the stored JSON, never re-formatted into something that could disagree with what was
actually received).

**Evidence.** `bcms_dr_tests.evidence` is JSON, holding both a human-entered evidence list (file
references — see the note in §8 on the shared evidence gap) and, for ingested rows, the provider
payload described above. This screen renders the two halves as separate sub-sections labelled
distinctly — "Recorded evidence" and "Provider data" — so a reader never mistakes a vendor's raw
webhook dump for something a human attested to.

**Below the record, for a test with `met_objectives = false`:** an inline "Raise a finding" control,
identical in shape to `Findings/Index.jsx`'s own raise form, source pre-set and `dr_test_id` implied
by context — the producer relationship the clause map's stamping table specifies
(`iso22301.8.5.exercise` where the test has an `occurrence_id`, otherwise `.8.4.5`).

## 3. Every state

- **Loading.** Server-rendered.
- **Index, no tests recorded for this system yet.** "No DR tests recorded for this system. A tested
  system is one with at least one row here — an untested recovery tier is a claim, not a fact." —
  deliberately pointed language, matching the module's convention that an empty register is not
  treated as automatically good news.
- **Show, a manually recorded test.** As above; `met_objectives` computed from stored
  `rto_actual_minutes`/`rpo_actual_minutes` against the system's targets **at the time the test was
  recorded** (the target the test is judged against is captured on the row itself if the backend
  snapshots it, or read live from the system if it does not — the backend engineer's choice, stated
  in the record either way so a later change to the system's target cannot silently reclassify an old
  test's verdict).
- **Show, an ingested test with `met_objectives` pending our own judgement.** Per the clause map,
  the endpoint never accepts the provider's own claim about whether the objective was met — so an
  ingested row lands with `met_objectives = null` until a `bcms.dr.manage`/`.test.record` holder
  reviews the actual-vs-target figures and confirms. This state renders as: "Received from {provider}
  on {received_at}. Objective met: **awaiting review** — confirm against the figures above." with a
  Yes/No confirmation control, not a silent auto-pass. This is the one write action on an otherwise
  read-only ingested record.
- **A breach** (`met_objectives = false` or actual exceeds target on either metric). The relevant
  figures render in rose with the arithmetic stated, matching §2.
- **Unpaired failover.** The index banner (§2); the show screen for that failover test additionally
  carries the same note in a small footer line.
- **Permission-denied.** Standard 403 for `.view`; the record form and the finding-raise control do
  not render without `.test.record` / `bcms.finding.manage` respectively.
- **Degraded network.** Standard handling; no polling anywhere on this screen — a test record is
  written once, after the fact, not watched live (a live DR failover in progress is tracked through
  the exercise workspace if it is running as a scheduled exercise occurrence, per Phase 9's own
  screen, not duplicated here).

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Record a manual test | `POST bcms.dr-systems.tests.store` | None |
| Confirm an ingested test's `met_objectives` | `POST bcms.dr-tests.{test}.confirm-objectives` (name per convention) | None beyond the Yes/No choice itself — this is a judgement call recorded once, not a destructive action. |
| Raise a finding from a breached test | `POST bcms.findings.store` (existing route, `dr_test_id` implied, `source` pre-filled) | None, matching `Findings/Index.jsx`'s own lack of confirm on raise. |
| View raw ingested payload | Client-side disclosure | None |

## 5. Accessibility (WCAG 2.1 AA)

- **`met_objectives` is never a checkbox the user could mistake for editable** on an ingested row
  pending confirmation — it renders as text ("Objective met: awaiting review") with a genuinely
  separate Yes/No control beneath it, so a screen reader does not announce a checked/unchecked state
  that looks like a stored fact before a human has confirmed one.
- **Breach figures state the arithmetic in text**, not colour alone: "62 min actual vs 30 min target —
  breached by 32 min" is the full accessible content of that cell.
- **The unpaired-failover banner is a landmark region**, first in the DOM on the index, so a screen-
  reader user does not have to scan the whole table to learn a failback is missing.
- **Keyboard path (index):** filters (if any) → unpaired-failover banner (not focusable, informational)
  → table rows, each linking to its show page. **(show):** record fields in reading order → evidence
  sub-sections (each a heading) → confirm-objectives control (ingested-pending only) → raise-a-finding
  form.
- **Contrast:** rose/amber for breach and pending states, matching the module's existing severity
  palette exactly.

## 6. Low bandwidth (100 kbps)

- **No polling anywhere on either screen** — a test record is a historical write, read back later,
  never a live console.
- **The raw ingested payload is collapsed by default** and rendered from data already in the page
  response, not a second request, so expanding it costs rendering time on a slow device, not bytes on
  the wire.
- **No chart** — target-vs-actual is two numbers and a sentence, not a bar; the module's existing tier-
  progress bar convention is for a proportion across many items, not a single before/after pair, and
  is not reached for here.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Target RTO/RPO | `bcms_dr_systems.rto_target_hours` / `.rpo_target_minutes` at the time of judgement (or a snapshot on the test row, backend engineer's choice, stated in the record) |
| Actual RTO/RPO | `bcms_dr_tests.rto_actual_minutes` / `.rpo_actual_minutes`, entered manually or from the ingested payload |
| `met_objectives` | Manual test: computed from actual vs target at the moment of save, shown as read-only, never a typed checkbox (mirrors the AAR builder's `threshold_breached` rule exactly). Ingested test: `null` until a human confirms, per the clause map's "never accepted from the provider" rule |
| Ingestion metadata | `bcms_dr_tests.evidence` JSON — `provider`, `external_test_id`, `received_at`, raw payload, content hash (ADR 0020 §3) |
| Unpaired-failover flag | Computed by comparing the most recent `failover` test's date against any `failback` test recorded afterward for the same system, within the runbook's own stated window |

## 8. Deliberately out of scope

- **A real invocation's evidence** — structurally impossible on this screen: there is no path from an
  incident into `bcms_dr_tests`, per ADR 0020 §4 ("`DrTestService` gets no path from an incident").
  See `dr-failover-failback-record.md`.
- **The ingestion webhook's own authentication and retry behaviour** — an integrations-engineer
  concern, following `docs/bcms/phase-7-inbound-token-contract.md`; this spec only covers what the
  human-facing screen shows once a payload has landed.
- **Human-uploaded evidence artefacts with a hash, lock and permissioned download** — the same gap
  `execution-workspace.md` §7 names for exercise evidence; a DR test's "Recorded evidence" sub-section
  shows whatever the backend can attach today (an evidence kind added to `bcms_evidence`'s local
  registry per ADR 0019 §1's open door) and this spec does not invent an upload control ahead of that
  decision.
- **Editing a confirmed `met_objectives` verdict** — no un-confirm path; a wrong confirmation is
  corrected by a new finding and a corrected note, not by silently flipping the flag back.
