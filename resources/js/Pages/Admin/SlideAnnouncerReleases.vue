<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Modal.vue';
import { useChunkedUpload } from '@/Composables/useChunkedUpload.js';

const props = defineProps({
    releases: { type: Array, default: () => [] },
});

const { isUploading, uploadError, overallProgress, upload } = useChunkedUpload({
    chunkRoute: 'admin.slide-announcer-releases.chunk',
    finalizeRoute: 'admin.slide-announcer-releases.finalize',
});

const selectedFile = ref(null);
const kind = ref('os');
const version = ref('');
const channel = ref('stable');
const notes = ref('');
const activateNow = ref(false);
const finalizeError = ref(null);
const releases = ref([...props.releases]);

// Inertia re-renders this page with fresh props after any router.post/delete
// (activate/destroy below), but releases was only ever seeded from props
// once — without this, the table stays stale until a manual reload.
watch(() => props.releases, (updated) => {
    releases.value = [...updated];
});

const acceptedExtension = computed(() => (kind.value === 'os' ? '.raucb' : '.tar.gz'));
const canSubmit = computed(() => selectedFile.value && version.value.trim());

function onFileSelected(event) {
    selectedFile.value = event.target.files[0] ?? null;
}

function formatBytes(bytes) {
    if (bytes == null) return '—';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
}

async function submit() {
    finalizeError.value = null;

    const result = await upload([selectedFile.value], {
        kind: kind.value,
        version: version.value.trim(),
        channel: channel.value,
        notes: notes.value || null,
        activate: activateNow.value,
    });

    if (!result) {
        // uploadError is already set by the composable
        return;
    }
    if (!result.success) {
        finalizeError.value = result.message ?? 'Publish failed.';
        return;
    }

    releases.value.unshift(result.release);
    selectedFile.value = null;
    version.value = '';
    notes.value = '';
    activateNow.value = false;
}

function activate(release) {
    if (confirm(`Activate ${release.kind} ${release.version} on ${release.channel}? This deactivates any other active ${release.kind} release on that channel.`)) {
        router.post(route('admin.slide-announcer-releases.activate', { slideAnnouncerRelease: release.id }));
    }
}

function destroy(release) {
    if (confirm(`Delete ${release.kind} ${release.version} (${release.channel})? This cannot be undone.`)) {
        router.delete(route('admin.slide-announcer-releases.destroy', { slideAnnouncerRelease: release.id }), {
            onSuccess: () => { detailsId.value = null; },
        });
    }
}

// ── Details lightbox ────────────────────────────────────────────────────

const detailsId = ref(null);
const copied = ref(null); // 'url' | 'sha256' | null
const detailsRelease = computed(() => releases.value.find(r => r.id === detailsId.value) ?? null);

function openDetails(release) {
    detailsId.value = release.id;
    copied.value = null;
}

function closeDetails() {
    detailsId.value = null;
}

