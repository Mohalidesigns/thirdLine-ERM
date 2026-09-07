<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule GRC commands
Schedule::command('issues:check-overdue')->dailyAt('08:00');
Schedule::command('kri:check-breaches')->dailyAt('07:00');
Schedule::command('treatments:check-overdue')->dailyAt('08:30');
Schedule::command('regulatory:check-deadlines')->twiceDaily(8, 16);

// RCSA v2 P5 (§9.3). After the other overdue sweeps, so a morning's digest
// reports one consistent picture. Reminders fire at exactly T-14, T-7 and T-0,
// which is what makes a second run in one day a duplicate rather than a wrong
// answer — see CheckRcsaActionPlans for why there is no "last reminded" column.
Schedule::command('rcsa:check-action-plans')->dailyAt('08:45');

// P7 (§11). Before the action-plan sweep, so a unit that is behind hears about
// the assessment first and the remediation second. Same T-14/T-7/T-0 milestone
// shape, which is what makes both idempotent without a "last reminded" column.
Schedule::command('rcsa:check-cycle-deadlines')->dailyAt('08:40');

// WP-04. The CBN publishes rates on business days; the fetcher runs before the
// KRI check so a monetary limit is evaluated against that morning's rate.
Schedule::command('fx:fetch-cbn-rates')->weekdays()->dailyAt('06:30');

// WP-08. After the overnight checks above have updated statuses, so the
// digest reports the morning's truth. Weekdays: a Saturday digest of
// weekday-due work is noise that trains people to ignore Monday's.
Schedule::command('my:digest')->weekdays()->dailyAt('07:30');

// WP-06. Hourly, not nightly: an SLA measured in hours cannot be enforced by a
// job that runs once a day, and a loss event's level-1 decision sits inside the
// CBN seven-day reporting window.
Schedule::command('workflow:sweep-slas')->hourly()->withoutOverlapping();

// Definitions that trigger on a schedule — a quarterly re-attestation of
// accepted risks, an annual policy review. Nothing runs unless a definition
// declares the matching cadence.
Schedule::command('workflow:run-scheduled --cadence=daily')->dailyAt('06:00');
Schedule::command('workflow:run-scheduled --cadence=weekly')->weeklyOn(1, '06:15');
Schedule::command('workflow:run-scheduled --cadence=monthly')->monthlyOn(1, '06:30');
Schedule::command('workflow:run-scheduled --cadence=quarterly')->quarterlyOn(1, '06:45');

// WP-07. Connector syncs, per cadence. A connector that has never run does its
// first pass as a dry run, so its field mapping is proved against real rows
// before anything reaches the measure engine.
Schedule::command('connectors:run --schedule=hourly')->hourly()->withoutOverlapping();
Schedule::command('connectors:run --schedule=daily')->dailyAt('05:30');
Schedule::command('connectors:run --schedule=weekly')->weeklyOn(1, '05:45');
Schedule::command('connectors:run --schedule=monthly')->monthlyOn(1, '06:00');

// Formula thresholds are re-evaluated after a period closes. The close screen
// runs this too — this is the safety net for periods closed by a job, and for a
// denominator (capital, CPI) entered days after the close itself.
Schedule::command('measures:rebaseline-thresholds')->monthlyOn(2, '05:00');

// Licensing (migration Phase 0): keep the deployment visible to the
// LicensingServer — and pick up revocations and entitlement changes — even
// when the app receives no traffic. The command itself defers to the
// server-driven heartbeat / revocation cadence, so hourly is a ceiling.
Schedule::command('license:heartbeat')->hourly()->withoutOverlapping();

// TPRM Phase 3 (FR-EVD-02). Before the morning digest, so a relationship owner
// who has a certificate expiring in seven days reads it in the same sweep as
// the rest of their day. The command no-ops when the module's feature flag is
// off, so an installation without TPRM schedules nothing that does anything.
Schedule::command('tprm:check-evidence-expiry')->dailyAt('07:15');

// TPRM Phase 4. Renewals run BEFORE the obligation sweep: a contract whose
// notice window closes today is a bigger fact than a duty falling due, and a
// reader working down a morning's notifications should meet it first.
//
// FR-CTR-02 keys these to the NOTICE deadline, not the expiry date. A contract
// expiring in ninety days with a hundred-and-twenty-day notice period has
// already renewed, and an expiry-based reminder tells somebody about a
// decision that is no longer theirs to make.
Schedule::command('tprm:check-contract-renewals')->dailyAt('07:20');
Schedule::command('tprm:check-obligations')->dailyAt('07:25');

// TPRM Phase 5. After the obligation sweep, because a finding raised by that
// sweep should be in the register before the scores are recomputed. Lapsed
// risk acceptances reopen first, then the engagements whose inputs actually
// changed are rescored — never every engagement nightly, which would turn the
// score-run table into a record of the passage of time rather than of
// decisions.
Schedule::command('tprm:recompute-scores')->dailyAt('07:35');

// TPRM Phase 6 (FR-MON-09). Last of the TPRM sweeps, because it derives
// signals from the state the earlier ones have just updated — an obligation
// breached at 07:25 should appear in the monitoring stream at 07:40, not
// tomorrow. The internal generator runs whether or not any external feed is
// configured, which is what makes "continuous monitoring" true on day one for
// a client with no data budget.
Schedule::command('tprm:run-monitoring')->dailyAt('07:40');

// TPRM Phase 6. Before the monitoring sweep, so a designation published
// overnight is on the list by the time anything screens against it. A failed
// refresh leaves the previous list standing and the screening driver reports a
// failure rather than a clear result — an empty or stale list must never be
// mistaken for a clean search.
Schedule::command('tprm:refresh-sanctions-lists')->dailyAt('05:15');
