# Screen spec — the QR check-in poster and its unauthenticated check-in page

Two surfaces, one spec: (A) the **kiosk/poster** an authenticated facilitator prints or displays at
an assembly point, and (B) the **unauthenticated check-in page** the QR code (or the SMS fallback
code) resolves to. (B) is the one an ordinary participant, with no session and no product
familiarity, actually uses — it carries the weight of this spec.

**Routes:**
- (A) `GET occurrences/{occurrence}/check-in-poster` → `bcms.occurrences.check-in-poster`,
  `middleware('permission:bcms.exercise.facilitate')` — printable/displayable, authenticated.
- (B) `GET /bcms/check-in/{token}` and `POST /bcms/check-in/{token}` — **unauthenticated, no route
  name required in JSX** (the QR encodes the literal URL; the SMS message carries the same URL or a
  short code that resolves to it). This mirrors `CascadeAckController`'s existing pattern exactly:
  a signed/unguessable token in the path, no session, POST → redirect → GET.
- SMS fallback: `POST /bcms/check-in` with a body of `{code}` typed in by hand, for a participant
  reading a poster with no camera or no QR reader — the same controller action, keyed by a short
  human-typeable code instead of the long token, both resolving to the same participant/occurrence
  pair.

**Reads:** prompt §Screens item 3; scope §"Attendance" (QR check-in at assembly points, SMS
check-in for low-tech sites, manual and geo methods, `check_in_method` recorded per participant —
already a column on `bcms_exercise_participants`, no schema gap here); acceptance criterion 3 (200
concurrent QR check-ins without contention; SMS works for a participant with no smartphone).

**Sibling to read before building:** `resources/js/Pages/Bcms/CallTrees/Acknowledge.jsx` — **this
screen (B) is built from the same template, not a new one.** No layout, no navigation, no login,
the exercise prefix as the first thing on the screen, POST → redirect → GET so a refresh cannot
double-submit or read as dead. The only material differences are the action (check-in vs.
acknowledge) and that this one also offers a manual short-code entry path for people without a
working camera.

---

## 1. Purpose and the user

**(A) The poster** is opened once, by the facilitator, before the exercise starts, and displayed or
printed at the assembly point. It is not touched again during the exercise — it is signage, not an
interactive screen once printed. Its user is the facilitator setting up, for perhaps thirty seconds.

**(B) The check-in page** is opened by every participant walking past the poster, on their own
phone, at the moment of arrival — potentially two hundred of them within a few minutes (acceptance
criterion 3), on whatever connection an assembly point outside a building actually has. In the first
three seconds they must see: this is an exercise (not a real evacuation), whose check-in this is,
and one button. Nothing else competes for their attention. A person with no smartphone at all reads
the SMS short code printed beside the QR square and texts it, or asks someone else to type it into
the fallback form on a shared kiosk device.

## 2. Layout

### (A) Poster / kiosk screen

`AppLayout` (this one *is* authenticated, so full chrome applies), a single centred card designed to
be legible when projected or printed at poster size:

- Exercise/occurrence title, large.
- **The QR code**, generated server-side (an image, not a client-rendered canvas — this must work
  when printed, with no JavaScript running at all) — rendered as `<img>` pointing at a
  server-generated PNG/SVG endpoint the facilitator can also right-click-save or send to a printer.
- Beneath the QR code, in equally large type: **"No camera? Text {short_code} to {shortcode_number}
  / or open {short_url} and type in: {short_code}"** — printed exactly this large, because the SMS
  fallback is not a footnote, it is scope item "SMS check-in for low-tech sites" and must be
  readable at the same distance as the QR square.
- A live count beneath, refreshed by the same 5-second-poll convention as `CallTrees/Live.jsx`:
  "{checked_in} of {expected} checked in" — this is the only part of the poster screen that updates
  after load, and only for the facilitator watching it on a laptop, not for the printed copy (which
  is static by definition).
- A "Download / print" button and a "Back to workspace" link.

### (B) Check-in page (unauthenticated)

Identical shell to `Acknowledge.jsx`: `min-h-screen flex items-center justify-center`, a single
`max-w-sm` white card, no header, no nav, no footer.

1. **"THIS IS AN EXERCISE"** — first line, bold, at the same visual weight `Alert.jsx` gives its
   simulation banner, *even though* this page has no layout to put a banner in; it is rendered as
   the card's own first line of text rather than omitted for lack of a banner component, because
   the rule ("unmistakable, not a footnote") applies to the surface, not to a specific component.
2. Occurrence/exercise name, and the participant's own name if the token resolves to a known
   participant ("{name}, checking in for {exercise title}").
3. One button: **"I'm here"** (mirroring `Acknowledge.jsx`'s "I received this" — same verb-first,
   plain-language convention).
