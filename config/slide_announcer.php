<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Heartbeat / online threshold
    |--------------------------------------------------------------------------
    */
    // Device heartbeats every 5 minutes (see Part 2), so a threshold of one
    // interval would flip "online" false on any single dropped beat.
    'online_threshold_minutes' => env('SLIDE_ANNOUNCER_ONLINE_THRESHOLD_MINUTES', 12),

    // How often devices heartbeat (local-app/backend/heartbeat.py's
    // INTERVAL_SECONDS), and how many in a row a device must miss before
    // its managers get a "went offline" status notification.
    'heartbeat_interval_minutes' => 5,
    'offline_after_missed_heartbeats' => env('SLIDE_ANNOUNCER_OFFLINE_AFTER_MISSED_HEARTBEATS', 3),

    /*
    |--------------------------------------------------------------------------
    | Heartbeat log retention
    |--------------------------------------------------------------------------
    |
    | Rows older than this are removed by the slide-announcer:prune-heartbeats
    | artisan command (not a query scope — this is a log, not user content).
    |
    */
    'heartbeat_retention_days' => env('SLIDE_ANNOUNCER_HEARTBEAT_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Pairing codes
    |--------------------------------------------------------------------------
    */
    'pairing_code_ttl_minutes' => env('SLIDE_ANNOUNCER_PAIRING_CODE_TTL_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Overdue updates
    |--------------------------------------------------------------------------
    |
    | Devices normally apply updates only in their overnight idle window. A
    | device that is only powered on for a weekly service never sees that
    | window, so once an offered update is this many hours old the device
    | skips the window and applies it on its next check (it still defers to
    | whatever is on screen — see DEVICE_CONTRACT.md, "Overdue updates").
    |
    */
    'update_overdue_after_hours' => env('SLIDE_ANNOUNCER_UPDATE_OVERDUE_AFTER_HOURS', 72),
];
