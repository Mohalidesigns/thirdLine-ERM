# Screen spec — `Bcms/Incidents/CrisisRoom` (the war room, common operating picture)

**Route:** `GET incidents/{incident}/crisis-room` → `bcms.incidents.crisis-room`,
`middleware('permission:bcms.incident.view')`. Facilitate-gated sub-actions (logging a decision,
assigning a task, activating a plan, dispatching an alert) carry `bcms.incident.manage` below;
recording a regulatory submission carries `bcms.incident.notify` specifically. Route-model-bound on
the incident's uuid.

**Reads:** `plans/bcms/prompts/PHASE-10-incident-crisis-itdr.md` (Scope → Crisis room; Screens item
2); `docs/bcms/phase-10-incident-clause-map.md` §1.1 (the lifecycle table — stand-down as an act,
not an absence), §1.2 (what to stamp, and where), §1.3 (severity may only move with a reason), §2
(reportability — two clocks, "unknown" re-prompted), §2.3 (the field-by-deadline table); `docs/adr/
0020-...md` in full — this screen is that ADR made visible: two countdown rows on
`bcms_incident_notifications`, never a single tick.

**Siblings:** `resources/js/Pages/Bcms/Exercises/Workspace.jsx` (the two-column
`lg:grid-cols-[1fr_360px]` shape, the sticky metrics bar, the plain-list timeline with a pinned
composer — this screen's spine is that screen's spine, adapted from a rehearsed exercise to a real
event); `resources/js/Pages/Bcms/Emns/Alert.jsx` (the unaccounted-for-first roll-call layout, the
`is_simulation` violet banner logic — inverted here, see §2 — and the dispatch-confirmation weight);
`resources/js/Pages/Bcms/Findings/Index.jsx` (read-only reference for how a finding raised from this
screen renders back).

---

## 1. Purpose and the user

This is the one screen a crisis team looks at together — on a shared display in a physical war room,
on individual laptops in a virtual one, and on somebody's phone at an assembly point with one bar of
signal. It exists from the moment an incident is declared until stand-down. In the first ten seconds
anyone glancing at it — including a colleague looking over a shoulder — must be able to tell: is this
real or a drill, how severe, how long has it been running, what has been decided, what is still open,
and — the single fact this ADR cycle exists to make visible — **exactly how much time is left before
each regulator must be told, and which regulator that is.** A crisis console and the exercise
workspace share a visual grammar but never a purpose: nothing that reaches this screen is rehearsed,
which is why the banner logic here is the exact opposite of `Workspace.jsx`'s (§2, item 1).

## 2. Layout

`AppLayout`, `PageHeader` title `{incident.reference} — {incident.title}`, subtitle
`{severity_label} · {activation_level_label} · declared {declared_at_local}`. Header actions:
"Notification log" (link to `incident-notification-log.md`'s screen, always visible when
`can.view_notifications`), and — only for `can.manage` while `status !== 'closed'` — "Stand down"
(opens the stand-down screen, `incident-stand-down.md`, never inline here: stand-down is a
deliberate act with its own gate, not a button among many on a busy screen).

**Above the fold, in this order, full width:**

1. **Exercise banner, shown only when `incident.is_exercise === true`**, matching
   `Workspace.jsx`'s violet "THIS IS AN EXERCISE" treatment at the same visual weight. This is the
   inverse default of the exercise workspace: there, the banner is always on because nothing there is
   real; here, it is **absent by default** because this screen exists for real incidents, and it must
   be unmissable on the rare occasion a crisis simulation is run through it (a full-ladder exercise
   that declares a real-looking incident to test the crisis team, per the clause map's §6.11 note).
   Absence of the banner is itself part of the "tell in ten seconds" requirement, so this rule is
   stated to the frontend engineer as binding, not a default to be tuned later.
2. **Two regulatory countdown tiles, side by side, always both rendered whenever an open obligation
   of that kind exists — never merged into one "regulator notified" state.** Each tile:
   `{regulator label} — {kind label}` (e.g. "CBN — initial notification"), a live countdown to
   `due_at` in `HH:MM:SS` for the final hour and `Dd HHh` beyond it, the deadline's clock-face time
   alongside the countdown ("due 14:32 tomorrow"), and a colour band: slate more than 25% of the
   window remains, amber inside that, rose past `due_at` with the overdue duration shown instead of a
   negative countdown ("6h 12m overdue"). A tile for a regulator with **no open obligation** does not
   render at all — an absent tile is not "safe", it means nobody has classified this incident as
   owing that regulator anything yet, which is exactly why §3's "unknown" state exists as its own
   banner, not a blank space. Clicking a tile opens the notification log for that regulator/incident
   (`incident-notification-log.md`).
3. **Unresolved-reportability banner** (`amber-50`/`amber-900`), shown whenever either of the two
   declaration-time questions is still `unknown`: "Reportability to {CBN / the NDPC} is still
   unresolved. Answer it now — the clock, once started, runs from when the incident was first
   detected, not from today." with an inline Yes/No/Still unknown control per outstanding question,
   posting immediately (§4) — the clause map's re-prompt requirement made concrete rather than a
   one-off form nobody returns to.
4. **Readiness/mock banners** are not applicable here (this is not an exercise occurrence) — the
   equivalent fact on this screen is the plan-activation panel's own "not yet activated" state (§2,
   item 7 below), not a borrowed banner.

