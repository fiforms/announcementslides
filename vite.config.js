import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import fs from 'node:fs';
import path from 'node:path';

// The in-browser upscaler (resources/js/Composables/useUpscaler.js) loads each
// model's weights from /upscaler-models/<key>/ on our own origin rather than
// from a CDN. They ship inside the @upscalerjs/* npm packages, so copy the
// 2x weights of each into public/ (git-ignored) whenever Vite starts or builds.
const UPSCALER_MODELS = {
    'default-model': 'models',
    'esrgan-slim': 'models/x2',
    'esrgan-medium': 'models/x2',
    'esrgan-thick': 'models/x2',
};

function copyUpscalerModels() {
    return {
        name: 'copy-upscaler-models',
        buildStart() {
            for (const [key, sub] of Object.entries(UPSCALER_MODELS)) {
                const src = path.resolve('node_modules/@upscalerjs', key, sub);
                const dest = path.resolve('public/upscaler-models', key);
                if (!fs.existsSync(src)) continue;
                const marker = path.join(dest, 'model.json');
                if (fs.existsSync(marker)
                    && fs.statSync(marker).size === fs.statSync(path.join(src, 'model.json')).size) continue;
                fs.cpSync(src, dest, { recursive: true });
            }
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        copyUpscalerModels(),
    ],
});
