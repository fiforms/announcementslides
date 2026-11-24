import { useI18n } from 'vue-i18n';

/**
 * Turns a notification ({ type, params, ... } from Notification::toClient)
 * into display text via `notifications.types.*` in the locale files.
 * A type can pick a variant key from its params (e.g. "you invited" vs
 * "invited by X"); unknown types fall back to a generic line so old rows keep
 * rendering after code changes.
 */
export function useNotificationText() {
    const { t, te, d } = useI18n();

    function variantKey(n) {
        const p = n.params ?? {};
        switch (n.type) {
            case 'slide_pending':
                return (p.count ?? 1) > 1 ? 'slide_pending_many' : 'slide_pending';
            case 'editor_joined':
                return p.by_you ? 'editor_joined_by_you' : (p.by ? 'editor_joined_by' : 'editor_joined');
            case 'added_as_editor':
                return p.by ? 'added_as_editor_by' : 'added_as_editor';
            default:
                return n.type;
        }
    }

    function message(n) {
        const key = `notifications.types.${variantKey(n)}`;
        return te(key) ? t(key, n.params ?? {}) : t('notifications.types.unknown');
    }

    function categoryLabel(category) {
        const key = `notifications.categories.${category}.label`;
        return te(key) ? t(key) : category;
    }

    function when(iso) {
        if (!iso) return '';
        const date = new Date(iso);
        const minutes = Math.round((Date.now() - date.getTime()) / 60000);
        if (minutes < 1) return t('notifications.just_now');
        if (minutes < 60) return t('notifications.minutes_ago', { n: minutes });
        const hours = Math.round(minutes / 60);
        if (hours < 24) return t('notifications.hours_ago', { n: hours });
        const days = Math.round(hours / 24);
        if (days < 7) return t('notifications.days_ago', { n: days });
        return date.toLocaleDateString();
    }

    return { message, categoryLabel, when };
}
