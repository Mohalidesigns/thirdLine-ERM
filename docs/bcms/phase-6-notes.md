# Phase 6 — Call tree management · GATE G2

**Branch:** `feature/bcms-module` · **Schema changes:** 1 column, 0 tables (ADR 0013)
**Track C, weeks 5–7.** Depends on Phase 1 (findings), Phase 4 (occurrences) and
Phase 2C (contacts) — of which **2C has not been built**, and §2 says what was
done instead.

---

## 1. What this phase is

A call tree is a laminated sheet on a wall. It fails in two ways and both are
silent: it goes out of date and nobody notices, and it names people who have
left. Everything in this phase is one of those two problems.

Twenty-two trees, a cascade engine that runs them in three modes, an
eight-metric scorecard stored at completion, and one screen that says
**"Ibrahim Sani unreachable → 34 staff isolated"** with four buttons under it.
That sentence is the gate.

## 2. Phase 2C has not been built, and this phase did not wait

The prompt says contacts, the AD manager-chain resolver and `ContactResolver`
"already exist from Phase 2C". Two of the three do — Phase 0 froze
`bcms_contacts` and `ContactResolver` at G0. The resolver does not.

**`bcms_contacts.manager_user_id` is the whole interface.** Phase 0 put the
column there; Phase 2C's staged AD/Entra sync is what fills it, after a human
approves the change report. `TreeProposalService` reads that column and nothing
else about any directory. So:

- Generation works **today**, against a hand-maintained roster.
- It works **unchanged** the week the sync goes live.
- It has no opinion about which directory a customer runs.
- Nothing here can write back to AD (standing rule 3) — there is no code path.

The same applies to §6.4's nightly sync. `bcms:call-tree-hygiene` **consumes**
the sync rather than performing one: by the time it runs, a leaver is already an
inactive contact. Reading LDAP here would be a second sync with its own opinion
about who works at the bank.

**What 2C still owes this phase:** the roster at real scale, the quarterly
verification campaign that moves the data-confidence KRI (computed here in the
meantime, from `last_verified_at` inside a ninety-day window), and manager links
that a person has not typed.

## 3. The decisions

**Staleness is computed, not stored** (ADR 0013 decision 2). The Phase 0
migration offers `stale` as a `status` value. Writing it there would destroy the
governance fact — an approved tree that goes stale would stop reading as
approved — and would make "overdue" depend on a job having run. A tree reviewed
on 1 January with a 180-day cycle is overdue on 30 June whether or not the
scheduler woke up.

`staleQuery()` and `isStale()` are written beside each other and have to agree.
The SQL builds **one arm per distinct cadence present** rather than
`DATE_SUB(NOW(), INTERVAL review_frequency_days DAY)`, which is MariaDB dialect
— Phase 4 already shipped one MariaDB-only predicate that passed every SQLite
test.

**One attempt is one person, not one channel.** Attempt 1 is the primary on
their best available channel; attempt 2 is the deputy. Multi-channel failover
within one attempt is the EMNS dispatcher's (Phase 7); modelling it here would
make `attempts` mean two things on one column, and `attempts >= 2` is how the
scorecard knows the deputy was needed.

**The write-ahead row is the test node**, not a delivery row (ADR 0013 decision
3). `bcms_call_tree_test_nodes` has `contacted_at`, `attempts`, `channel_used`
and `outcome`; they are written before `NotificationChannel::send()`, which is
standing rule 8 satisfied by the table Phase 0 built for it.
`bcms_notification_deliveries` has `alert_id` and `reminder_schedule_id` and no
column for a cascade — when Phase 7 makes the call tree an EMNS audience type,
a cascade dispatched *through an alert* writes delivery rows like any other
alert.

**`blocked` is a distinct outcome and it is what makes the demo possible.** A
node below a failure was never attempted. Recording it as `timeout` would blame
thirty-four people for missing a call nobody placed, and would make the tree look
four times more broken than it is. The live map paints it grey, not red, for the
same reason.

**Only the highest failure in a branch is reported as breaking it.** When a unit
lead fails and their team lead below them was therefore never called, both
failed but only the first caused anything. Listing both would double-count the
same thirty-four people.

**The blocked count is of people actually left unreached, not of descendants.** A
node with forty below it, six of whom acknowledged through the web link anyway,
blocked thirty-four. Reporting forty would be a number the cascade record itself
contradicts.

