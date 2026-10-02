import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import i18n from './i18n';

const fallbackName = import.meta.env.VITE_APP_NAME || 'Announcement Slides';

// Site name for the interface language: an APP_NAME_<locale> override from
// the server if one exists, else APP_NAME (see config/app.php).
let sharedProps = {};
const appName = () => sharedProps.appNameLocalized?.[i18n.global.locale.value] ?? sharedProps.appName ?? fallbackName;

createInertiaApp({
    title: (title) => title ? `${title} - ${appName()}` : appName(),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        sharedProps = props.initialPage.props;
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
