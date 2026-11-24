<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;

/**
 * Server-side text for a notification (emails). Mirrors the variant logic of
 * resources/js/Composables/useNotificationText.js, which renders the same
 * types in the app; the strings live in lang/<locale>/notifications.php.
 */
class NotificationText
{
    public const LOCALES = ['en', 'es'];

    public static function variant(string $type, array $params): string
    {
        return match ($type) {
            'slide_pending'   => ($params['count'] ?? 1) > 1 ? 'slide_pending_many' : 'slide_pending',
            'editor_joined'   => ! empty($params['by_you']) ? 'editor_joined_by_you' : (! empty($params['by']) ? 'editor_joined_by' : 'editor_joined'),
            'added_as_editor' => ! empty($params['by']) ? 'added_as_editor_by' : 'added_as_editor',
            default           => $type,
        };
    }

    public static function message(Notification $n, ?string $locale = null): string
    {
        $params = $n->data['params'] ?? [];
        $key = 'notifications.types.'.self::variant($n->type, $params);

        if (! trans()->has($key, $locale)) {
            $key = 'notifications.types.unknown';
        }

        // Only scalar params interpolate (placements etc. are rendered separately).
        $replace = array_map('strval', array_filter($params, fn ($v) => is_scalar($v)));

        return __($key, $replace, $locale);
    }

    /** @return array<string> detail lines: one per church for slide-placement notices, plus the start date */
    public static function placementLines(Notification $n, ?string $locale = null): array
    {
        $params = $n->data['params'] ?? [];

        $lines = collect($params['placements'] ?? [])
            ->map(fn ($p) => $p['entity'].': '.(! empty($p['shows'])
                ? __('notifications.added_to', ['shows' => implode(', ', $p['shows'])], $locale)
                : __('notifications.not_added', [], $locale)));

        if (! empty($params['more'])) {
            $lines->push(__('notifications.more_churches', ['n' => $params['more']], $locale));
        }

        if (! empty($params['starts_at'])) {
            $date = \Illuminate\Support\Carbon::parse($params['starts_at'])->locale($locale ?? app()->getLocale())->isoFormat('LL');
            $lines->push(__('notifications.starts', ['date' => $date], $locale));
        }

        return $lines->all();
    }

    public static function category(string $category, ?string $locale = null): string
    {
        return __("notifications.categories.$category", [], $locale);
    }

    public static function localeFor(User $user): string
    {
        $locale = $user->setting('locale');

        return in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }
}