async function copyToClipboard(text, which) {
    await navigator.clipboard.writeText(text);
    copied.value = which;
    setTimeout(() => { if (copied.value === which) copied.value = null; }, 1500);
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">Slide Announcer Releases</h1>
        </template>

        <div class="mx-auto max-w-5xl px-8 py-8 space-y-8">
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                <h2 class="text-lg font-semibold text-gray-900">Publish a release</h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <label class="block">
                            <span class="text-sm font-medium text-gray-700">Kind</span>
                            <select v-model="kind" :disabled="isUploading"
                                class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                                <option value="os">OS bundle (RAUC .raucb)</option>
                                <option value="app">Local-app archive (.tar.gz)</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-gray-700">Channel</span>
                            <select v-model="channel" :disabled="isUploading"
                                class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                                <option value="developer">Developer</option>
                                <option value="testing">Testing</option>
                                <option value="stable">Stable</option>
                            </select>
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Version</span>
                        <input type="text" v-model="version" :disabled="isUploading" placeholder="e.g. 2026.08.1 or 0.2.0"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">File ({{ acceptedExtension }})</span>
                        <input type="file" :accept="acceptedExtension" :disabled="isUploading" @change="onFileSelected"
                            class="mt-1 block w-full text-sm text-gray-700">
                        <span v-if="selectedFile" class="text-xs text-gray-500">
                            {{ selectedFile.name }} ({{ formatBytes(selectedFile.size) }})
                        </span>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Notes</span>
                        <textarea v-model="notes" :disabled="isUploading" rows="2"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm"></textarea>
                    </label>

                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" v-model="activateNow" :disabled="isUploading">
                        Activate immediately on this channel
                    </label>

                    <div v-if="isUploading" class="rounded-lg bg-indigo-50 px-4 py-3">
                        <div class="flex justify-between text-xs text-indigo-700 mb-1">
                            <span>Uploading…</span>
                            <span>{{ overallProgress }}%</span>
                        </div>
                        <div class="h-2 rounded-full bg-indigo-100 overflow-hidden">
                            <div class="h-full bg-indigo-500 transition-all" :style="{ width: overallProgress + '%' }" />
                        </div>
                    </div>

                    <div v-if="uploadError || finalizeError" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                        {{ uploadError || finalizeError }}
                    </div>

                    <button type="submit" :disabled="isUploading || !canSubmit"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                        {{ isUploading ? `Uploading… ${overallProgress}%` : 'Publish Release' }}
                    </button>
                </form>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Kind</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Version</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Channel</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">Size</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">Published</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="release in releases" :key="release.id" class="hover:bg-gray-50 cursor-pointer"
                            @click="openDetails(release)">
                            <td class="px-4 py-3">{{ release.kind }}</td>
                            <td class="px-4 py-3 font-mono">{{ release.version }}</td>
                            <td class="px-4 py-3">{{ release.channel }}</td>
                            <td class="px-4 py-3">
                                <span :class="release.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                    class="rounded-full px-2 py-0.5 text-xs font-medium">
                                    {{ release.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ formatBytes(release.file_size) }}</td>
                            <td class="px-4 py-3 hidden lg:table-cell text-gray-600">
                                {{ new Date(release.created_at).toLocaleString() }}
                            </td>
                            <td class="px-4 py-3 text-right space-x-3" @click.stop>
                                <button v-if="!release.is_active" @click="activate(release)"
                                    class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                                    Activate
                                </button>
                                <button v-if="!release.is_active" @click="destroy(release)"
                                    class="text-red-600 hover:text-red-800 text-sm font-medium">
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!releases.length">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">No releases published yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal :show="!!detailsRelease" max-width="lg" @close="closeDetails">
            <div v-if="detailsRelease" class="p-6 space-y-5">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">
                            {{ detailsRelease.kind }} {{ detailsRelease.version }}
                        </h3>
                        <p class="text-sm text-gray-500">{{ detailsRelease.channel }} channel</p>
                    </div>
                    <span :class="detailsRelease.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                        class="rounded-full px-2 py-0.5 text-xs font-medium">
                        {{ detailsRelease.is_active ? 'Active' : 'Inactive' }}
                    </span>
                </div>

                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-gray-500">Size</dt>
                        <dd class="text-gray-900">{{ formatBytes(detailsRelease.file_size) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Published</dt>
                        <dd class="text-gray-900">{{ new Date(detailsRelease.created_at).toLocaleString() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Activated</dt>
                        <dd class="text-gray-900">
                            {{ detailsRelease.released_at ? new Date(detailsRelease.released_at).toLocaleString() : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Published by</dt>
                        <dd class="text-gray-900">{{ detailsRelease.creator?.name ?? '—' }}</dd>
                    </div>
                    <div class="col-span-2" v-if="detailsRelease.notes">
                        <dt class="text-gray-500">Notes</dt>
                        <dd class="text-gray-900 whitespace-pre-wrap">{{ detailsRelease.notes }}</dd>
                    </div>
                </dl>

                <div>
                    <dt class="text-sm text-gray-500 mb-1">SHA-256</dt>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 rounded-lg bg-gray-50 border border-gray-200 px-3 py-2 text-xs text-gray-700 break-all">
                            {{ detailsRelease.sha256 }}
                        </code>
                        <button @click="copyToClipboard(detailsRelease.sha256, 'sha256')"
                            class="rounded-lg border border-gray-300 p-2 text-gray-500 hover:bg-gray-50 flex-shrink-0"
                            title="Copy SHA-256">
                            <svg v-if="copied === 'sha256'" class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div>
                    <dt class="text-sm text-gray-500 mb-1">Direct download link</dt>
                    <div class="flex items-center gap-2">
                        <input type="text" readonly :value="detailsRelease.url"
                            class="flex-1 rounded-lg bg-gray-50 border border-gray-200 px-3 py-2 text-xs text-gray-700"
                            @click="$event.target.select()">
                        <button @click="copyToClipboard(detailsRelease.url, 'url')"
                            class="rounded-lg border border-gray-300 p-2 text-gray-500 hover:bg-gray-50 flex-shrink-0"
                            title="Copy link">
                            <svg v-if="copied === 'url'" class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                        <a :href="detailsRelease.url" download
                            class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-700 flex-shrink-0">
                            Download
                        </a>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-gray-100 pt-4">
                    <div class="space-x-3">
                        <button v-if="!detailsRelease.is_active" @click="activate(detailsRelease)"
                            class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                            Activate
                        </button>
                        <button v-if="!detailsRelease.is_active" @click="destroy(detailsRelease)"
                            class="text-red-600 hover:text-red-800 text-sm font-medium">
                            Delete
                        </button>
                    </div>
                    <button @click="closeDetails" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Close
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
