# BCMS performance baseline — the §14 NFR targets

**Owner:** reliability-engineer · **Opened:** BCMS Phase 0 · **Measured:** Phase 12, verified at Gate G3

These are the targets to beat, recorded now so that Phase 12's load tests assert
against Blueprint §14's own numbers rather than against whatever the system
happens to do by then. They are also in `config/bcms.php` under `nfr`, so a
change to a target is a visible diff in code review rather than a forgotten
paragraph.

## The targets

| Area | Target | Where it is asserted | Config key |
|---|---|---|---|
| Calendar year view, 500+ occurrences | **< 1.5 s** | Phase 4 screen test, Phase 12 load test | `nfr.calendar_year_view_ms` |
| EMNS dispatch to 10,000 recipients, **queued** | **< 30 s** | Phase 7 | `nfr.dispatch_queue_seconds` |
| First SMS **delivered** after dispatch | **< 60 s** | Phase 7, against a real gateway | `nfr.first_sms_seconds` |
| Contacts per tenant | **50,000** | Phase 2C sync test | `nfr.contacts_per_tenant` |
| Concurrent exercise check-ins | **200** | Phase 9 | `nfr.concurrent_checkins` |
| Every critical screen usable | **at 100 kbps** | Throttled Playwright run, **per phase**, not deferred | `nfr.low_bandwidth_kbps` |
| Platform availability | 99.9% | Infrastructure, G3 | — |
| EMNS dispatch path availability | 99.95%, with degraded-mode operation | Infrastructure, G3 | — |
| Self-DR RTO | **≤ 15 minutes**, rehearsed | G3 runbook rehearsal | — |

> **"Queued in under 30 seconds" and "delivered in under 60" are different
> measurements and both are needed.** Queuing 10,000 jobs quickly proves the
> fan-out; it says nothing about whether a gateway accepted the first one. A
> dispatch path that queues instantly and delivers in nine minutes has met one
> target and failed the requirement.

## What is already in place at G0

**Four queues, four Horizon supervisors** (`config/horizon.php`), sized
separately because the jobs fail differently — ADR 0005 has the reasoning.
`bcms-lifesafety` carries `minProcesses` so a worker is always warm; the 60-second
first-SMS target starts losing seconds to a cold boot otherwise.

**Gate G0 criterion 7 is the separation test:** a job on `bcms-lifesafety` is
picked up while `bcms-sync` is backed up with 10,000 jobs. It is asserted in
`Phase0FoundationsTest` at the configuration level — that the four supervisors
exist, are on separate queues, and that life safety is the only one guaranteed a
warm worker — because a real Redis backlog is a Phase 12 load test, not a unit
test. **The configuration assertion is necessary and not sufficient**, and it is
recorded that way rather than claimed as a pass.

**The watchdog** (`bcms:watchdog`, hourly) looks for the failures that produce no
error: a reminder past its `send_at` still `pending`, and a delivery row written
ahead of a provider call that never moved off `queued`. It reports and does not
repair — a watchdog that retried what it found would hide the fault it exists to
surface, and could re-send an alert that did go out.

## Not yet measured

Everything in the table above. Phase 0 ships the queue topology and the targets;
no load has been run and nothing here should be quoted to a customer as
achieved. The first real numbers arrive in Phase 12 and this file is where they
land, beside the target, with the date and the shape of the run.
