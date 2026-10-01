<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Services\ImageValidationService;

/**
 * The admin-editable options for in-browser AI upscaling, stored in
 * app_settings (key 'upscaler') with these defaults. The size window an
 * image must fit to be upscaled lives in config('slides.upscale').
 */
class UpscalerSettings
{
    public const DEFAULTS = [
        'enabled'        => true,
        'auto_on_upload' => true,
        'model'          => 'esrgan-medium',
        'jpeg_quality'   => 98,
        'patch_size'     => 64,
        // Not an upscaler option as such, but it lives on the same page and
        // shares the JPEG quality: shrink images larger than 4K, and
        // re-encode files over the size limit, on upload.
        'downscale_oversized' => true,
    ];

    public const PATCH_SIZES = [32, 64, 96, 128];

    public static function all(): array
    {
        $stored = AppSetting::get('upscaler', []);
        $settings = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);

        // A model removed from the catalog falls back rather than breaking uploads.
        if (!array_key_exists($settings['model'], config('slides.upscale.models'))) {
            $settings['model'] = self::DEFAULTS['model'];
        }

        return $settings;
    }

    public static function save(array $values): void
    {
        AppSetting::put('upscaler', array_merge(self::all(), $values));
    }

    /** What the browser needs (shared with every Inertia page). */
    public static function forClient(): array
    {
        $s = self::all();
        $limits = config('slides.upscale');

        return [
            'enabled'        => $s['enabled'],
            'auto_on_upload' => $s['auto_on_upload'],
            'model'          => $s['model'],
            'jpeg_quality'   => $s['jpeg_quality'],
            'patch_size'     => $s['patch_size'],
            'downscale'      => [
                'enabled'   => $s['downscale_oversized'],
                'max_bytes' => ImageValidationService::MAX_FILE_SIZE,
                'max'     => ['w' => config('slides.downscale.max_width'), 'h' => config('slides.downscale.max_height')],
            ],
            'min'            => ['w' => $limits['min_width'], 'h' => $limits['min_height']],
            'max'            => ['w' => $limits['max_width'], 'h' => $limits['max_height']],
        ];
    }
}
