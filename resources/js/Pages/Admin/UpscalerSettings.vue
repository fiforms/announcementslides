<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { upscaleImage, upscaleIneligibility, ineligibleMessage } from '@/Composables/useUpscaler.js';

const { t } = useI18n();

// Options for the AI upscaler that runs in the browser of whoever uploads or
// upscales a slide image. Nothing here is processed on the server.
const props = defineProps({
    settings:   { type: Object, required: true },
    models:     { type: Array, required: true },
    patchSizes: { type: Array, required: true },
    limits:     { type: Object, required: true },
});

const form = useForm({ ...props.settings });

function save() {
    form.patch(route('admin.upscaler.update'), { preserveScroll: true });
}

// ── Try it: upscale a local image with any model, to compare results ──────
const tryModel = ref(props.settings.model);
const tryFile = ref(null);
const tryUrl = ref(null);
const tryResult = ref(null); // { url, width, height, ms, size, quality, model }
const tryError = ref(null);
const tryProgress = ref(0);
const trying = ref(false);
let abort = null;

const tryInfo = ref(null); // { width, height }

function revoke(url) {
    if (url) URL.revokeObjectURL(url);
}

async function pickFile(event) {
    const file = event.target.files?.[0] ?? null;
    revoke(tryUrl.value);
    revoke(tryResult.value?.url);
    tryResult.value = null;
    tryError.value = null;
    tryFile.value = file;
    tryUrl.value = file ? URL.createObjectURL(file) : null;
    tryInfo.value = null;

    if (file) {
        try {
            const bitmap = await createImageBitmap(file);
            tryInfo.value = { width: bitmap.width, height: bitmap.height };
            bitmap.close?.();
        } catch {
            tryError.value = t('upscaler.not_an_image');
        }
    }
}

const tryIneligible = computed(() => tryInfo.value
    ? upscaleIneligibility(tryInfo.value.width, tryInfo.value.height, props.limits)
    : null);

async function runTry() {
    trying.value = true;
    tryError.value = null;
    tryProgress.value = 0;
    revoke(tryResult.value?.url);
    tryResult.value = null;
    abort = new AbortController();

    try {
        const r = await upscaleImage(tryFile.value, {
            model: tryModel.value,
            quality: form.jpeg_quality,
            patchSize: form.patch_size,
            onProgress: (p) => { tryProgress.value = Math.round(p * 100); },
            signal: abort.signal,
        });
        tryResult.value = {
            url: URL.createObjectURL(r.blob),
            width: r.width, height: r.height, ms: r.ms, size: r.blob.size, quality: r.quality,
            model: tryModel.value,
        };
    } catch (err) {
        if (err.code !== 'aborted') tryError.value = err.message || t('upscaler.failed');
    } finally {
        trying.value = false;
        abort = null;
    }
}

