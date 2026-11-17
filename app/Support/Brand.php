<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Optional per-site branding images, kept in the public disk under brand/
 * (storage/app/public/brand/, served at /storage/brand/). That lives in the
 * storage volume, so Docker upgrades keep it and the repo ships none:
 *
 *   logo.png  large logo for the login/guest screens
 *   mark.png  square emblem: top-bar mark (shown on a white chip) and favicon
 *
 * A missing file falls back to the stock icon/text. The URL carries the file's
 * mtime so replacing an image busts browser caches.
 */
class Brand
{
    public static function logo(): ?string
    {
        return self::url('logo.png');
    }

    public static function mark(): ?string
    {
        return self::url('mark.png');
    }

    /** @return array{logo: ?string, mark: ?string} */
    public static function forClient(): array
    {
        return ['logo' => self::logo(), 'mark' => self::mark()];
    }

    private static function url(string $file): ?string
    {
        $disk = Storage::disk('public');
        $path = 'brand/' . $file;

        return $disk->exists($path) ? $disk->url($path) . '?v=' . $disk->lastModified($path) : null;
    }
}
