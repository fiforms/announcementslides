<script setup>
import { ref } from 'vue';
import { isWebUrl, scanSlideMedia } from '@/Composables/useQrScan.js';

const props = defineProps({
    media: { type: Array, default: () => [] },
});
const emit = defineEmits(['pick']);

const scanning = ref(false);
const urls = ref(null); // null until scanned
const total = ref(0);
const error = ref(null);

async function scan() {
    scanning.value = true;
    error.value = null;
    try {
        const { all } = await scanSlideMedia(props.media);
        total.value = all.length;
        urls.value = all.filter(isWebUrl);
        if (urls.value.length === 1) emit('pick', urls.value[0]);
    } catch {
        error.value = 'Could not read the slide files to scan them.';
    } finally {
        scanning.value = false;
    }
}
</script>

<template>
    <div class="text-right">
        <button type="button" @click="scan" :disabled="scanning"
            class="text-xs font-medium text-indigo-600 hover:text-indigo-800 disabled:text-gray-400">
            {{ scanning ? 'Scanning…' : 'Scan for QR code' }}
        </button>
        <div v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</div>
        <div v-else-if="urls && !urls.length" class="mt-1 text-xs text-gray-500">
            {{ total ? 'No web link in the QR code found.' : 'No QR code found on the slide or its overlay.' }}
        </div>
        <div v-else-if="urls?.length > 1" class="mt-1 text-xs text-left">
            <p class="text-amber-700">{{ urls.length }} QR codes found — pick one:</p>
            <button v-for="u in urls" :key="u" type="button" @click="emit('pick', u)"
                class="block max-w-full truncate text-indigo-600 hover:underline">{{ u }}</button>
        </div>
    </div>
</template>
