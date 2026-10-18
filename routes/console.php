<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// T29: 90 day retention for ai_evaluations.raw_response. Idempotent, so a
// missed day (scheduler downtime) is caught up by the next run.
Schedule::command('ai:prune-raw-responses')->dailyAt('02:30');