4. Below the button, small text: the site/assembly point name, so a person who followed a link from
   a text message (rather than scanning a poster in front of them) can confirm they are looking at
   the right one before tapping.

### SMS-fallback short-code entry (same unauthenticated shell, reached by `/bcms/check-in` with no
token, or via a kiosk device at the assembly point run by a marshal)

A single `<input>` for the short code (large, numeric-friendly, `inputmode="numeric"` or
alphanumeric per whatever `AttendanceService` generates), one "Check in" button. This is the form a
marshal with a tablet uses to check in a stream of people reading their codes aloud, or that a
participant with no camera opens on any browser and types the code they were texted or told.

## 3. Every state

### (A) Poster

- **Loading** — server-rendered, no skeleton.
- **Exercise not yet started / no participants invited** — the card shows the QR and code exactly
  the same (they are generated from the occurrence, not from attendance), with the live count
  reading "0 of {expected} — check-in opens when the exercise starts" rather than a bare "0".
- **Exercise completed** — the poster still renders (a facilitator printing it after the fact for
  the AAR would be unusual but not harmful) with the count frozen and a note: "This exercise has
  ended. Check-in is closed." — the QR image itself, if scanned late, must resolve to state (B)'s
  own "closed" state below, not silently still work.
- **Permission-denied** — standard 403 for a non-facilitator.

### (B) Check-in page

- **Valid token, not yet checked in.** The default state above.
- **Valid token, already checked in (a second scan, or a page refresh after submitting).** Matches
  `Acknowledge.jsx`'s `done` state exactly: "Recorded. You can close this page." — no button, no
  error, because a second tap on the same link is exactly what somebody does when unsure the first
  one registered, and the existing pattern already treats that as expected, not exceptional.
- **Unknown or expired token.** A plain, calm message — never a raw 404 or a stack trace, matching
  the product-wide rule for the public-facing surfaces of this module: "This check-in link is not
  recognised. Please see the exercise marshal, or text {short_code_hint} if you have one." Give a
  next step, never a dead end, because the person reading this has no other way to get help.
- **Exercise not running / already ended.** "This exercise's check-in has closed." — not phrased as
  an error, because the participant did nothing wrong; the exercise simply moved on.
- **Occurrence found but this token belongs to a *different* exercise's poster** (a stale QR code
  photographed and reused) — same "not recognised" message as an unknown token; the page never
  reveals which exercise a token *does* belong to, which would leak information to someone probing
  tokens.
- **Server/network error on submit.** The button stays in a "Checking in…" disabled state rather
  than silently failing; on a hard failure, "Something went wrong — try again, or tell the marshal
  you are here" with a retry button, because at an assembly point during a drill the fallback is
  always a human, and the screen should say so rather than leave someone stuck.
- **Degraded network** — see §6. This is, alongside the observer-scoring screen, one of the two
  surfaces in this phase most likely to be used on the worst connection in the estate, and unlike
  the observer screen it is used by people with zero familiarity with the product.

### Short-code fallback form

- **Empty / first load** — one input, one button, no prefill.
- **Wrong code** — "That code was not recognised. Check the digits and try again, or ask the
  marshal." — never "invalid token" or any internal vocabulary (this page is functionally a vendor
  portal analog: **plain language and forgiving errors, per the module's TPRM-portal rule extended
  here to anyone outside the facilitator's own screen**, because a participant is exactly the kind
  of "outside the system, unfamiliar with the product" user that rule was written for).
