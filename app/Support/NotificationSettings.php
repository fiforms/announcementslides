<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Site-wide notification knobs, edited on Admin → Notifications. Stored in
 * app_settings (`notifications.*` keys); config/notifications.php supplies
 * the defaults.
 */
class NotificationSettings
{
    public const KEYS = ['retention_days', 'inbox_max_days', 'digest_time', 'email_enabled'];

    public static function get(string $key): mixed
    {
        $value = AppSetting::get('notifications.'.$key, config('notifications.'.$key));

        return match ($key) {
            'email_enabled' => (bool) $value,
            'retention_days', 'inbox_max_days' => (int) $value,
            default => $value,
        };
    }

    public static function all(): array
    {
        return collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => self::get($k)])->all();
    }

    public static function save(array $values): void
    {
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $values)) {
                AppSetting::put('notifications.'.$key, $values[$key]);
            }
        }
    }

    public static function emailEnabled(): bool
    {
        return self::get('email_enabled');
    }
}
