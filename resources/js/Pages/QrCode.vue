<script setup>
import { computed, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import QrDesigner from '@/Components/OverlayEditor/QrDesigner.vue';
import QrPreview from '@/Components/OverlayEditor/QrPreview.vue';
import { QR_DEFAULTS, downloadBlob, normalizeUrl, qrSvgDocument, svgToPngBlob } from '@/Composables/overlay/qr.js';

// Standalone QR Code Creator: the overlay editor's QR designer on its own
// page. Everything happens in the browser — nothing is sent to the server.
const { t } = useI18n();

const STORAGE_KEY = 'qr-code-creator:options';
const PNG_SIZES = [512, 1024, 2048];

function loadOptions() {
    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null');
        if (saved && typeof saved === 'object') return { ...QR_DEFAULTS, ...saved, symbol: null };
    } catch {
        // Storage unavailable or corrupt: start from the defaults.
    }
    return { ...QR_DEFAULTS };
}

const input = ref('');
const options = ref(loadOptions());
const rendered = ref(null);
const failed = ref(false);
const pngSize = ref(1024);
const exporting = ref(false);
const exportError = ref(false);

// Remember the look (not the URL or symbol) for next time.
watch(options, value => {
    try {
        const { symbol, ...look } = value;
        localStorage.setItem(STORAGE_KEY, JSON.stringify(look));
    } catch {
        // Private mode / blocked storage: just don't remember.
    }
}, { deep: true });

const resolved = computed(() => normalizeUrl(input.value));

const baseName = computed(() => {
    try {
        return `qr-${new URL(resolved.value.url).hostname.replace(/^www\./, '')}`;
    } catch {
        return 'qr-code';
    }
});

function downloadSvg() {
    const svg = qrSvgDocument(rendered.value, options.value, 1024);
    downloadBlob(new Blob([svg], { type: 'image/svg+xml' }), `${baseName.value}.svg`);
}

async function downloadPng() {
    exporting.value = true;
    exportError.value = false;
    try {
        const blob = await svgToPngBlob(qrSvgDocument(rendered.value, options.value, pngSize.value), pngSize.value);
        downloadBlob(blob, `${baseName.value}.png`);
    } catch {
        exportError.value = true;
    } finally {
        exporting.value = false;
    }
}

const inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
const buttonCls = 'rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors';
</script>

<template>
    <Head :title="t('qr_code.title')" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div class="px-4 sm:px-0">
                    <h1 class="text-xl font-semibold text-gray-900">{{ t('qr_code.title') }}</h1>
                    <p class="mt-1 text-sm text-gray-500">{{ t('qr_code.intro') }}</p>
                </div>

                <div class="grid gap-6 bg-white p-4 shadow sm:rounded-lg sm:p-8 md:grid-cols-[1fr_18rem]">
                    <div class="min-w-0 space-y-5">
                        <div>
                            <label for="qr-url" class="mb-1 block text-sm font-medium text-gray-700">{{ t('qr_code.url') }}</label>
                            <input id="qr-url" v-model="input" type="url" placeholder="https://…" autofocus :class="inputCls" />
                            <p v-if="input && resolved.error" class="mt-1 text-xs text-red-600">
                                {{ t(`overlay_editor.qr_error_${resolved.error}`) }}
                            </p>
                            <p v-if="resolved.url && resolved.url.length > 300" class="mt-1 text-xs text-amber-700">{{ t('overlay_editor.qr_long_url') }}</p>
                            <p v-if="resolved.url && resolved.removed?.length" class="mt-1 text-xs text-gray-500">
                                {{ t('overlay_editor.qr_trackers_removed', { params: resolved.removed.join(', ') }) }}
                                <span class="block break-all">{{ resolved.url }}</span>
                            </p>
                        </div>

                        <QrDesigner v-model:options="options" v-model:rendered="rendered" v-model:failed="failed"
                            :url="resolved.url" />
                    </div>

                    <div class="space-y-4">
                        <QrPreview :rendered="rendered" :options="options" :failed="failed" class="w-full" />

                        <div class="space-y-2">
                            <button type="button" :class="[buttonCls, 'w-full']" :disabled="!rendered" @click="downloadSvg">
                                {{ t('qr_code.download_svg') }}
                            </button>
                            <div class="flex gap-2">
                                <button type="button" :class="[buttonCls, 'flex-1']" :disabled="!rendered || exporting" @click="downloadPng">
                                    {{ exporting ? t('qr_code.exporting') : t('qr_code.download_png') }}
                                </button>
                                <select v-model.number="pngSize" :aria-label="t('qr_code.png_size')"
                                    class="rounded-lg border border-gray-300 py-2 pl-3 pr-8 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option v-for="size in PNG_SIZES" :key="size" :value="size">{{ size }} px</option>
                                </select>
                            </div>
                            <p v-if="exportError" class="text-xs text-red-600">{{ t('qr_code.export_failed') }}</p>
                            <p class="text-xs text-gray-500">{{ t('qr_code.format_hint') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
