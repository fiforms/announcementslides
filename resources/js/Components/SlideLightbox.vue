<script setup>
import { computed, ref, watch } from 'vue';
import WidgetLayer from '@/Components/Widgets/WidgetLayer.vue';

const props = defineProps({
    slide: { type: Object, default: null },
    canUnarchive: { type: Boolean, default: false },
    // Which mode a newly-opened slide should start in: the windowed preview
    // (default for a tile click) or the full-page mode (default when landing
    // on a slide's canonical URL directly).
    startExpanded: { type: Boolean, default: false },
});

defineEmits(['close', 'unarchive']);

const isExpanded = ref(props.startExpanded);

// The component stays mounted across slide changes (only the `slide` prop
// changes), so reset the mode whenever a *different* slide opens.
watch(() => props.slide?.id, (id, previousId) => {
    if (id !== undefined && id !== previousId) isExpanded.value = props.startExpanded;
});

const MEDIA_TYPE_LABELS = {
    slide: 'Slide',
    'slide-overlay': 'Overlay',
    'color-flyer': 'Color Flyer',
    'easy-print-flyer': 'Easy-Print Flyer',
    'social-media-image': 'Social Media Image',
};

function mediaLabel(media) {
    if (media.media_type === 'slide' && media.mime_type?.startsWith('video/')) return 'Video';
    return MEDIA_TYPE_LABELS[media.media_type] ?? media.media_type;
}

// One download button per attachment. When the slide has an overlay, its
// 'Slide' button is the composited result and the bare base image moves to
// a separate 'Background' button (the overlay keeps its own button). Those two
// are flagged `special`: they're raw parts that are easy to send out by mistake
// (the background alone can carry stale content), so they sit behind a menu.
const downloads = computed(() => {
    const media = props.slide?.media ?? [];
    const hasOverlay = media.some(m => m.media_type === 'slide-overlay');

    return media.flatMap(m => {
        const item = { media: m, label: mediaLabel(m), href: route('slides.media.download', [props.slide.token, m.id]) };
        if (hasOverlay && m.media_type === 'slide') {
            return [
                { ...item, href: route('slides.download', props.slide.token) },
                { ...item, label: 'Background', special: true },
            ];
        }
        return [{ ...item, special: m.media_type === 'slide-overlay' }];
    });
});

const mainDownloads = computed(() => downloads.value.filter(d => !d.special));
const specialDownloads = computed(() => downloads.value.filter(d => d.special));
// The finished slide (composited when there's an overlay): the only thing the right-click menu offers.
const slideDownload = computed(() => mainDownloads.value.find(d => d.media.media_type === 'slide') ?? null);

// Leave room for the capped (30dvh) info panel, the gap and the padding so the buttons never leave the viewport.
const previewMaxH = computed(() => props.slide && isExpanded.value
    ? 'max-h-[min(85vh,calc(70dvh-3rem))]'
    : 'max-h-[min(65vh,calc(70dvh-3rem))]');

const specialOpen = ref(false);
const contextMenu = ref(null);

watch(() => props.slide?.id, () => {
    specialOpen.value = false;
    contextMenu.value = null;
});

function openContextMenu(event) {
    if (!slideDownload.value) return;
    event.preventDefault();
    contextMenu.value = {
        x: Math.min(event.clientX, window.innerWidth - 180),
        y: Math.min(event.clientY, window.innerHeight - 50),
    };
}

function isPdf(media) {
    return media.mime_type === 'application/pdf';
}

const copiedField = ref(null);

