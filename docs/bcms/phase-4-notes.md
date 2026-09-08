# BCMS Phase 4 — the resilience calendar and the generation engine

**Track:** B · **Weeks 2–5** · **Lead:** architect · **Depends on:** G0; Phase 1's process catalogue

Built in the `riskerm-wt/bcms` worktree on `feature/bcms-module`.

> The phase prompt calls this the centrepiece of the module and says not to
> compress it. It was not compressed.

---

## 1. What landed

| Layer | Files |
|---|---|
| ADR | `0012` — **one column, and the two conflict sources this product does not have** |
| Schema | `2026_09_13_120001_add_bcms_generation_log.php`; manifest regenerated in the same commit |
| Enums | `DistributionMode`; `bcms_exercise_occurrence` added to the application morph map |
| Value object | `PreferredWindow` (validated scheduling grammar) |
| Domain | `Exercises\BlackoutResolver`, `Exercises\WorkingCalendar` + `CalendarYear`, `Exercises\OccurrenceGenerator`, `Exercises\ConflictDetector`, `Exercises\LadderAdvisor`, `Exercises\RescheduleService`, `Exercises\CalendarService`, `Exercises\IcsFeedBuilder`, `Exercises\ExerciseProgrammeService`, `Exercises\ExerciseDefinitionService`, `Exercises\ProgrammeAdvisor` |
| Event | `Bcms\ExerciseOccurrenceScheduled` — the seam Phase 5 arms from |
| HTTP | `CalendarController`, `ExerciseProgrammeController`, `ExerciseDefinitionController`, `OccurrenceController`, `ResilienceCalendarPresenter`, 1 form request, 12 routes |
| Front end | `Calendar/Index` (eight views), `Exercises/Programmes`, `Exercises/Programme` (dashboard + wizard + coverage matrix), `YearHeatGrid`, `BoundSection` reuse |
| Seeds | 18 definitions across 14 exercise types and 6 sites, 46 occurrences, a change-freeze blackout, and three deliberate failures |
| Tests | `Phase4CalendarEngineTest` (34), `Phase4ScreensTest` (15) |

## 2. The ten acceptance criteria

| # | Criterion | Status |
|---|---|---|
| 1 | `frequency_per_year = 4` generates 4 conflict-free occurrences across the year, avoiding month-end and Nigerian public holidays | **Pass** — and the segments are measured in working days, not calendar days |
| 2 | Dragging records a justified audit entry; inside `min_notice_days` it creates an approval instead | **Pass** — the approval carries before/after and applies the counter with the move |
| 3 | Two definitions sharing participants: the second shifts forward, visibly in the generation log | **Pass** — and exercises at different times of the same day correctly do *not* clash |
| 4 | A blacked-out segment produces `needs_scheduling` and nothing is silently dropped | **Pass** |
| 5 | Regenerating twice produces no duplicates; a completed occurrence is untouched | **Pass** |
| 6 | Ladder warning on a full-scale exercise for a process with no successful tabletop | **Pass** — generalised to every rung, and it warns rather than blocks |
| 7 | Year view renders 500+ occurrences in under 1.5s — measured, not asserted | **Pass** — 520 occurrences in ~0.15s on this machine |
| 8 | Compliance view shows a CBN cadence as "required / completed / overdue" against real occurrences | **Pass** |
| 9 | ICS feed subscribes in Outlook and Google and updates on reschedule | **Structurally pass** — see below |
| 10 | `ExerciseOccurrenceScheduled` fires with the payload Phase 5 needs | **Pass** — and does *not* fire on a regeneration onto the same dates |

### Criterion 9, honestly

A PHP test cannot claim that Outlook subscribed. What is tested is everything
that decides whether it will: the feed is valid RFC 5545 with CRLF endings and
75-octet folding on character boundaries, the `UID` is stable across a
reschedule, and `SEQUENCE` increments — which together are what make a client
*move* an appointment rather than create a second one. Regenerating UIDs is the
commonest way an ICS feed fills somebody's calendar with duplicates, and that is
the failure the test is really about.

