import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

// The site name for the interface language: an APP_NAME_<locale> override
// from .env when one exists, otherwise the plain APP_NAME.
export function useAppName() {
    const page = usePage();
    const { locale } = useI18n();

    return computed(() => page.props.appNameLocalized?.[locale.value] ?? page.props.appName);
}
