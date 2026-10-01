<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import DropZone from '@/Components/DropZone.vue';
import ValidationWarnings from '@/Components/ValidationWarnings.vue';
import DateTimeLocalInput from '@/Components/DateTimeLocalInput.vue';
import { useChunkedUpload } from '@/Composables/useChunkedUpload.js';
import { useImageValidation } from '@/Composables/useImageValidation.js';
import { upscaleImage, upscaleIneligibility, ineligibleMessage, useUpscalerSettings } from '@/Composables/useUpscaler.js';
import { downscaleImage, downscaledSize, exceedsDownscaleLimit } from '@/Composables/useImageResize.js';

const props = defineProps({
    redirectRoute:     { type: String, required: true },
    redirectParams:    { type: Object, default: () => ({}) },
    entityId:          { type: Number, default: null },
    languages:         { type: Array, default: () => [] },
    pendingMessage:    { type: String, default: null },
    showStatusSelect:  { type: Boolean, default: false },
    // Entity uploads: that entity's non-main shows ({id, name}). Global
    // uploads: active global "separate show" templates ({id, name}).
    shows:             { type: Array, default: () => [] },
});

const emit = defineEmits(['success']);

const { isUploading, uploadError, fileProgress, overallProgress, upload } = useChunkedUpload();
const { validate: validateImage, validateDimensions } = useImageValidation();
const upscalerSettings = useUpscalerSettings();

const selectedFiles = ref([]);
const filePreviews  = ref([]);
const fileValidations = ref([]);
const title         = ref('');
const notes         = ref('');
const textDescription = ref('');
const link          = ref('');
const languageId    = ref('');
const publishAt     = ref('');
const expiresAt     = ref('');
const status        = ref('published');
const shareNearby   = ref(false);
const addToShow     = ref('main'); // 'main' | 'separate' | 'none'
const targetShowId  = ref('');
const newShowName   = ref('');

// Per selected file: whether it can be resized in the browser (AI-upscaled 2x
// if small, shrunk to 4K if larger) and whether it will be, plus the progress
// of that work (run on submit, before uploading).
const fileResize    = ref([]); // { kind, eligible, reason, enabled, status, progress, note }
const isResizing    = ref(false);
let resizeAbort     = null;

const busy = computed(() => isUploading.value || isResizing.value);
const canSubmit = computed(() => selectedFiles.value.length > 0 && title.value.trim());

const RESIZABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

function resizeInfoFor(file, validation) {
    const s = upscalerSettings;
    const none = { kind: null, eligible: false, reason: null, enabled: false };
    if (!s || !RESIZABLE_TYPES.includes(file.type) || !validation.width) return none;

    const base = { status: null, progress: 0, note: null };

    // Beyond 4K: shrink it (always on by default; the checkbox lets them keep it).
    if (exceedsDownscaleLimit(validation.width, validation.height, s)) {
        return { ...base, kind: 'downscale', eligible: true, reason: null, enabled: true };
    }

    if (!s.enabled) return none;
    const reason = upscaleIneligibility(validation.width, validation.height, s);
    return { ...base, kind: 'upscale', eligible: !reason, reason, enabled: !reason && s.auto_on_upload };
}

// The size the image will end up at if it's resized, or null.
function resizedSizeFor(i) {
    const v = fileValidations.value[i];
    const kind = fileResize.value[i]?.kind;
    if (!v?.width || !v?.height) return null;
    if (kind === 'upscale') return { width: v.width * 2, height: v.height * 2 };
    if (kind === 'downscale') return downscaledSize(v.width, v.height, upscalerSettings.downscale.max);
    return null;
}

// What the warnings should say: for a file that will be resized, judge the
// new size rather than the original's.
function issuesFor(i) {
    const v = fileValidations.value[i];
    if (!v) return [];
    const size = fileResize.value[i]?.enabled ? resizedSizeFor(i) : null;
    if (size) {
        const sizeIssues = v.issues.filter(m => !m.startsWith('Low resolution') && !m.startsWith('High resolution') && !m.startsWith('Aspect ratio'));
        return [...validateDimensions(size.width, size.height), ...sizeIssues];
    }
    return v.issues;
}

