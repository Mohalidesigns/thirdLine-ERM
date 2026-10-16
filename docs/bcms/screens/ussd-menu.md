> **DEFERRED — not in Phase 12.** Scope change from the product owner: USSD is deferred until a
> client deployment is being prepared. This spec is kept for when that work resumes, but nothing
> in 12B is built against it now — do not implement from this document in Phase 12.

# Screen spec — the USSD menu (every screen's exact text, depth ≤ 3)

**Surface:** not a browser screen. `POST bcms/ussd/{provider}` → `bcms.ussd.session`, in
`routes/bcms-webhooks.php`'s thin `bcms-webhook` group, `throttle:bcms-ussd` first, then
`AfricasTalkingGateway::authenticate()` (HTTP Basic, fail-closed, `hash_equals`). Rendered by
`App\Services\Bcms\Ussd\UssdMenu`, driven live by `php artisan bcms:ussd-emulate {msisdn}
[--script=1,1]` (a developer/demo tool — an emulator of the aggregator, not a mock of our code:
same POST shape, same route, same middleware). No Inertia, no React, no CSS: this document is the
copy deck and the state machine a screen reader running over a terminal, a feature phone, or a
developer's emulator transcript must render identically.

**Reads:** ADR 0023 §5 in full — §5.1 (`UssdGateway`/`UssdRequest`/`UssdReply` contract), §5.2
(authentication, route, throttle), §5.3 (identity: `MsisdnResolver`, no tenant on this route), §5.4
(session state: cache, TTL 180s, degraded-mode rebuild, "never guess"), §5.5 (menu shape: depth ≤ 3,
only what applies, per-encoding character budget), §5.6 (the sameness rule — the table this
document's Interactions section restates as literal screen text), §5.7 (`UssdChannel` pull
semantics). Also `RenderedMessage::EXERCISE_PREFIX` and `TemplateRenderer::LOCALES` (five locales;
`en`/`pcm` ship in this phase, `ha`/`yo`/`ig` are X2).

**Siblings read before writing this:** `qr-checkin.md` (the plainest-language, most-forgiving-error
rule for a surface an unfamiliar member of the public uses with no help nearby — the exact register
this menu is written in, extended from a web page to 182-character screens); `crisis-room.md` §2
item 1 (the "THIS IS AN EXERCISE" prefix rule, non-negotiable, restated here for a channel with no
room for a banner component at all — the prefix is simply the first characters of the body).

---

## 1. Purpose and the caller

Someone with a feature phone, or a smartphone with no data balance, dials a short code during or
after an event that matters to them — a fire drill they're expected at, a real evacuation, a call
they were meant to receive and didn't. They have never seen this product's web screens, they are
often standing outside a building, and every screen costs them a moment of attention and, for some
networks, real money. The entire menu must be answerable from a phone's twelve keys with no help
text beyond what fits on the screen. **The single design constraint that outranks every other one
here: never show a caller a choice that resolves ambiguously, and never guess on their behalf** —
ADR 0023 §5.4's "a wrong alert settled tells a crisis manager someone is out of a building they are
still in" is the sentence this whole document is written against.

The **emulator** operator is a developer or a demo presenter proving the same route works without a
live short code (E4 is still external, §6 below). Its transcript output is this same copy, plain
text, one screen per line group, suitable for pasting into `phase-12-notes.md`.

## 2. The menu tree, exact copy, English

All copy below is `en`. Character counts are the **caller-visible body only**, per screen, GSM-7
(the alphabet `SmsSegmenter::isGsm7()` recognises); the 182-character ceiling is asserted by
`Phase12UssdTest`, not computed here — these drafts are written to sit comfortably under it, with
headroom for the exercise prefix (22 characters, `RenderedMessage::EXERCISE_PREFIX`) on any screen
that can render live content. `compliance-analyst` authors the `pcm` sibling of every string below
in `app/Support/Bcms/UssdCopy.php`, against the same slots and the same character budget — this
document defines the **English canonical text and the slot structure**, not the Pidgin words.

### 2.1 Level 1 — what do you need

Built **per caller**, listing only the actions this msisdn has something open for, in this fixed
priority order (never reordered by recency): roll-call response, read current alert, exercise
check-in, confirm a call-tree call. An option with nothing open for this caller is omitted and the
remaining options renumber from 1, contiguous — a caller must never see "3. Check in" with no 1 or 2,
wondering what they missed.

```
CON Kano Heritage Bank
1 I'm safe / I need help
2 Read the current alert
3 Check in to an exercise
4 Confirm you received a call
```
*(≈ 92 characters at full length; a caller with only one item open sees one line, e.g.:)*
```
CON Kano Heritage Bank
1 Read the current alert
```

