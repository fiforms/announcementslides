<?php

/*
|--------------------------------------------------------------------------
| Media storage tiers
|--------------------------------------------------------------------------
|
| Every upload is stored and processed on the local `public` disk first
| (sanitizing, validation, thumbnails, video poster frames). When an offload
| disk is configured, large files are then moved there and served straight
| from its public URL, so their download traffic never touches the web
| host. Small files (SVGs, thumbnails, posters, overlay composites) always
| stay local. Leave MEDIA_OFFLOAD_DISK unset to keep everything on one host.
|
| See "Storage tiers" in ARCHITECTURE.md and the R2 section of INSTALLING.md.
|
*/

return [

    // Disk large files are moved to (e.g. "r2"); null = offloading off.
    'offload_disk' => env('MEDIA_OFFLOAD_DISK') ?: null,

    // Media files smaller than this stay local.
    'offload_min_bytes' => (int) env('MEDIA_OFFLOAD_MIN_BYTES', 1024 * 1024),

    // Only these types are ever offloaded. Never add image/svg+xml: SVGs rely
    // on the web server's sandbox CSP header (see "SVG security model").
    'offload_mimes' => [
        'video/*',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
    ],

    // Also move Slide Announcer release files (app bundles, OS bundles,
    // disk images) when offloading is on.
    'offload_releases' => (bool) env('MEDIA_OFFLOAD_RELEASES', true),

    // While a release is still being copied to the offload disk, devices are
    // not told about it (no *_update_available) and the disk-image download
    // links wait. Otherwise a fleet checking in right after tagging would all
    // download it from this host. Only applies while release offloading is on.
    'releases_wait_for_offload' => (bool) env('MEDIA_RELEASES_WAIT_FOR_OFFLOAD', true),

    // How long a presigned download link (for a "Download" button on an
    // offloaded file) stays valid.
    'download_url_minutes' => (int) env('MEDIA_DOWNLOAD_URL_MINUTES', 30),

    /*
     * Local copies of offloaded files pulled back for server-side work (ZIP
     * and REVELation exports, compositing, resize checks). Least recently
     * used files are evicted once the cache is over max_bytes, but never a
     * file used within min_age_minutes, so files in use by a running job are
     * kept even if that briefly takes the cache over its target.
     */
    'cache' => [
        'path' => storage_path('app/media-cache'),
        'max_bytes' => (int) env('MEDIA_CACHE_MAX_BYTES', 2 * 1024 * 1024 * 1024),
        'min_age_minutes' => (int) env('MEDIA_CACHE_MIN_AGE', 60),
    ],

];
