<script setup>
import { ref } from 'vue';

const props = defineProps({
    accept: { type: String, default: 'image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm' },
    multiple: { type: Boolean, default: true },
});

const emit = defineEmits(['files-selected']);

const isDragging = ref(false);
const fileInput  = ref(null);

function onDrop(e) {
    isDragging.value = false;
    const files = Array.from(e.dataTransfer.files);
    if (files.length) emit('files-selected', files);
}

function onFileInput(e) {
    const files = Array.from(e.target.files);
    if (files.length) emit('files-selected', files);
    // Reset so same file can be re-selected
    e.target.value = '';
}

function openPicker() {
    fileInput.value.click();
}
</script>

<template>
    <div
        class="relative flex flex-col items-center justify-center rounded-2xl border-2 border-dashed p-10 text-center transition-colors cursor-pointer"
        :class="isDragging ? 'border-indigo-500 bg-indigo-50' : 'border-gray-300 bg-gray-50 hover:border-indigo-400 hover:bg-indigo-50/50'"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="onDrop"
        @click="openPicker"
    >
        <svg class="h-12 w-12 text-gray-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
        </svg>
        <p class="text-sm font-medium text-gray-700">Drag &amp; drop slides here</p>
        <p class="mt-1 text-xs text-gray-500">or click to browse &mdash; JPEG, PNG, WebP, MP4</p>

        <input
            ref="fileInput"
            type="file"
            class="sr-only"
            :accept="accept"
            :multiple="multiple"
            @change="onFileInput"
        />
    </div>
</template>
