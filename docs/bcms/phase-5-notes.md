# BCMS Phase 5 — readiness checklists and the T-10 countdown · **GATE G1**

**Track:** B · **Weeks 5–8** · **Lead:** reliability-engineer · **Depends on:** Phase 4; the G0 contact and audience contracts

Built in the `riskerm-wt/bcms` worktree on `feature/bcms-module`.

> The phase prompt calls this the one that must be flawless, because it is the
> demo and because two failure modes end the feature. Every decision below is
> shaped by one of them.

---

## 1. What landed — and it needed **no schema at all**

| Layer | Files |
|---|---|
| ADR | **None.** Phase 0 specified this so well that nothing needed adding — see §2 |
| Content pack | `ReminderLadder` (the eleven rungs of Blueprint §5.4), `ReadinessTemplates` gained `TABLETOP` |
| Domain | `Reminders\ReminderScheduleBuilder`, `Reminders\ReminderDispatcher`, `Reminders\ReminderAudienceResolver`, `Reminders\DigestComposer`, `Reminders\ReadinessService`, `Reminders\AttendanceService` |
| Listener | `Bcms\MaterialiseReminderLadder` on `ExerciseOccurrenceScheduled` — the Track B seam, closed |
| Console | `bcms:dispatch-reminders` filled in (hourly); `bcms:watchdog` gained its alerting hook |
| HTTP | `ReadinessController`, 7 routes |
| Front end | `Exercises/Readiness` (checklist + gate + alert plan), `Exercises/MyReadiness` |
| Seeds | 270 participant rows, one exercise pulled to T-10 with 2 of 7 readiness items deliberately open |
| Tests | `Phase5CountdownTest` (20 — the twelve G1 criteria), `Phase5ScreensTest` (7) |

## 2. The ADR that was not needed

Four ADRs in five phases: fifteen changes, then eight, then five, then one. This
phase needed **zero**. Everything the countdown required was already there —
`bcms_reminder_schedules` with its unique idempotency key, the signed
`due_offset_days` on readiness template tasks, `override_reason` and
`overridden_by`, `bcms_notification_deliveries` with both nullable foreign keys,
and `quiet_hours`, `reminder_send_time`, `escalation_day_offset` and `timezone`
on `bcms_settings`.

Two things looked like they would need columns and did not:

**A deputy for a declined exercise.** `bcms_exercise_participants` already
carries a `deputy` role, so a decline creates a participant row rather than
filling a `deputy_user_id` column. A deputy who is a row is somebody the reminder
ladder resolves, the roll-call counts and the AAR lists; a column would have been
a second, invisible participant list.

**A per-user, per-channel idempotency key.** The phase prompt asks for
`sha256(occurrence, day, user, channel)`. ADR 0005 froze the SCHEDULE key as
`sha1(occurrence|offset|audience|template|mode)`, which is per *planned send*.
Both are right, at different levels: one planned send legitimately becomes
forty-six deliveries, so the per-recipient uniqueness belongs on the delivery
rows and the claim on the schedule row is what makes a re-run send nothing twice.

## 3. The twelve Gate G1 criteria

| # | Criterion | Status |
|---|---|---|
| 1 | Ten days out, one alert a day for ten consecutive days | **Pass** |
| 2 | Content varies with open readiness tasks | **Pass** — "3 of 7 readiness items outstanding — 6 days to …" |
| 3 | T-2 escalation to the line manager and programme owner | **Pass** — and explicitly *not* to the person who is late |
| 4 | Three dispatcher runs send exactly once | **Pass** |
| 5 | One person, five overlapping exercises, one digest | **Pass** — one message, five evidence rows, one provider id |
| 6 | Quiet hours defer and then send; never drop | **Pass** |
| 7 | Tenant timezone honoured across a UTC day boundary | **Pass** — and it found a real bug, §5 |
| 8 | Gating blocks the start; the override needs a reason and is audited | **Pass** |
| 9 | T-20 → T-5 rebuilds a compressed ladder and voids the rest | **Pass** — and it found a real bug, §5 |
| 10 | A worker dying mid-dispatch loses nothing and duplicates nothing | **Pass** — the write-ahead row is what proves it |
| 11 | The watchdog fires when the scheduler stops | **Pass** — and tells Atheris support, not only the tenant |
| 12 | The plan is accurate before any send | **Pass** — "22 alerts, 68 recipients" on the demo estate |

## 4. The decisions worth not re-litigating

**Discrete versus digest is the entire fatigue guard.** Four rungs arrive on
their own — the formal notice, the attendance request, the final brief, the
go-live — because each carries something that cannot be compressed into a line.
Everything else is one message per person per day, consolidating every exercise
they are in. A person in five overlapping exercises gets one email. That single
behaviour is the difference between a system people respect and one they filter
to junk, and it is the reason the T-9…T-4 band is `digest` and nothing else is.

**The daily band has two halves and they are disjoint.** People who still owe
something get the outstanding-items digest by email; people who owe nothing get a
one-line in-app note and no email at all. Sending somebody an email every morning
to tell them they have nothing to do is precisely the message that teaches them
to ignore the seventh one, which is the T-1 final brief.

**Escalation goes over the latecomer's head, not to them again.** By T-2 they
have had six daily reminders; a seventh is the definition of alert fatigue.
Escalation means somebody else now knows.

**One send, many evidence rows.** A consolidated digest is dispatched once and
writes a delivery row against *each* schedule row it covered, sharing a provider
message id. "Show me the reminders for this exercise" has to be complete even
when the message also mentioned four others; the shared id is what shows they
were one send, and `raw_response.consolidated_into` names it.

