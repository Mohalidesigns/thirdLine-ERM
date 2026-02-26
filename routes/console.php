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
