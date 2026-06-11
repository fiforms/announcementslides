<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DropZone from '@/Components/DropZone.vue';
import { useChunkedUpload } from '@/Composables/useChunkedUpload.js';

const { isUploading, uploadError, fileProgress, overallProgress, upload } = useChunkedUpload();

const selectedFiles = ref([]);
const filePreviews  = ref([]);
const title         = ref('');
const notes         = ref('');
const submitted     = ref(false);

function onFilesSelected(files) {
    selectedFiles.value = files;
    filePreviews.value  = files.map(f => ({
        name: f.name,
        size: f.size,
        url:  f.type.startsWith('image/') ? URL.createObjectURL(f) : null,
    }));
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
    if (!selectedFiles.value.length || !title.value.trim()) return;

    const result = await upload(selectedFiles.value, {
        title: title.value,
        notes: notes.value,
    });

    if (result) {
        submitted.value = true;
        selectedFiles.value = [];
        filePreviews.value  = [];
        title.value         = '';
        notes.value         = '';
    }
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">Submit a Slide</h1>
        </template>

        <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Success state -->
            <div v-if="submitted" class="rounded-xl border border-green-200 bg-green-50 p-8 text-center">
                <svg class="mx-auto h-12 w-12 text-green-500 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h2 class="text-lg font-semibold text-green-800 mb-1">Slide Submitted!</h2>
                <p class="text-sm text-green-700 mb-4">
                    Your slide has been sent to administrators for review. Once approved, it will appear in the public slide feed.
                </p>
                <button @click="submitted = false"
                    class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 transition-colors">
                    Submit Another
                </button>
            </div>

            <div v-else class="space-y-5">
                <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                    Your slide will be reviewed by an administrator before it becomes publicly visible.
                </div>

                <form @submit.prevent="submit" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
                    <DropZone @files-selected="onFilesSelected" :multiple="false" />

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
                                <button v-if="!isUploading" type="button" @click="removeFile(i)"
                                    class="text-gray-400 hover:text-red-500 transition-colors flex-shrink-0">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
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

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
                        <input v-model="title" type="text" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Give your slide a descriptive title" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes for administrators <span class="text-gray-400 font-normal">(optional)</span></label>
                        <textarea v-model="notes" rows="3"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Explain what this slide is for, or when it should run…" />
                    </div>

                    <div v-if="uploadError" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                        {{ uploadError }}
                    </div>

                    <button type="submit"
                        :disabled="isUploading || !selectedFiles.length || !title.trim()"
                        class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        {{ isUploading ? `Uploading… ${overallProgress}%` : 'Submit for Review' }}
                    </button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