- **Code recognised, already checked in** — same "Recorded" state as the QR path.
- **Rate-limited** (a marshal's kiosk device, or a bad actor, guessing codes rapidly) — a plain
  "Too many attempts — wait a moment and try again," no technical detail, matching the vendor-portal
  precedent for rate-limit messaging elsewhere in the product.

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Scan QR / open the check-in link | `GET /bcms/check-in/{token}` | none |
| Tap "I'm here" | `POST /bcms/check-in/{token}` (redirects to the same GET on success — POST→redirect→GET, matching `CascadeAckController`) | none |
| Type a short code and submit | `POST /bcms/check-in` with `{code}` in body | none |
| Facilitator: view/print the poster | `GET bcms.occurrences.check-in-poster` | none |
| Facilitator: download/print | client-side `window.print()` or an image download — no server action | none |
| Facilitator: manually check someone in from the workspace (not this screen) | `POST bcms.occurrences.check-in` with `{method:'manual', participant_id}` — specified on the execution-workspace screen, not duplicated here | none |

No destructive action exists anywhere in this spec. Checking in cannot be un-done through this
surface (a wrongly-recorded check-in is a facilitator correction from the workspace/readiness
screen, out of scope here).

## 5. Accessibility (WCAG 2.1 AA)

### (A) Poster
- The QR image carries `alt="QR code — scan to check in to {exercise title}"`, since it conveys
  meaning (a URL) that alt text should name, even though the visual code itself is unreadable by a
  screen reader — the surrounding short-code text is the accessible equivalent path, not an
  afterthought: **the SMS fallback is the accessibility path for this whole surface**, and the spec
  treats it as such rather than as a secondary channel for people without smartphones only.
- Live count uses `aria-live="polite"`, updating at most once per poll tick, not on every digit
  change, to avoid a screen reader re-announcing constantly if a facilitator leaves this open with
  a reader running.

### (B) Check-in page
- **The "I'm here" button is the only interactive element and gets initial focus on load**
  (`autoFocus` or a focus call on mount) — a participant arriving at a link from a text message with
  a screen reader should not have to navigate to find the one thing to do.
- **The exercise-prefix line is read first** by DOM order (it precedes the button), so "this is an
  exercise" is the first thing announced, matching its visual priority.
- **Minimum 44×44px tap target** for the button — this page is used exclusively on phones, often
  one-handed, often while also holding a bag or shepherding a colleague.
- **The "Recorded" done-state message uses `role="status"`**, announced once, not `aria-live`
  repeated (there is nothing to repeat — it renders once per page load).
- **Colour is not the only signal** for success/failure states — each carries explicit text ("Recorded"
  / "not recognised" / "Checking in…"), matching the pattern already in `Acknowledge.jsx`.
- **The short-code input has a visible `<label>`** ("Enter your check-in code"), `inputmode`
  appropriate to the code's character set, and an `aria-describedby` hint if the code format needs
  explaining (e.g. "6 characters, letters and numbers").

## 6. Low bandwidth (100 kbps) and 200-concurrent contention

- **This page has zero JavaScript dependency for its core read** — it is a server-rendered Inertia
  page with one POST; there is no client-side polling on the check-in page itself (only the
  facilitator's poster/kiosk view polls, and only because a facilitator is expected to be watching
  a live count, not the participant).
- **The whole page is a few hundred bytes of markup plus the product's base CSS** — no image beyond
  the poster's own QR (which participants never load; they only load the check-in page the QR
  *points at*, which has no QR image on it).
- **200 concurrent submits without contention (acceptance criterion 3):** this is a backend
  concern (idempotent upsert on `(occurrence_id, participant token)`, per the write-ahead-row
  discipline the module already uses elsewhere), but the screen's contribution is to **never block
  on a client-side duplicate-submit guard that could itself fail under load** — the button disables
  immediately on tap (preventing an accidental double-tap client-side) but the server is the actual
  authority, exactly as `CascadeAckController`'s POST→redirect→GET already proves at cascade scale.
- **On a stalled POST**, the button shows "Checking in…" and stays disabled rather than reverting,
  so a person on a bad connection does not tap twice and is not left wondering whether it worked —
  and if it never resolves, a visible timeout (a handful of seconds) falls back to the "something
  went wrong, tell the marshal" state rather than an infinite spinner.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| "{checked_in} of {expected}" on the poster | `bcms_exercise_participants` — count with `checked_in_at is not null` vs. total invited rows for the occurrence |
| Participant name on the check-in page | `bcms_exercise_participants.user_id`/`contact_id` resolved from the token, if the token is participant-specific; if the QR is a generic assembly-point poster (not per-person), no name is shown and the page reads "Checking in for {exercise title}" without a name |
| Short code | Generated by the backend's check-in token/short-code service (named in the backend engineer's implementation, not this spec) — this document only specifies that it is displayed at poster scale and typeable by hand |

## 8. Deliberately out of scope

- **Geo check-in method** — the schema supports `check_in_method = geo`, but no screen in this
  phase's prompt asks for a geofencing UI; if the backend implements it, it most plausibly attaches
  to a mobile app capability outside this web spec's scope, and is not designed here.
- **A facilitator dashboard of who has *not* checked in** — that already exists on the execution
  workspace's attendance card (`execution-workspace.md` §2) and is not duplicated here.
- **Editing or reversing a check-in** — a facilitator correction, out of scope for this
  unauthenticated surface by design; giving an anonymous link the power to *undo* a check-in would
  be a way to grief an exercise's attendance record.

## 9. Addendum, 2026-09-17 — the poster carries no per-participant token or code

**Decision, recorded by the coordinator in lieu of a `ui-designer` pass (Gate 2 blocking defect
5):** the original build of screen A printed one QR and one short code **per participant** on a
single sheet displayed at the assembly point. That sheet is exactly what §1 above says it is —
displayed where anyone standing near it can read it — which means any bystander could read a
participant's name, scan their personal QR (or type their personal short code) and check that
person in without them ever arriving. That forges headcount and time-to-assembly, the figure
acceptance criterion 1 is measured against, and the same sheet is a printed staff roster left
outdoors for the length of the exercise.

