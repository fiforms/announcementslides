<?php

namespace App\Support;

/**
 * What the About pages say about this site and the software behind it.
 *
 * A site is "branded" when its name (APP_NAME) isn't just the product's own
 * name; the page then credits the product ("X is based on AnnouncementSlides")
 * instead of presenting it as the product itself.
 */
class AboutInfo
{
    public const PRODUCT = 'AnnouncementSlides';
    public const REPO_URL = 'https://github.com/fiforms/announcementslides';
    public const DEVICE_REPO_URL = 'https://github.com/fiforms/slideannouncer';
    public const LICENCE = 'MIT';

    public static function isBranded(?string $siteName = null): bool
    {
        $name = strtolower(preg_replace('/[\s_-]+/', '', $siteName ?? (string) config('app.name')));

        return $name !== '' && $name !== strtolower(self::PRODUCT);
    }

    /** @return array<string, mixed> */
    public static function forClient(): array
    {
        return [
            'product' => self::PRODUCT,
            'branded' => self::isBranded(),
            'version' => config('app.version'),
            'licence' => self::LICENCE,
            'repo_url' => self::REPO_URL,
            'device_repo_url' => self::DEVICE_REPO_URL,
        ];
    }

    /** Extra rows for the admin About tab. @return array<string, string|null> */
    public static function systemDetails(): array
    {
        return [
            'app_version' => config('app.version') ?: 'dev',
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'environment' => (string) config('app.env'),
            'database' => (string) config('database.default'),
            'queue' => (string) config('queue.default'),
            'media_offload' => config('media.offload_disk'),
        ];
    }
}