The feed was also fetched over real HTTP against the running dev server,
unauthenticated, and returned a valid calendar; a tampered user id returned 403.
**Actually subscribing in Outlook and Google is [verify at integration]** at the
W8 window.

### Criterion 3(d) and (e), honestly

The prompt lists five conflict sources. Three exist here. **There is no audit
engagement register in this product** — thirdLine *is* this product, and what it
holds is `issues` with an audit *source*, which is an audit's output rather than
its diary. `ConflictDetector::auditEngagements()` names the seam and returns
nothing; inventing a table so the criterion could be ticked would put a register
in the product that nobody writes to. **Change-freeze windows are blackout
periods**, which the working calendar removes before the detector is asked
anything — and the demo estate now carries a "Core banking migration freeze" to
show it, which is also what produces criterion 4's unplaced occurrence.

## 3. The decisions worth not re-litigating

**The working-day set is the whole of `even` distribution.** Splitting the
*calendar* into four equal quarters and taking the midpoint of each puts an
exercise in the middle of December. Splitting the *working-day set* does not,
because December has far fewer usable days in it once the year-end close and
month-end are gone. On the demo tenant 2027 has 169 working days out of 261
weekdays, and the four midpoints land in February, May, August and November.
That difference is the whole of "does this engine understand a Nigerian bank's
year".

**A conflict is about people and time, not about dates.** Two exercises on the
same Tuesday are not a conflict; two exercises on the same Tuesday needing the
same fifteen people are. And a 45-minute fire drill at 09:00 does not collide
with a six-hour DR test at 14:00. Treating a day as atomic would shift half the
calendar for conflicts that are not real, and a generator that moves things for
no reason is one nobody trusts.

**A shift stays inside its own segment.** When a candidate day is taken, the
generator walks the *same* segment's remaining days, best fit first. Letting it
spill into the next segment is how four quarterly exercises silently become
three in Q4 — the calendar still shows four, and the regulator's question is
about the shape.

**`needs_scheduling` is a first-class outcome with a view of its own.** An
unplaced occurrence is invisible on every date-ranged view by construction, so
the year grid counts it separately and there is a dedicated list. An obligation
nobody can see is one nobody places.

**Every ladder rule warns and none blocks.** A bank with a full-scale failover
booked next month and no tabletop behind it has a real problem — but the
exercise is booked, the regulator is watching, and a system that refused to
record it is one people work around. Same argument `BiaValidator` makes about
the CBN's 30-minute threshold, same answer.

**Two blackout rules deliberately resolve to nothing.** Eid moves with the lunar
calendar and is declared days ahead; the election timetable is announced. A
computed date would be a wrong date most years, and the generator would then
avoid the wrong fortnight while the customer trusted it. They resolve to no
dates and are *reported as unresolved*, so the screen can say "confirm these"
instead of silently omitting them.

**Easter is computed by the anonymous Gregorian algorithm, not `easter_date()`.**
That function needs ext-calendar, which is not guaranteed on a customer's
on-premise PHP build, and a blackout calendar that silently loses Easter on one
deployment is exactly the difference nobody finds until an exercise is booked on
Good Friday.

**The advisor's evidence is computed; only the prose is a model's.** Which
processes are under-tested, how long since each was exercised and at what rung,
and which regulatory cadences are unmet are all questions this module can answer
exactly — so `ProgrammeAdvisor::gaps()` answers them with AI switched off
entirely. What the model adds is the proposed programme's shape. Nothing a
regulator sees is a model's arithmetic, and that split is the difference between
an AI feature a risk function signs off and one they do not.

**The ICS feed is a signed capability URL, not a token column.** Outlook sends no
cookie, so `auth` could never work; `URL::signedRoute()` gives a per-user,
tamper-evident address with no stored secret to leak or rotate. It is the first
member of a new category on `RouteAuthorizationTest`'s allowlist, and the
meta-test that guards that allowlist had to be updated deliberately — which is
what it is for.

**The reschedule approval rides on the platform's own engine.** `ApprovalRequest`
already does routing, payload application and audit. The payload *is* the
post-approval state, including the incremented `reschedule_count`, so approving
applies the move and the counter together rather than leaving them free to
disagree.

