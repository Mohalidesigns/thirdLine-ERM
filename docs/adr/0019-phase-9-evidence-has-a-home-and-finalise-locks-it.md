# ADR 0019 — Phase 9 evidence gets one BCMS table, and "immutable on finalise" means a hash and a lock

**Status:** Accepted · **Date:** 2026-09-13 · **Phase:** BCMS Phase 9 (Execution workspace & AAR) · **Author:** architect
**Requested by:** compliance-analyst, `docs/bcms/phase-9-aar-clause-map.md` §5, refinements 1, 4, 7 and 12
**Consumers:** backend-engineer **(lead)** · ui-designer · frontend-engineer · qa-engineer · code-reviewer · Phase 10 (PIR evidence) · Phase 11 (board pack reads the export) · Phase 12 (offline/PWA capture)

## Context

The compliance-analyst's Phase 9 input found the one thing the G0 freeze missed:
**there is no evidence table.** `bcms_exercise_scores.evidence_file_id` and
`bcms_readiness_tasks.evidence_file_id` are unconstrained `unsignedBigInteger`
columns pointing at no table, `ReadinessController` validates one as a bare
integer, and the prompt's "photos from mobile, files, screenshots" have nowhere
to go. Criterion 8 — "finalising an AAR makes its evidence artefacts immutable" —
has nothing to make immutable.

What the product already has, checked rather than assumed:

