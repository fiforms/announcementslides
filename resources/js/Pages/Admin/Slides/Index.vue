<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SlideCard from '@/Components/SlideCard.vue';
import DropZone from '@/Components/DropZone.vue';
import ValidationWarnings from '@/Components/ValidationWarnings.vue';
import { useImageValidation } from '@/Composables/useImageValidation.js';

const props = defineProps({
    current:   { type: Array, default: () => [] },
    pending:   { type: Array, default: () => [] },
    upcoming:  { type: Array, default: () => [] },
    archived:  { type: Array, default: () => [] },
    drafts:    { type: Array, default: () => [] },
    languages: { type: Array, default: () => [] },
    globalShowTemplates: { type: Array, default: () => [] },
});

const addToShow = ref('main'); // 'main' | 'separate' | 'none'
const targetShowId = ref('');
const newShowName = ref('');

// ── Upload form ────────────────────────────────────────────────────────────────

// Must stay below upload_max_filesize in php.ini (default 2 MB).
// .htaccess raises it to 8 MB for Apache; for nginx/artisan-serve, set upload_max_filesize=8M.
const CHUNK_SIZE = 1.5 * 1024 * 1024; // 1.5 MB — safe under the 2 MB default

const { validate: validateImage } = useImageValidation();

const showUploadPanel = ref(false);
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

