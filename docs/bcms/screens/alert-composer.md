# Screen spec — `Components/Bcms/AiAlertComposer` (the AI drafting panel inside EMNS compose)

**Surface:** not a new route. A panel embedded in the existing compose flow,
`resources/js/Pages/Bcms/Emns/Index.jsx` (`GET bcms/emns` → `bcms.emns.index`), and reachable
wherever that flow is entered with occurrence/incident context (the crisis room's "Compose a
SitRep" / roll-call composer, `crisis-room.md` §2). Its own network call is
`POST bcms.alerts.compose-with-ai` (name assumed, following the `bcms.<resource>.<verb>` convention
every other BCMS route uses — `backend-engineer`'s to confirm), `middleware('permission:
bcms.alert.compose')`, **no MFA** — ADR 0023 §4.3 states this matches compose today, because
drafting is not sending. This document also specifies the additions **the existing draft screen**
(`resources/js/Pages/Bcms/Emns/Alert.jsx`, `bcms.alerts.show`) needs to carry an AI-composed draft
through to dispatch, since ADR 0023 §4.3 says "audience, channels and response options are set by
the human on the existing screen, as for any draft" and that screen today has no such controls for
*any* draft (§1, below) — this is a genuine gap the ADR's contract exposes, not a restatement of
something already built.

**Reads:** ADR 0023 §4 in full — §4.1 (the composer is pure and cannot send), §4.2 (brief, output,
the deterministic SMS-budget and readability checks, the language rule), §4.3 (the persist-and-
redirect hand-off), §4.4 (the release guard: `confirm_ai_content` on every dispatch entry point,
`AlertService::release()` refusing an `ai_generated` alert with no human actor). Also §2.1's
guardrail table in full — this panel is capability 6, and every row applies to it.

**Siblings read before writing this:** `resources/js/Pages/Bcms/Emns/Index.jsx` (the existing
compose form this panel sits above — `title`/`message`/`template_id`/`severity`/`channels`/
`audience_rule`/`ack_window_minutes` posted to `bcms.alerts.store`; the channel-toggle button group;
the `dual_approval` note); `resources/js/Pages/Bcms/Emns/Alert.jsx` (the draft/dispatch screen this
panel's output lands on — the `is_simulation` violet banner, the `window.confirm` dispatch pattern
this spec now extends with a required checkbox, §4); `Bia/Workspace.jsx` (the `ai_generated`
sky-50/900 banner wording, reused verbatim); `Programme.jsx` (the `advisor.available`/`advisor.
reason` shape for an AI feature that is off).

---

## 1. Purpose and the user

A facilitator or crisis-room operator needs to get a clear, calm message out fast and is not
confident the first sentence they'd type is the right one — often mid-incident, sometimes on a
phone. This panel exists to turn a one-line description ("robbery in progress at the Lekki branch")
into a properly worded title and body **in the time it takes to read this sentence**, with the
person still deciding severity, audience and channels themselves, and still required to read the
result before it can be sent. It is never on the life-safety critical path (ADR 0023 §4, closing
line): a pre-scripted template dispatches without waiting on a model, and this panel is an
accelerant for the *wording*, never the send decision.

## 2. Layout

**Where it sits.** On `Emns/Index.jsx`, directly above the existing manual `title`/`message` fields,
a collapsed toggle: "Draft with AI" (`can.compose`, matching the existing form's own gate) —
collapsed by default so a facilitator who already knows exactly what to say is not slowed down by
an extra control. Expanding it reveals the brief form; it does **not** hide or disable the manual
fields beneath it, because the two are alternative starting points for the same form, not two
different screens.

**The brief form:**

- **Description** — `≤280`, `form-textarea`, required, placeholder "What happened, in one plain
  sentence." Live counter.
- **Severity** — `form-select`, the same `severities` prop the manual form already receives — **this
  is the human's choice**, carried through to the persisted alert untouched by the model (§4.2:
  "the human-chosen `severity`").
- **Context** — read-only, shown only when the panel was opened with an `occurrence_id` or
  `incident_id` in the surrounding page's own props (e.g. reached from the crisis room or the
  execution workspace): "Drafting for: {occurrence/incident title}." When opened from the plain EMNS
  console with no such context, this row is simply absent — never a picker the operator has to fill
  in by hand, because guessing the wrong occurrence here is worse than omitting the field.
- **Template hint** — optional `form-select`, "Base the wording on a template (optional)," reusing
  the same `templates` prop the manual form already has.
- **"Draft with AI"** button, replacing "Generate" while `processing`: "Drafting…" (disabled, no
  full-page spinner — the manual fields beneath remain usable while this runs, matching
  `aar-builder.md`'s in-flight AI pattern).

**The result, once drafted (before persisting — see §4 for exactly when the persist happens):**

- **Title** and **message** rendered read-only for a beat, then handed into the manual form's own
  `title`/`message` fields as the panel closes — see §4's "what happens next" note; this spec treats
  the visible preview as owned by the panel and the eventual edit surface as the existing screen's
  own fields, so there is exactly one place title/message are ever typed into once a draft exists.
- **Voice script**, shown only when present, in a muted collapsible ("Also generate a voice script"),
  never required reading.
- **Segment and encoding counts** (deterministic, from `SmsSegmenter`, never the model): "{n} SMS
  segment(s), {GSM-7|UCS-2 (uses characters outside the SMS alphabet)}." When `will_truncate` is
  true after the one shorten retry ADR 0023 §4.2 allows: an amber note, "This message was
  shortened to fit two SMS segments (the exercise prefix and reply link need to fit too). The full
  version below has been cut on a word boundary — check nothing important was lost." with the
  pre-truncation text still visible beneath for comparison, because a shortened life-safety message
  is exactly the kind of silent edit a reader must be able to see happened.
- **Readability note** (deterministic, `App\Support\Bcms\Readability`, ADR 0023 §4.2): a plain
  sentence, "Reads at roughly a {grade} reading level," shown as a **warning, never a block** — "A
  readability miss is a warning, not a block."
- **Template locale coverage**, shown only when a `suggested_template_code` came back: a compact
  grid reusing `TemplateRenderer::coverage()`'s existing five-locale shape ("English ✓ · Pidgin ✓ ·
  Hausa — · Yoruba — · Igbo —"), with the caption already used on the templates screen: "A language
  with no authored template falls back to English, and the fallback is recorded on the delivery.
  Emergency copy is authored and reviewed by a person — nothing here is machine-translated." This
  is the panel's entire answer to multilingual reach; it never offers to translate the drafted text
  itself (§3, AI unavailable/language state).

## 3. Every state

- **Collapsed (default).** Just the toggle.
- **Expanded, empty brief.** The form above, no result yet.
- **AI off by configuration** (any of the three switches). The toggle itself still renders —
  matching `Programme.jsx`'s `advisor.available` precedent — but expanding it shows only a note in
  place of the form: "AI drafting is unavailable: {reason}. Write the title and message directly
  below." and the manual fields are what the operator uses; there is no dead form to submit into a
  wall. This is a deployment-level "no," distinct from the three states below, all of which mean the
  feature is switched on but the local model itself is the problem right now.
- **Drafting, and it is taking a while.** Button reads "Drafting…", disabled; brief fields stay
  editable so the operator can keep adjusting while waiting. **Because this panel is reached from a
  live compose flow — sometimes mid-incident — waiting the full `short` budget (60 seconds,
  `config('services.llm.budgets.short')`) in silence is not acceptable.** After roughly 8 seconds
  with no response, an inline note appears beside the button, without replacing it: "Still
  drafting… you can keep waiting, or write it yourself now." with a **"Write it myself"** link. That
  link aborts the in-flight `compose-with-ai` request (`AbortController`, so a slow local model is
  not left doing pointless work once the operator has moved on) and collapses back to the manual
  fields, focus landing on Title — the operator is never forced to wait out a local model that has
  decided to be slow at the worst possible moment.
- **The local model is not reachable at all** (`ollama serve` not running, connection refused —
  typically returned in under two seconds, well before the "taking a while" note above would ever
  show). **"The AI model is not running right now. Write the title and message directly below."**
  shown immediately in place of "Drafting…", collapsing straight to the manual fields — there is no
  value in a retry affordance for a daemon that is simply not up, so this state offers none, unlike
  the timeout and unusable-output states below.
- **The local model is running but did not finish inside the short budget** (60 seconds). **"The
  model did not finish in time. You can try again, or write the message yourself below."** — kept
  retryable, because a busy self-hosted model recovering a moment later is the expected case on this
  architecture, not a fault to escalate.
- **The model answered, but the output could not be used** (malformed/truncated JSON, ADR 0023 trap
  20 — more likely on `granite4:micro`'s small context than on a larger hosted model). **"The
  draft could not be produced this time. Try a shorter description, or write the message yourself
  below."** — the brief's own fields are preserved for a retry, and the manual fields beneath remain
  the fallback path an operator was always going to need. None of these three model-side states is
  ever shown as a configuration error or as something to report; the copy is calm and immediately
  actionable, because the fallback (writing the message by hand) is always one click away and was
  always going to be needed occasionally on a self-hosted model this size.
- **Draft ready, in English, within budget, no truncation.** The result block above, template
  coverage grid included.
- **Draft ready, truncated after the shorten retry.** The amber truncation note (§2), never
  presented as an error — the draft still lands and is still usable, per the "warning, not a block"
  rule ADR 0023 states for readability and the same posture is taken here for the SMS budget.
- **Model output tried to set a reach field.** Per ADR 0023 §2.1's "AI writes words, humans decide
  reach" row, the composer's output contract (ADR 0023 §4.2) has **no field for severity, audience,
  channels or response options at all** — there is nothing for the model to set here, so this state
  cannot occur on this panel by construction, unlike a capability with a richer output contract. This
  spec states that explicitly because the guardrail test (`Phase12AiGuardrailsTest`) still asserts
  it, even though the panel itself needs no code path for it.
- **Hand-off in progress** (submitting the drafted title/message as a new alert). See §4 — the
  moment "Use this draft" is clicked, the panel's own responsibility ends and the persisted-draft
  screen (`Alert.jsx`) takes over.
- **Degraded network.** See §6.

## 4. Interactions and their server actions, and exactly what persists when

| Action | Route | What happens |
|---|---|---|
| Draft with AI | `POST bcms.alerts.compose-with-ai` | **Recommended default where ADR 0023 is silent on the UI mechanics:** this call returns the composer's result **without persisting anything** — `{title, message, voice_script, suggested_template_code, segments, encoding, will_truncate, readability_grade, template_coverage}` — so the operator can review, retry, or discard before anything exists as a row. ADR 0023 §4.3 describes the eventual persisted call (`AlertService::compose([...'ai_generated' => true])`) as happening from "the human's request," which this panel treats as the moment below, not the drafting moment — drafting and creating are kept separate so a rejected or abandoned draft never litters the alerts table with `ai_generated = true` rows nobody asked to keep. |
| Use this draft | Client-side: populates the manual form's `title`/`message` and sets a hidden `ai_generated_brief` field (the description, severity and context, so the eventual store call can pass them to `AiProvenance::record()`) | None — the panel closes, the manual fields now hold the drafted text, editable like any other typed text |
| Submit the compose form (with or without an AI-sourced title/message) | `POST bcms.alerts.store` (existing route), extended to accept the optional `ai_generated_brief` payload and, when present, set `ai_generated = true` on the created alert and call `AiProvenance::record()` against it | This is the **one** point at which an AI-assisted alert is actually created — matching ADR 0023 §2.3's rule that provenance is recorded "after the subject row exists" |
| Edit the drafted title/message before submitting | Ordinary form editing, no separate route | The moment a facilitator edits the AI text, it is still `ai_generated = true` on save — provenance is set once and never cleared by a human edit (ADR 0023 §2.1) |
| Dispatch (either the ordinary flow or an AI-assisted alert) | `POST bcms.alerts.dispatch({alert})`, `middleware(['permission:bcms.alert.dispatch', 'mfa'])` (unchanged) | **New requirement on this Form Request per ADR 0023 §4.4:** when `alert.ai_generated`, the request must carry `confirm_ai_content: accepted`. `Alert.jsx`'s dispatch control gains a required checkbox, visible **only** on an `ai_generated` draft: "I have read this AI-drafted message and confirm it is accurate." unchecked by default, the Dispatch/Release button disabled until it is ticked. The existing `window.confirm` wording is unchanged and fires **after** the checkbox is ticked, not instead of it — two different facts (content confirmed, and "are you sure you want to send this now") deserve two different controls, matching the crisis room's own separation of a re-grade reason from a send confirmation. |
| Dispatch from the crisis room (SitRep, roll-call, stakeholder comms) | Same `bcms.alerts.dispatch` route, same Form Request | The identical `confirm_ai_content` checkbox is added to the crisis room's own compose/dispatch panel (`crisis-room.md` §2, item 4) whenever the alert being sent is `ai_generated` — ADR 0023 §4.4 is explicit that this is required "on every dispatch Form Request, including the crisis room's." |

**The audience-and-channels gap this hand-off exposes.** `bcms.alerts.store` today requires
`channels` and `audience_rule` in the same request as `title`/`message` (Emns/Index.jsx's existing
form posts all of them together) — there has never been a draft alert with empty targeting before,
because every alert has always been created fully specified. An AI-composed alert is created the
same way: **the operator still fills in severity (already chosen in the brief), channels and
audience on the same compose form** before clicking submit — the AI panel only ever pre-fills
`title`/`message`, never bypasses the targeting fields. This keeps `Alert.jsx` itself unchanged and
avoids inventing a second "set audience later" screen the ADR does not ask for. **This is the
`ui-designer` default recommendation for the one point ADR 0023 §4.3 leaves open** ("Audience,
channels and response options are set by the human on the existing screen, as for any draft" is
satisfied by *this* existing screen, `Emns/Index.jsx`'s compose form, not a second edit on
`Alert.jsx`) — `backend-engineer` should confirm `compose-with-ai` is genuinely non-persisting
before building `AlertController::composeWithAi` to the letter of §4.3's prose, or flag if a
persist-first design is preferred, in which case `Alert.jsx` needs the audience/channel panel this
document's earlier draft assumed, and this section should be revisited.

## 5. Accessibility (WCAG 2.1 AA)

- **The "Draft with AI" toggle is a real `<button aria-expanded>`**, and expanding it moves focus to
  the Description field — a keyboard user opening the panel lands where they need to type, not at
  the top of the page.
- **The drafting state announces via `aria-live="polite"` exactly once per transition**: "Drafting…"
  on submit, then "Draft ready" or "The draft could not be produced" on completion — never the
  intermediate spinner state repeated.
- **The truncation and readability notes are static text on render**, not `aria-live` — they exist
  because a draft exists, not because something just changed mid-session.
- **"Use this draft" is the primary action once a draft exists**, reachable directly after the
  result block in tab order, before the voice-script disclosure and the coverage grid — a screen
  reader user should not have to tab through the coverage grid to accept the draft.
- **The dispatch checkbox (§4) has a visible `<label>` and is `required`**, announced as such; its
  disabled-until-checked effect on the Dispatch button is stated in text beneath the button ("Confirm
  the message above before sending") in addition to the disabled state itself.
- **Keyboard path (composer panel):** toggle → Description → Severity → Template hint (context row is
  not focusable, it is read-only) → "Draft with AI" → (once drafted) result text (not focusable,
  static) → voice-script disclosure → coverage grid (not focusable) → "Use this draft" → "Discard".
- **Contrast:** the AI-draft `sky-50`/`900` tone and the truncation `amber-50`/`900` tone are both
  existing, audited pairs; no new colour is introduced.

## 6. Low bandwidth (100 kbps)

- **The compose-with-ai call is small in both directions** — a brief of a few hundred bytes out, a
  JSON result of a similar size back (title, message, a handful of numbers, the coverage grid). It
  is not the scenario generator's 40–90 second job; ADR 0023 does not size a budget for it but
  `services.llm.budgets.short` (600 tokens / 60 s, ADR 0023 §2.2) is the one this capability uses, so
  the panel's own timeout expectation is under a minute, shown as a plain "Drafting…" state with no
  progress bar granularity to fake.
- **On a stalled request**, the button stays "Drafting…" and disabled rather than silently
  reverting, matching the module's general stalled-POST convention (`qr-checkin.md` §6); the
  8-second "Write it myself" escape hatch (§3) is this panel's own bounded-wait mechanism — tighter
  than waiting out the full 60-second server budget, because a slow local model on a poor network
  connection compounds two independent delays, and a crisis operator should never be made to wait
  out the worse of the two before they can fall back to typing.
- **The coverage grid is text, not an image** — five short labels and five glyphs, negligible
  weight.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Segment count, encoding, truncation flag | `SmsSegmenter::segments()`/`isGsm7()`, computed deterministically over the drafted (or shortened) message body — never asked of the model |
| Readability grade | `App\Support\Bcms\Readability` (new, deterministic), computed over the same body |
| Template locale coverage | `TemplateRenderer::coverage()`, the same source `Emns/Templates.jsx` already reads |
| `ai_generated`, `ai_drafted_at`/provenance on the eventual alert | Set at `bcms.alerts.store` time when the hidden `ai_generated_brief` payload is present; recorded via `AiProvenance::record()` against the created alert (ADR 0023 §2.3) |
| Severity | The human's own selection in the brief form, carried through unedited by the model (ADR 0023 §4.2) |
| Which of the four "could not draft" states is shown | A server-provided `{code, message}` on the `compose-with-ai` response — `disabled` / `unreachable` / `timeout` / `unusable_output` — the same four-way distinction `scenario-library.md` §3 and §7 use, never inferred client-side from a bare HTTP status, so a slow-but-healthy local model is never misreported as switched off |

## 8. Deliberately out of scope

- **Machine translation of the drafted message.** ADR 0023 is explicit and absolute: "It does not
  machine-translate anything, anywhere." The panel's only multilingual affordance is showing which
  *human-authored* template covers which locale.
- **The composer sending anything.** ADR 0023 §4.1: the composer service holds nothing that can
  dispatch, and this panel never renders a send control of its own — Dispatch lives entirely on
  `Alert.jsx`, unchanged in that respect.
- **A "regenerate" button that edits the existing draft in place.** Once "Use this draft" has been
  clicked, further changes are ordinary text edits to the manual fields, not a second model call —
  keeping exactly one place text is typed after that point, per §2.
- **Voice-call dispatch of the voice script.** Producing the script is capability 6's scope; wiring
  it into `VoiceTtsChannel` is unchanged by this phase.
