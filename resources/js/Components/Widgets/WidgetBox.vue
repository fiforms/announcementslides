<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { mountWidget } from '@/Composables/widgets/widgetHost.js';

// One widget placement: a box at its canvas position/size that the widget
// module draws into. Mounted once per (placement, size, params) — the
// parent keys it so any change remounts cleanly, and unmounting always runs
// the widget's cleanup (timers would otherwise outlive the slide).
const props = defineProps({
    placement: { type: Object, required: true },
    entryUrl: { type: String, required: true },
    mode: { type: String, default: 'live' },
});

const { locale } = useI18n();
const page = usePage();
const el = ref(null);
let handle = null;

onMounted(() => {
    handle = mountWidget(el.value, props.placement, props.entryUrl, {
        mode: props.mode, locale: locale.value, location: page.props.widgetLocation ?? null,
    });
});
onBeforeUnmount(() => handle?.dispose());
</script>

<template>
    <div ref="el" class="absolute overflow-hidden"
        :style="{
            left: `${placement.x}px`, top: `${placement.y}px`,
            width: `${placement.w}px`, height: `${placement.h}px`,
            opacity: placement.opacity ?? 1,
        }" />
</template>
