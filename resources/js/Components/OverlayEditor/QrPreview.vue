<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { qrSvgDocument } from '@/Composables/overlay/qr.js';

// Shows a rendered QR code (QrDesigner's v-model:rendered) exactly as it
// will be saved or downloaded, over a checkerboard so a transparent
// background is visible.
const props = defineProps({
    rendered: { type: Object, default: null },
    options: { type: Object, required: true },
    failed: { type: Boolean, default: false },
});
const { t } = useI18n();

const svg = computed(() => props.rendered ? qrSvgDocument(props.rendered, props.options) : '');
</script>

<template>
    <div class="flex aspect-square items-center justify-center rounded-lg bg-[repeating-conic-gradient(#e5e7eb_0%_25%,#fff_0%_50%)] bg-[length:16px_16px] p-2">
        <div v-if="rendered" class="h-full w-full [&>svg]:h-full [&>svg]:w-full" v-html="svg" />
        <span v-else class="px-2 text-center text-xs text-gray-400">
            {{ failed ? t('overlay_editor.qr_failed') : t('overlay_editor.qr_preview') }}
        </span>
    </div>
</template>
