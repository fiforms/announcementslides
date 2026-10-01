<?php

namespace App\Support;

/**
 * The size rules for resizing a slide image in the browser: an AI upscale
 * doubles it, a downscale fits it within the 4K limit (aspect ratio kept).
 * The browser does the work (see useUpscaler.js / useImageResize.js); the
 * server uses this only to check that an uploaded result is what was asked.
 */
class ImageResize
{
    public const UPSCALE = 'upscale';
    public const DOWNSCALE = 'downscale';

    public static function kinds(): array
    {
        return [self::UPSCALE, self::DOWNSCALE];
    }

    public static function exceedsLimit(int $w, int $h): bool
    {
        return $w > config('slides.downscale.max_width') || $h > config('slides.downscale.max_height');
    }

    /** The size a resize of this kind should produce, or null if it doesn't apply to $w×$h. */
    public static function target(string $kind, int $w, int $h): ?array
    {
        if ($kind === self::UPSCALE) {
            return [$w * 2, $h * 2];
        }

        if (!self::exceedsLimit($w, $h)) {
            return null;
        }

        $scale = min(config('slides.downscale.max_width') / $w, config('slides.downscale.max_height') / $h);

        return [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
    }
}
