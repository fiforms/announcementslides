<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useChunkedUpload } from '@/Composables/useChunkedUpload';

const props = defineProps({
    slide: { type: Object, required: true },
    mediaTypes: { type: Array, default: () => [] },
    storeRoute: { type: String, required: true },
    destroyRoute: { type: String, required: true },
    routeParams: { type: Object, default: () => ({}) },
    reloadOnly: { type: Array, default: () => ['slide'] },
});

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
                <img v-if="media.thumbnail_url" :src="media.thumbnail_url" class="h-10 w-16 rounded object-cover bg-slate-100" />
                <div v-else class="h-10 w-16 rounded bg-slate-100 flex items-center justify-center text-[10px] text-gray-400">
                    {{ media.mime_type?.split('/')?.[1] ?? 'file' }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900">{{ labelFor(media.media_type) }}</p>
                    <p class="truncate text-xs text-gray-500">{{ media.original_filename }} · {{ formatBytes(media.file_size) }}</p>
                </div>
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
