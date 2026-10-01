<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useChunkedUpload } from '@/Composables/useChunkedUpload';
import { upscaleImage, upscaleIneligibility, ineligibleMessage, useUpscalerSettings } from '@/Composables/useUpscaler';

const props = defineProps({
    slide: { type: Object, required: true },
    mediaTypes: { type: Array, default: () => [] },
    storeRoute: { type: String, required: true },
    destroyRoute: { type: String, required: true },
    routeParams: { type: Object, default: () => ({}) },
    reloadOnly: { type: Array, default: () => ['slide'] },
    // The routes that upscale a media file and switch between its original and
    // upscaled versions. By convention they sit beside the store route
    // ('x.media.store' -> 'x.media.upscale' / 'x.media.version').
    upscaleRoute: { type: String, default: null },
    versionRoute: { type: String, default: null },
});

const upscaler = useUpscalerSettings();
const upscaleRouteName = computed(() => props.upscaleRoute ?? props.storeRoute.replace(/store$/, 'upscale'));
const versionRouteName = computed(() => props.versionRoute ?? props.storeRoute.replace(/store$/, 'version'));

// Upscales in progress, by media id: { progress, error }
const upscaling = ref({});
let upscaleAbort = null;

const selectedType = ref(props.mediaTypes[0]?.value ?? 'slide-overlay');
const fileInput = ref(null);

const selectedTypeConfig = computed(() =>
    props.mediaTypes.find(t => t.value === selectedType.value)
);

const { isUploading, uploadError, overallProgress, upload } = useChunkedUpload({
    finalizeRoute: props.storeRoute,
    finalizeRouteParams: { ...props.routeParams, slide: props.slide.id },
    buildFinalizePayload: (completedUploads) => ({
        ...completedUploads[0],
        media_type: selectedType.value,
    }),
});

function labelFor(type) {
    return props.mediaTypes.find(t => t.value === type)?.label ?? type;
}