async function onFilesSelected(files) {
    selectedFiles.value = files;
    filePreviews.value  = files.map(f => ({
        name: f.name,
        size: f.size,
        url:  f.type.startsWith('image/') ? URL.createObjectURL(f) : null,
        type: f.type,
    }));

    fileValidations.value = await Promise.all(files.map(f => validateImage(f)));
    fileResize.value      = files.map((f, i) => resizeInfoFor(f, fileValidations.value[i]));

    if (!title.value && files.length === 1) {
        title.value = files[0].name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ');
    }
}

function removeFile(i) {
    selectedFiles.value.splice(i, 1);
    filePreviews.value.splice(i, 1);
    fileValidations.value.splice(i, 1);
    fileResize.value.splice(i, 1);
}

function cancelResize() {
    resizeAbort?.abort();
}

// Resizes every file marked for it, one at a time. A failure (no WebGL, out of
// memory, …) is reported on that file and it uploads as the original instead;
// only cancelling stops the whole submission. Returns upload items for
// useChunkedUpload.upload, or null if cancelled.
async function prepareUploads() {
    const items = [];
    isResizing.value = true;
    resizeAbort = new AbortController();

    try {
        for (let i = 0; i < selectedFiles.value.length; i++) {
            const file = selectedFiles.value[i];
            const info = fileResize.value[i];

            if (!info?.enabled) {
                items.push(file);
                continue;
            }

            info.status = 'working';
            info.progress = 0;
            info.note = null;

            try {
                const isUpscale = info.kind === 'upscale';
                const result = isUpscale
                    ? await upscaleImage(file, {
                        model: upscalerSettings.model,
                        quality: upscalerSettings.jpeg_quality,
                        patchSize: upscalerSettings.patch_size,
                        onProgress: (p) => { info.progress = Math.round(p * 100); },
                        signal: resizeAbort.signal,
                    })
                    : await downscaleImage(file, upscalerSettings.downscale.max, { quality: upscalerSettings.jpeg_quality });
                const name = file.name.replace(/\.[^/.]+$/, '') + '.jpg';
                items.push({
                    file: new File([result.blob], name, { type: 'image/jpeg' }),
                    resize: { kind: info.kind, model: isUpscale ? upscalerSettings.model : null, original: file },
                });
                info.status = 'done';
                info.note = `${isUpscale ? 'Upscaled' : 'Downscaled'} to ${result.width}×${result.height}.`;
            } catch (err) {
                if (err.code === 'aborted') {
                    info.status = null;
                    return null;
                }
                items.push(file);
                info.status = 'failed';
                info.note = `${err.message} The original will be uploaded instead.`;
            }
        }
    } finally {
        isResizing.value = false;
        resizeAbort = null;
    }

    return items;
}