**Sticky metrics bar** (`sticky top-0 z-10`, the `Workspace.jsx`/`Live.jsx` convention, reused
verbatim), one row of tiles: **Elapsed** (since `declared_at`, client-computed, never re-fetched every
tick), **Activation level**, **Open tasks**, **Unaccounted for** (only shown once a roll-call has been
dispatched from this incident — sourced identically to `Alert.jsx`'s own tile, §7), **Decisions
logged**. Every tile is a plain number-and-label pair, no chart, per the module's low-bandwidth
convention for this exact kind of screen.

**Below the fold, two-column `lg:grid-cols-[1fr_360px]` (desktop); single column, reordered, on
mobile per §6:**

- **Left, the decision log (the spine).** A reverse-chronological list over `bcms_incident_log`, one
  row per entry: `logged_at` (`HH:mm`), a type chip (`decision` slate, `action` blue, `communication`
  violet, `situation_report` gold-accent, `escalation` rose), `content`, `logged_by`, and — the load-
  bearing detail this screen adds beyond the exercise workspace's timeline — **a visible amendment
  chain**: any entry with `supersedes_entry_id` renders directly beneath the entry it supersedes,
  visually nested (indented, joined by a thin left border), both kept on screen, the newer one
  labelled "Supersedes the entry above" rather than the older one being hidden. This is not
  decoration: "ISO 22361 treats stand-down as an act, not an absence of activity" and the same holds
  for a corrected decision — the record must show that a correction happened and what it replaced, an
  examiner's first read (clause map §1.2). A sticky **decision composer** above the list: an entry-type
  select (`decision` default — this is the ISO 22361 artefact, so it is the default choice, not
  `manual`/generic as the exercise workspace defaults to), a content textarea, and — **only for
  `decision` entries** — two extra fields the clause map requires be captured at the moment, not
  reconstructed later: "Options considered" and "Rationale" (both short text, both required for a
  `decision` type specifically, optional for the other four types). A "This corrects entry #{ref}"
  toggle appears once an existing entry is selected from the list (click any row to select it for
  correction), which sets `supersedes_entry_id` on submit rather than opening a separate edit form —
  there is no edit control on this screen, only supersession, matching the clause map's "append-only
  in practice" rule.
- **Right, four stacked cards:**
  1. **Severity & activation.** Current band, activation level, and a **"Re-grade"** action
     (`can.manage`) that reopens the declaration screen's severity-matrix component inline as a small
     modal, requiring the same reason field as declaration-time override — because §1.3's rule that a
     severity move needs a stated reason applies for the incident's whole life, not only at T+0.
  2. **Plan activation.** One row per plan activated against this incident (from `bcms_plan_activations`
     where `incident_id` matches): plan name, version/approved-date, `activated_by`/`activated_at`, a
     link to the plan's offline bundle (Phase 3). An **"Activate a plan"** button (`can.manage`,
     `bcms.plan.activate`) opens the same modal `Plans/Show.jsx` already uses (reason required), posting
     to `bcms.plans.activate` with `incident_id` set and `is_exercise` forced `false` unless
     `incident.is_exercise` — plan activation from a drill-flagged incident stays flagged, per the
     ADR's own reminder that Phase 10 must not write `is_exercise` incorrectly anywhere it touches.
  3. **Tasks board.** A compact list, not a kanban (the prompt's "live board" is satisfied by grouping,
     not a drag surface a crisis team has no time to operate): grouped by status (`open`,
     `in_progress`, overdue-highlighted in rose, `complete` collapsed under a "show {n} complete"
     toggle). Each row: title, owner, due time (relative — "due in 20 min" / "40 min overdue"),
     a quick "Mark complete" action (`can.manage`). An inline "Add task" mini-form (title, owner,
     due-at) sits above the list, matching `Findings/Index.jsx`'s `AddAction` shape.
  4. **Comms & roll-call.** Three buttons stacked: **"Compose a SitRep"** (opens the SitRep composer
     panel inline — a templated textarea plus an audience-group multi-select, posting as an EMNS alert
     with `incident_id` set and `entry_type = situation_report` written to the decision log
     automatically by the server on dispatch — this screen never writes the log entry itself, so the
     two records cannot drift); **"Dispatch a roll-call"** (`bcms.alert.life_safety` — posts an EMNS
     alert with `incident_id` set, `is_simulation = incident.is_exercise`, and redirects into
     `Emns/Alert.jsx`'s live roll-call view rather than duplicating that screen's headcount UI here,
     per the clause map's own instruction that roll-call "stays Phase 7's dispatcher"); and **"Compose
     a stakeholder communication"** (customer/regulator/media/vendor — same composer shape as the
     SitRep but `entry_type = communication`, with an **approval-path note** shown before send when the
     audience is `regulator` or `media`: "This goes to {audience} and is recorded exactly as sent —
     confirm before sending" per the clause map's "audit record of exactly what was said" requirement).
     Below the buttons, a compact read of the last three communications sent, each a one-line summary
     linking to its full decision-log entry.

## 3. Every state

- **Loading.** Server-rendered; the metrics bar and both countdown tiles poll after mount (§6).
- **Open, `activation_level = full`.** The default state described above, every panel active.
- **Open, lower activation levels** (`standby`/`partial`/`monitor`). Same layout; the plan-activation
  card shows "No crisis-level activation required at this level" when nothing has been activated, and
  the tasks/comms cards remain fully usable — a sev3 incident still needs a decision log, just not a
  convened crisis team.
- **Both reportability answers resolved `No`.** Neither countdown tile renders; a single
  `slate-50` line under the header states: "Not currently classified as reportable to the CBN or the
  NDPC. This can be changed at any time from the panel below." — never silence where a fact belongs
  (matching the AAR builder's rule that a zero must read as a stated fact, not an absent measurement).
- **One or both answers `unknown`.** The unresolved-reportability banner (§2, item 3) persists on every
  load until resolved — this is the "re-prompt" the clause map requires, not a dismissible toast.
- **A classification changes from `No`/`unknown` to `Yes` for either question, mid-incident.** Per
  ADR 0020 Amendment 2 rules 5-7 (corrected from this document's earlier "defaults to now", which
  code-reviewer defect 4 caught: nearly every incident would fail to classify under that default, and
  the direction it nudged an officer toward — later awareness, without a reason — is precisely the one
  the amendment exists to guard against), this action **is** the creation of the notification row,
  with `awareness_at` **defaulting to the incident's own `detected_at`, falling back to `declared_at`,
  never to "now"** — a clock that starts when somebody clicked is often hours late on a busy night.
  The crisis-room control for this is one small form, **shared by both the CBN and the personal-data
  questions, and shared with `incident-notification-log.md`'s own "Classify a new obligation" control**
  via `resources/js/Components/Bcms/AwarenessField.jsx` — defect 4 was found once on each screen and
  both are fixed from the same component so they cannot drift back apart (`ClassifyBcmsIncidentRequest`
  validates `awareness_at`/`awareness_reason` identically regardless of `question`): a datetime field
  pre-filled with that default, editable **earlier with no
  justification** (it only shortens the bank's own window) and **later only with a required "Why is
  awareness later than detection?" reason** (`awareness_reason`) — so the criterion-5 test case
  (detected yesterday, classified today, deadline yesterday + 72h) is answerable from what this screen
  actually submits by default, with no typing required, rather than from an assumption the backend
  papers over. If both `detected_at` and `declared_at` are absent, the server refuses classification
  and its message renders inline under the field — the screen does not invent a start for the clock.
  The new countdown tile appears immediately on success.
- **A countdown reaches zero / goes overdue.** The tile turns rose and its label changes from a
  countdown to an elapsed-overdue duration; nothing else on the screen changes state — an overdue
  clock does not block any other action, because refusing to let a crisis team keep working while a
  regulatory deadline has passed would be exactly the wrong failure mode.
- **Roll-call dispatched.** The "Unaccounted for" metrics-bar tile appears (it does not exist before a
  roll-call has been sent) and updates from the same 5-second poll `Alert.jsx` already uses; the
  "Dispatch a roll-call" button becomes "View roll-call" (link into `Emns/Alert.jsx`).
- **No plan available to activate for this incident's process/unit.** The plan-activation card states
  so, with a link to the plan register rather than a disabled button.
- **Incident closed** (`status = closed`). The whole screen becomes read-only: the composer, task
  form, comms buttons and re-grade action disappear; a banner replaces them: "This incident was closed
  at {closed_at_local}. Continue to the post-incident review" with a button into `pir-post-incident-
  review.md`'s route (creating the PIR row first if none exists, mirroring the AAR builder's own
  "create on first visit" behaviour). Countdown tiles for any obligation still open at closure
  **continue to render** — closing an incident does not close an outstanding regulatory clock, and the
  clause map is explicit that a reportable incident may not be closed with an unresolved notification
  and no recorded decision that it was not, in fact, reportable; this screen's read-only closed state
  is where that fact stays visible rather than disappearing with the rest of the controls.
- **Permission-denied.** Standard 403 for `bcms.incident.view`; sub-actions requiring
  `bcms.incident.manage`/`.notify`/`bcms.plan.activate`/`bcms.alert.*` simply do not render their
  controls for a viewer without them (read-only render of the same panels), matching the AAR builder's
  "no apparent means to do the thing you cannot do" rule.
- **Server/network error on a foreground action.** Standard Inertia handling; the composer's typed
  text is preserved, matching `Workspace.jsx`.
- **Degraded network.** See §6.

## 4. Interactions and their server actions

| Action | Route | Confirmation |
|---|---|---|
| Log a decision/action/comms/escalation entry | `POST bcms.incidents.log.store` | None for a fresh entry — additive and never destructive. |
| Correct (supersede) an entry | Same route, with `supersedes_entry_id` set | A lightweight inline confirm naming what happens: "This is recorded as a correction — both entries stay visible." No modal; matches the weight of logging itself, since nothing is deleted. |
| Answer / re-answer a reportability question | `POST bcms.incidents.classify` (name per convention — the backend engineer's endpoint for the two-question state, distinct from the general `update`) | None for `No`/`Unknown`; for **Yes** to either question, the awareness-time sub-form (§3) is itself the confirmation — there is no separate "are you sure", because the awareness-time field (pre-filled from `detected_at`/`declared_at`, a required reason if moved later) already forces a deliberate answer. Server-side validation errors on `awareness_at`/`awareness_reason` render inline under the field, not as a lost click. |
| Re-grade severity | `POST bcms.incidents.regrade` (name per convention) | Reason required inline, matching declaration's override field — no modal, because it is a text requirement, not a destructive step. |
| Activate a plan | `POST bcms.plans.activate` with `incident_id` | Reason required (existing `Plans/Show.jsx` modal, reused, not rebuilt). |
| Add / complete a task | `POST bcms.incidents.tasks.store` / `POST bcms.incidents.tasks.{task}.complete` | None. |
| Compose & dispatch a SitRep | `POST bcms.alerts.store` (with `incident_id`, `entry_type=situation_report`) then `POST bcms.alerts.dispatch` | Same dispatch-confirmation weight as `Alert.jsx`'s own `window.confirm`, wording adapted: "Send this SitRep to {audience}?" |
| Dispatch a roll-call | `POST bcms.alerts.store` (`incident_id`, life-safety template) then the existing dispatch flow | Same as any life-safety dispatch — `Alert.jsx`'s existing confirm, unchanged. |
| Compose & send a stakeholder communication | Same as SitRep, `entry_type=communication` | As above, plus the audience note (§2) shown inline before the confirm when audience is regulator/media. |
| Record a regulatory submission | Handled on the notification-log screen (`incident-notification-log.md`), reached via the countdown tile or the header link — **not inlined here**, because a submission record carries content fields (reference, snapshot) too long for a crisis-room card, and because keeping the act of *recording a submission* physically separate from the countdowns that watch it is what stops the two screens' states from silently drifting apart. | — |
| Stand down | Navigates to `incident-stand-down.md`'s route — not an inline action on this screen (§2). | — |

No numeric id is ever spliced into a URL from this screen; every link and action target is a
server-built prop (`incident.uuid`, `entry.id` used only as a React key, never in a route), per
`ModuleActionUrlRouteKeyTest`.

## 5. Accessibility (WCAG 2.1 AA)

- **Both countdown tiles carry their deadline as visible text, never colour alone**, and each is a
  `role="timer"` region with `aria-label` stating the full sentence once on mount ("CBN initial
  notification due in 18 hours 40 minutes, at 14:32 tomorrow") — the ticking value itself is **not**
  `aria-live`, matching the exercise workspace's rule against re-announcing a number every second; a
  screen-reader user can tab to the tile to hear the current value on demand.
  When a tile crosses into "overdue", that specific transition **is** announced once
  (`aria-live="assertive"`, a single "CBN notification is now overdue" utterance) because crossing a
  regulatory deadline is exactly the kind of state change the alert-fatigue rule's exception is meant
  for — one clear, rare, consequential announcement, not a stream.
- **The exercise banner's absence is not itself something a screen reader needs told** — there is
  nothing to announce about a banner not being there; a screen reader user hears the countdown tiles
  and the severity/activation subtitle instead, which already state that this is a live incident.
- **The decision log's supersession chain uses real nesting** (`<li>` inside `<li>` or an `aria-
  level` increment) so a screen reader announces "this entry corrects the previous one" as structure,
  not merely as adjacent text.
- **Decision-composer's two decision-only fields ("Options considered", "Rationale") are announced
  as required specifically when `entry_type = decision`** — `aria-required` toggles with the select,
  same pattern as the observer-scoring screen's low-score commentary requirement.
- **Keyboard path:** exercise banner (when present, not focusable) → countdown tiles (each a single
  tab stop, `role="timer"`, activates its click-through on Enter) → unresolved-reportability banner's
  inline controls (when shown) → header actions (Notification log, Stand down) → metrics bar (not
  focusable) → decision composer (type select → options/rationale fields when applicable → content
  textarea → Log button) → decision log list (each entry a static `<li>`, its "select to correct"
  affordance reachable as a button per row) → severity/activation card's Re-grade action → plan-
  activation card's rows and Activate button → tasks card's add-form and per-row complete buttons →
  comms card's three compose buttons in the visual order given in §2.
- **Contrast:** the rose "overdue" state uses the module's existing rose-700/rose-50 pairing (matching
  `Alert.jsx`'s "unaccounted for" tone); the gold situation-report chip follows the same
  reduced-opacity-background rule the exercise workspace already documents for Gold as an accent, never
  as body text.

## 6. Low bandwidth (100 kbps) and mobile behaviour

This screen must work at 100 kbps on a phone at an assembly point or a flooded branch — the exact
scenario the module's low-bandwidth rule exists for, and the crisis room is where it matters most,
because unlike the exercise workspace this is never a rehearsal.

- **Poll interval 5 seconds**, one small JSON document — `GET bcms.incidents.live-metrics` returns
  `{ metrics, countdown_tiles, counts, latest_entry_id, latest_entry_at, status }` **and nothing
  else**: no `entries`, `tasks` or `plan_activations` array, and never the full crisis-room payload
  (a prior build shipped the whole screen's data on every tick; this is the fix). The metrics bar and
  both countdown tiles update in place from that body every 5 seconds, on a phone as cheaply as on a
  war-room display. **The countdown tiles do not depend on the poll to keep ticking** — they are
  computed client-side against the stored `due_at` between polls (a plain JS interval, no network
  cost); the poll only re-syncs `due_at`/`is_overdue` in case a submission or classification changed
  it from another device.
- **The decision log, task list and plan-activation panel are not re-fetched every tick.** They are
  ordinary Inertia props, unchanged by the poll, until the poll itself detects a reason to refresh
  them: a `latest_entry_id` that differs from the last one this screen saw, a change in `counts`
  (open/closed tasks, active plans, roll-call responses), or a change in incident `status`. Any one of
  those triggers exactly one partial reload, `router.reload({ only: ['entries', 'tasks',
  'plan_activations', 'incident'], preserveScroll: true })` — the three lists refetch themselves, not
  the countdown tiles or the metrics bar, and the crisis team's scroll position and open forms are
  untouched.
- **On a failed poll**, the same "last updated {n}s ago — connection is slow or down" note
  `Workspace.jsx` uses, placed directly under the metrics bar — critical here because a facilitator
  relying on this screen during a real disruption must know if the unaccounted-for count in front of
  them is live. **After two consecutive failed polls**, the interval backs off from 5 seconds to 30
  — a flooded branch's one bar of signal should not be spent retrying every 5 seconds against a
  connection that has already failed twice — and the note becomes "Reconnecting… last updated {n}s
  ago", `aria-live="polite"` (a status update worth announcing once, not the ticking number itself).
  The next successful poll resets both the interval and the note back to normal. Polling stops
  outright once the incident's status is terminal (closed or cancelled — stand-down is exactly this
  transition), matching §3's "incident closed" read-only state; there is nothing left on this screen
  a live poll would still need to refresh.
- **Mobile layout reorders rather than hides.** Single column, in this order: exercise banner (if
  any) → countdown tiles (stacked, full width — these are the single most important facts on a phone
  screen and must not be pushed below the fold by the metrics bar) → unresolved-reportability banner →
  metrics bar (wraps to two rows) → decision composer (collapsed to just the content textarea plus a
  "More fields" disclosure for options/rationale, so logging a one-line decision from a phone takes
  one tap, not a scroll through two extra fields every time) → decision log → the four right-column
  cards, each as a full-width collapsible section, **plan activation and comms expanded by default**,
  tasks and severity/activation collapsed by default (the two most likely actions from a phone at an
  assembly point are activating a plan and sending a comms/roll-call, so those stay open).
- **No image, chart or map on this screen at all.** Every fact is a number, a label or short text;
  the tier-progress-style bar used elsewhere in the module has no equivalent here because nothing on
  this screen is a proportion worth a bar — a countdown is a duration, best read as digits.
- **The composer posts a small payload** (a few hundred bytes) — no autosave sync, matching the
  exercise workspace's own reasoning: the browser's own retained form state is the protection against
  a dropped connection mid-entry, not a background save.

## 7. The numbers on this screen, and where each comes from

| Number / fact | Source |
|---|---|
| Elapsed time | Computed client-side from `incident.declared_at`, refreshed by the poll tick |
| Both countdown tiles' `due_at`/`submitted_at` | `bcms_incident_notifications`, one open row per regulator per incident (ADR 0020 §2) — `due_at` read as stored, **never recomputed on read** |
| "Unaccounted for" and roll-call headcount | The same `RollCallService` summary `Alert.jsx` already reads, for the alert whose `incident_id` matches this incident |
| Open tasks / decisions logged counts | `bcms_incident_tasks` where `status != complete`; `bcms_incident_log` count, this incident |
| The decision log itself, and the supersession chain | `bcms_incident_log`, ordered `logged_at desc`, `supersedes_entry_id` resolved to nest |
| Plan-activation rows | `bcms_plan_activations` where `incident_id` matches, joined to `bcms_plans` for name/version/approval date |
| Severity/activation-level labels | `bcms_incidents.severity` / `.activation_level`, cast through the new `IncidentSeverity`/`ActivationLevel` enums (ADR 0020 §3) |
| The unresolved-reportability banner's presence | Whichever of the two classification questions is still `unknown` — a derived boolean, not stored separately |

## 8. Deliberately out of scope

- **Recording a regulatory submission itself** — a separate screen, `incident-notification-log.md`,
  reached from this one; keeping the write action off the crisis room is a deliberate separation (§4).
- **The full roll-call headcount console** — `Emns/Alert.jsx`, linked to, not duplicated.
- **Stand-down** — its own screen (`incident-stand-down.md`), reached from the header link, not an
  inline modal here (a stand-down decision deserves the same weight as `Finalise` on the AAR builder,
  not a button among a dozen others).
- **The post-incident review** — `pir-post-incident-review.md`, reached only once the incident is
  closed.
- **DR-specific tiles** (RTO clock, downtime) — those live on the exercise workspace for a *planned*
  DR test occurrence; a real IT-DR invocation run through this crisis room shows its outage timeline
  as ordinary decision-log entries and its actuals in the PIR, per the clause map's real-invocation
  rule (`docs/bcms/phase-10-incident-clause-map.md` §3.4) — this screen does not grow a second DR
  metrics bar for that case.
- **Editing a `final` PIR's own fields from here** — none of this screen's actions touch a finalised
  review; that gate is entirely `pir-post-incident-review.md`'s.