**Consent is not a data-quality failure.** It is reported on its own line with
the names. Counting a withdrawal as bad data puts pressure on somebody to "fix"
it, which is the pressure the NDPA exists to remove.

**Hybrid is the default.** A Nigerian examiner asking how the MD was informed
does not accept "an SMS was delivered" as evidence that a human was spoken to.
Tiers 0–1 are confirmed by a person, tier 2 down is dispatched, and the
scorecard reports the two halves separately (criterion 4) — a completion rate
that mixed them would flatter the automated half.

**Three permissions, not two.** Seeing a tree, drawing one and firing one at two
hundred people at 03:00 are three authorities. `bcms.calltree.test` is held by a
shorter list than `bcms.calltree.manage`. Acknowledging is `calltree.view`,
because the person confirming they were reached is a teller.

## 4. Deviations from the prompt

**`live` is polled JSON, not SSE or a websocket.** The blueprint asks for one of
those. This product broadcasts over the log driver and ships to on-prem estates
where a websocket through the bank's proxy is a project of its own. A cascade is
minutes long and hundreds of nodes wide, so a five-second poll of one small
document is sufficient and is the only option that works behind a corporate
firewall on the day of the demo. The live screen also **ticks the engine on
read**, so a cascade somebody is watching stays correct between the minutely
sweeps; both paths are idempotent.

**The designer is nested rows with indent guides, not a drag-to-reposition
canvas.** A canvas photographs better and is worse to use: a call tree is read on
a 360px screen at three in the morning. Re-parenting is a control on the row.

**The hygiene sweep flags; it does not raise a finding.**
`FindingSource::CallTreeTest` carries `call_tree_test_id`, and `FindingService`
correctly declines a source with a foreign key and nothing to point at —
otherwise a nightly sweep would file one unlinked finding every night for ever.
Inventing a ninth source for a sweep, or filing under `audit`, would both be
worse. The sweep writes `call_tree.hygiene_flagged` with the downstream count
and moves the dashboard, which is what criterion 6 asks for. The corrective
action is raised by a person from the broken-branch screen against a cascade
that actually ran (criterion 9).

**Deputies are never invented.** The obvious trick — make the next sibling the
deputy — produces a tree where every must-reach node has a deputy who has never
been told they are one. A missing deputy is a real gap on the health dashboard
(development standard §5).

## 5. The unannounced rule closed a hole in Phase 4

Criterion 7 needed the occurrence hidden from participants. Phase 4 kept an
unannounced exercise out of the ICS feed and Phase 5 suppressed its reminder
ladder — and **both were pointless, because the drill was sitting on the month
view where anybody could read it.** `CalendarService::baseQuery()` now excludes
it from anybody who cannot `bcms.exercise.manage`, except the definition's owner
and the occurrence's facilitator. The facilitator's own readiness ladder still
runs: unannounced means unannounced to participants, not to the person holding
the stopwatch.

## 6. Defects found

- **The dashboard's orphan count disagreed with the designer's orphan list.**
  `structure()` counted only nodes whose person had left; `orphanedNodes()`
  listed those *and* nodes with no usable address. One screen said 0 while the
  other listed one. Found by running the seeder, not by a test. Both now count
  the same set, with the two causes labelled separately in the list.
- **The first sibling in every group had no deputy**, because there was no
  earlier peer when it was inserted. The demo estate had ~100 spurious gaps,
  which would have hidden the five that are deliberate. A second pass fixes it.
- **Total cascade time measured to the wrong end.** It ran from initiation to
  whenever somebody clicked close, so the seeded demo reported a 200-person
  cascade as taking **0 minutes**. Blueprint §6.3 defines it as "Tier 0
  initiation → last Tier 3 acknowledgement", and it now is; a facilitator who
  leaves the screen open over lunch no longer turns an eleven-minute cascade
  into a ninety-minute one.
- **The acknowledgement page rendered from its own POST.** Recorded correctly,
  but the browser was left on a URL that re-submits when refreshed — and a
  refresh is exactly what somebody does when they are not sure a tap
  registered. It also made a second tap on the link in the message report the
  link as dead rather than the answer as recorded. Now POST → redirect → GET,
  and `nodeForToken()` is scoped to cascades that are still OPEN rather than to
  nodes that are still pending, so an answered node keeps resolving and says
  so. **Found by clicking the button in a browser after the suite was green** —
  the same way Phase 3 found the uuid routing defect.