**"Nothing open" and "unknown number" — identical text (ADR 0023 §5.5's hard rule):**
```
END This number has nothing open right now. If that's wrong, ask your BC coordinator.
```
*(≈ 84 characters.)* This is returned for a caller not found by `MsisdnResolver::contactsFor()`
*and* for a known contact with no open roll-call, alert, exercise or cascade — deliberately
indistinguishable, so a wrong-number caller learns nothing about who owns the number they dialled.

### 2.2 "I'm safe / I need help" (roll-call response)

**One open alert for this caller.** Level 1's selection **is** the level-2 confirm (§5.5: "with one
candidate, the confirm is level 2"):
```
CON Flood warning - Lagos Island. Are you safe?
1 I'm safe
2 I need help
```
*(≈ 60 characters, before the branch/site name — the title comes from `RenderedMessage`, truncated
on a word boundary to fit, never from `$alert->message` directly, per standing rule 5 and ADR 0023
trap 10.)*

**More than one open alert.** Level 2 lists them, titles truncated on a word boundary:
```
CON Which alert?
1 Flood warning - Lagos Island (10:02)
2 Robbery drill - HQ (09:40)
```
then the same Yes/No confirm as above, now level 3.

**Recorded (first answer):**
```
END Recorded. Stay safe.
```
```
END Recorded. Help is on the way — stay where it's safe to.
```

**Replay (first-answer-wins, ADR 0023 §6's rule extended to USSD by owner question 2's default —
the earlier answer stands and the caller is told plainly):**
```
END You already answered "I'm safe" for this at 10:05. If you need help now, call {help_line}.
```
*(the recorded value and time, exactly as the offline sync screen's `already_recorded` outcome
states it — the same honesty rule applied to a different channel.)*

### 2.3 Read the current alert

Renders through `TemplateRenderer::render($alert, ChannelKey::Ussd, $contact->preferred_language)`,
truncated to one segment (`SmsSegmenter::truncate($body, 1)` — 160 GSM-7 / 70 UCS-2 characters), the
exercise prefix applied first if `is_simulation`. This is a terminal `END` screen — there is nothing
further to select once the alert is read, and re-dialling shows it again (the record, not a session):
```
END {rendered body, ≤ 160 GSM-7 / 70 UCS-2 characters}
```
A simulation renders (prefix counted inside the same budget, since `TemplateRenderer` applies it
before truncation, per its own contract):
```
END THIS IS AN EXERCISE. {rendered body, truncated to fit}
```

### 2.4 Check in to an exercise

**One open occurrence this caller is a participant in:**
```
CON Check in to "Fire drill - HQ"?
1 Yes
2 No
```
**More than one:**
```
CON Which exercise?
1 Fire drill - HQ (09:00)
2 Assembly point drill - Annex (09:30)
```
then the same Yes/No confirm.

**Recorded:**
```
END Recorded. Thank you for checking in.
```
**Already checked in (idempotent replay, ADR 0023 §5.6 — `CheckInService::checkIn()` is set-once):**
```
END You were already checked in to this at 09:04.
```
**Chose "No" (a caller who opened this by mistake):**
```
END No check-in recorded. Dial again if you meant to check in.
```

### 2.5 Confirm you received a call (cascade acknowledgement)

**One pending cascade node:**
```
CON Confirm you received the call about "Fire drill - HQ"?
1 Yes
2 No
```
**More than one** — same list-then-confirm shape as §2.2/§2.4.

**Recorded:**
```
END Recorded. Thank you.
```
**Already acknowledged:**
```
END Already recorded — you confirmed this at 09:12.
```

### 2.6 Degraded, stale and fail-closed screens (ADR 0023 §5.4, §5.2)

**Cache miss or a stalled session store mid-session, where rebuilding from the database would
change what a stored selection means.** Never guessed:
```
END Please dial again.
```
*(the exact three words ADR 0023 §5.4 specifies — no elaboration, because at this point the system
genuinely does not know what the caller meant and must not imply that it does.)*

**A new alert arrives between two screens of the same session (stale-menu protection):**
```
END This has changed since you dialled. Please dial again.
```

**Rate-limited** (the throttle trips before the controller runs at all — see §5, the one open UI
question in this document). **Recommended default:** rather than let the aggregator's own generic
failure reach the handset, the controller responds with a USSD-shaped screen when the throttle key
is *this specific caller's*, not the whole route's:
```
END Too many attempts. Please wait a minute and try again.
```

**Authentication failure** (an unconfigured or wrong Basic credential on the callback). No USSD
screen renders at all — the gateway receives a 401 before `parse()` ever runs, and whatever the
aggregator's own platform shows the caller is outside this product's copy entirely. This document
records that deliberately: `authenticate()` fails closed, and a caller-facing "something went wrong"
screen would require trusting a callback the product has just decided not to trust.

## 3. Depth and character-budget summary

| Screen | Max depth | Longest English variant (chars) | Notes |
|---|---|---|---|
| Level 1 (what do you need) | 1 | ~92 (all four options) | Renumbered per caller |
| Level 2 (disambiguate) | 2 | ~90 for two items with dates | Titles truncate on a word boundary; a third open item is a design smell worth flagging to `qa-engineer`, not silently listing a third line — no caller in the tested scenarios has three simultaneously open items of one kind, and if one does, the list still renders, just longer |
| Level 3 (confirm) | 3 | ~65 | Yes/No only, never a third option |
| Read alert | 1 (terminal) | 160 GSM-7 / 70 UCS-2 | The one screen whose body is genuinely content-length-bound, not copy-bound |
| Nothing-open / unknown | 1 (terminal) | ~84 | Identical text for both cases, always |
| Degraded / stale / rate-limited | 1 (terminal) | ~75 | Never a guess, never a partial menu |

Every screen above sits comfortably inside the 182-character ceiling ADR 0023 §5.5 sets, with room
to spare for `pcm` — Nigerian Pidgin uses the same Latin character set as English and stays GSM-7,
so it costs no encoding penalty; `ha`/`yo`/`ig`, when they ship (X2), will "often force UCS-2" per
the ADR, at which point their screens are re-measured against the 70-character single-segment
ceiling, not assumed to fit because the English did.

## 4. Interactions — the sameness rule, made literal

| Menu action | Domain call, `via = 'ussd'` | Same record as |
|---|---|---|
| I'm safe / I need help | `RollCallService::record($recipient, SAFE\|NEEDS_HELP, $text, 'ussd')` | An SMS reply to the same alert — `Phase12UssdTest::a_ussd_safe_lands_on_the_same_recipient_row_as_an_sms_reply` |
| Read current alert | `TemplateRenderer::render($alert, ChannelKey::Ussd, $locale)` | Never `$alert->message` directly (trap 10) |
| Check in | `CheckInService::checkIn($participant, 'ussd')`, participant matched on `contact_id` or `user_id` | A QR/short-code check-in — `Phase12UssdTest::…check_in_lands_on_the_same_participant_row…` |
| Confirm cascade call | `CascadeEngine::acknowledge($node, 'ussd')` | A DTMF/voice acknowledgement on the same node |

Every selected id from a stored menu is **re-fetched with its eligibility predicate inside the
query** before anything is written (ADR 0016 §3, restated by ADR 0023 §5.4) — this document does
not specify that mechanism, only that no screen above may render success text before that check has
run.

## 5. Where this document leaves a UI question open, and the recommended default

ADR 0023 does not specify what the caller sees on a throttle rejection — §5.2 sizes the ceiling but
is silent on the response shape. **Recommended default (§2.6):** a plain USSD `END` screen when the
throttle key is per-caller (`{provider}+msisdn`), because that caller genuinely needs to know to
wait; the per-IP throttle key exists to bound abuse from one aggregator connection across many
callers and is not a caller's own fault, so no per-caller message is owed there — the request simply
fails at the gateway layer and the aggregator's own platform message shows, exactly as an
authentication failure does. This needs `backend-engineer`/`integrations-engineer` to confirm the
controller can distinguish which key tripped, or to accept the simpler fallback of a bare 429 with
no USSD body in both cases, which is safe (never wrong) but less kind to a caller retrying blind.

## 6. Accessibility and low bandwidth

USSD is, definitionally, the module's lowest-bandwidth surface — a handful of bytes per screen, no
image, no rich text, and it works on hardware with no accessible-technology stack of its own; the
"accessibility" of a feature phone menu is almost entirely the **plainness of the copy**, which §2
is written to. Two things remain in scope for this spec:

- **The emulator's terminal output is plain, linear text** — one screen per block, `CON`/`END`
  prefix kept visible (it is the actual wire protocol, not decoration), so a developer running it
  under a screen reader in a terminal hears exactly what a caller's handset would show, in order,
  with nothing rendered as an unlabelled symbol.
- **No screen ever uses a glyph, emoji or box-drawing character** — GSM 03.38 has none that read
  well on a decade-old handset, and every character outside the basic alphabet doubles the encoding
  cost (`SmsSegmenter`'s own reasoning, applied here even though this document computes fit rather
  than segments).

**Low bandwidth (100 kbps and below) is USSD's native case, not an edge case** — a session is a few
hundred bytes each way over signalling, not data, so there is nothing to defer and no "warm" vs
"cold" state to design for; the entire module's low-bandwidth rule (§7 of every other spec in this
directory) is, for this one surface, simply true by construction.

## 7. Deliberately out of scope

- **A "0. Back" or "0. Exit" option at every level.** Depth is capped at three and every screen
  states its own consequence plainly; adding a universal back option would cost characters on every
  single screen for a need the 180-second session TTL and the ability to simply hang up and re-dial
  already meet. If usability testing on real handsets (E4) shows callers wanting it, that is an
  amendment, not an assumption made here.
- **A push-mode registration screen.** `UssdChannel`'s `mode = push` exists behind
  `requiredConfig()` for aggregators that sell network-initiated USSD; no caller-facing menu text
  changes because of it, and this document does not specify one that has no caller to show it to.
- **Multi-language menu switching mid-session** (e.g. "9 for English, or 9 for Hausa"). Locale is
  resolved once, from `$contact->preferred_language`, before the first screen renders; a caller who
  wants a different language changes it through their own contact record, not through this menu.
