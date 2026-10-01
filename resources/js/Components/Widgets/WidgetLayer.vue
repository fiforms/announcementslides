<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import WidgetBox from './WidgetBox.vue';
import { CANVAS } from '@/Composables/overlay/model.js';

// Live overlay widgets, stacked above a slide's overlay image. Fills its
// (relative) parent and maps the overlay's 1920×1080 canvas into it with
// the same object-contain fit as the overlay <img>, so a widget placed at
// (x, y) in the editor lands exactly there at any screen size. Widgets
// draw in canvas units; the stage's CSS scale does the rest.
const props = defineProps({
    // Saved placements (slide.overlay_widgets) or, in the editor, the
    // unsaved widget elements merged with their catalog entry_url.
    widgets: { type: Array, default: () => [] },
    mode: { type: String, default: 'live' },
});

const root = ref(null);
const fit = ref(null);

function measure() {
    const box = root.value;
    if (!box?.clientWidth || !box?.clientHeight) return;
    const scale = Math.min(box.clientWidth / CANVAS.w, box.clientHeight / CANVAS.h);
    fit.value = {
        scale,
        x: (box.clientWidth - CANVAS.w * scale) / 2,
        y: (box.clientHeight - CANVAS.h * scale) / 2,
    };
}

let observer;
onMounted(() => {
    measure();
    observer = new ResizeObserver(measure);
    observer.observe(root.value);
});
onBeforeUnmount(() => observer?.disconnect());

const placements = computed(() => props.widgets.filter(w => w.entry_url));

// Remount on any change a widget can't be expected to react to itself.
function key(p) {
    return `${p.id}:${p.entry_url}:${p.w}x${p.h}:${JSON.stringify(p.params ?? {})}`;
}
</script>

<template>
    <div ref="root" class="pointer-events-none absolute inset-0 overflow-hidden">
        <div v-if="fit && placements.length" class="absolute origin-top-left"
            :style="{
                left: `${fit.x}px`, top: `${fit.y}px`,
                width: `${CANVAS.w}px`, height: `${CANVAS.h}px`,
                transform: `scale(${fit.scale})`,
            }">
            <WidgetBox v-for="p in placements" :key="key(p)" :placement="p" :entry-url="p.entry_url" :mode="mode" />
        </div>
    </div>
</template>