// Chunked upload state
const isUploading     = ref(false);
const uploadError     = ref(null);
const fileProgress    = ref([]); // [{ name, progress: 0-100, done: bool }]
const overallProgress = computed(() => {
    if (!fileProgress.value.length) return 0;
    return Math.round(fileProgress.value.reduce((sum, f) => sum + f.progress, 0) / fileProgress.value.length);
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

    if (!form.title && files.length === 1) {
        form.title = files[0].name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ');
    }
}

function removeFile(index) {
    selectedFiles.value.splice(index, 1);
    filePreviews.value.splice(index, 1);
}

function formatBytes(bytes) {
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function uploadChunks(file, uploadId, onProgress) {
    const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
    let result = null;

    for (let i = 0; i < totalChunks; i++) {
        const start = i * CHUNK_SIZE;
        const chunk = file.slice(start, start + CHUNK_SIZE);

        const fd = new FormData();
        fd.append('upload_id',    uploadId);
        fd.append('chunk_index',  i);
        fd.append('total_chunks', totalChunks);
        fd.append('filename',     file.name);
        fd.append('mime_type',    file.type);
        fd.append('chunk',        chunk, `chunk_${i}`);

        const { data } = await window.axios.post(route('uploads.chunk'), fd, {
            headers: { 'X-CSRF-TOKEN': csrfToken() },
        });

        onProgress(Math.round(((i + 1) / totalChunks) * 100));

        if (data.status === 'complete') {
            result = data;
        }
    }

    return result;
}

async function submitUpload() {
    if (!selectedFiles.value.length || !form.title) return;

    isUploading.value  = true;
    uploadError.value  = null;
    fileProgress.value = selectedFiles.value.map(f => ({ name: f.name, progress: 0, done: false }));

    const completedUploads = [];

    try {
        for (let fi = 0; fi < selectedFiles.value.length; fi++) {
            const file     = selectedFiles.value[fi];
            const uploadId = crypto.randomUUID();

            const assembled = await uploadChunks(file, uploadId, (pct) => {
                fileProgress.value[fi].progress = pct;
            });

            fileProgress.value[fi].done = true;
            completedUploads.push(assembled);
        }

        const payload = {
            uploads:          completedUploads,
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

        await window.axios.post(route('uploads.finalize'), payload, {
            headers: { 'X-CSRF-TOKEN': csrfToken() },
        });

        router.visit(route('admin.slides.index'), {
            onSuccess: () => {
                form.reset();
                selectedFiles.value  = [];
                filePreviews.value   = [];
                fileProgress.value   = [];
                addToShow.value      = 'main';
                targetShowId.value   = '';
                newShowName.value    = '';
                showUploadPanel.value = false;
            },
        });
    } catch (err) {
        uploadError.value = err.response?.data?.message ?? 'Upload failed. Please try again.';
    } finally {
        isUploading.value = false;
    }
}

// ── Slide actions ─────────────────────────────────────────────────────────────

function approve(slide) {
    router.post(route('admin.slides.approve', slide.id));
}

function reject(slide) {
    if (confirm(`Reject "${slide.title}"?`)) {
        router.post(route('admin.slides.reject', slide.id));
    }
}

function archive(slide) {
    if (confirm(`Archive "${slide.title}"? It will move to the archived tab.`)) {
        router.post(route('admin.slides.archive', slide.id));
    }
}

function unarchive(slide) {
    if (confirm(`Restore "${slide.title}"? It will be active again.`)) {
        router.post(route('admin.slides.unarchive', slide.id));
    }
}

function destroy(slide) {
    if (confirm(`Delete "${slide.title}"? This cannot be undone.`)) {
        router.delete(route('admin.slides.destroy', slide.id));
    }
}

// ── Active tab ────────────────────────────────────────────────────────────────

const tabs = computed(() => [
    { key: 'current',  label: 'live',     count: props.current.length },
    { key: 'pending',  label: 'pending',  count: props.pending.length },
    { key: 'upcoming', label: 'upcoming', count: props.upcoming.length },
    { key: 'drafts',   label: 'drafts',   count: props.drafts.length },
    { key: 'archived', label: 'archived', count: props.archived.length },
]);

const activeTab = ref('current');

// ── Drag-and-drop reordering (live tab only) ────────────────────────────────────
// Only the "current" tab is ordered by sort_order; the other tabs sort by date,
// so reordering is scoped to it.
const orderedCurrent = ref([...props.current]);
const draggedSlide   = ref(null);
const dragOverSlide  = ref(null);
const dropPosition   = ref(null); // 'before' | 'after'

const isReorderable = computed(() => activeTab.value === 'current');
const activeSlides  = computed(() =>
    activeTab.value === 'current' ? orderedCurrent.value : props[activeTab.value]
);

// Keep the local ordered copy in sync when the server data changes.
watch(() => props.current, (val) => { orderedCurrent.value = [...val]; });

function handleDragStart(e, slide) {
    draggedSlide.value = slide;
    e.dataTransfer.effectAllowed = 'move';
}

function handleDragOver(e, slide) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';

    // Don't show an indicator on the row being dragged.
    if (!draggedSlide.value || draggedSlide.value.id === slide.id) {
        dragOverSlide.value = null;
        return;
    }

    // Decide before/after from the cursor's position within the row so the
    // indicator edge matches exactly where the slide will be inserted.
    const rect = e.currentTarget.getBoundingClientRect();
    const midpoint = rect.top + rect.height / 2;
    dropPosition.value = e.clientY < midpoint ? 'before' : 'after';
    dragOverSlide.value = slide;
}

function handleTableDragLeave(e) {
    if (e.target.tagName === 'TBODY') {
        dragOverSlide.value = null;
        dropPosition.value = null;
    }
}

function handleDrop(e, targetSlide) {
    e.preventDefault();
    const dragged = draggedSlide.value;
    const position = dropPosition.value;
    if (!dragged || dragged.id === targetSlide.id) {
        return handleDragEnd();
    }

    const list = [...orderedCurrent.value];
    const fromIndex = list.findIndex(s => s.id === dragged.id);
    if (fromIndex === -1 || !list.some(s => s.id === targetSlide.id)) {
        return handleDragEnd();
    }

    // Remove the dragged slide first, then locate the target so the insertion
    // index already accounts for the shift caused by removal.
    list.splice(fromIndex, 1);
    let insertIndex = list.findIndex(s => s.id === targetSlide.id);
    if (position === 'after') insertIndex += 1;
    list.splice(insertIndex, 0, dragged);

    orderedCurrent.value = list;
    updateSortOrder();
    handleDragEnd();
}

function handleDragEnd() {
    draggedSlide.value = null;
    dragOverSlide.value = null;
    dropPosition.value = null;
}

function updateSortOrder() {
    const order = orderedCurrent.value.map(s => s.id);
    fetch(route('admin.slides.reorder'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ order }),
    }).catch(err => console.error('Failed to update sort order:', err));
}

