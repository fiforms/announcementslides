<script setup>
import { onMounted, ref } from 'vue';
import WidgetLayer from '@/Components/Widgets/WidgetLayer.vue';

// One half of a show's frame, mounted once by the player and left alone
// while slides come and go (so a clock doesn't restart and a background
// video doesn't reload on a slide change):
//   layer="base" — the background image/video, under the slide stages.
//   layer="top"  — the overlay image and widgets, over the slide stages
//                  (so above a slide's own overlay and widgets).
// `frame` has a slide's field names: file_url/mime_type for the background,
// overlay_url/overlay_widgets for the top layer.
const props = defineProps({
    frame: { type: Object, required: true },
    layer: { type: String, required: true },
});

const videoEl = ref(null);

// Loops with sound where the browser allows it, else muted rather than
// frozen (same fallback as SlideStage).
onMounted(async () => {
    const el = videoEl.value;
    if (!el) return;
    el.muted = false;
    try {
        await el.play();
    } catch {
        el.muted = true;
        try { await el.play(); } catch { /* give up silently */ }
    }
});
</script>

<template>
    <div class="pointer-events-none absolute inset-0">
        <template v-if="layer === 'base' && frame.file_url">
            <video v-if="frame.mime_type?.startsWith('video/')" ref="videoEl" :src="frame.file_url"
                loop autoplay playsinline preload="auto" class="absolute inset-0 h-full w-full object-contain" />
            <img v-else :src="frame.file_url" alt="" class="absolute inset-0 h-full w-full object-contain" />
        </template>
        <template v-else-if="layer === 'top'">
            <img v-if="frame.overlay_url" :src="frame.overlay_url" alt="" class="absolute inset-0 h-full w-full object-contain" />
            <WidgetLayer v-if="frame.overlay_widgets?.length" :widgets="frame.overlay_widgets" />
        </template>
    </div>
</template>
