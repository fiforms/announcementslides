<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Nearby sharing radius
    |--------------------------------------------------------------------------
    |
    | Default "as the crow flies" radius (in miles) used when a viewer enables
    | "include nearby" on the dashboard. Authenticated users can override this
    | with a per-user setting (setting_tag = 'nearby_radius_miles'); anonymous
    | viewers always use this default.
    |
    */
    'nearby_radius_miles' => env('SLIDES_NEARBY_RADIUS_MILES', 50),

    /*
    |--------------------------------------------------------------------------
    | ffmpeg binary
    |--------------------------------------------------------------------------
    |
    | Used by GenerateThumbnail to extract a frame from video slides. Set
    | FFMPEG_BINARY to an absolute path if `ffmpeg` isn't on the PATH the
    | queue worker's process runs with (a common gap between an interactive
    | shell's PATH and a supervisor/systemd service's PATH).
    |
    */
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),

    /*
    |--------------------------------------------------------------------------
    | rsvg-convert binary
    |--------------------------------------------------------------------------
    |
    | Used by SyncOverlayThumbnail to rasterize an SVG overlay before
    | compositing it with GD, which can't decode SVG itself. From the
    | librsvg2-bin package on Debian/Ubuntu (librsvg via Homebrew on macOS).
    | Optional — if the binary isn't installed, SVG overlays are simply
    | skipped (the slide's thumbnail falls back to the primary alone) rather
    | than failing; every other overlay format (PNG/WebP) is unaffected.
    |
    */
    'rsvg_binary' => env('RSVG_BINARY', 'rsvg-convert'),

    /*
    |--------------------------------------------------------------------------
    | Media types
    |--------------------------------------------------------------------------
    |
    | Every Slide has at least one 'slide' media file; the rest are optional
    | additional versions attached from the Edit screens. Adding a new type
    | (or a new allowed mime for an existing one) only requires editing this
    | map — no migration needed, since slide_media.media_type is a plain
    | string column.
    |
    */
    'media_types' => [
        'slide' => [
            'label' => 'Slide',
            'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime', 'video/webm'],
        ],
        'slide-overlay' => [
            'label' => 'Overlay',
            'mimes' => ['image/png', 'image/webp', 'image/svg+xml'],
        ],
        'color-flyer' => [
            'label' => 'Color Flyer',
            'mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
        ],
        'easy-print-flyer' => [
            'label' => 'Easy-Print Flyer',
            'mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
        ],
        'social-media-image' => [
            'label' => 'Social Media Image',
            'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI upscaling and downscaling
    |--------------------------------------------------------------------------
    |
    | Oversized images (beyond `downscale`) are shrunk to fit it, also in the
    | browser, using a plain canvas — see resources/js/Composables/useImageResize.js.
    |
    | Images are upscaled 2x in the uploader's browser (UpscalerJS) when they
    | fit within max_* and are at least min_* — see App\Support\UpscalerSettings
    | and resources/js/Composables/useUpscaler.js. The admin-editable options
    | (model, JPEG quality, …) live in the app_settings table; `models` is the
    | catalog the admin page offers. `key` is the folder the weights are
    | served from (public/upscaler-models/, copied from node_modules by the
    | Vite build), and must match the keys in useUpscaler.js.
    |
    */
    'downscale' => [
        // Images larger than this are shrunk to fit it (3840×2160 = 4K) in the browser.
        'max_width'  => 3840,
        'max_height' => 2160,
    ],

    'upscale' => [
        'min_width'  => 960,
        'min_height' => 540,
        'max_width'  => 1920,
        'max_height' => 1080,
        'models' => [
            'default-model'  => ['label' => 'Default (UpscalerJS)', 'size' => '1.6 MB',  'note' => 'Small and fast, a light ESRGAN. Fine for clean graphics.'],
            'esrgan-slim'    => ['label' => 'ESRGAN Slim',          'size' => '0.9 MB',  'note' => 'Fastest and lowest quality; for slow computers.'],
            'esrgan-medium'  => ['label' => 'ESRGAN Medium',        'size' => '2.7 MB',  'note' => 'Good balance of speed and detail.'],
            'esrgan-thick'   => ['label' => 'ESRGAN Thick',         'size' => '28 MB',   'note' => 'Best detail, but a large download and slow, especially without a good GPU.'],
        ],
    ],
];
