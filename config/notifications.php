<?php

/*
|--------------------------------------------------------------------------
| In-app notifications (see app/Services/Notifier.php)
|--------------------------------------------------------------------------
|
| Every notification belongs to one category. A category's `audience` decides
| who sees it on their preferences page (and admin-only categories are never
| delivered to anyone else); `inbox` and `email` are the defaults a user gets
| until they change them on their Profile page:
|
|   inbox: true|false              show it in the in-app inbox
|   email: none|immediate|daily    also email it, at once or in the daily digest
|
| Audiences: everyone (any signed-in user), managers (entity leaders/editors
| and site admins), admin (site admins only).
|
| Site-wide knobs (retention, digest time, email on/off) are AppSettings,
| edited on Admin → Notifications; the values below are their defaults.
*/

return [

    'categories' => [
        'slide_review' => ['audience' => 'everyone', 'inbox' => true, 'email' => 'immediate'],
        'global_slide' => ['audience' => 'managers', 'inbox' => true, 'email' => 'daily'],
        'nearby_slide' => ['audience' => 'managers', 'inbox' => true, 'email' => 'daily'],
        'promo'        => ['audience' => 'managers', 'inbox' => true, 'email' => 'daily'],
        'team'         => ['audience' => 'managers', 'inbox' => true, 'email' => 'daily'],
        'slide_schedule' => ['audience' => 'everyone', 'inbox' => true, 'email' => 'none'],
        'admin_review_queue' => ['audience' => 'admin', 'inbox' => true, 'email' => 'daily'],
        'admin_shared' => ['audience' => 'admin', 'inbox' => true, 'email' => 'none'],
        // A church's Slide Announcer reported a temperature over the limit.
        'device_temperature' => ['audience' => 'managers', 'inbox' => true, 'email' => 'immediate'],
        // "Status notifications": a device went offline / came back. Opt-in.
        'device_status' => ['audience' => 'managers', 'inbox' => false, 'email' => 'none'],
    ],

    'email_modes' => ['none', 'immediate', 'daily'],

    // Cleared (archived) notifications are deleted after this many days.
    'retention_days' => (int) env('NOTIFICATION_RETENTION_DAYS', 90),

    // Inbox notifications nobody cleared are archived after this many days (0 = never).
    'inbox_max_days' => (int) env('NOTIFICATION_INBOX_MAX_DAYS', 180),

    // Local time (app timezone) the daily digest goes out.
    'digest_time' => env('NOTIFICATION_DIGEST_TIME', '07:00'),

    // Off until the deployment has a real mailer configured.
    'email_enabled' => (bool) env('NOTIFICATION_EMAIL_ENABLED', false),

    // A Slide Announcer heartbeat at or above this CPU temperature (°C)
    // raises a device_temperature notice.
    'device_temp_threshold_c' => (int) env('NOTIFICATION_DEVICE_TEMP_THRESHOLD_C', 70),

    // How many notifications the bell's dropdown shows.
    'recent_limit' => 8,
];