**Quiet hours defer; they never drop.** The row keeps `status = pending`, its
`send_at` moves to the next permitted window, and the reason is recorded. A
dropped reminder is failure mode (a) wearing a feature's clothes.

**A rung with nobody to send to is recorded as sent, with the reason.** It fired
and reached nobody, which the plan screen should say — rather than sitting
`pending` for ever and being reported by the watchdog as a stalled scheduler.

**In-app is not a `NotificationChannel`.** `ChannelKey` is frozen at G0 as the
eight channels that need a gateway. In-app needs none — this product has
`notifications_log` and a bell — so a ninth case would have meant a mock adapter,
a real adapter and a provider for a database insert, and would have changed a
frozen contract to model the *absence* of a gateway.

**The watchdog reports in-app and to a log line, not by email.** The fault it
detects is "the notification path has stopped", so routing the warning about it
through that same path is how a watchdog reports nothing at the moment it
matters.

**An override is not a completion and must not look like one.** The task becomes
`waived`, keeps its reason and its signatory, renders amber rather than green,
and the AAR carries it. Rendering it as a tick would let a bank run a DR failover
with no rollback plan and nothing on screen to say anybody decided to.

## 5. Four defects, three of them real bugs in earlier work

**`BcmsSettings::sendAtOn()` put every reminder a day early for any tenant west
of UTC.** It converted midnight-UTC into the tenant's zone — which for New York
is the *previous* evening — and then set 07:30, landing on the wrong calendar
day. Lagos is UTC+1 and hid it completely, so every test and the entire demo
estate passed. Found by criterion 7's "across a UTC day boundary" clause, which
is in the prompt for exactly this reason. Fixed by formatting the date and
reparsing it *in* the tenant's zone.

**`ChannelRegistry` was not a singleton.** Every caller got its own registry and
its own adapters, so `swap()` went to a throwaway instance while the dispatcher
used a fresh mock. Wasteful today; actively wrong the moment an adapter holds
state — a connection, a rate limiter, a batch buffer — which is exactly what
Phase 7 ships. This is the same defect as Phase 2's `BcmsSettings`, three phases
later, and both are now registered together with the reasoning.

**A compressing reschedule would have fired the whole run-up at once.** Moving an
exercise from twenty days out to five left the T-10 rung five days in the past,
and it was recreated as `pending` — so the next tick would have sent "ten days to
go" about something five days away, ten times over. The prompt names this
outcome ("a correct, compressed ladder — not ten missed sends") and the
implementation did the wrong thing until criterion 9 caught it. Rungs whose whole
day has passed are now voided with a reason. **The line is the start of today,
not this moment**: a ladder built at 09:00 for an exercise ten days out has its
07:30 notice ninety minutes in the past and that notice is genuinely due.

**The go-live rung trusted a stale start time.** It is measured from
`scheduled_start` rather than from the date, so anything that moved only the date
left it pointing at the old day. It now ignores a start time that is not on the
exercise's own date.

## 6. Known gaps handed forward

- **Every channel is still a Phase 0 mock, deliberately.** Phase 7 owns the real
  adapters and swaps them through `ChannelRegistry`. Nothing in Phase 5 asserts a
  gateway's behaviour — only the dispatcher's — so if a swap needs a change here,
  the abstraction is wrong and that is the defect.
- **The demo estate has six contacts, not the prompt's forty-six participants.**
  The contact roster is Phase 2C's and has not landed; six people is what the
  demo organisation has. The behaviours that matter — consolidation, the
  escalation, the two owners deliberately behind — are all demonstrable at that
  size, and the plan screen reads "22 alerts, 68 recipients".
- **Delivery to the queue is synchronous.** ADR 0005 specifies four Redis queues
  and the dispatcher is written to hand off to `bcms-reminders`; today it sends
  inline, which is correct against mock adapters and will not be against a real
  SMS gateway. Phase 7 moves the send into a job — the write-ahead row already
  makes that safe.
- **The escalation view (screen 5) and the digest email template (screen 4) are
  not built.** The escalation's *content* is composed and tested; what is missing
  is an owner-facing screen listing who is behind across every exercise, and an
  HTML email layout. Both are presentation over data that already exists.
- **Attendance confirmation has no deep link.** The T-3 message asks somebody to
  confirm or decline; the route exists and is tested, but the message does not
  yet carry a signed link to it. That belongs with Phase 7's channel work, where
  a callback token is already part of `RenderedMessage`.

## 7. HANDOFF

- **Phase 7 (EMNS)** reuses `ChannelRegistry`, `ContactResolver` and
  `bcms_notification_deliveries` unchanged, and swaps the mock adapters one at a
  time as each provider's paperwork clears. It also owns moving the send onto the
  `bcms-alerts` and `bcms-reminders` queues.
- **Phase 9 (execution)** opens the workspace from the `exercise.workspace_open`
  rung at T-0 and chases the AAR from T+1, T+3 and T+7. Those rungs exist and
  dispatch today; what they link to is Phase 9's.
- **Phase 6 (call trees)** gets unannounced exercises for free: the ladder
  already suppresses every participant rung and keeps the facilitator's.
- **Phase 11 (reporting)** should read `bcms_notification_deliveries` for the
  clause 7.4 evidence pack. It is evidence, not telemetry, which is why the row
  is written before the provider is called rather than after it succeeds.
