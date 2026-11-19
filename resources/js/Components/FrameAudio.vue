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
    shuffle: { type: Boolean, default: false },
    suspended: { type: Boolean, default: false },
});

const el = ref(null);
const order = ref([]);  // indices into tracks, in play order
const pos = ref(0);
const src = computed(() => props.tracks[order.value[pos.value]]?.url ?? null);

// A new pass over the playlist: in order, or shuffled (never opening with the
// track that just played, when there is a choice).
function restart(justPlayed = -1) {
    const idx = props.tracks.map((_, i) => i);
    if (props.shuffle) {
        for (let i = idx.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [idx[i], idx[j]] = [idx[j], idx[i]];
        }
        if (idx.length > 1 && idx[0] === justPlayed) [idx[0], idx[1]] = [idx[1], idx[0]];
    }
    order.value = idx;
    pos.value = 0;
}
restart();

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
    if (props.tracks.length < 2) return;
    if (pos.value + 1 < order.value.length) pos.value++;
    else restart(order.value[pos.value]);
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
watch(() => [props.tracks.map((t) => t.url).join('|'), props.shuffle], () => restart());
watch(src, () => nextTick(() => { applyVolume(); play(); }));
</script>

<template>
    <audio ref="el" :src="src" :loop="tracks.length === 1" preload="auto" class="hidden" @ended="next" @error="next" />
</template>
