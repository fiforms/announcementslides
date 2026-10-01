<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Widget bundle storage
    |--------------------------------------------------------------------------
    |
    | Installed widget packages are extracted to widgets/{slug}/{version}/ on
    | this disk and served through the widgets.asset route (never straight off
    | the disk), so only files listed in the installed manifest are reachable
    | and they're always same-origin with the correct MIME type.
    |
    */
    'disk' => env('WIDGETS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Package limits
    |--------------------------------------------------------------------------
    */
    'max_zip_bytes'      => 20 * 1024 * 1024,
    'max_unpacked_bytes' => 20 * 1024 * 1024,
    'max_files'          => 500,

    // Extension => Content-Type for files a widget package may contain.
    'file_types' => [
        'js'    => 'text/javascript; charset=utf-8',
        'mjs'   => 'text/javascript; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'png'   => 'image/png',
        'webp'  => 'image/webp',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'txt'   => 'text/plain; charset=utf-8',
        'md'    => 'text/plain; charset=utf-8',
    ],

    /*
    |--------------------------------------------------------------------------
    | Widget data fetches (see App\Services\Widgets\WidgetDataService)
    |--------------------------------------------------------------------------
    |
    | Widgets never get a general-purpose proxy: every upstream request is
    | built server-side from an endpoint template in an admin-installed
    | manifest plus the slide's saved, validated parameters.
    |
    */
    'fetch' => [
        'timeout_seconds'   => 5,
        'max_redirects'     => 3,
        'max_bytes'         => ['ical' => 5 * 1024 * 1024, 'json' => 2 * 1024 * 1024, 'text' => 2 * 1024 * 1024],
        'min_ttl'           => 60,
        'default_ttl'       => 300,
        // How long a last-good response keeps being served when the
        // upstream fails.
        'stale_seconds'     => 24 * 60 * 60,
        'user_agent'        => 'AnnouncementSlides-Widgets/1.0',
        // Upstream fetches (cache misses only) per minute.
        'per_caller_limit'  => 60,
        'per_host_limit'    => 30,
        'preview_limit'     => 20,
        'ical_max_events'   => 500,
        'ical_default_days' => 60,
    ],
];
