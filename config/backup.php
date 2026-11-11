<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Where archives are written
    |--------------------------------------------------------------------------
    |
    | A filesystem disk and a directory on it. The default is the private
    | disk (storage/app/private/backups), which is never web-served: an
    | archive holds user emails and password hashes. A backup on the same
    | volume as the data is not safe from losing that volume, so download
    | copies from Admin > Backups or point BACKUP_DISK at another disk.
    |
    */
    'disk' => env('BACKUP_DISK', 'local'),
    'path' => 'backups',

    /*
    |--------------------------------------------------------------------------
    | Schedule (database-only backups)
    |--------------------------------------------------------------------------
    |
    | off | daily | weekly. Needs the scheduler (bundled in the Docker image).
    | Scheduled and automatic backups are always database-only; a full
    | backup (uploaded files too) is started by hand.
    |
    */
    'schedule' => env('BACKUP_SCHEDULE', 'daily'),
    'time'     => env('BACKUP_TIME', '02:30'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Only automatic archives are pruned: the newest `keep` scheduled ones and
    | the newest `keep_safety` pre-migrate / pre-restore ones. Backups made by
    | hand (web or CLI) stay until deleted.
    |
    */
    'keep'        => (int) env('BACKUP_KEEP', 14),
    'keep_safety' => (int) env('BACKUP_KEEP_SAFETY', 3),

    // Take a database backup before applying pending migrations on container
    // start (docker/entrypoint.sh). Fresh installs are skipped.
    'before_migrate' => (bool) env('BACKUP_BEFORE_MIGRATE', true),

    // Extra arguments for mysqldump / mysql, e.g. "--skip-ssl".
    'client_options' => env('BACKUP_CLIENT_OPTIONS', ''),

    // A run still "running" after this many minutes is treated as dead.
    'stale_minutes' => 240,
];
