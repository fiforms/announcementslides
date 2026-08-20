import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            screens: {
                // Top nav bar collapse point: it carries enough links
                // (Announcements, My Slides, Show Editor, SlideAnnouncers,
                // entity switcher, Admin) that the default `sm`/`lg` steps
                // start clipping well before mobile widths.
                nav: '1080px',
            },
        },
    },

    plugins: [forms],
};
