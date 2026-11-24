<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

// The picker for a "menu" show (play_mode 'menu'): every slide as a numbered
// tile, chosen with the mouse or the keyboard/remote (arrows move, Enter or
// Space plays). The slideshow owns playback; this only reports the choice.
// Its kiosk twin is kiosk-products/slideannouncer/frontend/components/SlideMenu.vue.
const props = defineProps({
    slides: { type: Array, required: true },
    title: { type: String, default: '' },
    // Where focus returns to after coming back from a slide.
    initialIndex: { type: Number, default: 0 },
    // Fixed so Up/Down can move a whole row without measuring the layout.
    columns: { type: Number, default: 4 },
});

const emit = defineEmits(['select']);

const focused = ref(Math.min(Math.max(props.initialIndex, 0), Math.max(props.slides.length - 1, 0)));
const tiles = ref([]);

watch(() => props.slides.length, (len) => {
    if (focused.value >= len) focused.value = Math.max(len - 1, 0);
});

const thumb = (slide) => slide.thumbnail_url
    || (slide.mime_type?.startsWith('image/') ? slide.file_url : null);

function move(delta) {
    const len = props.slides.length;
    if (!len) return;
    focused.value = Math.min(Math.max(focused.value + delta, 0), len - 1);
    tiles.value[focused.value]?.scrollIntoView?.({ block: 'nearest' });
}

function onKeydown(e) {
    if (e.key === 'ArrowRight') move(1);
    else if (e.key === 'ArrowLeft') move(-1);
    else if (e.key === 'ArrowDown') move(props.columns);
    else if (e.key === 'ArrowUp') move(-props.columns);
    else if (e.key === 'Enter' || e.key === ' ') emit('select', focused.value);
    else return;
    e.preventDefault();
    e.stopPropagation();
}

// Capture phase: the slideshow's own key handler must not also act on these.
onMounted(() => window.addEventListener('keydown', onKeydown, true));
onUnmounted(() => window.removeEventListener('keydown', onKeydown, true));

const gridStyle = computed(() => ({ gridTemplateColumns: `repeat(${props.columns}, minmax(0, 1fr))` }));
</script>

<template>
    <div class="absolute inset-0 z-10 overflow-y-auto bg-black/70 px-8 py-10">
        <h2 v-if="title" class="mb-6 text-center text-3xl font-semibold text-white">{{ title }}</h2>
        <div class="mx-auto grid max-w-7xl gap-5" :style="gridStyle">
            <button
                v-for="(slide, i) in slides"
                :key="slide.id"
                ref="tiles"
                type="button"
                class="group overflow-hidden rounded-lg bg-slate-800 text-left outline-none ring-2 transition"
                :class="i === focused ? 'ring-white scale-[1.03]' : 'ring-transparent hover:ring-white/50'"
                @click="focused = i; emit('select', i)"
                @mouseenter="focused = i"
            >
                <div class="aspect-video bg-slate-900">
                    <img v-if="thumb(slide)" :src="thumb(slide)" class="h-full w-full object-cover" alt="" />
                </div>
                <div class="flex items-baseline gap-2 px-3 py-2 text-white">
                    <span class="text-sm font-semibold tabular-nums text-white/60">{{ i + 1 }}</span>
                    <span class="truncate text-sm font-medium">{{ slide.title }}</span>
                </div>
            </button>
        </div>
    </div>
</template>
