<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Collection;

/**
 * Per-user notification choices, kept as UserSettings
 * (`notify.<category>.inbox` = 1|0, `notify.<category>.email` = none|immediate|daily).
 * A missing tag means the category's default from config/notifications.php.
 * This is the only code that reads or writes those tags.
 */
class NotificationPreferences
{
    public static function categories(): array
    {
        return config('notifications.categories');
    }

    public static function exists(string $category): bool
    {
        return isset(self::categories()[$category]);
    }

    public static function audience(string $category): string
    {
        return self::categories()[$category]['audience'] ?? 'everyone';
    }

    /** Whether the user belongs to a category's audience (for the preferences page). */
    public static function eligible(User $user, string $category): bool
    {
        return match (self::audience($category)) {
            'admin'    => $user->isAdmin(),
            'managers' => $user->isAdmin() || $user->adminEntities()->exists(),
            default    => true,
        };
    }

    /** @return array<string, array{inbox: bool, email: string}> the user's effective choices for every category they're eligible for */
    public static function forUser(User $user): array
    {
        $stored = $user->settings()->where('setting_tag', 'like', 'notify.%')
            ->pluck('setting_value', 'setting_tag');

        $isManager = $user->isAdmin() || $user->adminEntities()->exists();

        return collect(self::categories())
            ->filter(fn ($c) => match ($c['audience']) {
                'admin'    => $user->isAdmin(),
                'managers' => $isManager,
                default    => true,
            })
            ->map(fn ($c, $key) => self::resolve($key, $stored))
            ->all();
    }

    /** @param array<string, array{inbox?: bool, email?: string}> $choices */
    public static function save(User $user, array $choices): void
    {
        foreach ($choices as $category => $choice) {
            if (! self::exists($category)) {
                continue;
            }
            if (array_key_exists('inbox', $choice)) {
                $user->putSetting("notify.$category.inbox", $choice['inbox'] ? '1' : '0');
            }
            if (isset($choice['email']) && in_array($choice['email'], config('notifications.email_modes'), true)) {
                $user->putSetting("notify.$category.email", $choice['email']);
            }
        }
    }

    /**
     * Effective choices for many users in one query.
     *
     * @param  array<int>  $userIds
     * @return Collection<int, array{inbox: bool, email: string}> keyed by user id
     */
    public static function bulk(array $userIds, string $category): Collection
    {
        $tags = ["notify.$category.inbox", "notify.$category.email"];

        $stored = UserSetting::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('setting_tag', $tags)
            ->get(['user_id', 'setting_tag', 'setting_value'])
            ->groupBy('user_id');

        return collect($userIds)->mapWithKeys(fn ($id) => [
            $id => self::resolve($category, ($stored[$id] ?? collect())->pluck('setting_value', 'setting_tag')),
        ]);
    }

    /** @param Collection|array $stored setting_tag => value */
    private static function resolve(string $category, $stored): array
    {
        $default = self::categories()[$category];
        $inbox = $stored["notify.$category.inbox"] ?? null;
        $email = $stored["notify.$category.email"] ?? null;

        return [
            'inbox' => $inbox === null ? (bool) $default['inbox'] : $inbox === '1',
            'email' => in_array($email, config('notifications.email_modes'), true) ? $email : $default['email'],
        ];
    }
}
