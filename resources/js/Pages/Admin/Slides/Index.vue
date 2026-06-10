<script setup>
import { ref, computed } from 'vue';
import { useForm, router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SlideCard from '@/Components/SlideCard.vue';
import DropZone from '@/Components/DropZone.vue';

const props = defineProps({
    current:  { type: Array, default: () => [] },
    pending:  { type: Array, default: () => [] },
    upcoming: { type: Array, default: () => [] },
    archived: { type: Array, default: () => [] },
    drafts:   { type: Array, default: () => [] },
});

// ── Upload form ────────────────────────────────────────────────────────────────

const showUploadPanel = ref(false);
const selectedFiles   = ref([]);
const filePreviews    = ref([]);

const form = useForm({
    files:      [],
    title:      '',
    notes:      '',
    publish_at: '',
    expires_at: '',
    status:     'published',
});

function onFilesSelected(files) {
    selectedFiles.value = files;
    form.files = files;

    filePreviews.value = files.map(f => ({
        name: f.name,
        url: f.type.startsWith('image/') ? URL.createObjectURL(f) : null,
        type: f.type,
    }));

    if (!form.title && files.length === 1) {
        form.title = files[0].name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ');
    }
}

function removeFile(index) {
    selectedFiles.value.splice(index, 1);
    filePreviews.value.splice(index, 1);
    form.files = [...selectedFiles.value];
}

function submitUpload() {
    form.post(route('admin.slides.store'), {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            selectedFiles.value = [];
            filePreviews.value  = [];
            showUploadPanel.value = false;
        },
    });
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

function destroy(slide) {
    if (confirm(`Delete "${slide.title}"? This cannot be undone.`)) {
        router.delete(route('admin.slides.destroy', slide.id));
    }
}

// ── Active tab ────────────────────────────────────────────────────────────────

const tabs = computed(() => [
    { key: 'current',  label: 'Live',     count: props.current.length },
    { key: 'pending',  label: 'Pending',  count: props.pending.length },
    { key: 'upcoming', label: 'Upcoming', count: props.upcoming.length },
    { key: 'drafts',   label: 'Drafts',   count: props.drafts.length },
    { key: 'archived', label: 'Recent Archived', count: props.archived.length },
]);

const activeTab = ref('current');
const activeSlides = computed(() => props[activeTab.value]);

function statusBadge(status) {
    const map = {
        published: 'bg-green-100 text-green-800',
        pending:   'bg-yellow-100 text-yellow-800',
        draft:     'bg-gray-100 text-gray-700',
        rejected:  'bg-red-100 text-red-700',
    };
    return map[status] ?? 'bg-gray-100 text-gray-700';
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between w-full">
                <h1 class="text-xl font-semibold text-gray-900">Slides</h1>
                <button @click="showUploadPanel = !showUploadPanel"
                    class="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Upload Slides
                </button>
            </div>
        </template>

        <!-- Upload Panel -->
        <div v-if="showUploadPanel" class="mb-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-base font-semibold text-gray-900">Upload New Slides</h2>

            <form @submit.prevent="submitUpload" class="space-y-5">
                <DropZone @files-selected="onFilesSelected" />

                <!-- File preview list -->
                <ul v-if="filePreviews.length" class="space-y-2">
                    <li v-for="(f, i) in filePreviews" :key="i"
                        class="flex items-center gap-3 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                        <img v-if="f.url" :src="f.url" class="h-10 w-16 rounded object-cover" />
                        <svg v-else class="h-10 w-10 text-gray-300 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M15 10l4.553-2.069A1 1 0 0121 8.876V15.5a1 1 0 01-1.447.894L15 14M3 8.5A1.5 1.5 0 014.5 7h8A1.5 1.5 0 0114 8.5v7a1.5 1.5 0 01-1.5 1.5h-8A1.5 1.5 0 013 15.5v-7z" />
                        </svg>
                        <span class="flex-1 text-sm text-gray-700 truncate">{{ f.name }}</span>
                        <button type="button" @click="removeFile(i)" class="text-gray-400 hover:text-red-500 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </li>
                </ul>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Caption / Title <span class="text-red-500">*</span></label>
                        <input v-model="form.title" type="text" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="e.g. Camp Meeting 2026" />
                        <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes <span class="text-gray-400 font-normal">(internal)</span></label>
                        <textarea v-model="form.notes" rows="2"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Optional notes for your team…" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Publish Date <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input v-model="form.publish_at" type="datetime-local"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <p class="mt-1 text-xs text-gray-500">Don't show before this date/time</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Expiration Date <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input v-model="form.expires_at" type="datetime-local"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <p class="mt-1 text-xs text-gray-500">Hide after this date/time</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select v-model="form.status"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="published">Published (live immediately)</option>
                            <option value="draft">Draft (not visible)</option>
                        </select>
                    </div>
                </div>

                <div v-if="form.errors.files" class="text-sm text-red-600">{{ form.errors.files }}</div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" :disabled="form.processing || !selectedFiles.length"
                        class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        {{ form.processing ? 'Uploading…' : `Upload ${selectedFiles.length} file${selectedFiles.length === 1 ? '' : 's'}` }}
                    </button>
                    <button type="button" @click="showUploadPanel = false"
                        class="rounded-lg border border-gray-300 px-5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                        Cancel
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
                    {{ tab.label }}
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
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Slide</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">Dates</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">Uploaded by</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="slide in activeSlides" :key="slide.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-12 w-20 flex-shrink-0 rounded overflow-hidden bg-slate-100">
                                    <img v-if="slide.thumbnail_url || slide.file_url"
                                        :src="slide.thumbnail_url || slide.file_url"
                                        class="h-full w-full object-cover" />
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900 line-clamp-1">{{ slide.title }}</p>
                                    <p v-if="slide.notes" class="text-xs text-gray-400 line-clamp-1 mt-0.5">{{ slide.notes }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold capitalize"
                                :class="statusBadge(slide.status)">
                                {{ slide.status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500 space-y-0.5">
                            <div v-if="slide.publish_at">From: {{ new Date(slide.publish_at).toLocaleDateString() }}</div>
                            <div v-if="slide.expires_at">Exp: {{ new Date(slide.expires_at).toLocaleDateString() }}</div>
                            <div v-if="!slide.publish_at && !slide.expires_at" class="text-gray-300">Always on</div>
                        </td>
                        <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-500">
                            {{ slide.uploader?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <template v-if="slide.status === 'pending'">
                                    <button @click="approve(slide)"
                                        class="rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700 transition-colors">
                                        Approve
                                    </button>
                                    <button @click="reject(slide)"
                                        class="rounded-md border border-red-300 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50 transition-colors">
                                        Reject
                                    </button>
                                </template>
                                <Link :href="route('admin.slides.edit', slide.id)"
                                    class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                    Edit
                                </Link>
                                <button @click="destroy(slide)"
                                    class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition-colors">
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="mt-8 rounded-xl border-2 border-dashed border-gray-200 py-16 text-center">
            <p class="text-gray-400">No slides in this category.</p>
        </div>
    </AdminLayout>
</template>
