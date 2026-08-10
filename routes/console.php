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

// WP-04. The CBN publishes rates on business days; the fetcher runs before the
// KRI check so a monetary limit is evaluated against that morning's rate.
Schedule::command('fx:fetch-cbn-rates')->weekdays()->dailyAt('06:30');

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
