<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import WidgetLayer from '@/Components/Widgets/WidgetLayer.vue';

// One slide's full visual stack: media, overlay image and widget layer.
// SlideshowModal mounts a stage *before* it is shown (invisible, but laid
// out and loading) and only fades it in once it emits `ready` — i.e. the
// image/video, the overlay and every widget have all loaded and painted. So
// a slide change never reveals half-loaded graphics.
const props = defineProps({
    slide: { type: Object, required: true },
    // Faded in. A stage starts hidden and is made visible by its parent.
    visible: { type: Boolean, default: false },
    // The slide on screen: its video plays; a stage that stops being
    // active (the next one took over) pauses.
    active: { type: Boolean, default: false },
});

const emit = defineEmits(['ready', 'ended']);

// A broken or very slow asset must not stall the show forever: after this
// the stage reports ready anyway and shows whatever it has.
const READY_TIMEOUT_MS = 20000;

const videoEl = ref(null);

// A slide may have no image (overlay/widgets only): then there's no media to wait for.
const waiting = new Set();
if (props.slide.file_url) waiting.add('media');
if (props.slide.overlay_url) waiting.add('overlay');
if (props.slide.overlay_widgets?.length) waiting.add('widgets');

let done = false;
let gone = false;

// Two animation frames after the last asset lands, so it has really been
// painted (not merely decoded) before the parent starts the fade. The
// timeout covers a throttled/hidden tab where frames never fire.
const nextPaint = () => new Promise(resolve => {
    const t = setTimeout(resolve, 200);
    requestAnimationFrame(() => requestAnimationFrame(() => { clearTimeout(t); resolve(); }));
});

async function finish() {
    if (done) return;
    done = true;
    await nextPaint();
    if (!gone) emit('ready');
}

function settle(name) {
    if (waiting.delete(name) && !waiting.size) finish();
}

// Nothing to wait for (e.g. a slide with no image and no overlay): ready now.
onMounted(() => { if (!waiting.size) finish(); });

const timeout = setTimeout(() => {
    if (done) return;
    console.warn(`Slide "${props.slide.title}" not fully loaded after ${READY_TIMEOUT_MS}ms; showing it anyway`, [...waiting]);
    finish();
}, READY_TIMEOUT_MS);

onBeforeUnmount(() => {
    gone = true;
    clearTimeout(timeout);
});

// `load` fires when the bytes are in; decode() additionally waits for the
// bitmap to be decoded off the main thread, which is what makes a large
// image appear instantly instead of painting in strips.
async function imageLoaded(name, event) {
    try { await event.target.decode(); } catch { /* broken image: settle below */ }
    settle(name);
}

// Plays with sound where the browser allows it (opening the slideshow is
// itself a user click, which is usually enough); falls back to muted
// playback rather than leaving the slide frozen if a stricter browser
// blocks unmuted autoplay for a visitor with no prior interaction on the
// site.
async function playWithSound() {
    const el = videoEl.value;
    if (!el) return;
    el.muted = false;
    try {
        await el.play();
    } catch {
        el.muted = true;
        try { await el.play(); } catch { /* give up silently */ }
    }
}

watch(() => props.active, active => {
    if (active) playWithSound();
    else videoEl.value?.pause();
}, { immediate: true });

defineExpose({ videoEl });
</script>

<template>
    <div class="absolute inset-0 transition-opacity duration-1000 ease-in-out" :class="visible ? 'opacity-100' : 'opacity-0'">
        <video
            v-if="slide.file_url && slide.mime_type?.startsWith('video/')"
            ref="videoEl"
            :src="slide.file_url"
            :loop="slide.video_playback_mode === 'loop'"
            preload="auto"
            playsinline
            class="absolute inset-0 h-full w-full object-contain"
            @canplay="settle('media')"
            @error="settle('media')"
            @ended="emit('ended')"
        />
        <img
            v-else-if="slide.file_url"
            :src="slide.file_url"
            :alt="slide.title"
            class="absolute inset-0 h-full w-full object-contain"
            @load="imageLoaded('media', $event)"
            @error="settle('media')"
        />
        <img
            v-if="slide.overlay_url"
            :src="slide.overlay_url"
            :alt="`${slide.title} overlay`"
            class="absolute inset-0 h-full w-full object-contain"
            @load="imageLoaded('overlay', $event)"
            @error="settle('overlay')"
        />
        <WidgetLayer v-if="slide.overlay_widgets?.length" :widgets="slide.overlay_widgets" @ready="settle('widgets')" />
    </div>
</template>
