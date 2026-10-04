<script setup>
import Modal from '@/Components/Modal.vue';

defineProps({ show: { type: Boolean, default: false } });
defineEmits(['close']);
</script>

<template>
    <Modal :show="show" max-width="md" @close="$emit('close')">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900">{{ $t('slides.revelation_info_title') }}</h2>
            <p class="mt-2 text-sm text-gray-600">{{ $t('slides.revelation_info_body') }}</p>
            <div class="mt-4 flex flex-col gap-1 text-sm">
                <a href="https://snapshots.vrbm.org/revelation-snapshot-presenter/" target="_blank" rel="noopener"
                    class="text-indigo-600 underline hover:text-indigo-800">{{ $t('slides.revelation_info_about') }}</a>
                <a href="https://github.com/fiforms/revelation-electron-wrapper/releases" target="_blank" rel="noopener"
                    class="text-indigo-600 underline hover:text-indigo-800">{{ $t('slides.revelation_info_releases') }}</a>
            </div>
            <div class="mt-6 flex justify-end">
                <button @click="$emit('close')"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors">
                    {{ $t('slides.revelation_info_close') }}
                </button>
            </div>
        </div>
    </Modal>
</template>
