# Screen spec — `Bcms/Incidents/NotificationLog` (regulatory notification log)

**Route:** `GET incidents/{incident}/notifications` → `bcms.incidents.notifications.index`,
`middleware('permission:bcms.incident.view')`. Recording a submission: `POST incidents/{incident}/
notifications` → `bcms.incidents.notifications.store`, `middleware('permission:bcms.incident.notify')`.
Route-model-bound on the incident's uuid; individual notification rows are addressed nested (no own
uuid — a child of the incident, per ADR 0020 §2's schema note), referenced by their numeric id only
inside a server-built action URL, never spliced into JSX.

**Reads:** `docs/adr/0020-phase-10-the-pir-shares-the-aar-table-and-a-notification-is-a-row.md` §2 in
full — this screen **is** that decision rendered; `docs/bcms/phase-10-incident-clause-map.md` §2
(the field-by-deadline table, the two-clocks-never-merge rule), §5 (the NDPA register's rule that
this table and the incident log are personal-data records — "describe categories, never paste
records").

**Siblings:** `resources/js/Pages/Bcms/Findings/Index.jsx` (the register-row shape: reference, a
short structured summary line, a status chip, this screen's per-notification card borrows that
rhythm exactly rather than inventing a new one); the AAR builder's evidence-index table (§5 of
`docs/adr/0019-...md` — "filename, kind, caption, uploader, captured_at, sha256, lock state, not the
bytes" is the same register-not-bundle principle this screen applies to regulator submissions).

---

## 1. Purpose and the user

A compliance officer preparing an examiner's file, or the officer who actually made a submission,
opens this screen to answer one question at a time: *which obligations does this incident owe, to
whom, by when, and has each one been met?* This is not a composer — nothing here drafts or sends a
regulatory report — it is where **the fact that a submission happened** gets written down, with its
reference number, so that months later an examiner's question ("show me every notification and when
it was due") is answered by reading this screen, not by reconstructing it from a decision-log search.
In the first ten seconds a reader must see every obligation this incident has ever owed, whether each
is still open, and — for anything overdue — how overdue, in the same rose treatment the crisis room's
countdown tiles use, because this is the same fact viewed as a permanent record rather than a live
clock.

## 2. Layout

`AppLayout`, `PageHeader` title "Regulatory notifications — {incident.reference}", subtitle
`{incident.title}`, a link back to the crisis room.

**One card per regulator obligation** (i.e. one card per distinct `(regulator, basis_clause_ref)`
pair that has ever had a row — CBN and NDPC render as separate cards even when both were opened on
the same incident, per the ADR's "never merged" rule), each card:

- Header: `{regulator label}` (CBN / NDPC / Other), the basis clause ref as small muted text
  (`cbn.rcf.incident_response` / `ndpa.breach_notification`), and — if still open — the same live
  countdown treatment the crisis room uses (this is the one number on this screen that ticks; every
  other fact here is a stored record). If every submission for this obligation is made and none
  remains open, the header instead shows "Fulfilled — last submission {kind} on {submitted_at}".
- A table of every submission row against this obligation, oldest first: `kind` (initial /
  intermediate / final / supplementary — chip, one colour per kind, text always shown alongside),
  `sequence` (only rendered when `kind = supplementary` and more than one exists — "supplementary #2"),
  `due_at`, `submitted_at` (or "— open —" in rose if past due, amber if not), `submitted_by`,
  `reference` (monospace, the regulator's own acknowledgement — "not yet issued" if null on a
  submitted row), and a "View content" disclosure that expands `content_snapshot` as read-only,
  formatted text (never a raw JSON dump — the backend is specified to render it through the same
  presenter the export uses, per the clause map's own "one presenter" instruction extended here from
  Phase 9's AAR/PIR export to this table).
- Below the table, for `can.notify` holders only, an **"Record a submission"** inline form (not a
  separate screen — this is the one write action the whole page exists for): `kind` select (offering
  only kinds not yet exhausted — `initial` disabled once one exists, `supplementary` always
  available, `final` closes the obligation), a reference field, and the content fields the clause
  map's §2.3 table names for that regulator and that submission's timing (a short structured form,
  not a rich editor — this is a record of what was submitted elsewhere, not a drafting tool). The
  Submit button reads **"Record as submitted"**, deliberately not "Submit" or "Send" — nothing on
  this screen transmits anything to a regulator, matching `bcms.incident.notify`'s own catalogue
  wording verbatim.
- **A "Classify a new obligation" control** at the top of the page (`can.manage` — this is a
  classification act, not a notification-recording act, per the permission split reasoned through in
  `crisis-room.md` §3), for the case where an obligation needs opening from this screen directly
  rather than via the crisis room's unresolved-reportability banner — e.g. a compliance officer
  reviewing the incident days later and realising NDIC also needs telling. Opens the same
  awareness-time sub-form the crisis room uses.

## 3. Every state

- **No obligations exist yet** (both reportability questions answered `No`, or still `unknown`, and
  nothing classified). One message, not a blank page: "No regulatory obligation has been classified
  for this incident yet. If that is wrong, classify one above or return to the crisis room to answer
  the reportability questions." — never an empty table read as "nothing to report."
- **One or more obligations open, none overdue.** The default layout, countdowns amber/slate per §2
  of `crisis-room.md`'s colour rule, applied identically here.
- **An obligation overdue.** Its card header turns rose, states the overdue duration, and — this
  screen's one addition beyond the crisis-room tile — a permanent, non-dismissible note is appended
  to the card once it first goes overdue and stays even after a late submission is recorded: "This
  obligation went overdue at {due_at}; the submission below was recorded {n} hours after the
  deadline." An examiner's question about lateness must be answerable from this screen without cross-
  referencing anything else.
- **An obligation fulfilled** (its last kind is `final` and submitted). Card header green-toned
  ("Fulfilled"), table stays visible and fully readable — a closed obligation's history does not
  collapse or hide.
- **Content snapshot missing** on an older or malformed row (should not happen once the write path is
  built, but the read side must not assume it): "Content not recorded for this submission" shown in
  place of the disclosure, in muted text, not an error.
- **Validation error on Record a submission.** Per-field, `role="alert"` — a `kind` that has no
  content fields filled for the regulator's required set (clause map §2.3) is rejected server-side and
  the message names which fields are missing.
- **Classifying a new obligation — the awareness field.** Per ADR 0020 Amendment 2 rules 5-7 (the same
  rule `crisis-room.md` §3 documents, via the same shared `AwarenessField` component — code-review
  defect 4 was found once on each screen and both are fixed together so they cannot drift back apart):
  the datetime field defaults to the incident's own `detected_at`, falling back to `declared_at`, never
  to "now" — pre-filled, so classifying with the default takes no typing. Moving it **earlier** needs
  no justification; moving it **later** reveals a required "Why is awareness later than detection?"
  field (`awareness_reason`), sent only when the chosen time is in fact later than the default. Server
  validation errors on `awareness_at`/`awareness_reason` render inline under the field, `role="alert"`.
- **Permission-denied.** Standard 403 for `bcms.incident.view`; the recording form and the classify
  control simply do not render for a user without `bcms.incident.notify`/`.manage` respectively.
- **Degraded network.** Standard handling; typed content in the recording form is preserved on a
  failed submit, matching every other long form in the module.

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Classify a new obligation | `POST bcms.incidents.notifications.classify` (name per convention) with the shared `AwarenessField` (`resources/js/Components/Bcms/AwarenessField.jsx`, also used by `crisis-room.md`) | None beyond the form itself — the awareness-time question, defaulted from `detected_at`/`declared_at` and requiring a reason only when moved later, is the deliberate step (§3, above). |
| Record a submission | `POST bcms.incidents.notifications.store` | None — this is a factual record, not a transmission; there is nothing to confirm beyond what the form itself captures. The button's own label ("Record as submitted") is the safeguard against misunderstanding what the action does, not a confirm dialog. |
| View content snapshot | Client-side disclosure toggle | None |
| Export | `GET incidents/{incident}/notifications/export` → `bcms.incidents.notifications.export`, `permission:bcms.report.export` | Plain download, no confirmation — non-destructive, same reasoning as the AAR export. |

No submission is ever transmitted from this screen or any route behind it — there is no outbound
integration in `bcms_incident_notifications` and no route that calls out to CBN or the NDPC, per ADR
0020 §2 point 4. This is stated here because it is the single most important behavioural fact this
screen must never appear to contradict, including in its empty and error states.

## 5. Accessibility (WCAG 2.1 AA)

- **Each obligation card is a labelled region** (`aria-label="{regulator} — {basis clause ref}"`), so
  a screen-reader user can jump between CBN and NDPC obligations the way a sighted reader scans between
  the two cards.
- **The countdown/overdue state on an open card is announced once on load**, not on a repeating
  interval — this screen is a record to be read, not a live console, so unlike the crisis room there
  is no ticking `role="timer"`; the countdown value is computed once server-side at render and
  labelled plainly ("Due in 18 hours" or "6 hours overdue"), refreshed only on navigation.
- **The submission table's `kind` chips carry text, never colour alone**, matching the module-wide
  rule.
- **"Record as submitted" is never a generic-looking primary button** — its accessible name states
  the obligation it applies to ("Record a submission — CBN initial notification"), so a screen-reader
  user moving quickly between two open cards' forms does not submit against the wrong regulator.
- **Keyboard path:** back link → "Classify a new obligation" control → each obligation card in order
  (header, then its table rows as static content, then its recording form's fields in order) →
  export link.
- **Contrast:** rose/amber/slate/green tones reuse the module's existing audited pairings; the
  monospace reference field uses the same tabular-nums treatment as `Alert.jsx`'s delivery funnel.

## 6. Low bandwidth (100 kbps)

- **This is a read-mostly record, not a live console** — no polling at all on this screen, unlike the
  crisis room; a reader returns to it deliberately and reloads to see anything new, which is the
  correct trade for a document whose whole point is to be an accurate historical record rather than a
  live view.
- **Content snapshots are collapsed by default** and only fetched-and-rendered on disclosure (they are
  already present in the page payload as JSON, not a second request — "fetched" here means rendered,
  not requested — so there is no additional network cost to expanding one; the cost saved is
  rendering effort on a slow device, not bytes over the wire).
- **The recording form posts a small, structured payload** — no attachment, no large text field beyond
  the content-snapshot fields the regulator's own template requires.
- **Export is an explicit, deliberate download**, never prefetched, matching the AAR export's rule.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Every obligation card and its rows | `bcms_incident_notifications`, grouped by `(regulator, basis_clause_ref)`, ordered by `awareness_at` then `sequence` |
| The live/static countdown | `due_at` stored at classification time (ADR 0020 §2 point 2) minus now, computed at render — never recomputed from a re-derived window, so a later change to the configured 24h/72h figures in `config/bcms.php` does not retroactively move a historical deadline |
| "This obligation went overdue at…" note | Derived once, comparing `submitted_at` (or now, if still open) against `due_at`; the note is worded from stored timestamps, not a separate flag column |
| `submitted_by` | `bcms_incident_notifications.submitted_by` → `users.name` |

## 8. Deliberately out of scope

- **Drafting or sending anything to a regulator.** No integration exists and none is implied by this
  screen's language — every label reads as a record of a fact, never an action that reaches outside
  the product.
- **NigFinCERT as a distinct regulator card.** Per the clause map, NigFinCERT is modelled as an
  additional recipient of a CBN notification, not a separate obligation with its own clock — this
  screen does not invent a third countdown for it.
- **The DR ingestion webhook's evidence** — unrelated table (`bcms_dr_tests.evidence`), a different
  screen (`dr-test-record.md`).
- **Editing or deleting a recorded submission.** `content_snapshot` is "what was submitted, as
  submitted" — there is no edit path; a correction is a new `supplementary` submission, exactly as a
  decision-log correction is a new superseding entry, not an edit of the old one.