## 4. The defect this phase kept finding

**Four separate enum-cast mistakes, all the same shape.** `ExerciseType::ladder_level`,
`ExerciseOccurrence::status` and `ExerciseOccurrence::outcome` are all cast to
enums on their models, and code that read them as strings silently did nothing:

- `LadderAdvisor::levelOf()` parsed `ladder_level` as a string, got null, and
  **disabled every rule in the class** — the tests passed because they asserted
  on an empty warning list.
- `OccurrenceGenerator::isFixed()` compared `status` to `->value` strings, which
  are never equal to a case, so a regeneration would have **rewritten completed
  exercises**.
- `LadderAdvisor` cast `outcome` to a string and threw.

Phase 2's notes already record this trap ("a string never equals an enum and the
comparison silently always fails"). It is not a trap that gets learned once —
three of the four were found by running the code against the seeded estate
rather than by a test, and only one of them failed loudly.

**A `whereYear` on a nullable date silently excludes the nulls.** The year grid
counted `unscheduled` inside a loop over rows that could never contain an
unscheduled occurrence. It has to be a second query, and it is the one row the
grid most needs to show.

**`create()` returns only the attributes it was given.** A column default of
`true` is not on the model until it is refreshed, so `daily_reminder_enabled`
read as null — falsy — and the wizard's notification estimate quoted one
reminder instead of eleven. The service now sets the flags it later reads.

## 5. Known gaps handed forward

- **The reminder ladder is Phase 5's.** `ExerciseOccurrenceScheduled` fires with
  the occurrence and the definition; nothing listens yet. The calendar's right
  panel has a declared slot for readiness progress and the countdown so its
  shape does not change when they land.
- **Participants are resolved from the definition's audience rule, not from
  `bcms_exercise_participants`,** because nothing materialises participant rows
  until Phase 5/9. The detector already prefers real rows where they exist.
- **The demo generates 46 occurrences, not the prompt's ~85.** 18 definitions
  across 14 types and 6 sites as asked, with frequencies a real programme would
  carry. Inflating them to reach a number would have made the ladder coverage
  and the conflict density unrealistic, which is what the estate is for.
- **Contacts were given business units by this phase's seeder.** They inherit
  from the user record and the demo's users sit at organisation level, so every
  `org_node` audience resolved to nobody and a conflict detector that works on
  shared *people* had none to share. Phase 2C owns the roster properly.
- **The Gantt view lists rather than draws.** Bars ordered by ladder rank with
  their dates; the sequencing dependency is visible as ordering rather than as
  drawn arrows. Drag-and-drop rescheduling is likewise a modal rather than a
  drag — the governance is the feature and it is complete; the gesture is not.
- **The programme advisor has never met a live model.** Four switches now, all
  off by default.
- **`peak_periods` on a BIA is still free text.** Phase 2's handoff flagged it as
  something Phase 4 might need structured to avoid scheduling into month-end.
  It turned out not to be needed: month-end is a *blackout period*, which is
  organisation-wide, structured, and already seeded. The gap can be closed as
  answered rather than deferred.

## 6. HANDOFF

- **Phase 5** listens for `ExerciseOccurrenceScheduled` and materialises
  `bcms_reminder_schedules` from `lead_time_days`, `daily_reminder_enabled`,
  `reminder_mode`, `unannounced`, `default_audience_rule` and
  `default_channel_set` — all on the definition, all on the event. The generator
  must not change.
- **Phase 6** call tree tests are occurrences of a `CALLTREE` definition; the
  type is seeded and `unannounced` already suppresses the countdown.
- **Phase 9** execution opens from an occurrence; `readiness_complete` and
  `blocking_tasks_open` are stored on it and maintained by the readiness service.
- **Phase 11** wants `ExerciseProgrammeService::summary()` for the completion-rate
  KRI and `CalendarService::compliance()` for the board pack's cadence section.
  `completion_rate` returns `null` rather than 100% when nothing was planned —
  do not "fix" that.