**The fix.** `ExecutionController::checkInPoster()` no longer ships a `participants` array at all —
no per-participant `check_in_url`, no `short_code`, nothing that resolves to one individual. The
poster payload keeps only:

- the occurrence identity (`occurrence.title`, `.uuid`, `.status`, `.ended`),
- `expected` / `checked_in` — the counts, unchanged, still queried live rather than derived,
- `code_form_url` — **one** URL, `route('bcms.check-in.code')`, the GET to the short-code *entry
  form* (screen B's SMS-fallback shell) — never a URL that checks anyone in on its own,
- `live_metrics_url`, unchanged.

`CheckInPoster.jsx` renders exactly one QR, encoding `code_form_url`, plus the "THIS IS AN
EXERCISE" banner, the occurrence title, the live count, and the instruction to scan the code or
visit the printed URL and enter the check-in code texted or emailed to the participant
individually. A bystander who scans the poster's QR or copies its URL reaches only the code-entry
form — with no code of their own, they cannot check anyone in.

**Where each participant's code still comes from.** The SMS/email distribution path the spec
already assumed at §1 ("a person with no smartphone... texts it, or asks someone else to type it
in") is the only place a participant's own token/short code is ever sent — building that dispatch
is out of scope for this defect fix.

**Correction, 2026-09-23 (review #2, finding A).** The sentence this addendum originally closed
with — "a facilitator reads a participant's code from the authenticated workspace screen
(`Workspace.jsx`'s attendance card)" — was false when written on 2026-09-17: the attendance rows
carried only `id` and `name`, and no screen anywhere showed a participant's code or check-in link
to anyone. Between 2026-09-17 and 2026-09-23 that sentence described a facility that did not exist,
and acceptance criterion 3 ("check in by QR or short code") was unmeetable as a result — there was
no way for a code to reach a facilitator's eyes at all, short-circuiting both the QR and the
short-code path this whole spec is about.

**What exists now.** In the workspace payload, each row of `attendance.not_checked_in[]` carries,
**only when `can.facilitate` is true**, two additional keys: `short_code` (the same 8-hex code this
spec's short-code form accepts) and `check_in_url` (the same absolute URL the participant's own QR
would encode, per §1's (B) surface). For a non-facilitator viewer the keys are simply absent from
the row — never sent blank, never present-but-hidden client-side. `Workspace.jsx`'s attendance card
renders a facilitator-only code cell per not-checked-in row when the keys are present: the code in
monospace, labelled "Check-in code" so it can be read aloud, and a "Copy link" button
(`navigator.clipboard.writeText(check_in_url)`, with a plain-text fallback showing the link itself
if the clipboard API is unavailable, and an `aria-live="polite"` confirmation). This is
`bcms.exercise.facilitate`-gated the same way the poster route and the manual check-in button
already are — no new permission invented. The rest of this addendum (the poster itself, the
SMS/email dispatch gap) is unchanged.

**Advisory 13, folded into the same pass.** `CheckIn.jsx` and `CheckInCode.jsx` built their POST
URLs by hand (`` `/bcms/check-in/${token}` ``, `'/bcms/check-in'`) instead of using a server-built
URL. `CheckInController`'s render payload (both `show()`/`store()`'s shared `render()` and
`codeForm()`/`codeStore()`) now ships `check_in_url` and `code_form_url` respectively, and both
screens post to the shipped URL rather than reconstructing it — the same discipline
`ModuleActionUrlRouteKeyTest` polices for numeric-vs-uuid route keys, applied here to hand-built
path strings instead of a `route()`/`tryRoute()` call.

**Correction, 2026-09-23 (live browser pass) — the poster's wording claimed a distribution path
that does not exist.** Screen A told participants to "enter the check-in code you were sent by SMS
or email," and screen B's hint repeated it ("Printed on the poster, or texted to you"). Neither
claim is true: automatic per-participant distribution at exercise start (the SMS/email path this
addendum's earlier text, and §1, both assumed) was assessed against the real EMNS alert dispatcher
and **deliberately not built** — see `docs/bcms/phase-9-notes.md` §11, "T-0 distribution —
assessed, not built." It needs a per-recipient variable threaded through
`AlertDispatcher::sendOne()` and its callers, none of which are in scope for a wording fix, plus its
own ADR. The only source of a participant's code today is the facilitator, reading it off the
workspace attendance card (finding A, above) and relaying it by voice or in person.

`CheckInPoster.jsx` now reads "and enter the check-in code your facilitator gives you," and
`CheckInCode.jsx`'s hint reads "Ask your facilitator for this code." Both are short, plain, and true
of the product as it exists today. **This wording must change back to naming SMS/email (or
whatever channel is actually built) once T-0 distribution ships** — do not leave the
facilitator-relay wording in place as a permanent design; it is a statement of today's gap, not a
decision to keep manual relay forever.
