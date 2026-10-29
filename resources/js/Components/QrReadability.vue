<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ValidationWarnings from '@/Components/ValidationWarnings.vue';
import { checkSlideReadability } from '@/Composables/useQrScan.js';

// Checks that the QR code on a slide (with its overlay burned in) is big and
// contrasty enough to scan from the audience. Runs on its own when the slide
// is opened and again whenever its image or overlay change.
const props = defineProps({
    media: { type: Array, default: () => [] },
});

const checking = ref(false);
const result = ref(null);

const signature = computed(() => JSON.stringify(
    props.media
        .filter(m => ['slide', 'slide-overlay'].includes(m.media_type))
        .map(m => [m.id, m.media_type, m.file_url, m.file_size, m.active_variant]),
));

let run = 0;
async function check() {
    const mine = ++run;
    checking.value = true;
    try {
        const outcome = await checkSlideReadability(props.media);
        if (mine === run) result.value = outcome;
    } catch {
        if (mine === run) result.value = null;
    } finally {
        if (mine === run) checking.value = false;
    }
}

onMounted(check);
watch(signature, check);
</script>

<template>
    <div v-if="checking" class="mt-1 text-xs text-gray-500">Checking QR code readability…</div>
    <template v-else-if="result">
        <p v-if="result.unreadable.length" class="mt-2 text-sm font-bold text-red-600">
            Error: the overlay contains {{ result.unreadable.length === 1 ? 'a QR code' : `${result.unreadable.length} QR codes` }}
            that can't be read on the slide as shown (<span class="break-all">{{ result.unreadable.join(', ') }}</span>).
            Check its size, contrast and the picture behind it.
        </p>
        <p v-else-if="!result.codes.length" class="mt-1 text-xs text-gray-500">No QR Code Detected</p>
        <div v-if="result.issues.length" class="mt-2">
            <ValidationWarnings :issues="result.issues" title="QR code may be hard to scan" />
        </div>
        <p v-else-if="result.codes.length && !result.unreadable.length" class="mt-1 text-xs text-green-700">
            QR code looks readable on the slide (including its overlay).
        </p>
    </template>
</template>