function formatBytes(bytes) {
    return bytes < 1024 * 1024 ? `${(bytes / 1024).toFixed(0)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

const modelLabel = (key) => props.models.find(m => m.value === key)?.label ?? key;

onBeforeUnmount(() => {
    abort?.abort();
    revoke(tryUrl.value);
    revoke(tryResult.value?.url);
});
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ $t('nav.upscaler') }}</h1>
        </template>

        <div class="mx-auto max-w-5xl space-y-8">
            <form class="space-y-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm" @submit.prevent="save">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ $t('upscaler.settings') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ $t('upscaler.description', { min: `${limits.min.w}×${limits.min.h}`, max: `${limits.max.w}×${limits.max.h}` }) }}
                    </p>
                </div>

                <label class="flex items-start gap-3">
                    <input v-model="form.enabled" type="checkbox"
                        class="mt-0.5 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                    <span>
                        <span class="block text-sm font-medium text-gray-900">{{ $t('upscaler.enable') }}</span>
                        <span class="block text-xs text-gray-500">{{ $t('upscaler.enable_hint') }}</span>
                    </span>
                </label>

                <label class="flex items-start gap-3">
                    <input v-model="form.auto_on_upload" type="checkbox" :disabled="!form.enabled"
                        class="mt-0.5 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                    <span>
                        <span class="block text-sm font-medium text-gray-900">{{ $t('upscaler.auto_on_upload') }}</span>
                        <span class="block text-xs text-gray-500">{{ $t('upscaler.auto_on_upload_hint') }}</span>
                    </span>
                </label>

                <fieldset class="space-y-2" :disabled="!form.enabled">
                    <legend class="text-sm font-medium text-gray-900">{{ $t('upscaler.model') }}</legend>
                    <label v-for="m in models" :key="m.value"
                        class="flex cursor-pointer items-start gap-3 rounded-lg border p-3"
                        :class="form.model === m.value ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 hover:bg-gray-50'">
                        <input v-model="form.model" type="radio" :value="m.value"
                            class="mt-1 border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        <span>
                            <span class="block text-sm font-medium text-gray-900">{{ m.label }} <span class="font-normal text-gray-500">· {{ $t('upscaler.download_size', { size: m.size }) }}</span></span>
                            <span class="block text-xs text-gray-600">{{ m.note }}</span>
                        </span>
                    </label>
                    <p v-if="form.errors.model" class="text-sm text-red-600">{{ form.errors.model }}</p>
                </fieldset>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-900">{{ $t('upscaler.jpeg_quality', { n: form.jpeg_quality }) }}</label>
                        <input v-model.number="form.jpeg_quality" type="range" min="60" max="100" step="1"
                            :disabled="!form.enabled" class="mt-2 w-full" />
                        <p class="mt-1 text-xs text-gray-500">
                            {{ $t('upscaler.jpeg_quality_hint') }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-900">{{ $t('upscaler.tile_size') }}</label>
                        <select v-model.number="form.patch_size" :disabled="!form.enabled"
                            class="mt-2 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option v-for="n in patchSizes" :key="n" :value="n">{{ n }} px</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ $t('upscaler.tile_size_hint') }}
                        </p>
                    </div>
                </div>

                <label class="flex items-start gap-3 border-t border-gray-100 pt-6">
                    <input v-model="form.downscale_oversized" type="checkbox"
                        class="mt-0.5 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                    <span>
                        <span class="block text-sm font-medium text-gray-900">
                            {{ $t('upscaler.shrink', { size: `${limits.downscale.max.w}×${limits.downscale.max.h}` }) }}
                        </span>
                        <span class="block text-xs text-gray-500">
                            {{ $t('upscaler.shrink_hint') }}
                        </span>
                    </span>
                </label>

                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                        {{ $t('upscaler.save') }}
                    </button>
                    <span v-if="form.recentlySuccessful" class="text-sm text-green-700">{{ $t('upscaler.saved') }}</span>
                </div>
            </form>

            <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ $t('upscaler.try_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ $t('upscaler.try_description') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <input type="file" accept="image/jpeg,image/png,image/webp" class="text-sm text-gray-700" @change="pickFile" />
                    <select v-model="tryModel" :disabled="trying"
                        class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option v-for="m in models" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                    <button v-if="!trying" type="button" :disabled="!tryFile || !!tryIneligible" @click="runTry"
                        class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 disabled:opacity-50">
                        {{ $t('upscaler.upscale_2x') }}
                    </button>
                    <button v-else type="button" class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-700 hover:bg-red-50"
                        @click="abort?.abort()">
                        {{ $t('upscaler.cancel') }} ({{ tryProgress }}%)
                    </button>
                </div>
                <p v-if="tryIneligible" class="text-sm text-amber-700">{{ ineligibleMessage(tryIneligible, limits) }}</p>
                <p v-if="tryError" class="text-sm text-red-600">{{ tryError }}</p>
                <div v-if="trying" class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
                    <div class="h-full rounded-full bg-purple-500 transition-all duration-200" :style="{ width: tryProgress + '%' }" />
                </div>

                <div v-if="tryUrl" class="grid gap-4 md:grid-cols-2">
                    <figure>
                        <figcaption class="mb-1 text-xs font-medium text-gray-700">
                            {{ $t('upscaler.original') }}<span v-if="tryInfo"> · {{ tryInfo.width }}×{{ tryInfo.height }}</span>
                        </figcaption>
                        <img :src="tryUrl" :alt="$t('upscaler.original')" class="w-full rounded-lg border border-gray-200 bg-gray-50" />
                    </figure>
                    <figure v-if="tryResult">
                        <figcaption class="mb-1 text-xs font-medium text-gray-700">
                            {{ modelLabel(tryResult.model) }} · {{ tryResult.width }}×{{ tryResult.height }} ·
                            {{ formatBytes(tryResult.size) }} (q{{ tryResult.quality }}) ·
                            {{ (tryResult.ms / 1000).toFixed(1) }}s
                            · <a :href="tryResult.url" download="upscaled.jpg" class="text-indigo-600 hover:underline">{{ $t('slides.download') }}</a>
                        </figcaption>
                        <img :src="tryResult.url" :alt="$t('upscaler.upscaled')" class="w-full rounded-lg border border-gray-200 bg-gray-50" />
                    </figure>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