async function copy(text, field) {
    try {
        await navigator.clipboard.writeText(text);
        copiedField.value = field;
        setTimeout(() => { if (copiedField.value === field) copiedField.value = null; }, 1500);
    } catch {
        // Clipboard access denied/unavailable — nothing sensible to do but leave the text visible to copy manually.
    }
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="slide"
                class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 overflow-hidden bg-black/85 p-4"
                @click.self="$emit('close')">

                <!-- Expand/shrink button (z-10: the relative preview below
                     otherwise paints over it once expanded to 95vw) -->
                <button @click="isExpanded = !isExpanded"
                    class="absolute top-4 right-16 z-10 rounded-full bg-white/10 p-2 text-white hover:bg-white/20 transition-colors"
                    :aria-label="isExpanded ? 'Shrink' : 'Expand'">
                    <svg v-if="!isExpanded" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 8V4h4M20 8V4h-4M4 16v4h4M20 16v4h-4" />
                    </svg>
                    <svg v-else class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 4v4H5M15 4v4h4M9 20v-4H5M15 20v-4h4" />
                    </svg>
                </button>

                <!-- Close button -->
                <button @click="$emit('close')"
                    class="absolute top-4 right-4 z-10 rounded-full bg-white/10 p-2 text-white hover:bg-white/20 transition-colors"
                    aria-label="Close">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <!-- Preview (base + overlay, composited exactly like the slideshow) -->
                <div class="relative w-full flex-shrink"
                    :class="isExpanded ? 'max-w-[95vw]' : 'max-w-4xl'"
                    @click.self="$emit('close')"
                    @contextmenu="openContextMenu">
                    <video v-if="slide.mime_type?.startsWith('video/')"
                        :src="slide.file_url"
                        controls controlslist="nodownload" autoplay loop
                        class="w-full rounded-lg object-contain shadow-2xl"
                        :class="previewMaxH" />
                    <img v-else-if="slide.file_url || slide.thumbnail_url" :src="slide.file_url || slide.thumbnail_url"
                        :alt="slide.title" draggable="false"
                        class="w-full rounded-lg object-contain shadow-2xl"
                        :class="previewMaxH" />
                    <!-- A slide with no image: a black 16:9 stage for its overlay and widgets. -->
                    <div v-else class="aspect-video w-full rounded-lg bg-black shadow-2xl"
                        :class="previewMaxH" />
                    <img v-if="slide.overlay_url" :src="slide.overlay_url" :alt="`${slide.title} overlay`" draggable="false"
                        class="pointer-events-none absolute inset-0 h-full w-full object-contain" />
                    <WidgetLayer v-if="slide.overlay_widgets?.length" :key="slide.id" :widgets="slide.overlay_widgets" />
                </div>

                <!-- Description / link / attached files -->
                <div class="flex max-h-[30dvh] w-full flex-col gap-3 rounded-xl border border-white/10 bg-black/60 p-4 text-white shadow-2xl backdrop-blur-md"
                    :class="isExpanded ? 'max-w-[95vw]' : 'max-w-4xl'"
                    @click.stop>
                    <div class="flex flex-shrink-0 items-start justify-between gap-3">
                        <p class="line-clamp-2 text-lg font-medium">{{ slide.title }}</p>
                        <button v-if="canUnarchive" @click="$emit('unarchive', slide)"
                            class="flex flex-shrink-0 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 8l6-6 6 6M10 2v16m-8 4h16" />
                            </svg>
                            Un-archive
                        </button>
                    </div>

                    <div v-if="slide.text_description" class="flex min-h-0 items-start gap-2 overflow-y-auto">
                        <p class="flex-1 text-sm text-white/80 whitespace-pre-line">{{ slide.text_description }}</p>
                        <button @click="copy(slide.text_description, 'description')" title="Copy description"
                            class="flex-shrink-0 rounded p-1 text-white/60 hover:bg-white/10 hover:text-white">
                            <svg v-if="copiedField === 'description'" class="h-4 w-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>

                    <div v-if="slide.link" class="flex flex-shrink-0 items-center gap-2">
                        <a :href="slide.link" target="_blank" rel="noopener"
                            class="flex-1 truncate text-sm text-indigo-300 hover:text-indigo-200 underline">{{ slide.link }}</a>
                        <button @click="copy(slide.link, 'link')" title="Copy link"
                            class="flex-shrink-0 rounded p-1 text-white/60 hover:bg-white/10 hover:text-white">
                            <svg v-if="copiedField === 'link'" class="h-4 w-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>

                    <div v-if="downloads.length" class="relative flex flex-shrink-0 flex-wrap gap-2 pt-1">
                        <a v-for="d in mainDownloads" :key="d.label + d.media.id"
                            :href="d.href"
                            class="flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/20 transition-colors">
                            <svg v-if="isPdf(d.media)" class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <svg v-else class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M4 8h16M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z" />
                            </svg>
                            {{ d.label }}
                            <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                        </a>

                        <!-- Raw parts (background / overlay) live behind one extra click -->
                        <div v-if="specialDownloads.length" class="relative">
                            <button type="button" @click="specialOpen = !specialOpen"
                                class="flex items-center gap-1.5 rounded-lg border border-white/20 px-3 py-1.5 text-xs font-medium text-white/80 hover:bg-white/10 transition-colors"
                                :aria-expanded="specialOpen">
                                Special Assets
                                <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                </svg>
                            </button>
                            <div v-if="specialOpen" class="fixed inset-0 z-10" @click="specialOpen = false" />
                            <div v-if="specialOpen"
                                class="absolute bottom-full left-0 z-20 mb-1 min-w-40 rounded-lg border border-white/10 bg-neutral-900 p-1 shadow-2xl">
                                <a v-for="d in specialDownloads" :key="d.label + d.media.id"
                                    :href="d.href" @click="specialOpen = false"
                                    class="block whitespace-nowrap rounded px-3 py-1.5 text-xs text-white hover:bg-white/10">
                                    Download {{ d.label }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right-click replaces the browser menu so "Save image as" can't grab the raw background -->
                <template v-if="contextMenu && slideDownload">
                    <div class="fixed inset-0 z-[60]" @click="contextMenu = null" @contextmenu.prevent="contextMenu = null" />
                    <div class="fixed z-[61] rounded-lg border border-white/10 bg-neutral-900 p-1 shadow-2xl"
                        :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px' }">
                        <a :href="slideDownload.href" @click="contextMenu = null"
                            class="block whitespace-nowrap rounded px-3 py-1.5 text-sm text-white hover:bg-white/10">
                            Download {{ slideDownload.label }}
                        </a>
                    </div>
                </template>
            </div>
        </Transition>
    </Teleport>
</template>