function formatBytes(bytes) {
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

async function submit() {
    if (!canSubmit.value) return;

    const payload = {
        title:             title.value,
        notes:             notes.value,
        text_description:  textDescription.value,
        link:              link.value || null,
        language_id:       languageId.value || null,
        publish_at:        publishAt.value || null,
        expires_at:        expiresAt.value || null,
    };

    if (props.showStatusSelect) {
        payload.status = status.value;
    }

    if (props.entityId) {
        payload.entity_id = props.entityId;
        payload.share_nearby = shareNearby.value;
    }

    payload.add_to_show = addToShow.value;
    if (addToShow.value === 'separate') {
        if (targetShowId.value) {
            if (props.entityId) {
                payload.show_id = targetShowId.value;
            } else {
                payload.global_template_id = targetShowId.value;
            }
        } else {
            payload.new_show_name = newShowName.value;
        }
    }

    const items = await prepareUploads();
    if (!items) return;

    const result = await upload(items, payload);

    if (result) {
        router.visit(route(props.redirectRoute, props.redirectParams), {
            onSuccess: () => {
                selectedFiles.value = [];
                filePreviews.value  = [];
                fileValidations.value = [];
                fileResize.value    = [];
                title.value         = '';
                notes.value         = '';
                textDescription.value = '';
                link.value          = '';
                languageId.value    = '';
                publishAt.value     = '';
                expiresAt.value     = '';
                shareNearby.value   = false;
                addToShow.value     = 'main';
                targetShowId.value  = '';
                newShowName.value   = '';
                emit('success');
            },
        });
    }
}
</script>

<template>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="mb-4 text-base font-semibold text-gray-900">Upload Slides</h2>

        <div v-if="pendingMessage" class="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            {{ pendingMessage }}
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <DropZone @files-selected="onFilesSelected" />

            <ul v-if="filePreviews.length" class="space-y-3">
                <li v-for="(f, i) in filePreviews" :key="i"
                    class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                    <div class="flex items-center gap-3">
                        <img v-if="f.url" :src="f.url" class="h-10 w-16 rounded object-cover flex-shrink-0" />
                        <svg v-else class="h-10 w-10 text-gray-300 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M15 10l4.553-2.069A1 1 0 0121 8.876V15.5a1 1 0 01-1.447.894L15 14M3 8.5A1.5 1.5 0 014.5 7h8A1.5 1.5 0 0114 8.5v7a1.5 1.5 0 01-1.5 1.5h-8A1.5 1.5 0 013 15.5v-7z" />
                        </svg>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-gray-700 truncate">{{ f.name }}</p>
                            <p class="text-xs text-gray-400">{{ formatBytes(f.size) }}</p>
                        </div>
                        <button v-if="!busy" type="button" @click="removeFile(i)"
                            class="text-gray-400 hover:text-red-500 transition-colors flex-shrink-0">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div v-if="fileValidations[i]" class="mt-2">
                        <ValidationWarnings :issues="issuesFor(i)" />
                    </div>
                    <div v-if="fileResize[i]" class="mt-2 text-xs">
                        <label v-if="fileResize[i].eligible && !fileResize[i].status" class="flex items-center gap-2 text-gray-700">
                            <input v-model="fileResize[i].enabled" type="checkbox" :disabled="busy"
                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                            <span>
                                {{ fileResize[i].kind === 'upscale' ? 'Upscale 2× with AI' : 'Downscale to fit 4K' }}
                                <span v-if="resizedSizeFor(i)" class="text-gray-400">
                                    ({{ fileValidations[i]?.width }}×{{ fileValidations[i]?.height }} →
                                    {{ resizedSizeFor(i).width }}×{{ resizedSizeFor(i).height }};
                                    the original is kept)
                                </span>
                            </span>
                        </label>
                        <p v-else-if="fileResize[i].reason === 'too-small'" class="text-gray-400">
                            {{ ineligibleMessage(fileResize[i].reason, upscalerSettings) }}
                        </p>
                        <div v-if="fileResize[i].status === 'working'">
                            <template v-if="fileResize[i].kind === 'upscale'">
                                <div class="mb-1 flex justify-between text-gray-500">
                                    <span>Upscaling… {{ fileResize[i].progress }}%</span>
                                    <button type="button" @click="cancelResize" class="text-red-600 hover:underline">Cancel</button>
                                </div>
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200">
                                    <div class="h-full rounded-full bg-purple-500 transition-all duration-200"
                                        :style="{ width: fileResize[i].progress + '%' }" />
                                </div>
                            </template>
                            <p v-else class="text-gray-500">Downscaling…</p>
                        </div>
                        <p v-else-if="fileResize[i].note" :class="fileResize[i].status === 'failed' ? 'text-amber-700' : 'text-green-700'">
                            {{ fileResize[i].note }}
                        </p>
                    </div>
                    <div v-if="isUploading && fileProgress[i]" class="mt-2">
                        <div class="flex justify-between text-xs text-gray-500 mb-1">
                            <span>{{ fileProgress[i].done ? 'Done' : 'Uploading…' }}</span>
                            <span>{{ fileProgress[i].progress }}%</span>
                        </div>
                        <div class="h-1.5 w-full rounded-full bg-gray-200 overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-200"
                                :class="fileProgress[i].done ? 'bg-green-500' : 'bg-indigo-500'"
                                :style="{ width: fileProgress[i].progress + '%' }" />
                        </div>
                    </div>
                </li>
            </ul>

            <div v-if="isUploading && filePreviews.length > 1" class="rounded-lg bg-indigo-50 px-4 py-3">
                <div class="flex justify-between text-sm font-medium text-indigo-700 mb-1.5">
                    <span>Overall progress</span>
                    <span>{{ overallProgress }}%</span>
                </div>
                <div class="h-2 w-full rounded-full bg-indigo-100 overflow-hidden">
                    <div class="h-full rounded-full bg-indigo-500 transition-all duration-200"
                        :style="{ width: overallProgress + '%' }" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
                    <input v-model="title" type="text" required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="e.g. Camp Meeting 2026" />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes <span class="text-gray-400 font-normal">(optional)</span></label>
                    <textarea v-model="notes" rows="2"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="Optional context for administrators…" />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description <span class="text-gray-400 font-normal">(optional)</span></label>
                    <textarea v-model="textDescription" rows="2"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="Shown alongside the slide, e.g. event details…" />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Link <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input v-model="link" type="url" placeholder="https://…"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Language <span class="text-gray-400 font-normal">(optional)</span></label>
                    <select v-model="languageId"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">No specific language (visible in all)</option>
                        <option v-for="lang in languages" :key="lang.id" :value="lang.id">
                            {{ lang.name }} ({{ lang.native_name }})
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Publish Date <span class="text-gray-400 font-normal">(optional)</span></label>
                    <DateTimeLocalInput v-model="publishAt" />
                    <p class="mt-1 text-xs text-gray-500">Don't show before this date/time</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Expiration Date <span class="text-gray-400 font-normal">(optional)</span></label>
                    <DateTimeLocalInput v-model="expiresAt" />
                    <p class="mt-1 text-xs text-gray-500">Hide after this date/time</p>
                </div>

                <div v-if="showStatusSelect">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select v-model="status"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="published">Published (live immediately)</option>
                        <option value="pending">Pending (submit for review)</option>
                        <option value="draft">Draft (not visible)</option>
                    </select>
                </div>

                <div class="sm:col-span-2 space-y-2">
                    <label class="block text-sm font-medium text-gray-700">Add to show</label>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="addToShow" type="radio" value="main"
                            class="border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        Add to Main Show (default)
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="addToShow" type="radio" value="separate"
                            class="border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        Add to a different show
                    </label>
                    <div v-if="addToShow === 'separate'" class="ml-6 flex flex-wrap items-center gap-2">
                        <select v-model="targetShowId"
                            class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">+ Create new show…</option>
                            <option v-for="s in shows" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                        <input v-if="!targetShowId" v-model="newShowName" type="text" placeholder="New show name"
                            class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="addToShow" type="radio" value="none"
                            class="border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        Do not add to any show
                    </label>
                </div>

                <div v-if="entityId" class="sm:col-span-2">
                    <label class="flex items-start gap-2">
                        <input v-model="shareNearby" type="checkbox"
                            class="mt-0.5 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                        <span class="text-sm font-medium text-gray-700">Share with nearby churches</span>
                    </label>
                    <div v-if="shareNearby" class="mt-2 rounded-lg bg-indigo-50 border border-indigo-200 px-4 py-3 text-sm text-indigo-800">
                        Local slide sharing allows you to promote events and ministries relevant to others in your local area. Ensure that your slide contains all relevant information such as the date and time, and the specific address of the event. Please share any events you would invite the public to, but don't share weekly announcements such as regular potluck, regular weekly services, other events specifically for local members. Refrain from sharing generic greetings or anything not specifically tied to an event.
                        <p class="mt-2 text-xs text-indigo-700">Shared slides must meet the image quality requirements.</p>
                    </div>
                </div>
            </div>

            <div v-if="uploadError" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                {{ uploadError }}
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" :disabled="busy || !canSubmit"
                    class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                    {{ isResizing ? 'Resizing…' : isUploading ? `Uploading… ${overallProgress}%` : `Upload ${selectedFiles.length || ''} slide${selectedFiles.length === 1 ? '' : 's'}` }}
                </button>
            </div>
        </form>
    </div>
</template>
