# Screen spec — `Bcms/Exercises/Workspace` (the exercise execution workspace)

**Route:** `GET occurrences/{occurrence}/workspace` → `bcms.occurrences.workspace`, `middleware('permission:bcms.exercise.view')`.
Facilitate-gated sub-actions carry their own permission below. Route-model-bound on
`{occurrence}` via `HasBcmsUuid` (uuid route key), same as every other BCMS occurrence route.

**Reads:** `plans/bcms/prompts/PHASE-09-execution-aar.md` §Screens item 1;
`docs/bcms/phase-9-aar-clause-map.md` §1.2 (rows 1–3), §1.3 (who signs), §5 refinements 1, 9, 12;
`docs/bcms/phase-5-notes.md` (the T-0 rung, the gate, the override); `docs/bcms/phase-6-notes.md`
(the live-poll pattern, the mock-channel banner, the "blocked ≠ timeout" reasoning — this screen
does not repeat the cascade console, it links to it).

**Siblings read before writing this:** `resources/js/Pages/Bcms/Exercises/Readiness.jsx` (the gate
banner, the override modal, the two-column `lg:grid-cols-[1fr_360px]` shape this screen borrows),
`resources/js/Pages/Bcms/CallTrees/Live.jsx` (the five-second poll, the "polling stops when the
thing stops" discipline, the `Stat` tile), `resources/js/Pages/Bcms/Emns/Alert.jsx` (the
`is_simulation` violet banner, the mocked-channel amber banner, the destructive-confirm pattern for
Abort). This screen's visual grammar is those three, not a new one.

---

## 1. Purpose and the user

The facilitator (`bcms.exercise.facilitate`) opens this at T-0, from the readiness screen's
"Start exercise" action or from the calendar. For the length of the exercise — minutes for a fire
drill, hours for a DR failover — this is the one screen open on their laptop, and for an observer
at an assembly point it is one screen open on a phone with one bar of signal. In the first ten
seconds they must see: is this exercise running, is it a drill or a live event, what just happened,
and what do I need to log or release next. A crisis console and this workspace share components but
not a purpose — this screen scores and evidences a *planned* exercise; `Emns/Alert.jsx` is what
runs during an actual one. If both are ever open in the same organisation at once, the simulation
banner is the only thing standing between a rehearsed evacuation and a real one, which is why it is
non-negotiable (§3).

## 2. Layout

`AppLayout`, `PageHeader` title `{occurrence.definition_name} — {exercise_type_label}`, subtitle
`{ladder_level_label} · {site ?? location} · started {actual_start_local}`. Header actions: a link
back to `bcms.occurrences.readiness` labelled "Readiness", and — only for `can.facilitate` while
`running` — "End exercise" (opens the completion confirm, §4).

**Above the fold, in this order, full width:**

1. **Simulation / unannounced banner**, unmissable, matching `Alert.jsx`'s violet treatment
   (`border-2 border-violet-300 bg-violet-50 text-violet-900`), prefixed literally
   **"THIS IS AN EXERCISE"** when `occurrence.is_simulation !== false` — which for the execution
   workspace is every occurrence, because nothing that reaches this screen is a live activation;
   a live continuity activation runs through the plan-activation flow (`Plans/Show.jsx`,
   `plans.activate`), not the exercise engine. This is stated to the frontend engineer as a rule,
   not a per-record flag to compute: **always render it, at the same visual weight as
   `Alert.jsx`'s, because "somebody glancing at a colleague's monitor must tell instantly" (BCMS
   design rule) has to hold even when nobody on this screen questions what it is.**
2. **Ladder-advisor banner** (`amber-50`/`amber-900`), shown only when
   `ladder_warnings.length > 0` (`quantitative_results.ladder.advisor_warnings[]` in the making,
   sourced live from `LadderAdvisor::adviseDefinition()` per clause-map refinement 9): one line per
   warning, e.g. "This definition has never run a tabletop before this level" or "2 corrective
   actions from a lower rung are still open." Text only — the advisor warns and never blocks, and
   this screen must not imply otherwise.
