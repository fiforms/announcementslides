<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'

// A show's background music — the kiosk twin of the main app's
// resources/js/Components/FrameAudio.vue; keep them in step. `tracks` is the
// show's playlist ([{url}], cached locally by sync.py): one track loops,
// several play in order and then start over. A hidden <audio>, mounted once
// by Slideshow.vue so it keeps playing across slide changes. `suspended`
// holds it still (show paused, external feed on screen, or a video slide
// that has its own sound).
// kiosk-start.sh launches Chromium with --autoplay-policy=no-user-gesture-
// required, so it starts without a click.
const props = defineProps({
  tracks: { type: Array, required: true },
  volume: { type: Number, default: 100 },
  suspended: { type: Boolean, default: false },
})

const el = ref(null)
const index = ref(0)
const src = computed(() => props.tracks[index.value]?.url ?? null)

function applyVolume() {
  if (el.value) el.value.volume = Math.min(1, Math.max(0, props.volume / 100))
}

async function play() {
  if (!el.value || props.suspended || !src.value) return
  try { await el.value.play() } catch { /* nothing more to try */ }
}

function next() {
  if (props.tracks.length > 1) index.value = (index.value + 1) % props.tracks.length
}

onMounted(() => {
  applyVolume()
  play()
})

watch(() => props.volume, applyVolume)
watch(() => props.suspended, (s) => (s ? el.value?.pause() : play()))
watch(() => props.tracks.map((t) => t.url).join('|'), () => {
  if (index.value >= props.tracks.length) index.value = 0
})
watch(src, () => nextTick(() => { applyVolume(); play() }))
</script>

<template>
  <audio ref="el" :src="src" :loop="tracks.length === 1" preload="auto" style="display: none" @ended="next" @error="next" />
</template>
