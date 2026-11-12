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

// Keeps the local cache of offloaded media (config/media.php) near its size
// target. A no-op when nothing has been offloaded.
Schedule::command('media:prune-cache')->everyFifteenMinutes()->withoutOverlapping();

// Database-only backups (see config/backup.php). Full backups are started by hand.
$backupDriver = config('database.connections.'.config('database.default').'.driver');

if (config('backup.schedule') !== 'off' && in_array($backupDriver, ['mysql', 'mariadb'], true)) {
    $backup = Schedule::command('backup:create --tag=scheduled --prune')
        ->withoutOverlapping()
        ->runInBackground();

    config('backup.schedule') === 'weekly'
        ? $backup->weeklyOn(0, config('backup.time'))
        : $backup->dailyAt(config('backup.time'));
}
