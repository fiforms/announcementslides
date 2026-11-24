<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import DropZone from '@/Components/DropZone.vue';
import ValidationWarnings from '@/Components/ValidationWarnings.vue';
import DateTimeLocalInput from '@/Components/DateTimeLocalInput.vue';
import ResizeOption from '@/Components/ResizeOption.vue';
import { useImageValidation } from '@/Composables/useImageValidation.js';
import { isWebUrl } from '@/Composables/useQrScan.js';
import { useChunkedUpload } from '@/Composables/useChunkedUpload.js';
import { useUploadResize } from '@/Composables/useUploadResize.js';

// The admin slide upload form (files, details, "add to show"), shared by the
// Slides page and the per-promo upload page. With `promo` set it only ever
// adds to that playlist (the show choice is fixed and not offered).
const props = defineProps({
    languages: { type: Array, default: () => [] },
    globalShowTemplates: { type: Array, default: () => [] },
    // { id, name } of the playlist to add to, or null for the full choice.
    promo: { type: Object, default: null },
});

const emit = defineEmits(['cancel', 'done']);

const initialAddToShow = props.promo ? 'separate' : 'main';
const initialTarget = props.promo ? props.promo.id : '';
const addToShow = ref(initialAddToShow); // 'main' | 'separate' | 'none'
const targetShowId = ref(initialTarget);
const newShowName = ref('');

// ── Upload form ────────────────────────────────────────────────────────────────


const { validate: validateImage } = useImageValidation();
const { isUploading, uploadError, fileProgress, overallProgress, upload } = useChunkedUpload();
const resize = useUploadResize();
const busy = computed(() => isUploading.value || resize.isResizing.value);

const { t } = useI18n();

const selectedFiles   = ref([]);
const filePreviews    = ref([]);
const fileValidations = ref([]);

const form = useForm({
    title:            '',
    notes:            '',
    text_description: '',
    link:             '',
    language_id:      '',
    publish_at:       '',
    expires_at:       '',
    status:           'published',
});

async function onFilesSelected(files) {
    selectedFiles.value = files;

    filePreviews.value = files.map(f => ({
        name: f.name,
        size: f.size,
        url:  f.type.startsWith('image/') ? URL.createObjectURL(f) : null,
        type: f.type,
    }));

    fileValidations.value = await Promise.all(files.map(f => validateImage(f)));
    resize.setFiles(files, fileValidations.value);

    // One link covers a whole batch, so only a lone slide's QR code is used for it.
    const qrUrl = files.length === 1 ? fileValidations.value[0]?.qrCodes?.find(isWebUrl) : null;
    if (qrUrl && !form.link) form.link = qrUrl;

    if (!form.title && files.length === 1) {
        form.title = files[0].name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ');
    }
}

function removeFile(index) {
    selectedFiles.value.splice(index, 1);
    filePreviews.value.splice(index, 1);
    fileValidations.value.splice(index, 1);
    resize.removeFile(index);
}

