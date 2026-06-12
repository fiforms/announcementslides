<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import DropZone from '@/Components/DropZone.vue';
import ValidationWarnings from '@/Components/ValidationWarnings.vue';
import { useChunkedUpload } from '@/Composables/useChunkedUpload.js';
import { useImageValidation } from '@/Composables/useImageValidation.js';

const props = defineProps({
    redirectRoute:     { type: String, required: true },
    redirectParams:    { type: Object, default: () => ({}) },
    entityId:          { type: Number, default: null },
    languages:         { type: Array, default: () => [] },
    pendingMessage:    { type: String, default: null },
    showStatusSelect:  { type: Boolean, default: false },
});

const emit = defineEmits(['success']);

const { isUploading, uploadError, fileProgress, overallProgress, upload } = useChunkedUpload();
const { validate: validateImage } = useImageValidation();

const selectedFiles = ref([]);
const filePreviews  = ref([]);
const fileValidations = ref([]);
const title         = ref('');
const notes         = ref('');
const languageId    = ref('');
const publishAt     = ref('');
const expiresAt     = ref('');
const status        = ref('published');
const shareNearby   = ref(false);

const canSubmit = computed(() => selectedFiles.value.length > 0 && title.value.trim());

async function onFilesSelected(files) {
    selectedFiles.value = files;
    filePreviews.value  = files.map(f => ({
        name: f.name,
        size: f.size,
        url:  f.type.startsWith('image/') ? URL.createObjectURL(f) : null,
        type: f.type,
    }));

    fileValidations.value = await Promise.all(files.map(f => validateImage(f)));

    if (!title.value && files.length === 1) {
        title.value = files[0].name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ');
    }
}

function removeFile(i) {
    selectedFiles.value.splice(i, 1);
    filePreviews.value.splice(i, 1);
}

function formatBytes(bytes) {
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

async function submit() {
    if (!canSubmit.value) return;

    const payload = {
        title:       title.value,
        notes:       notes.value,
        language_id: languageId.value || null,
        publish_at:  publishAt.value || null,
        expires_at:  expiresAt.value || null,
    };

    if (props.showStatusSelect) {
        payload.status = status.value;
    }

    if (props.entityId) {
        payload.entity_id = props.entityId;
        payload.share_nearby = shareNearby.value;
    }

    const result = await upload(selectedFiles.value, payload);

    if (result) {
        router.visit(route(props.redirectRoute, props.redirectParams), {
            onSuccess: () => {
                selectedFiles.value = [];
                filePreviews.value  = [];
                title.value         = '';
                notes.value         = '';
                languageId.value    = '';
                publishAt.value     = '';
                expiresAt.value     = '';
                shareNearby.value   = false;
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
                        <button v-if="!isUploading" type="button" @click="removeFile(i)"
                            class="text-gray-400 hover:text-red-500 transition-colors flex-shrink-0">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div v-if="fileValidations[i]" class="mt-2">
                        <ValidationWarnings :issues="fileValidations[i].issues" />
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
                    <input v-model="publishAt" type="datetime-local"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <p class="mt-1 text-xs text-gray-500">Don't show before this date/time</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Expiration Date <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input v-model="expiresAt" type="datetime-local"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
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
                <button type="submit" :disabled="isUploading || !canSubmit"
                    class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                    {{ isUploading ? `Uploading… ${overallProgress}%` : `Upload ${selectedFiles.length || ''} slide${selectedFiles.length === 1 ? '' : 's'}` }}
                </button>
            </div>
        </form>
    </div>
</template>