function formatBytes(bytes) {
    if (!bytes) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0, n = bytes;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(n < 10 && i > 0 ? 1 : 0)} ${units[i]}`;
}

function isLastPrimary(media) {
    return media.media_type === 'slide'
        && props.slide.media.filter(m => m.media_type === 'slide').length <= 1;
}

async function onFileSelected(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    const result = await upload([file], { media_type: selectedType.value });
    if (result) {
        router.reload({ only: props.reloadOnly });
    }
    if (fileInput.value) fileInput.value.value = '';
}

function upscaleBlocked(media) {
    return upscaleIneligibility(media.image_width, media.image_height, upscaler);
}

function canOfferUpscale(media) {
    return upscaler?.enabled && media.can_upscale && media.active_variant !== 'upscaled';
}

async function upscaleMedia(media) {
    if (Object.keys(upscaling.value).length) return;
    upscaling.value = { [media.id]: { progress: 0, error: null } };
    const state = upscaling.value[media.id];
    upscaleAbort = new AbortController();

    try {
        const response = await fetch(media.file_url);
        if (!response.ok) throw new Error('The image could not be downloaded.');

        const result = await upscaleImage(await response.blob(), {
            model: upscaler.model,
            quality: upscaler.jpeg_quality,
            patchSize: upscaler.patch_size,
            onProgress: (p) => { state.progress = Math.round(p * 100); },
            signal: upscaleAbort.signal,
        });

        state.progress = 100;
        const name = (media.original_filename ?? 'slide').replace(/\.[^/.]+$/, '') + '.jpg';
        const uploader = useChunkedUpload({
            finalizeRoute: upscaleRouteName.value,
            finalizeRouteParams: { ...props.routeParams, slide: props.slide.id, media: media.id },
            buildFinalizePayload: (completed) => ({ ...completed[0], model: upscaler.model }),
        });
        const saved = await uploader.upload([new File([result.blob], name, { type: 'image/jpeg' })], { media_type: media.media_type });
        if (!saved) throw new Error(uploader.uploadError.value);

        router.reload({ only: props.reloadOnly });
    } catch (err) {
        if (err.code !== 'aborted') state.error = err.message || 'Upscaling failed.';
    } finally {
        if (!state.error) upscaling.value = {};
        upscaleAbort = null;
    }
}

function cancelUpscale() {
    upscaleAbort?.abort();
}

function switchVersion(media, version) {
    router.post(route(versionRouteName.value, { ...props.routeParams, slide: props.slide.id, media: media.id }), { version }, {
        preserveScroll: true,
        preserveState: true,
        only: props.reloadOnly,
    });
}

function removeMedia(media) {
    if (!confirm(`Remove this ${labelFor(media.media_type)} file?`)) return;
    router.delete(route(props.destroyRoute, { ...props.routeParams, slide: props.slide.id, media: media.id }), {
        preserveScroll: true,
        preserveState: true,
        only: props.reloadOnly,
    });
}
</script>

<template>
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
        <h3 class="text-sm font-semibold text-gray-900">Media files</h3>

        <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200">
            <li v-for="media in slide.media" :key="media.id"
                class="flex items-center gap-3 px-3 py-2">
                <img v-if="media.thumbnail_url || media.mime_type === 'image/svg+xml'" :src="media.thumbnail_url || media.file_url" class="h-10 w-16 rounded object-cover bg-slate-100" />
                <div v-else class="h-10 w-16 rounded bg-slate-100 flex items-center justify-center text-[10px] text-gray-400">
                    {{ media.mime_type?.split('/')?.[1] ?? 'file' }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900">{{ labelFor(media.media_type) }}</p>
                    <p class="truncate text-xs text-gray-500">
                        {{ media.original_filename }} · {{ formatBytes(media.file_size) }}
                        <template v-if="media.image_width"> · {{ media.image_width }}×{{ media.image_height }}</template>
                        <span v-if="media.active_variant === 'upscaled'"
                            class="ml-1 rounded bg-purple-100 px-1.5 py-0.5 font-medium text-purple-700">AI upscaled</span>
                    </p>
                    <div v-if="upscaling[media.id]" class="mt-1">
                        <template v-if="upscaling[media.id].error">
                            <p class="text-xs text-red-600">{{ upscaling[media.id].error }}
                                <button type="button" class="underline" @click="upscaling = {}">Dismiss</button>
                            </p>
                        </template>
                        <template v-else>
                            <div class="flex justify-between text-xs text-gray-500">
                                <span>{{ upscaling[media.id].progress >= 100 ? 'Uploading…' : `Upscaling… ${upscaling[media.id].progress}%` }}</span>
                                <button v-if="upscaling[media.id].progress < 100" type="button" class="text-red-600 hover:underline" @click="cancelUpscale">Cancel</button>
                            </div>
                            <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
                                <div class="h-full rounded-full bg-purple-500 transition-all duration-200"
                                    :style="{ width: upscaling[media.id].progress + '%' }" />
                            </div>
                        </template>
                    </div>
                </div>
                <template v-if="!upscaling[media.id]">
                    <button v-if="media.active_variant === 'upscaled'" type="button" @click="switchVersion(media, 'original')"
                        title="Go back to the image as it was uploaded"
                        class="rounded-lg border border-purple-200 px-3 py-1 text-xs font-medium text-purple-700 hover:bg-purple-50">
                        Undo upscale
                    </button>
                    <template v-else-if="canOfferUpscale(media)">
                        <button v-if="media.has_upscaled" type="button" @click="switchVersion(media, 'upscaled')"
                            title="Use the upscaled version again"
                            class="rounded-lg border border-purple-200 px-3 py-1 text-xs font-medium text-purple-700 hover:bg-purple-50">
                            Redo upscale
                        </button>
                        <button type="button" @click="upscaleMedia(media)"
                            :disabled="!!upscaleBlocked(media) || Object.keys(upscaling).length > 0"
                            :title="upscaleBlocked(media) ? ineligibleMessage(upscaleBlocked(media), upscaler) : 'Double the resolution with AI (the original is kept)'"
                            class="rounded-lg border border-purple-200 px-3 py-1 text-xs font-medium text-purple-700 hover:bg-purple-50 disabled:opacity-30 disabled:cursor-not-allowed">
                            {{ media.has_upscaled ? 'Upscale again' : 'Upscale 2×' }}
                        </button>
                    </template>
                </template>
                <a :href="media.file_url" target="_blank" rel="noopener"
                    class="rounded-lg border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                    Download
                </a>
                <button type="button" @click="removeMedia(media)" :disabled="isLastPrimary(media)"
                    :title="isLastPrimary(media) ? 'A slide must keep at least one Slide file' : 'Remove'"
                    class="rounded-lg border border-red-200 px-3 py-1 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-30 disabled:cursor-not-allowed">
                    Remove
                </button>
            </li>
        </ul>

        <div class="flex flex-wrap items-end gap-3 pt-2">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Add media type</label>
                <select v-model="selectedType"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option v-for="t in mediaTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
            </div>
            <div>
                <input ref="fileInput" type="file" :accept="selectedTypeConfig?.accept" :disabled="isUploading"
                    @change="onFileSelected"
                    class="block text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-indigo-700" />
            </div>
            <p v-if="isUploading" class="text-xs text-gray-500">Uploading… {{ overallProgress }}%</p>
        </div>
        <p v-if="uploadError" class="text-xs text-red-600">{{ uploadError }}</p>
    </div>
</template>
