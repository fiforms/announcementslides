<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// A show's background music. `tracks` is the show's playlist ([{url}]); one
// track loops, several play in order and then start over. Rendered as a
// hidden <audio>, mounted once by the player so it keeps playing across
// slide changes. The kiosk has a twin (slideannouncer/frontend/components/
// FrameAudio.vue); keep them in step.
// `suspended` holds it still (player paused, or a video slide has its own sound).
const props = defineProps({
    tracks: { type: Array, required: true },
    volume: { type: Number, default: 100 },
    suspended: { type: Boolean, default: false },
});

const el = ref(null);
const index = ref(0);
const src = computed(() => props.tracks[index.value]?.url ?? null);

function applyVolume() {
    if (el.value) el.value.volume = Math.min(1, Math.max(0, props.volume / 100));
}

async function play() {
    if (!el.value || props.suspended || !src.value) return;
    try {
        await el.value.play();
    } catch { /* blocked until the visitor interacts: see the listener below */ }
}

function next() {
    if (props.tracks.length > 1) index.value = (index.value + 1) % props.tracks.length;
}

// A shared-link player may start with no prior interaction, so the browser
// can refuse sound; start on the first click or key instead.
function onInteract() {
    if (el.value?.paused) play();
}

onMounted(() => {
    applyVolume();
    play();
    window.addEventListener('pointerdown', onInteract);
    window.addEventListener('keydown', onInteract);
});
onBeforeUnmount(() => {
    window.removeEventListener('pointerdown', onInteract);
    window.removeEventListener('keydown', onInteract);
});

watch(() => props.volume, applyVolume);
watch(() => props.suspended, (s) => (s ? el.value?.pause() : play()));
watch(() => props.tracks.map((t) => t.url).join('|'), () => {
    if (index.value >= props.tracks.length) index.value = 0;
});
watch(src, () => nextTick(() => { applyVolume(); play(); }));
</script>

<template>
    <audio ref="el" :src="src" :loop="tracks.length === 1" preload="auto" class="hidden" @ended="next" />
</template>