function formatBytes(bytes) {
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

async function submitUpload() {
    if (!selectedFiles.value.length && !form.title) return;

    const payload = {
        title:            form.title,
        notes:            form.notes,
        text_description: form.text_description,
        link:             form.link || null,
        language_id:      form.language_id || null,
        publish_at:       form.publish_at,
        expires_at:       form.expires_at,
        status:           form.status,
        add_to_show:      addToShow.value,
    };

    if (addToShow.value === 'separate') {
        if (targetShowId.value) {
            payload.global_template_id = targetShowId.value;
        } else {
            payload.new_show_name = newShowName.value;
        }
    }

    // Resize what's marked (shrink beyond 4K / AI-upscale small), then upload.
    const items = await resize.prepare(selectedFiles.value);
    if (!items) return;

    if (!await upload(items, payload)) return;

    // A playlist was created or added to: its content is managed under
    // Playlists, which is where the new slide actually appears.
    const toPromos = payload.add_to_show === 'separate';
    router.visit(route(toPromos ? 'admin.playlists.index' : 'admin.slides.index'), {
        onSuccess: () => {
            form.reset();
            selectedFiles.value  = [];
            filePreviews.value   = [];
            fileValidations.value = [];
            resize.reset();
            addToShow.value      = initialAddToShow;
            targetShowId.value   = initialTarget;
            newShowName.value    = '';
            emit('done');
        },
    });
}
</script>

<template>
    <div class="mb-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-base font-semibold text-gray-900">{{ $t('admin.upload_new_slides') }}</h2>

            <form @submit.prevent="submitUpload" class="space-y-5">
                <DropZone @files-selected="onFilesSelected" />

                <!-- File preview list -->
                <ul v-if="filePreviews.length" class="space-y-2">
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
                            <button v-if="!busy" type="button" @click="removeFile(i)" class="text-gray-400 hover:text-red-500 transition-colors flex-shrink-0">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <!-- Validation warnings -->
                        <div v-if="fileValidations[i]" class="mt-2">
                            <ValidationWarnings :issues="resize.issuesFor(i)" />
                        </div>
                        <ResizeOption :info="resize.infos.value[i]" :validation="fileValidations[i]" :size="resize.sizeFor(i)"
                            :settings="resize.settings" :busy="busy"
                            @update:enabled="resize.infos.value[i].enabled = $event" @cancel="resize.cancel" />
                        <!-- Per-file progress bar (shown while uploading) -->
                        <div v-if="isUploading && fileProgress[i]" class="mt-2">
                            <div class="flex justify-between text-xs text-gray-500 mb-1">
                                <span>{{ fileProgress[i].done ? $t('admin.done') : $t('admin.uploading') }}</span>
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

                <!-- Overall progress bar -->
                <div v-if="isUploading && filePreviews.length > 1" class="rounded-lg bg-indigo-50 px-4 py-3">
                    <div class="flex justify-between text-sm font-medium text-indigo-700 mb-1.5">
                        <span>{{ $t('admin.overall_progress') }}</span>
                        <span>{{ overallProgress }}%</span>
                    </div>
                    <div class="h-2 w-full rounded-full bg-indigo-100 overflow-hidden">
                        <div class="h-full rounded-full bg-indigo-500 transition-all duration-200"
                            :style="{ width: overallProgress + '%' }" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('admin.caption_title') }} <span v-if="!selectedFiles.length" class="text-red-500">*</span></label>
                        <input v-model="form.title" type="text"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="e.g. Camp Meeting 2026" />
                        <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('admin.notes') }} <span class="text-gray-400 font-normal">(internal)</span></label>
                        <textarea v-model="form.notes" rows="2"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Optional notes for your team…" />
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description <span class="text-gray-400 font-normal">(optional)</span></label>
                        <textarea v-model="form.text_description" rows="2"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Shown alongside the slide, e.g. event details…" />
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Link <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input v-model="form.link" type="url" placeholder="https://…"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Language <span class="text-gray-400 font-normal">(optional)</span></label>
                        <select v-model="form.language_id"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">No specific language (visible in all)</option>
                            <option v-for="lang in languages" :key="lang.id" :value="lang.id">
                                {{ lang.name }} ({{ lang.native_name }})
                            </option>
                        </select>
                        <p v-if="form.errors.language_id" class="mt-1 text-xs text-red-600">{{ form.errors.language_id }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('admin.publish_date') }} <span class="text-gray-400 font-normal">{{ $t('admin.optional') }}</span></label>
                        <DateTimeLocalInput v-model="form.publish_at" />
                        <p class="mt-1 text-xs text-gray-500">{{ $t('upload.publish_hint') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('admin.expiration_date') }} <span class="text-gray-400 font-normal">{{ $t('admin.optional') }}</span></label>
                        <DateTimeLocalInput v-model="form.expires_at" />
                        <p class="mt-1 text-xs text-gray-500">{{ $t('upload.expiry_hint') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('admin.status') }}</label>
                        <select v-model="form.status"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="published">{{ $t('upload.status_published') }}</option>
                            <option value="draft">{{ $t('upload.status_draft') }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="promo" class="rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                    Adding to the playlist <strong>{{ promo.name }}</strong>. Every site gets a copy.
                </div>
                <div v-else class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700">Add to show</label>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="addToShow" type="radio" value="main"
                            class="border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        Add to every site's Main Show (default)
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="addToShow" type="radio" value="separate"
                            class="border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        Add to a separate playlist
                    </label>
                    <div v-if="addToShow === 'separate'" class="ml-6 flex flex-wrap items-center gap-2">
                        <select v-model="targetShowId"
                            class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">+ Create new show…</option>
                            <option v-for="t in globalShowTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                        <input v-if="!targetShowId" v-model="newShowName" type="text" placeholder="Show name"
                            class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="addToShow" type="radio" value="none"
                            class="border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        Do not add to any show
                    </label>
                </div>

                <div v-if="uploadError" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                    {{ uploadError }}
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" :disabled="busy || (!selectedFiles.length && !form.title)"
                        class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        {{ resize.isResizing.value ? 'Resizing…' : isUploading ? `${$t('admin.uploading')} ${overallProgress}%` : selectedFiles.length ? $t('upload.btn_upload', { n: selectedFiles.length }) : $t('upload.btn_create') }}
                    </button>
                    <button type="button" :disabled="busy" @click="emit('cancel')"
                        class="rounded-lg border border-gray-300 px-5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        {{ $t('admin.cancel') }}
                    </button>
                </div>
            </form>
    </div>
</template>
