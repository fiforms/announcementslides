<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    show: { type: Boolean, default: false },
    slide: { type: Object, default: null },
    slides: { type: Array, default: null },
});

const emit = defineEmits(['close']);

const page = usePage();
const currentIndex = ref(0);
const isPaused = ref(false);
const showControls = ref(true);
const slidesList = computed(() => {
    if (props.slides && props.slides.length > 0) {
        return props.slides;
    }
    return props.slide ? [props.slide] : [];
});
const advanceTimer = ref(null);
const slideshowInterval = ref(10000); // Default 10 seconds
const controlsHideTimer = ref(null);
const containerRef = ref(null);

const currentSlide = computed(() => slidesList.value[currentIndex.value]);

function isVideoSlide(slide) {
    return !!slide?.mime_type?.startsWith('video/');
}

const getSlideshowInterval = () => {
    if (typeof window !== 'undefined' && localStorage) {
        const stored = localStorage.getItem('slideshowInterval');
        return stored ? parseInt(stored) * 1000 : 10000;
    }
    return 10000;
};

const advanceSlide = () => {
    currentIndex.value = (currentIndex.value + 1) % slidesList.value.length;
};

// Per-slide scheduler: an image or a video in 'hold_last_frame'/'loop' mode
// advances after the normal slide delay; a 'play_through' video advances
// instead from its 'ended' event (see onVideoEnded) — no fixed delay races
// it. Re-runs on every currentIndex change (including manual nav), so
// switching slides always restarts the countdown for the new slide.
const clearAdvanceTimer = () => {
    if (advanceTimer.value) {
        clearTimeout(advanceTimer.value);
        advanceTimer.value = null;
    }
};

const scheduleAdvance = () => {
    clearAdvanceTimer();
    if (isPaused.value) return;

    const slide = currentSlide.value;
    if (isVideoSlide(slide) && slide.video_playback_mode === 'play_through') {
        return;
    }

    advanceTimer.value = setTimeout(() => {
        if (!isPaused.value) advanceSlide();
    }, slideshowInterval.value);
};

const onVideoEnded = () => {
    const slide = currentSlide.value;
    if (isVideoSlide(slide) && slide.video_playback_mode === 'play_through') {
        advanceSlide();
    }
    // hold_last_frame: no-op — the <video> (not looping) naturally freezes
    // on its last frame until scheduleAdvance()'s timeout fires.
};

// Plays with sound where the browser allows it (opening the slideshow is
// itself a user click, which is usually enough); falls back to muted
// playback rather than leaving the slide frozen if a stricter browser
// blocks unmuted autoplay for a visitor with no prior interaction on the
// site.
const playWithSound = async (event) => {
    const el = event.target;
    el.muted = false;
    try {
        await el.play();
    } catch {
        el.muted = true;
        try { await el.play(); } catch { /* give up silently */ }
    }
};

const startSlideshow = () => {
    slideshowInterval.value = getSlideshowInterval();
    scheduleAdvance();
};

const stopSlideshow = () => {
    clearAdvanceTimer();
};

watch(currentIndex, () => scheduleAdvance());

const nextSlide = () => {
    currentIndex.value = (currentIndex.value + 1) % slidesList.value.length;
};

const prevSlide = () => {
    currentIndex.value = (currentIndex.value - 1 + slidesList.value.length) % slidesList.value.length;
};

const togglePause = () => {
    isPaused.value = !isPaused.value;
    if (isPaused.value) {
        clearAdvanceTimer();
    } else {
        scheduleAdvance();
    }
};

const scheduleControlsHide = () => {
    showControls.value = true;
    if (controlsHideTimer.value) clearTimeout(controlsHideTimer.value);
    controlsHideTimer.value = setTimeout(() => {
        showControls.value = false;
    }, 5000);
};

const handleMouseMove = () => {
    if (props.show) {
        scheduleControlsHide();
    }
};