3. **Readiness-override banner** (`amber-50`/`amber-900`), shown when any readiness task on this
   occurrence has `override_reason` set: "This exercise started with {n} blocking item(s)
   overridden. Every override is listed below and will appear in the after-action report." — the
   same "not a completion" language as `Readiness.jsx`, because this is the same fact reappearing
   one screen later, not a new one.
4. **Mocked-channel banner** (`amber-50`/`amber-900`), shown when `channels_are_mocked`, same
   wording as `CallTrees/Live.jsx`: "Notification channels are still recording mocks. Nothing has
   left the building."

**Floating metrics bar** — sticky under the header on scroll (`sticky top-0 z-10`, matching the
product's existing sticky-header convention), one row of `Stat` tiles (the `CallTrees/Live.jsx`
component, reused, not reinvented), type-specific per §2.2 of the clause map:

| Exercise type family | Tiles shown |
|---|---|
| `FIREDRILL`/`EVAC` | Elapsed, Headcount (checked-in / expected), Time to assembly (running clock vs target) |
| `CALLTREE` | Elapsed, Cascade completion %, Tier progress (links out to `CallTrees/Live.jsx` rather than duplicating the tree) |
| `DRFAILOVER`/`DRFAILBACK`/`DRTEST` | Elapsed, RTO clock (running, red past target), Downtime so far |
| everything else | Elapsed only, plus "Decisions logged: {n}" from timeline `entry_type = decision` |