function displayStatus(slide) {
    // Archived slides keep their DB status (usually "published"); show the
    // archived state explicitly while on that tab.
    return activeTab.value === 'archived' ? 'archived' : slide.status;
}

function statusBadge(status) {
    const map = {
        published: 'bg-green-100 text-green-800',
        pending:   'bg-yellow-100 text-yellow-800',
        draft:     'bg-gray-100 text-gray-700',
        rejected:  'bg-red-100 text-red-700',
        archived:  'bg-slate-100 text-slate-700',
    };
    return map[status] ?? 'bg-gray-100 text-gray-700';
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between w-full">
                <h1 class="text-xl font-semibold text-gray-900">{{ $t('nav.slides') }}</h1>
                <button @click="showUploadPanel = !showUploadPanel"
                    class="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    {{ $t('admin.upload_slides') }}
                </button>
            </div>
        </template>

        <!-- Upload Panel -->
        <div v-if="showUploadPanel" class="mb-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
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
                            <button v-if="!isUploading" type="button" @click="removeFile(i)" class="text-gray-400 hover:text-red-500 transition-colors flex-shrink-0">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <!-- Validation warnings -->
                        <div v-if="fileValidations[i]" class="mt-2">
                            <ValidationWarnings :issues="fileValidations[i].issues" />
                        </div>
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('admin.caption_title') }} <span class="text-red-500">*</span></label>
                        <input v-model="form.title" type="text" required
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
                        <input v-model="form.publish_at" type="datetime-local"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <p class="mt-1 text-xs text-gray-500">{{ $t('upload.publish_hint') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('admin.expiration_date') }} <span class="text-gray-400 font-normal">{{ $t('admin.optional') }}</span></label>
                        <input v-model="form.expires_at" type="datetime-local"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
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

                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700">Add to show</label>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="addToShow" type="radio" value="main"
                            class="border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        Add to every site's Main Show (default)
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="addToShow" type="radio" value="separate"
                            class="border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        Distribute as a separate one-off show (e.g. a special promo video)
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
                    <button type="submit" :disabled="isUploading || !selectedFiles.length || !form.title"
                        class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        {{ isUploading ? `${$t('admin.uploading')} ${overallProgress}%` : $t('upload.btn_upload', { n: selectedFiles.length }) }}
                    </button>
                    <button type="button" :disabled="isUploading" @click="showUploadPanel = false"
                        class="rounded-lg border border-gray-300 px-5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        {{ $t('admin.cancel') }}
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabs -->
        <div class="border-b border-gray-200 mb-6">
            <nav class="-mb-px flex gap-6">
                <button v-for="tab in tabs" :key="tab.key"
                    @click="activeTab = tab.key"
                    class="pb-3 text-sm font-medium border-b-2 transition-colors"
                    :class="activeTab === tab.key
                        ? 'border-indigo-600 text-indigo-600'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'">
                    {{ $t(`admin.tab_${tab.label}`) }}
                    <span class="ml-1.5 rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="tab.count > 0 ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500'">
                        {{ tab.count }}
                    </span>
                </button>
            </nav>
        </div>

        <!-- Slide list table -->
        <div v-if="activeSlides.length" class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th v-if="isReorderable" class="px-4 py-3 text-left font-medium text-gray-500 w-8"></th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('admin.col_slide') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">{{ $t('admin.status') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('admin.col_dates') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">{{ $t('admin.col_uploaded_by') }}</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">{{ $t('admin.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100" @dragleave="handleTableDragLeave" @dragend="handleDragEnd">
                    <tr v-for="slide in activeSlides" :key="slide.id"
                        class="hover:bg-gray-50 transition-colors"
                        :class="{ 'opacity-50': draggedSlide?.id === slide.id }"
                        :style="isReorderable && dragOverSlide?.id === slide.id ? {
                            boxShadow: dropPosition === 'before'
                                ? 'inset 0 3px 0 0 rgb(99, 102, 241)'
                                : 'inset 0 -3px 0 0 rgb(99, 102, 241)',
                            backgroundColor: 'rgb(239, 246, 255)'
                        } : {}"
                        :draggable="isReorderable"
                        @dragstart="handleDragStart($event, slide)"
                        @dragover="handleDragOver($event, slide)"
                        @drop="handleDrop($event, slide)">
                        <td v-if="isReorderable" class="px-3 py-3 text-center cursor-grab active:cursor-grabbing">
                            <svg class="h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M9 3h2v2H9V3zm0 4h2v2H9V7zm0 4h2v2H9v-2zm4-8h2v2h-2V3zm0 4h2v2h-2V7zm0 4h2v2h-2v-2zm4-8h2v2h-2V3zm0 4h2v2h-2V7zm0 4h2v2h-2v-2z" />
                            </svg>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-12 w-20 flex-shrink-0 rounded overflow-hidden bg-slate-100">
                                    <img v-if="slide.thumbnail_url || (slide.file_url && !slide.mime_type?.startsWith('video/'))"
                                        :src="slide.thumbnail_url || slide.file_url"
                                        class="h-full w-full object-cover" />
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <p class="font-medium text-gray-900 line-clamp-1">{{ slide.title }}</p>
                                        <svg v-if="slide.validation_issues?.length" class="h-4 w-4 text-yellow-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" title="Image quality warnings detected">
                                            <path fill-rule="evenodd"
                                                d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                                clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <p v-if="slide.entity" class="text-xs text-indigo-600 font-medium mt-0.5">{{ slide.entity.name }}</p>
                                    <p v-if="slide.validation_issues?.length" class="text-xs text-yellow-700 mt-0.5">
                                        <span v-for="(issue, idx) in slide.validation_issues" :key="idx" class="block">• {{ issue }}</span>
                                    </p>
                                    <p v-if="slide.notes" class="text-xs text-gray-400 line-clamp-1 mt-0.5">{{ slide.notes }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold capitalize"
                                :class="statusBadge(displayStatus(slide))">
                                {{ displayStatus(slide) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500 space-y-0.5">
                            <div v-if="slide.publish_at">{{ $t('admin.from') }} {{ new Date(slide.publish_at).toLocaleDateString() }}</div>
                            <div v-if="slide.expires_at">{{ $t('admin.expiration') }} {{ new Date(slide.expires_at).toLocaleDateString() }}</div>
                            <div v-if="!slide.publish_at && !slide.expires_at" class="text-gray-300">{{ $t('admin.always_on') }}</div>
                        </td>
                        <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-500">
                            {{ slide.uploader?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <template v-if="slide.status === 'pending'">
                                    <button @click="approve(slide)"
                                        class="rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700 transition-colors">
                                        {{ $t('admin.approve') }}
                                    </button>
                                    <button @click="reject(slide)"
                                        class="rounded-md border border-red-300 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50 transition-colors">
                                        {{ $t('admin.reject') }}
                                    </button>
                                </template>
                                <Link :href="route('admin.slides.edit', slide.id)"
                                    class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                    {{ $t('admin.edit') }}
                                </Link>
                                <button v-if="activeTab === 'archived'" @click="unarchive(slide)"
                                    class="rounded-md border border-blue-200 px-2.5 py-1 text-xs font-medium text-blue-700 hover:bg-blue-50 transition-colors">
                                    {{ $t('admin.restore') }}
                                </button>
                                <button v-if="activeTab === 'archived'" @click="destroy(slide)"
                                    class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition-colors">
                                    {{ $t('admin.delete') }}
                                </button>
                                <button v-else @click="archive(slide)"
                                    class="rounded-md border border-amber-200 px-2.5 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 transition-colors">
                                    {{ $t('admin.archive') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="mt-8 rounded-xl border-2 border-dashed border-gray-200 py-16 text-center">
            <p class="text-gray-400">{{ $t('admin.no_slides_in_category') }}</p>
        </div>
    </AdminLayout>
</template>