- **The `stale` prefilter was a no-op.** The first draft of `staleQuery()` wrote
  `whereRaw('last_reviewed_at < ?', [now()])`, documented as "a cheap prefilter
  with the collection filter as the truth" — which would have made
  `staleQuery()->count()` return every tree. Rewritten to express the whole rule.

## 6a. One shared trait changed, and why

`ScopedToOrgHierarchy::visibleQuery()` and `scopeVisibleTo()` declared their
builder as `Builder<Model>`. Returning the builder they were handed, that made
every typed caller a variance error — and the pressure it created was to widen
the caller's own return type, which is exactly the wrong direction. Both now
carry `@template TModel`, so a `Builder<CallTree>` goes in and a
`Builder<CallTree>` comes out. Docblocks only; no behaviour changed, and PHPStan
is back to its 7 pre-existing findings across the whole product.

## 7. The demo estate

`CallTreeDemoSeeder`, run from `BcmsDemoSeeder`. 22 trees over ~440 contacts:
12 departments, 1 crisis team, 1 executive cascade, 8 branches.

**The Operations tree is the demo.** Four tiers, 200 people, and the first unit
lead deliberately carries **34** reports while the others share what is left —
real trees are lopsided, and the number on the screen has to come from the shape
of the tree rather than from a fixture. That lead's contact record has no mobile,
no email and no deputy, exactly as a real dead record looks: the tree still names
somebody and still looks complete.

**One cascade is run for real at seed time** — through the mock adapters, with
the reachable people acknowledging and the analyser computing what the failure
cost. Nothing is fixtured. If the count on the screen is wrong, the code is
wrong.

Deliberate flaws: 3 stale trees, 5 must-reach nodes stripped of their deputy,
8 contacts with dead numbers, 1 withdrawn consent. Every number is in the
reserved `+2348000` range and does not route.

The dashboard reports **22** must-reach nodes without a deputy, not 5: the other
seventeen are unit leads who are the only report of their section head and
therefore have no peer to deputise. That is a real gap on a real tree and the
seeder does not paper over it — inventing a deputy for a singleton node is the
thing §4 refuses to do. The five that are *stripped* are the ones a demo can
point at as having been repaired.

## 7a. What was checked in a browser, and what was not

The unauthenticated acknowledgement page was driven end to end at 375px against
the seeded estate: the link opens with the exercise prefix first, the button
records the response, the redirect lands on a thank-you that survives a refresh,
and a second visit says the answer is in. That is the one screen in this phase a
person reaches with no session, so it is the one that had to be checked by hand.

**The four authenticated screens were not opened in a browser.** They are covered
at the props boundary by `Phase6ScreensTest` and their links are covered by
`BcmsRouteKeyTest` — which exists because Phase 3 found every BCMS detail link
in the module broken in a way no feature test could see. Signing in needs
credentials, and typing those is not something to do on somebody's behalf.

## 8. Known gaps handed forward

- **Every channel is still a Phase 0 recording mock.** The live map and the
  results screen both say so on screen, because a green tick from a mock is the
  most dangerous thing this module can show. Phase 7 swaps them through
  `config('bcms.channels')` and nothing here changes.
- **Cascade dispatch is inline, not queued.** Correct against mocks, wrong
  against a real gateway at 200 nodes — the same gap Phase 5 has. The
  write-ahead row already makes the move safe.
- **USSD acknowledgement is `[verify at integration]`** at the W14 window. The
  inbound handler and the cascade record are built; Phase 12 wires the
  aggregator. The route accepts a `channel` and records it.
- **The inbound webhook has no provider signature verification.** It is
  throttled and matches an unguessable token inside the body. Each provider's
  scheme arrives with its adapter in Phase 7.
- **`nodeForToken()` scans pending nodes.** Correct and constant-time at cascade
  scale (hundreds). If a tenant ever runs thousands of concurrent cascades it
  needs an index, which needs a token column, which needs an ADR.
- **The KRI mirror writes only where the risk team has already defined the KRI.**
  `mirrorKris()` never creates one — a KRI this module invented on the risk
  team's behalf would appear on their dashboard without an owner.
- **Trees are not yet an EMNS audience type.** Phase 7.
- **A cascade is never auto-completed.** When every node has settled the test is
  finished in fact, and a human still closes it — the facilitator adds what the
  system could not see, and that note is evidence.
