<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

// The picker for a "menu" show (play_mode 'menu', e.g. a numbered video
// series): every slide as a titled tile. The remote's arrows move the
// highlight and OK/Enter plays it; Slideshow.vue owns playback and brings
// this back when Back is pressed. Twin of the web player's
// resources/js/Components/SlideMenuGrid.vue.
const props = defineProps({
  slides: { type: Array, required: true },
  title: { type: String, default: '' },
  // Where the highlight returns to after watching a slide.
  initialIndex: { type: Number, default: 0 },
  // Fixed so Up/Down can move a whole row without measuring the layout.
  columns: { type: Number, default: 4 },
  // False while something else owns the remote (the Menu overlay, an external feed).
  active: { type: Boolean, default: true },
})

const emit = defineEmits(['select'])

const focused = ref(Math.min(Math.max(props.initialIndex, 0), Math.max(props.slides.length - 1, 0)))
const tiles = ref([])

watch(() => props.slides.length, (len) => {
  if (focused.value >= len) focused.value = Math.max(len - 1, 0)
})

const thumb = (slide) => slide.thumb_url || (slide.mime_type?.startsWith('image/') ? slide.media_url : null)

function move(delta) {
  const len = props.slides.length
  if (!len) return
  focused.value = Math.min(Math.max(focused.value + delta, 0), len - 1)
  tiles.value[focused.value]?.scrollIntoView?.({ block: 'nearest' })
}

function onKeydown(event) {
  if (!props.active) return
  if (event.key === 'ArrowRight' || event.key === 'MediaFastForward') move(1)
  else if (event.key === 'ArrowLeft' || event.key === 'MediaRewind') move(-1)
  else if (event.key === 'ArrowDown' || event.key === 'MediaTrackNext') move(props.columns)
  else if (event.key === 'ArrowUp' || event.key === 'MediaTrackPrevious') move(-props.columns)
  else if (event.key === 'Enter' || event.key === ' ' || event.key === 'MediaPlayPause') emit('select', focused.value)
  else return
  event.preventDefault()
  // Slideshow.vue's own listener would otherwise skip slides underneath.
  event.stopPropagation()
}

// Capture phase, so it runs ahead of Slideshow.vue's bubble-phase listener.
onMounted(() => window.addEventListener('keydown', onKeydown, true))
onUnmounted(() => window.removeEventListener('keydown', onKeydown, true))

const gridStyle = computed(() => ({ gridTemplateColumns: `repeat(${props.columns}, minmax(0, 1fr))` }))
</script>

<template>
  <div class="slide-menu">
    <h2 v-if="title" class="slide-menu-title">{{ title }}</h2>
    <div class="slide-menu-grid" :style="gridStyle">
      <div
        v-for="(slide, i) in slides"
        :key="slide.id"
        ref="tiles"
        class="slide-menu-tile"
        :class="{ focused: i === focused }"
      >
        <div class="slide-menu-thumb">
          <img v-if="thumb(slide)" :src="thumb(slide)" alt="" />
        </div>
        <div class="slide-menu-caption">
          <span class="slide-menu-number">{{ i + 1 }}</span>
          <span class="slide-menu-name">{{ slide.title }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.slide-menu {
  position: absolute;
  inset: 0;
  z-index: 4;
  overflow-y: auto;
  padding: 3rem 4rem;
  background: rgba(0, 0, 0, 0.7);
}
.slide-menu-title {
  margin-bottom: 2rem;
  text-align: center;
  color: #fff;
  font-size: 2.4rem;
  font-weight: 600;
}
.slide-menu-grid {
  display: grid;
  gap: 1.5rem;
  max-width: 100rem;
  margin: 0 auto;
}
.slide-menu-tile {
  overflow: hidden;
  border-radius: 0.6rem;
  background: #1e293b;
  outline: 4px solid transparent;
  transition: transform 0.12s ease, outline-color 0.12s ease;
}
.slide-menu-tile.focused {
  outline-color: #fff;
  transform: scale(1.04);
}
.slide-menu-thumb {
  aspect-ratio: 16 / 9;
  background: #0f172a;
}
.slide-menu-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.slide-menu-caption {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  padding: 0.7rem 0.9rem;
  color: #fff;
  font-size: 1.2rem;
}
.slide-menu-number {
  color: rgba(255, 255, 255, 0.6);
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}
.slide-menu-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
