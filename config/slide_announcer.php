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
];
