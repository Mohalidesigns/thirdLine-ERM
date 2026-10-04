# Screen spec — `Bcms/Exercises/Score` (observer scoring, mobile-first)

**Route:** `GET occurrences/{occurrence}/score` → `bcms.occurrences.score.show`,
`middleware('permission:bcms.exercise.evaluate')` — a narrower grant than
`bcms.exercise.facilitate` (clause map §1.3: "No scoring on behalf of another evaluator — a score
row with a null `evaluator_id` is rejected even though the column is nullable"). Submission:
`POST occurrences/{occurrence}/scores` → `bcms.occurrences.scores.store`, same permission.

**Reads:** prompt §Screens item 2; clause map §1.2 row 2, §1.3, §2.1 conditions 5–6, §2.3 (no
`user_id`/name on commentary — a **different** rule, for participant feedback, not for scoring:
scoring commentary is attributed by design, feedback comments are not — do not conflate the two).

**Siblings:** `resources/js/Pages/Bcms/CallTrees/Acknowledge.jsx` (the no-layout, one-thing-to-do,
phone-first shell this screen's *card-at-a-time* mode borrows), `resources/js/Pages/Bcms/Emns/
Alert.jsx`'s `Figure`/`Big` tiles for score distribution. This screen is signed-in (unlike
`Acknowledge.jsx`), so it keeps `AppLayout`'s header for navigation home, but strips everything else
down to one thing at a time on a narrow viewport.

---

## 1. Purpose and the user

An observer or evaluator (`bcms.exercise.evaluate`), standing at an assembly point, in a control
room, or shadowing a call-tree cascade, opens this on their own phone during the exercise — not
after it. In the first ten seconds they must see exactly one thing: which objective they are
scoring right now, worded exactly as it will appear in the AAR. This is not a form to fill in at a
desk afterwards; it is built to be usable one-handed, outdoors, between other tasks, which is why it
is one card per objective rather than a long scrollable form.

## 2. Layout

No `PageHeader` chrome-heavy banner — a slim top bar: exercise title (truncated), a small "×" or
back link to the workspace (`bcms.occurrences.workspace`), and a progress indicator
"{scored} of {total} objectives" so an evaluator mid-exercise can see how much is left without
counting cards.

**One `<fieldset>` card per objective**, shown one at a time (paged, not a scroll of many forms —
a `Prev`/`Next` style stepper, defaulting to the first unscored objective), each:

- The objective text, exactly as snapshotted (`objective_text`), large enough to read at arm's
  length outdoors — this is the one piece of copy on the screen given deliberate visual priority.
  Below it, in small muted text, which exercise type metric this objective maps to if any (e.g.
  "Feeds: time to assembly"), so an evaluator understands why a low score here matters beyond the
  narrative.
- **A 1–5 scale as five large tap targets** (native `<button type="button">` elements in a
  `role="radiogroup"` with `aria-checked`, not a native `<input type="range">` — a slider is hard
  to hit precisely one-handed and gives no discrete value until released; five big buttons are
  unambiguous and thumb-sized, minimum 44×44px per WCAG 2.1's target-size guidance). Each button
  carries its number and a one-word anchor beneath it: 1 "Failed" · 2 "Poor" · 3 "Adequate" ·
  4 "Good" · 5 "Excellent" — plain language rather than a bare number, because a facilitator
  reading this back weeks later should not have to remember what "3" meant.
- **Commentary** — a `<textarea>`, **required and visibly marked so the moment a score of 1 or 2 is
  tapped** (border switches to `red-300`, label gains "(required for this score)"), optional
  otherwise. This mirrors the clause map's write-time rule exactly (§2.1 condition 6): the client
  enforces it as UX, the server refuses it as the actual rule — this screen must not rely on the
  client check alone, and the spec for the backend is explicit that a 1–2 with no commentary is
  rejected regardless of what the button state showed.
- **Photo attach** — see §7 of `execution-workspace.md`: same evidence gap, same degraded state.
  This card shows the identical "pending an architecture decision" note rather than a second,
  differently-worded placeholder for the same gap.
- **Save this objective** button, per-card (not one submit at the bottom of a long form) — each
  score posts independently the moment it is confirmed, so losing signal after scoring three of
  five objectives loses nothing already saved. This is the load-bearing decision for a screen built
  for a 100 kbps connection: a single giant submit at the end is a single point of total loss.

**Stepper controls** (`Prev objective` / `Next objective`) below the card, plus a "Done — return to
workspace" link once every objective shows a saved indicator.

## 3. Every state

- **Loading.** Server-rendered; no skeleton needed.
- **Objective already scored by this evaluator.** The card opens pre-filled with the stored score,
  scale, and commentary, and an "Update this score" label instead of "Save" — re-scoring is allowed
  up to `AarService::finalise()` locking the occurrence (a score is not append-only the way a
  timeline entry is; correcting a mis-tap is expected).
- **Objective scored by a *different* evaluator already** (multiple evaluators covering the same
  objective is valid — the schema allows multiple score rows per objective). This screen shows only
  *this* evaluator's own row; it does not show or allow editing another evaluator's score
  (`evaluator_id` is the signed-in user, non-negotiable per clause map §1.3). A small note under the
  objective text: "Also scored by {n} other evaluator(s)" — informational only, no detail, because
  seeing another evaluator's number before scoring your own invites anchoring.
- **No objectives defined for this exercise type/definition.** The whole screen shows one message:
  "This exercise has no objectives to score. If that is wrong, ask the exercise owner to add them
  to the definition." — not a blank stepper with "0 of 0".
- **Exercise not yet started, or already completed and the AAR is final.** Read-only: every score
  shown, no Save/Update controls, banner: "Scoring is closed — this exercise is final." (matching
  the immutability language used elsewhere for a finalised AAR).
- **Validation error** (score of 1–2 submitted with no commentary, or an attempted submit with no
  score selected). Inline, beneath the relevant control, `role="alert"` — never a toast that
  disappears before an evaluator standing in a noisy assembly area can read it.
- **Save succeeds.** A brief inline confirmation on the card itself ("Saved.") rather than a
  page-level toast — consistent with the low-bandwidth, one-thing-at-a-time design of this screen.
- **Save fails (network).** The typed score and commentary remain in the form (never cleared on
  failure); a retry button appears in place of "Saving…"; see §6.
- **Permission-denied.** A user without `bcms.exercise.evaluate` gets the product's standard 403.
  Nav entry to this screen is not exposed to `bcms.exercise.view`-only users.

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Select 1–5 | client state only, not yet posted | none |
| Save/Update this objective's score | `POST bcms.occurrences.scores.store` (`occurrence`, `objective_id` in body) | none — reversible by re-scoring |
| Attach evidence | blocked pending the architect's ADR, same as the workspace screen | n/a |
| Return to workspace | plain navigation to `bcms.occurrences.workspace` | none, no unsaved-state warning needed because every score already saved independently |

There is no destructive action on this screen. A score can be corrected but the clause map gives no
"delete a score" path, and this spec does not invent one.

## 5. Accessibility (WCAG 2.1 AA)

- **The 1–5 scale is a native `role="radiogroup"`** with each option an actual button carrying
  `aria-checked`, `aria-label="Score {n}: {anchor label}"` — a screen reader announces the group's
  name (the objective text, via `aria-labelledby` pointing at the heading), how many options, and
  which is selected, exactly the guarantee a styled `<div>` grid would not give for free.
  **Minimum 44×44px targets**, per WCAG's target-size success criterion, because this screen is
  designed for imprecise one-handed tapping.
  The word "radiogroup" was chosen over five separate checkboxes specifically because exactly one
  score must be selectable, and a native radio semantic communicates that mutual exclusivity to
  assistive tech without extra ARIA bookkeeping.
- **The required-commentary state is announced, not only shown.** When a 1 or 2 is selected, the
  textarea's `aria-required` flips to `true` and a visually-adjacent `<span id="commentary-hint">`
  ("Commentary is required for this score") is referenced via `aria-describedby` — so a screen
  reader user tabbing to the textarea after selecting a low score hears why it is now required,
  not just that it is.
- **Keyboard path**: back link → progress text (not focusable) → objective heading (not focusable)
  → five score buttons in order 1→5, arrow-key navigation within the radiogroup per the standard
  ARIA radiogroup pattern (Left/Up = previous, Right/Down = next, matching what a screen-reader
  user already expects from any radiogroup on the product) → commentary textarea → Save button →
  Prev/Next stepper → Done link.
- **Colour is never the only signal for a score.** Each button shows its number and its anchor word;
  the selected state uses both a filled background *and* a checkmark glyph, not colour alone
  (colour-blindness safe against the 1–5 scale, which otherwise risks a red-to-green gradient read).
- **Save confirmation is `aria-live="polite"`**, matching the ai-settings screen's "Saved."
  convention, so an evaluator does not have to look down at a phone screen to know a save
  succeeded.

## 6. Low bandwidth (100 kbps)

- **Each score is its own small POST** (a handful of fields) — never a batch submit of every
  objective, so a dropped connection after two of five loses nothing.
- **No client-side draft sync beyond the browser's own form retention.** A failed POST leaves the
  score and commentary exactly as typed; retrying is one tap on the same card, not a re-entry of
  the whole form.
- **No photo thumbnail, no chart, no icon beyond the product's existing glyph set** loads on this
  screen — it is the lightest page in the module by design, because it is the one most likely to be
  opened on the worst connection in the estate (a rural branch assembly point, not the head-office
  control room).
- **The five-button scale and the anchor words are static markup**, not an image sprite or SVG — a
  slow connection costs nothing extra to render this screen correctly.
- **"Saving…" state persists rather than reverting silently** on a stalled request, matching the
  ai-settings convention, so a person on a bad link is not tempted to tap Save twice and create a
  double-submit race (the server is still the authority on last-write, but the UI should not invite
  the collision).

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Objective text | `bcms_exercise_scores.objective_text` if this evaluator has already scored (the snapshot); otherwise the live `bcms_objectives` text for the definition, snapshotted into the row on first save (clause map §1.2 row 2 / §2.1 condition 5) |
| "{scored} of {total}" progress | Count of this evaluator's own score rows with `score is not null` vs. count of objectives on the definition |
| "Also scored by {n} other evaluator(s)" | Count of `bcms_exercise_scores` rows for this occurrence + objective, excluding the signed-in evaluator's own |
| Mean score shown on the AAR builder (not this screen) | Computed there, not here — this screen never shows an aggregate that could look like a target to hit |

## 8. Deliberately out of scope

- **Photo/file evidence** — same architect-ADR gap as the workspace screen; no upload control
  rendered until it resolves.
- **Viewing or editing another evaluator's score** — the service refuses it and this screen does
  not attempt a UI for it.
- **An aggregate scorecard view** — that lives on the AAR builder (`aar-builder.md`), which is where
  a mean, a distribution and a "met/not met" verdict belong; this screen's whole purpose is single,
  attributed, in-the-moment scoring.

## 9. Addendum — the HEIC resolution (added by the Phase 10 ui-designer pass, at the architect's request)

This screen's photo-attach control (§3, §7 of `execution-workspace.md`) sits behind the same
architect ADR gap and, once it resolves, is subject to the **identical** decision
`execution-workspace.md` §10 now specifies in full: client-side re-encode to JPEG via an off-screen
`<canvas>` before upload, never a `FileUploadService::MIME_EXTENSIONS` change, for the same three
reasons given there (a screen-local fix beats a product-wide allowlist change; the service's
content-detection rule stays honest because the bytes that arrive are genuinely JPEG; a re-encoded
JPEG costs less on this screen's 100 kbps target than an accepted HEIC would have). This screen does
not repeat that reasoning in full — it is stated once, in `execution-workspace.md` §10, and this
addendum exists only to point at it so the two evidence cards in this module never drift onto two
different resolutions of the same trap.

**The one thing this screen adds beyond that shared decision:** because this screen is used
one-handed, outdoors, under time pressure (§1), the HEIC-decode-failure fallback message must be
**short enough to read at a glance** rather than the fuller guidance text `execution-workspace.md`
§10 specifies for its own, less time-pressured evidence card. This screen shows the compact form:

> "Photo not readable here. Switch your camera to JPEG in Settings, or pick a different photo.
> Nothing else on this screen was lost."

— same remediation, same "nothing was lost" reassurance, fewer words, because an evaluator standing at
an assembly point mid-exercise reads a error message the way they read the objective text above it:
once, fast, and while doing something else.