Every tile with a target renders the target beside the actual (`Stat`'s existing `of` prop), never
the actual alone — an unscored number invites the wrong read mid-drill.

**Below the fold, two-column `lg:grid-cols-[1fr_360px]` (the `Readiness.jsx` shape):**

- **Left, the timeline (the spine).** A reverse-chronological list, one row per
  `bcms_exercise_timeline` entry: time (`HH:mm`), a coloured type chip (`system` grey, `manual`
  blue, `inject` violet, `decision` slate, `milestone` gold — Gold `#D4AF37` used here as an accent
  chip background at reduced opacity, per the theme's existing accent use, never as body text),
  `content`, and `logged_by`. A sticky **"Log an entry"** composer pinned above the list (textarea +
  type select, defaulting to `manual`) for `can.facilitate` — this is the plainest form on the
  screen because it is used most often and under time pressure. **Not a `DataGrid`**: the timeline
  is append-mostly, read top-to-bottom during a live event, and a data-grid's column chrome, sort
  controls and pagination bar are exactly the friction this list must not have; `CallTrees/Live.jsx`
  and `Readiness.jsx` both already choose a plain list over `DataGrid` for the same reason, so this
  is house convention, not a deviation.
- **Right, three stacked cards:**
  1. **Injects** — one row per `bcms_exercise_injects`, ordered by `sequence`: title, scheduled
     `release_offset_minutes` (rendered as "due at T+{mm}"), a **Release now** button
     (`can.facilitate`, only while `released_at` is null) that posts immediately and appends a
     `inject` timeline entry server-side. Released injects show `released_at` and `released_by` and
     grey out but never disappear — a facilitator scrolling back needs to see what has already gone
     out. Injects that are `ai_generated` (per the migration's own column) carry the sky-badge
     `AI draft` convention already in `Bia/Index.jsx`, because a facilitator releasing AI-authored
     scenario content to two hundred people is exactly the moment provenance must be visible, not
     buried.
  2. **Attendance** — same shape as `Readiness.jsx`'s attendance card: `{checked_in} of {expected}
     ({percent}%)`, a link to the QR check-in poster (`bcms.occurrences.check-in-poster`, see the
     QR check-in spec) for `can.facilitate`, and an `<details>` list of who has not checked in. Each
     not-checked-in row carries the existing "Check in" manual button, and, **only for
     `can.facilitate`**, a check-in code cell (added 2026-09-23, review #2 finding A — see
     `qr-checkin.md` §9's correction): the row's `short_code` in monospace under a visible
     "Check-in code" label, and a "Copy link" button that copies `check_in_url` to the clipboard.
     Both keys are present on the row's payload only when `can.facilitate` is true; a
     non-facilitator's row carries neither, and the cell renders nothing for it — this is the one
     place on this screen a participant's own check-in credential is legitimately visible, and it
     is gated exactly as tightly as the poster and the manual check-in action next to it.
  3. **Evidence** — see §7 (evidence gap) for what this card can and cannot do until the architect
     rules on refinement 1.

- **"End exercise" CTA** lives in the header (not floated at the bottom of the page), because a
  facilitator scrolling through a long timeline must not lose it, and a header button survives a
  scroll position the way a footer button does not.

## 3. Every state

- **Loading.** Server-rendered Inertia page; no client skeleton needed for first paint. The
  metrics bar and timeline poll after mount (§6) — see "degraded network" below for what shows
  while a poll is in flight or failing.
- **Not yet started (`occurrence.actual_start` is null).** This route 404s rather than showing an
  empty workspace — starting is `bcms.occurrences.readiness`'s job (the existing gate screen); a
  workspace with nothing in it would invite a facilitator to start logging before the readiness
  gate has run, which is exactly the gate Phase 5 built to prevent.
- **Running (`occurrence.actual_end` is null).** The default state described above. Polling is
  active.
- **Completed (`occurrence.actual_end` set).** Polling stops immediately on load (mirroring
  `CallTrees/Live.jsx`'s "polling stops when the cascade stops"). The metrics bar freezes on its
  final values. The composer and Release/Log controls disappear, replaced by a single banner:
  "This exercise ended at {actual_end_local}. Continue to the after-action report" with a button to
  `bcms.aars.show` (creating the draft AAR first if none exists yet — the backend's `complete`
  action is specified to do this per the API surface's `POST .../complete`). Read-only past this
  point; nothing on this screen edits a completed occurrence.
- **Empty timeline** (running, nothing logged yet — the first seconds after start). The list shows
  one line: "Nothing logged yet. The clock is running — the first entry is usually the assembly
  point confirming they are in position." Never a bare blank list; an empty timeline during a live
  drill is a fact the facilitator needs stated, not implied by whitespace.
- **No injects defined.** The injects card reads "This exercise has no scripted injects." — not an
  error, most tabletop-and-below exercises legitimately have none.
- **Attendance check-in code cell — facilitator.** Each not-checked-in row shows the code cell
  described in §2, unconditional on anything else (it is not hidden while `completed`, since a
  facilitator relaying a code after the clock stops but before every straggler is marked present is
  still a legitimate use, and the underlying check-in write is what enforces the frozen-state rule,
  not this display).
- **Attendance check-in code cell — non-facilitator.** `short_code`/`check_in_url` are absent from
  the row; the cell renders nothing at all, not a placeholder and not a disabled control — matching
  the module's existing rule that an unavailable capability shows no affordance rather than a dead
  one (`execution-workspace.md` §7's evidence-gap card follows the same rule).
- **Copy succeeds.** The `aria-live="polite"` region beside the button reads "Link copied." once;
  it is not re-announced on a second copy of the same row unless the text actually changes (React
  re-renders the same string as a no-op for most screen readers, which is acceptable here — there
  is no re-announcement requirement beyond "once per successful copy").
- **Clipboard unavailable** (`navigator.clipboard.writeText` missing — an insecure context or an
  older browser — **or the write never resolves at all**, added 2026-09-23). The same live region
  shows the check-in link itself as selectable plain text instead of a confirmation, with a short
  instruction to read the code aloud or copy the link by hand — never a silent no-op and never a
  raw JavaScript error surfaced to the facilitator. `writeText()` carries no timeout of its own; a
  live pass found the promise staying pending indefinitely (most likely a pending clipboard
  permission prompt), which showed the facilitator neither outcome — nothing at all. The click now
  races the write against a 1.5s timer: if the write has not resolved by then, this fallback state
  is shown; if the write resolves afterwards, the screen does **not** flip back to "Link copied." —
  whichever outcome is shown first is the one that stays, so a facilitator already reading the link
  aloud is never interrupted by a message reversing under them.
- **Permission-denied.** A user without `bcms.exercise.view` gets the product's standard 403; the
  route itself is 404 for anyone who cannot see the occurrence at all (org-hierarchy scoping, per
  `ScopedToOrgHierarchy`).
- **Server/network error on a background poll.** See §6 — the last good snapshot stays on screen
  with a small "last updated {n}s ago, retrying" note; the page never blanks itself because one poll
  failed.
- **Server error on a foreground action** (log entry, release inject, check-in, score submit fails
  to save). Standard Inertia error handling: the composer's typed text is not cleared, the error
  renders inline beneath the control that failed, exactly as `Readiness.jsx`'s override form does.
- **Concurrent facilitators.** Two people with `bcms.exercise.facilitate` may both have this open
  (a fire-drill facilitator and a scribe, say). Both post independently; the poll is what
  reconciles them — there is no live-lock on the composer. This is stated because it is a real
  demo case (acceptance criterion 3's 200-participant concurrency) and because a naive "only one
  editor" lock would make the scribe unable to log while the facilitator is mid-release.

## 4. Interactions and their server actions

| Action | Route (name) | Confirmation |
|---|---|---|
| Log a timeline entry | `POST bcms.occurrences.timeline.store` | None — logging is additive and never destroys anything. |
| Release an inject | `POST bcms.occurrences.injects.release` (`{occurrence}`, `{inject}`) | None for a scripted (on-schedule) release; for a *manual* early release (before its offset), a lightweight inline confirm — "Release now, {mm} minutes early?" — because releasing "the generator has failed" before the scripted moment can derail a scripted narrative, and that is a decision worth one click of friction, not a modal. |
| Check in a participant on their behalf (manual method, facilitator only) | `POST bcms.occurrences.check-in` with `{method: 'manual', participant_id}` | None. |
| Copy a participant's check-in link (facilitator only, added 2026-09-23) | **No server action** — `navigator.clipboard.writeText(check_in_url)` against the URL already shipped on the row; nothing is sent to the server and no route is called | None — the confirmation is the `aria-live` region, not a modal. |
| Submit an observer score | Delegates to the Observer scoring screen/route (`bcms.occurrences.scores.store`) — this screen does not duplicate the scoring form; the injects/attendance card links out to it for the signed-in evaluator. |
| Upload evidence | See §7 — behind the architect's ruling. |
| **End exercise** | `POST bcms.occurrences.complete` | **Destructive-adjacent, not destructive**: ends the clock and is not itself irreversible (an AAR reopen later can still correct facts), but it stops every in-progress action on this screen, so it carries a confirm modal naming what stops: "End this exercise? The timeline, injects and attendance close for editing. You can still amend facts through the after-action report." Matches the weight `CallTrees/Live.jsx` gives "Close and score" versus the heavier `window.confirm` it gives "Abort". |
| Abort (equivalent to the call-tree console's Abort, for an exercise that must stop early — e.g. a genuine emergency interrupts a drill) | `POST bcms.occurrences.complete` with an `aborted` flag/reason, mirroring `CallTrees/Live.jsx`'s `window.prompt`-for-reason pattern | Required reason, native `window.prompt`, matching the existing call-tree abort exactly — no new confirmation pattern invented for one screen. |

No route above is invented ad hoc for this spec beyond what the prompt's API surface already
names, translated into the product's shipped `routes/web.php` convention (dotted resource names,
not `/api/v1/...` — per clause-map refinement 2, which this spec follows rather than re-litigates).
The backend engineer supplies the exact URL as a server-built prop or via `tryRoute()`, never a
numeric id spliced into a path in JSX, per the `ModuleActionUrlRouteKeyTest` convention already
enforced elsewhere in the module.

## 5. Accessibility (WCAG 2.1 AA)

- **The simulation banner is a landmark, not decoration.** `role="status"` with `aria-live="polite"`
  is wrong here — it should announce once on load only (a live region that re-announces on every
  poll would be read out every five seconds), so it is a plain, non-live, but visually and
  structurally first element in the DOM (before the metrics bar), reachable by "skip to main
  content" only after it, so a screen-reader user cannot land on the timeline without having passed
  it.
- **Keyboard path**: simulation banner (not focusable) → advisor/override/mock banners (not
  focusable) → header actions (Readiness link, End exercise button) → metrics bar (not focusable,
  each `Stat` carries its label and value as plain text so a reader announces "Headcount, 210 of
  340" without a custom widget) → timeline composer (type select, then textarea, then Log button)
  → timeline list (each entry a `<li>`, not individually focusable — this is a log, not a set of
  controls) → injects card (each row's Release button) → attendance card → evidence card → End
  exercise (also reachable here if the header is scrolled out of view — **the CTA must exist in
  both places for this reason**, contradicting §2's "lives in the header only" for keyboard users
  specifically: a keyboard user tabbing through a long page should not have to tab back to the top.
  Resolve as: the header button is the primary, and a second, visually secondary "End exercise"
  link appears after the evidence card as a fallback, both posting to the same route).
- **Type chips on timeline entries carry a text label**, not colour alone (`system` / `manual` /
  `inject` / `decision` / `milestone` spelled out), matching the product's existing rule that no
  status is colour-only (`Readiness.jsx`'s status chips, `Alert.jsx`'s unaccounted/safe chips).
- **The composer's type `<select>` has a visible `<label>`** ("Entry type"), not a placeholder
  standing in for one.
- **Injects' Release button states its consequence in its accessible name**: "Release: {title}" not
  a bare "Release", so a screen-reader user tabbing rapidly through several inject rows hears which
  one they are about to fire.
- **Live region for the metrics bar is intentionally absent.** A poll every five seconds
  re-announcing "Headcount 210 of 340, Headcount 212 of 340…" to a screen-reader user is exactly the
  alert-fatigue failure mode the module's design rule warns against; the numbers update visually and
  a screen-reader user can re-read them on demand, but nothing pushes an announcement uninvited.
- **Contrast**: the gold milestone chip uses `#D4AF37` at a background tint with dark text
  (`amber-900`-equivalent ink), never gold text on white, per the existing theme's documented
  insufficient-contrast rule for Gold as body text.
- **The check-in code cell (added 2026-09-23).** The "Copy link" button's accessible name includes
  the participant's name ("Copy check-in link: {name}"), not a bare "Copy", for the same reason the
  Release button states its consequence — a facilitator tabbing through several not-checked-in rows
  must be able to tell them apart by ear. The code itself is never the only way to identify the row:
  it sits beside the participant's name, which precedes it in DOM order. Success/failure are both
  stated as text in the `aria-live="polite"` region next to the button — colour is not the signal
  (no green/red styling swap on the code or button, matching the module's existing "text states the
  fact" rule). The button and the code are both reachable by keyboard in the same tab sequence as
  the row's existing "Check in" button, immediately after it.

## 6. Low bandwidth (100 kbps)

This is the screen the module's low-bandwidth rule was written for: a field observer at an
assembly point on 3G, or worse, at a rural site during the incident the product exists for.

- **Poll interval is 5 seconds, matching `Live.jsx` and `Alert.jsx`**, and stops entirely once
  `completed` — no tab left open overnight quietly spending someone's data bundle.
- **The poll fetches one small JSON document** (`GET bcms.occurrences.live-metrics`, JSON, not a
  full Inertia page) — timeline deltas, current metric values, attendance counts. It does **not**
  refetch the whole page, matching the `Live.jsx`/`Alert.jsx` precedent exactly.
- **On a failed poll**, the last successful snapshot stays exactly as it was, with a small
  `text-slate-400` note under the metrics bar: "Last updated {n}s ago — connection is slow or
  down." This is the one addition this screen makes beyond its siblings, because a facilitator
  relying on this screen during a real disruption (the DR-failover case, for instance) must know
  the numbers in front of them might be stale, not assume they are live.
- **No image, video or map asset loads by default.** Evidence thumbnails (once built, see §7) are
  behind an explicit tap, never auto-loaded inline, on this screen specifically — a photo grid
  auto-fetching at an assembly point on a saturated cell tower is the wrong trade every time.
- **The composer posts a small payload** (a few hundred bytes of text) — no client-side draft
  autosave to a server endpoint on every keystroke; the browser's own form-state retention (Inertia
  preserves local state on a failed request) is what protects a half-typed entry, not a background
  sync.
- **The metrics bar has no chart.** Every value is a number and a target, not inline SVG — there is
  nothing here dense enough to earn even the house SVG convention; a bar or arc would cost more
  bytes than the two-number pair it replaces.

## 7. The evidence gap — flagged, not silently worked around

The clause map (refinement 1) is explicit: **there is no `bcms_exercise_evidence` table in the
frozen schema**, and `evidence_file_id` on `bcms_exercise_scores` and `bcms_readiness_tasks` is an
unconstrained integer pointing at nothing. "Photos from mobile, files, screenshots" (the prompt's
own words for screen 1) cannot be stored today. This spec does not invent a table, and does not
pretend the card above can upload a photo until the architect rules.

**The evidence card's degraded state, shown until the architect decides (option a vs b in the
clause map):**

> "Evidence capture is pending an architecture decision (ADR — evidence storage). What already
> counts as evidence: the timeline above, every observer score with its evaluator, the attendance
> record, and the call-tree test result where this exercise used one. A photograph is not yet
> storable in this release."

This is rendered as a plain informational card (`slate-50` background, no alarm colour — this is a
known, deliberately scoped gap, not a fault), with no upload control at all rather than a disabled
one, matching the AI-settings screen's rule that an unimplemented capability shows no interactive
affordance rather than a dead one.

**If the architect chooses option (a)** (a real `bcms_exercise_evidence` table), this card's final
shape — capture button, thumbnail grid, actor/timestamp/clause-ref per item, immutability once the
AAR finalises — is a follow-on addition to this same file, not a new screen, and should specify:
camera-capture input (`<input type="file" accept="image/*" capture>`) for the mobile case,
a caption field, and the same `iso_clause_ref` stamping every other evidence-bearing artefact in
this module carries. That addendum is out of scope for this document until the ADR lands.

## 8. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Elapsed time | Computed client-side from `occurrence.actual_start`, refreshed by the poll tick — never a value the server is asked to recompute every 5 seconds |
| Headcount checked-in / expected | `bcms_exercise_participants` — `checked_in_at is not null` count vs total invited, per occurrence |
| Time to assembly (actual) | `min(checked_in_at) .. threshold-crossing timestamp` per the definition's target — server-computed, mirrors the clause map's `time_to_assembly_seconds` |
| Cascade completion % (CALLTREE type) | Read-through link to `CallTrees/Live.jsx`'s own `counts.reached / test.nodes_total` — not recomputed here |
| RTO clock (DR types) | `now() - actual_start` compared to `rto_target_minutes` on the definition/type — same numbers the AAR's `quantitative_results.metrics` will store at completion |
| Timeline entries | `bcms_exercise_timeline`, ordered by `logged_at desc` |
| Inject release state | `bcms_exercise_injects.released_at` / `.released_by` |
| Attendance percentage | `bcms_exercise_participants.attendance_status` / `.checked_in_at` |
| Ladder warnings | `LadderAdvisor::adviseDefinition($definition)` — computed live, not stored, until it is copied into `quantitative_results.ladder.advisor_warnings[]` at AAR finalisation (clause map §2.2) |
| Readiness overrides shown in the banner | `bcms_readiness_tasks` where `override_reason is not null`, scoped to this occurrence |
| `channels_are_mocked` | Same computed fact `CallTrees/Live.jsx` and `Emns/Alert.jsx` already expose — no new source |
| `short_code` / `check_in_url` on a not-checked-in row (added 2026-09-23) | The same per-participant token/short-code service `CheckInService`/`CheckInController` use for the unauthenticated check-in page (`qr-checkin.md` §7) — server-built, never assembled client-side; present on the row only when the current viewer has `can.facilitate` |

## 9. Deliberately out of scope

- **Photo/file evidence capture** — blocked on the architect's ADR (§7).
- **The AAR itself** — a separate screen (`aar-builder.md`), reached only after `complete`.
- **Observer scoring's full form** — a separate mobile-first screen (`observer-scoring.md`); this
  workspace links to it rather than embedding a duplicate scoring UI.
- **QR poster / SMS check-in** — a separate, unauthenticated screen (`qr-checkin.md`).
- **EMNS-channel delivery of injects** — the prompt itself marks this `[verify at integration]` at
  the W11 window; this screen's Release button always delivers in-app in this phase, and shows no
  channel picker until that integration lands.
- **The corrective-action register and its verify workflow** — already shipped
  (`resources/js/Pages/Bcms/Findings/Index.jsx`); this screen never re-implements it.

## 10. Addendum — the HEIC resolution (added by the Phase 10 ui-designer pass, at the architect's request)

`docs/adr/0019-phase-9-evidence-has-a-home-and-finalise-locks-it.md` §1 named the trap and left the
decision to whichever screen spec resolves it first: an iPhone's default photo format is HEIC,
`FileUploadService::MIME_EXTENSIONS` has no `image/heic` entry, and a photograph taken at an assembly
point on a default-configured phone would be refused by validation — which, at 3G on a fire drill,
reads as "the app is broken," not as a format problem.

**Decision: client-side re-encode to JPEG, not a server-side allowlist change.** The evidence card's
capture control (`<input type="file" accept="image/jpeg,image/png" capture>`, per the ADR's own
follow-on note in §1) draws the selected image onto an off-screen `<canvas>` and re-encodes it to
JPEG **before** the upload request is built, whatever format the source file arrived in. This is
chosen over adding `image/heic` to `FileUploadService::MIME_EXTENSIONS` for three reasons:

1. **It is a screen-local decision, not a product-wide one.** Widening `MIME_EXTENSIONS` changes what
   *every* upload endpoint in the product accepts — the TPRM evidence profile, the loss-event
   attachment profile, every other caller of the same service — for the sake of one capture control on
   one screen. ADR 0019 §1 already flags that the alternative "needs the `finfo` build on the
   deployment target verified first," which is a deployment-wide dependency this screen spec is not in
   a position to guarantee across every self-hosted install this product ships to.
2. **The service's own content-detection rule stays exactly as documented.** `FileUploadService`
   derives `file_type` from what the file actually *is* (`getMimeType()`), never from the client's
   header or filename, and re-encoding client-side means the bytes that arrive at the server are
   genuinely a JPEG — the service does not have to trust a claim, it detects the truth.
3. **It costs nothing extra on a low-bandwidth connection.** A re-encoded JPEG at reasonable canvas
   dimensions is smaller, not larger, than an unconverted HEIC would have been had the platform
   accepted it — this is a net improvement for the 100 kbps case this screen is built around, not a
   trade against it.

**The fallback message, for a browser that cannot decode HEIC at all** (older WebView builds, or a
browser without `createImageBitmap`/canvas HEIC decoding support — this is a real gap, not a
theoretical one, since HEIC decoding in a `<canvas>` context is not universal across mobile browsers
in 2026): the capture control detects a failed decode attempt (the canvas draw throws, or the
resulting bitmap is zero-dimensioned) and shows, in place of a silent failure or a cryptic upload
error, this exact message inline on the evidence card:

> "This photo could not be read on this device. Open your camera settings and switch photo format to
> 'Most Compatible' (JPEG), or use your phone's own Photos app to convert it, then try again. Nothing
> was lost — you can retake or re-select the photo."

This is deliberately specific about the fix (the iOS Settings → Camera → Formats → "Most Compatible"
path is the actual remediation most affected users need) rather than a generic "upload failed," because
a facilitator standing at an assembly point during a live drill has neither the time nor, often, the
technical background to diagnose a codec problem from a bare error string — and because "nothing was
lost" matters exactly as much here as it does everywhere else on this screen's low-bandwidth design:
a failed capture must never look like a failed *save* of everything already logged.

This addendum does not change any other section of this document. The evidence card's placement,
its "pending an architecture decision" framing where the table itself has not yet been built, and
every other state in §3 remain as written above; this section resolves only the HEIC question ADR
0019 explicitly left open, in the form the ADR itself invited ("Decide it in the screen spec; do not
discover it at the demo").
