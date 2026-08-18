<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweeps auto-created one-off global shows (e.g. a promo video's per-entity
// copies) once their slides expire. Requires the deployment's cron to run
// `php artisan schedule:run` every minute — no scheduler currently runs in
// this app, so this is new infrastructure, not just a new command.
Schedule::command('shows:prune-empty')->daily();