| | |
|---|---|
| `App\Services\FileUploadService` (WP-11) | **The** upload primitive. `DISK = 'local'` as a constant with no override, one `rules()` definition shared by the Form Request and the store, content-detected extension (`getMimeType()`, never the client's header or filename), uuid filenames, traversal-proof `download()`, named per-endpoint profiles. |
| `2026_08_20_090000_relocate_public_attachments_to_private_disk.php` | Why that constant exists: control-test evidence and bulk imports were written to the `public` disk and served at `/storage/...` with no session, no permission and no tenant check. |
| `tp_documents` / `App\Models\Tprm\Document` + `Tprm\Evidence\EvidenceService` | Tenant-scoped, uuid, soft-deleted, audited, `hash` (sha256 of the **stored** bytes), `mime`, `size`, guarded state, a **local** `owner_type` kind registry deliberately kept out of the enforced morph map, supersession, 5-minute signed downloads. The pattern to copy. |
| `loss_event_attachments`, `issue_attachments`, `control_test_evidence` | Three per-parent tables. **None carries `organization_id` or `BelongsToOrganization`** — each is scoped only through its parent — none has a content hash, and none has a polymorphic owner. `DocumentRepositoryController` is a read-only aggregator over them, not a model. |
| `EvidenceService::scan()` | `return 'pending';` — the documented no-virus-scanner gap. |

So there is **no** tenant-scoped generic file model in this product to link to.
There is one upload primitive worth reusing, and one evidence table worth
copying.

## Decision

### 1. `bcms_evidence` — one new BCMS table, and the reuse is the *service*, not the table

Files go through `FileUploadService` with a new profile. Rows go in
`bcms_evidence`. There is no link table to `tp_documents` and no second upload
path.

**Why not a `bcms_evidence` link table over an existing file model.** The
coordinator's preferred option required an existing model that is tenant-scoped
and on the private disk. The three ERM-core tables are neither generic nor
tenant-scoped, so linking to them is not available. `tp_documents` *is*
tenant-scoped and private — and is still the wrong home:

1. **It would write BCMS state into `tp_audit_logs`.** `Document` uses
   `TprmAuditable`. ADR 0007 deviation 6 puts BCMS state changes in
   `bcms_audit_logs`, and the BCMS watchdog counts failures there. A BCMS artefact
   audited into TPRM's trail is invisible to every BCMS evidence query.
2. **Its semantics are a vendor's certificate, not a photograph.** `issuer`,
   `scope_text`, `valid_to` (which drives assurance decay and the ×0.7
   scope-mismatch modifier), `document_type_id` → `tp_document_types`,
   `extraction_status` → TPRM's AI pipeline, `uploaded_via` → the vendor portal.
   An assembly-point photo has an issuer of nobody and an expiry of never.
   Borrowing the table means every one of those columns is null on BCMS rows and
   every TPRM query must learn to exclude a kind it never expected.
3. **It inverts the ownership rule.** TPRM owns third parties, contracts,
   obligations and the vendor portal. It does not own "files". Making BCMS a
   client of TPRM's evidence table couples the continuity module's retention,
   screens and permissions to the third-party module's.

**Also rejected: a json `attachments` column**, which is the shape
`bcms_incident_log.attachments` already has. A json array of paths has no hash,
no uploader, no lock, no permission surface and no route — which is to say it
cannot satisfy criterion 8 and cannot be shown to an examiner.

```
bcms_evidence
  id, uuid                              routed by uuid (HasBcmsUuid) — a download link is
                                        shared; a numeric id enumerates the estate
  organization_id                       FK, BelongsToOrganization
  occurrence_id                         FK bcms_exercise_occurrences, NOT NULL — the anchor, §4
  owner_type (20), owner_id             local kind registry: occurrence | score | readiness_task | aar
  kind (20)                             photo | file | screenshot
  caption (255, nullable)
  file_name (190), file_path (255), mime (120), size (unsigned big int)
  hash (64)                             sha256 of the bytes AS STORED
  captured_at (nullable)                when it was taken; defaults to upload time
  iso_clause_ref                        DoD: every evidence-bearing artefact carries one
  uploaded_by, locked_at, locked_by, timestamps, softDeletes
  index (organization_id, occurrence_id) · (organization_id, owner_type, owner_id) · (organization_id, hash)
```

Six things this table deliberately does **not** have:

1. **No `disk` column.** There is one disk by construction
   (`FileUploadService::DISK`). A stored disk name is an invitation to a second.
2. **No `virus_scan_status`.** TPRM's is a stub that returns `pending` for ever,
   and a column that always says `pending` is the "green tick from a mock" this
   module has twice refused to ship. Scanning belongs to `FileUploadService` —
   one place, every module — and when it arrives it arrives there. Until then the
   controls are the real ones: a content-detected extension allowlist, a size
   cap, a non-web-served disk, and downloads only through a permissioned route.
3. **No system-generated evidence, and `file_path` is NOT NULL.** "System
   generated data" is a **reference, not a copy**: the call-tree scorecard, the
   delivery rows and the DR output are first-class records already, and
   `quantitative_results.sources[]` (the analyst's `bcms.aar.quantitative.v1`
   schema) names table and row id. Copying them in here would be a second,
   unschematised store for facts that would then be able to disagree with their
   originals.
4. **No `owner_type` in the global morph map.** A local kind registry with a
   `ownerModels()` map, exactly as `Document` and `tp_waivers.waivable_type` do
   and for the reason `Document`'s docblock gives: the enforced morph map is a
   global namespace and `score` or `aar` in it would claim names the wider
   product may want.
5. **Only four kinds, all of which reach an occurrence.** `call_tree_test` and
   `incident` are **not** in the Phase 9 registry: a call-tree test may exist with
   no occurrence, so it would give the table a second anchor path, which ADR 0017
   rule 2 says is a modelling question rather than an `orWhere`. They arrive with
   the phase that needs them, together with the answer.
6. **No supersession chain.** A re-shot photograph is a new row (§2). Versioning
   evidence matters when a later version invalidates a citation, which is a TPRM
   problem, not this one.

**Profile.** `FileUploadService::PROFILE_BCMS_EVIDENCE` —
`jpg, jpeg, png, gif, pdf, docx, xlsx, csv, txt`, 20 MB. Wider than TPRM's
(images are the *point* here) and capped above the 10 MB default because a
12-megapixel photograph of a sign-in sheet is a few megabytes and a scanned
attendance register is more.

> **Named trap for the ui-designer and frontend-engineer: an iPhone's default
> photo format is HEIC, and `MIME_EXTENSIONS` has no `image/heic` entry.** A
> photo taken at an assembly point on a default-configured iPhone will be
> **refused by validation**, which at 3G on a fire drill reads as "the app is
> broken". Either the capture control converts to JPEG client-side (`<input
> type="file" accept="image/jpeg,image/png">` plus a canvas re-encode), or
> `image/heic` is added to `FileUploadService` — a **product-wide** change that
> needs the `finfo` build on the deployment target verified first. Decide it in
> the screen spec; do not discover it at the demo.

### 2. "Immutable on finalise" means a verified hash and a lock, concretely

| | |
|---|---|
| **Before finalise** | Evidence may be uploaded and soft-deleted by the uploader or a holder of `bcms.exercise.facilitate`. |
| **At `AarService::finalise()`** | Every `bcms_evidence` row for the occurrence is **hash-verified first**. Any row whose stored bytes no longer match `hash`, or whose file is missing, **refuses the finalisation** and is named in the message — condition 12 alongside the analyst's eleven. Then every row gets `locked_at`/`locked_by`, in the same transaction as `status = final`. |
| **After finalise** | No update and no delete of a locked row, by any route: the destroy and update actions answer with a flash (standard §3's lifecycle-as-flash), never a 403, and the refusal writes an audit row — criterion 8 asks for "refused **and logged**". There is no hard-delete path anywhere in the phase. |
| **The other artefacts** | Timeline entries, scores, injects and participant attendance for that occurrence are frozen while `status = final`, refused in their services, not by a trigger. |
| **Re-opening** (analyst refinement 7, adopted) | Requires `bcms.aar.approve`, writes a `system` timeline entry with the stated reason, clears `approved_by`/`approved_at`, retains the distribution history in the audit log, and requires re-approval **and re-distribution**. |
| **Re-opening does not unlock evidence.** | New evidence may be **added** to a re-opened AAR — a new row, with its own uploader and timestamp. Existing rows stay locked. Re-opening a report is not a licence to change the photographs, and "the file was replaced after the report was signed" is the one thing an evidence pack must be able to deny. |

The hash is what makes this a claim rather than an assertion: `locked_at` alone
says the application declined to edit the row; `hash` says the bytes are the
bytes. Both go in the export (§5), neither is fillable.

**Rejected: a database trigger or an append-only table.** The product has one
append-only, hash-chained trail (`tp_audit_logs`) and it is TPRM's; adding a
second mechanism for four tables in one phase is a second thing to get right.
The lock is checked in the service that owns the write, and the guard is a test.

### 3. The two orphan `evidence_file_id` columns are retired, not dropped

They are **not** made into a foreign key. An FK would force one artefact per
score, when an observer scoring "assembly complete in 5 minutes" attaches three
photographs; the polymorphic owner (`owner_type = 'score'`) is the link.

So: nothing writes them, the Form Requests stop accepting them, and a guard test
asserts no `create()`/`update()`/`fill()` in BCMS names either column. In
particular `ReadinessController`'s bare-integer validation of `evidence_file_id`
is removed in this phase — it accepts an arbitrary caller-supplied id into a
column with no referent, which is the same defect family as standard §4's bare
`exists:`, and Phase 9 is the phase that gives evidence a real home so it is the
phase that stops the fake link.

**They are not dropped in this phase.** Dropping is itself a structural change
with a rollback that loses data, and a nullable column nothing writes costs
nothing. They stay in the manifest, marked; a single cleanup migration may drop
both once a release has passed with nothing referencing them. Retiring in
place and dropping later are two decisions, and only the first is needed now.

### 4. Visibility: evidence is a **derived** model under ADR 0017

`occurrence_id` is NOT NULL precisely so there is exactly one anchor path:
`orgAnchorPath()` = `occurrence.definition`, the same path `ExerciseParticipant`
and `ReadinessTask` take. `bcms_evidence` uses `BindsToVisibleRecord` when Phase
7.5 lands and is **not** in the organisation-level pinned map.

That is not bookkeeping. Assembly-point photographs of identifiable staff are
personal data under the NDPA with a need-to-know test, and a uuid in a URL is
exactly how another division reads them. Routes are nested under the occurrence
with `->scopeBindings()`.

**Permissions: none added.** Upload requires `bcms.exercise.facilitate` or
`bcms.exercise.evaluate` — the two roles actually present at an exercise. Read
and download require `bcms.exercise.view` plus the visibility filter above. The
export is `bcms.report.export`, as every other BCMS export is.

### 5. The AAR export endpoint (analyst refinement 12, adopted)

`GET bcms/occurrences/{occurrence}/aar/export`, `permission:bcms.report.export`,
named `bcms.occurrences.aar.export` — the shape `occurrences.deliveries.export`
and `bia-report.export` already use. Modelled on
`App\Services\Bcms\Emns\EvidenceExport`: **built from stored values, computing
nothing**, because an evidence pack that disagrees with last month's copy of
itself is worse than none.

Contents: the AAR's nine sections; the full timeline; scores with evaluator and
snapshotted objective text; the attendance reconciliation; the readiness
overrides; `quantitative_results` including `sources[]` and `not_measured[]`;
every finding with its severity, clause ref and CAPA status; the carried-action
dispositions; and **an evidence index — filename, kind, caption, uploader,
captured_at, sha256, lock state — not the bytes.**

The index rather than a bundle, for two reasons: zipping 340 assembly
photographs is a different feature with a different cost, and it would be
unusable on the 100 kbps link the DoD requires screens to work on. The bytes are
fetched individually through the permissioned download route, and the hash in the
index is what lets an examiner prove the file they were given is the file that
was locked. Two renderings, as Phase 3 did for plans: JSON for machines and a PDF
through `ThirdLine\Reporting\DocumentRenderer`.

Without this endpoint the phase is not evidence-sufficient — the analyst holds
that gate — and Phase 11's ISO and CBN packs would have to reassemble Phase 9's
evidence, making "in one click" into "a human assembled it".

### 6. `review_required` and `needs_review`: no columns, and the analyst's §3.4 is adopted as written

| The prompt asks for | What Phase 9 does |
|---|---|
| flag the plan `review_required` | **No column, and none is added.** Set `needs_review = true` on the specific `bcms_plan_sections` rows proved wrong, through Phase 3's own service; raise the finding with `affected_plan_id` and `iso22301.8.4.4`. `PlanDriftDetector::needsReviewQuery()` already surfaces the plan. A plan-level flag would say the whole plan is suspect when one section is, and ADR 0011 already settled that review state is derived. |
| flag the BIA assessment for reassessment | **The finding is the flag.** `affected_process_id` + `iso22301.8.2.2`, surfaced on the BIA screen as an open finding against the process. Moving an approved assessment's `status` would destroy the governance fact — the approval happened — which is the same argument ADR 0013 made for call-tree staleness and Phase 3 made for approved plans. |
| a failed call-tree branch → a data-quality CAPA | Raised against the `call_tree_test_id` that actually ran. Phase 6 deliberately refuses a finding with nothing to point at. |
| the outcome moves the resilience KRIs | Registered through the KRI module as Phases 4, 5 and 6 did. Phase 11 aggregates and registers nothing that exists. |

**`FindingSeverity` (refinement 4): approved, and it needs no ADR of its own.**
`bcms_findings.severity` is an existing column; an enum over an existing string
column is not a structural change. Cast it on the model — an uncast enum column
is a shipped BCMS defect family (`verification_status = 'failed'`).

## What this ADR deliberately does not do

- **It does not create a second upload path.** Anything that accepts a file in
  BCMS calls `FileUploadService`. The WP-11 post-mortem exists because two
  endpoints grew their own.
- **It does not add a virus-scan column, a `disk` column, or a supersession
  chain.** See §1.
- **It does not touch `bcms_aars`.** `status`, `approved_at` and `distributed_at`
  already carry the lifecycle; re-open history goes to the audit log and a
  `system` timeline entry, which is where the *reason* can live.
- **It does not build a register or a verify path.** `bcms_findings`,
  `bcms_corrective_actions`, their service, the register screen and
  `actions/{action}/verify` shipped in Phase 1 (analyst §3.1). Phase 9 is a
  producer and owns exactly one column nobody else writes,
  `carried_to_occurrence_id`. A second register is an automatic review rejection
  under Orchestration §5.
- **It does not open an `/api/v1/bcms/...` surface** (refinement 2). Routes go in
  `routes/web.php` behind `feature:bcms` like every other BCMS route (ADR 0007
  deviation 2). Read the DoD's "endpoints match Blueprint §10 exactly" as "match
  the shipped convention".
- **It does not decide Phase 10's evidence.** The kind registry has room for
  `incident`, and whether `bcms_incident_log.attachments` is then retired is
  Phase 10's question, not this one — with the note that a json path array cannot
  hold a hash, a lock or a permission.
- **It does not write the NDPA register entry** (refinement 13): photographic
  evidence of identifiable staff, observer commentary naming individuals, and
  free-text participant comments. compliance-analyst owns
  `docs/compliance/ndpa-register.md`, and the DoD's personal-data line is not
  satisfiable until it lands.

## Consequences

- **Freeze delta: 1 new table, 0 columns on existing tables, 0 new indexes on
  existing tables, 2 columns retired in place.** The line reads 15 (P1) → 8 (P2)
  → 5 (P3) → 1 (P4) → 0 (P5) → 1 (P6) → 0 (P7) → 0 (P7.5) → 2C (3 tables, 1
  column, 1 index) → **P9 (1 table, 0 columns)**. `bcms:verify-schema --write`
  regenerates the manifest in the same commit as the migration.
- **Criterion 8 becomes testable in both directions**: an edit and a delete after
  finalise are refused and audited, and a file whose bytes changed under a
  finalised AAR is detectable rather than merely disallowed.
- **Two shipped endpoints change shape slightly.** The readiness-task completion
  and score-write Form Requests stop accepting `evidence_file_id`. No tenant can
  have relied on it: it referenced nothing.
- **Phase 12's offline capture has a target.** A PWA queueing photographs from an
  assembly point with no signal writes `bcms_evidence` rows on reconnect through
  the same service; `captured_at` is why the column exists and why it is not
  derived from the upload timestamp.
- **The HEIC question is open and owned by the screen spec.** It is the most
  likely way this phase fails in front of a customer, and it fails at the
  moment of capture, on a phone, during a drill.

---

## Note, 2026-09-23 — the check-in method values

`bcms_exercise_participants.check_in_method` is a plain `string(20)` with no
enum. The Phase 0 migration's comment lists `qr|sms|manual|geo`. Phase 9 writes
four values: `qr` (the participant opened their own token link), `code` (the
short code typed into the unauthenticated web form), `manual` (the facilitator
marked them present) and, reserved for the SMS reply path when it is built,
`sms`. `geo` is not written by anything yet. A web short-code check-in was
recorded as `sms` until code review #3 of Phase 9 caught it. Readers
(`AarService`'s attendance breakdown, the AAR export) group on the raw value and
compare against none of these strings, so adding a value needs no reader
change. No schema change.