const requestFullScreen = async () => {
    if (!containerRef.value) return;
    try {
        if (document.fullscreenElement) {
            await document.exitFullscreen();
        } else {
            await containerRef.value.requestFullscreen();
        }
    } catch (err) {
        console.error('Fullscreen request failed:', err);
    }
};

const handleKeydown = (e) => {
    if (!props.show) return;

    if (e.key === 'Escape') {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        }
        emit('close');
    }
    if (e.key === 'ArrowRight' || e.key === ' ') nextSlide();
    if (e.key === 'ArrowLeft') prevSlide();
    if (e.key === 'p' || e.key === 'P') togglePause();
};

onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown);
    if (controlsHideTimer.value) clearTimeout(controlsHideTimer.value);
    stopSlideshow();
    if (document.fullscreenElement) {
        document.exitFullscreen();
    }
});

const handleClose = () => {
    stopSlideshow();
    currentIndex.value = 0;
    isPaused.value = false;
    emit('close');
};

const handleShow = () => {
    if (props.show) {
        startSlideshow();
        scheduleControlsHide();
        // Request fullscreen after a brief delay to ensure DOM is ready
        setTimeout(() => {
            requestFullScreen();
        }, 100);
    } else {
        stopSlideshow();
        if (controlsHideTimer.value) clearTimeout(controlsHideTimer.value);
    }
};
</script>

<template>
    <Transition
        enter-active-class="transition ease-out duration-300"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition ease-in duration-300"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
        @enter="handleShow"
        @leave="handleShow"
    >
        <div v-if="show" ref="containerRef" class="fixed inset-0 bg-black z-50 flex items-center justify-center" :class="showControls ? 'cursor-auto' : 'cursor-none'" @mousemove="handleMouseMove">
            <!-- Slide Image -->
            <div class="relative w-full h-full flex items-center justify-center">
                <transition
                    enter-active-class="transition ease-out duration-1000"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition ease-in duration-1000"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                    mode="out-in"
                >
                    <video
                        v-if="isVideoSlide(currentSlide)"
                        :key="currentIndex"
                        :src="currentSlide.file_url"
                        :loop="currentSlide.video_playback_mode === 'loop'"
                        playsinline
                        class="h-full w-full object-contain"
                        @ended="onVideoEnded"
                        @loadedmetadata="playWithSound"
                    />
                    <img
                        v-else
                        :key="currentIndex"
                        :src="currentSlide.file_url"
                        :alt="currentSlide.title"
                        class="h-full w-full object-contain"
                    />
                </transition>

                <!-- Controls -->
                <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 flex items-center gap-4 bg-black/50 px-6 py-3 rounded-full backdrop-blur transition-opacity duration-300" :class="showControls ? 'opacity-100' : 'opacity-0 pointer-events-none'">
                    <button
                        @click="prevSlide"
                        class="text-white hover:text-gray-300 transition p-2"
                        title="Previous (←)"
                    >
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <button
                        @click="togglePause"
                        class="text-white hover:text-gray-300 transition p-2"
                        :title="isPaused ? 'Play (P)' : 'Pause (P)'"
                    >
                        <svg v-if="isPaused" class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z" />
                        </svg>
                        <svg v-else class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm4-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <button
                        @click="nextSlide"
                        class="text-white hover:text-gray-300 transition p-2"
                        title="Next (→)"
                    >
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <span class="text-white text-sm font-medium">{{ currentIndex + 1 }} / {{ slidesList.length }}</span>
                </div>

                <!-- Close button -->
                <button
                    @click="handleClose"
                    class="absolute top-8 right-8 text-white hover:text-gray-300 transition-all duration-300 p-2 bg-black/50 rounded-full backdrop-blur"
                    :class="showControls ? 'opacity-100' : 'opacity-0 pointer-events-none'"
                    title="Close (Esc)"
                >
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </Transition>
</template>
