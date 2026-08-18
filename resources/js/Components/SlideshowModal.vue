<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
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
const videoEl = ref(null);

// Mirrors the device kiosk's key mapping (slideannouncer/local-app/frontend/
// src/views/Slideshow.vue) — including remote-only keys like MediaTrackNext
// or BrowserHome that a plain browser keyboard will rarely if ever send, so
// the two stay in lockstep rather than drifting into separate mappings.
const NEXT_SLIDE_KEYS = ['ArrowDown', 'MediaTrackNext'];
const PREV_SLIDE_KEYS = ['ArrowUp', 'MediaTrackPrevious'];
const SEEK_FORWARD_KEYS = ['ArrowRight', 'MediaFastForward'];
const SEEK_BACK_KEYS = ['ArrowLeft', 'MediaRewind'];
const RESTART_KEYS = ['Home', 'BrowserHome', 'BrowserBack', 'GoBack', 'Back'];
const PLAY_PAUSE_KEYS = ['MediaPlayPause', ' ', 'p', 'P'];
const SEEK_STEP_SECONDS = 15;
const SEEK_FAST_STEP_SECONDS = 30;
const SEEK_RAPID_WINDOW_MS = 1500;
const SEEK_INDICATOR_HOLD_MS = 1500;
let lastSeekAt = 0;
let lastSeekDirection = 0;
let seekHideTimer = null;
const showSeekIndicator = ref(false);
const seekPositionSeconds = ref(0);
const seekDurationSeconds = ref(0);

const currentSlide = computed(() => slidesList.value[currentIndex.value]);

function isVideoSlide(slide) {
    return !!slide?.mime_type?.startsWith('video/');
}

function formatTime(seconds) {
    const safe = Number.isFinite(seconds) && seconds > 0 ? seconds : 0;
    const mins = Math.floor(safe / 60);
    const secs = Math.floor(safe % 60);
    return `${mins}:${String(secs).padStart(2, '0')}`;
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

// Routed through goToIndex (rather than mutating currentIndex directly) so
// every manual nav restarts the advance countdown, whether or not the index
// actually changed (e.g. restartShow() jumping to slide 1 while already there).
const goToIndex = (index) => {
    const len = slidesList.value.length;
    if (len === 0) return;
    currentIndex.value = ((index % len) + len) % len;
    scheduleAdvance();
    // A slide change makes any in-progress seek indicator stale (wrong video).
    if (seekHideTimer) { clearTimeout(seekHideTimer); seekHideTimer = null; }
    showSeekIndicator.value = false;
};

const nextSlide = () => goToIndex(currentIndex.value + 1);

const prevSlide = () => goToIndex(currentIndex.value - 1);

// Left/Right (and MediaRewind/MediaFastForward) seek a playing video by
// SEEK_STEP_SECONDS; a second press in the same direction within
// SEEK_RAPID_WINDOW_MS escalates to SEEK_FAST_STEP_SECONDS so a long video
// can be scrubbed quickly. On a non-video slide there's nothing to seek, so
// these keys fall back to plain next/prev slide navigation instead.
const seekOrGoToIndex = (direction) => {
    const el = videoEl.value;
    if (!el) {
        goToIndex(currentIndex.value + direction);
        return;
    }
    const now = Date.now();
    const rapid = lastSeekDirection === direction && (now - lastSeekAt) < SEEK_RAPID_WINDOW_MS;
    const step = rapid ? SEEK_FAST_STEP_SECONDS : SEEK_STEP_SECONDS;
    lastSeekAt = now;
    lastSeekDirection = direction;
    const max = Number.isFinite(el.duration) ? el.duration : Infinity;
    el.currentTime = Math.min(Math.max(el.currentTime + direction * step, 0), max);
    showSeekIndicatorFor(el);
};

// Position/duration bar shown briefly on each seek press — mirrors the
// device kiosk's seek indicator (there's no scrub bar on this modal to show
// position otherwise while seeking via keyboard). Re-arms on every press
// rather than a fixed duration, so a run of rapid presses keeps it visible
// throughout instead of flickering off between them.
const showSeekIndicatorFor = (el) => {
    seekPositionSeconds.value = el.currentTime;
    seekDurationSeconds.value = Number.isFinite(el.duration) ? el.duration : 0;
    showSeekIndicator.value = true;
    if (seekHideTimer) clearTimeout(seekHideTimer);
    seekHideTimer = setTimeout(() => { showSeekIndicator.value = false; }, SEEK_INDICATOR_HOLD_MS);
};

// Home/Back restart the show: jump to slide 1 and, if we're already there,
// reset the video's playback position too (goToIndex's own remount handles
// the "already elsewhere" case, since a fresh <video> starts at 0 anyway).
const restartShow = () => {
    const wasIndex = currentIndex.value;
    goToIndex(0);
    if (wasIndex === 0) {
        const el = videoEl.value;
        if (el) {
            el.currentTime = 0;
            if (!isPaused.value) el.play().catch(() => {});
        }
    }
};

const togglePause = () => {
    isPaused.value = !isPaused.value;
    const el = videoEl.value;
    if (isPaused.value) {
        clearAdvanceTimer();
        if (el) el.pause();
    } else {
        scheduleAdvance();
        if (el) el.play().catch(() => {});
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
        return;
    }
    if (NEXT_SLIDE_KEYS.includes(e.key)) { e.preventDefault(); nextSlide(); }
    else if (PREV_SLIDE_KEYS.includes(e.key)) { e.preventDefault(); prevSlide(); }
    else if (SEEK_FORWARD_KEYS.includes(e.key)) { e.preventDefault(); seekOrGoToIndex(1); }
    else if (SEEK_BACK_KEYS.includes(e.key)) { e.preventDefault(); seekOrGoToIndex(-1); }
    else if (RESTART_KEYS.includes(e.key)) { e.preventDefault(); restartShow(); }
    else if (PLAY_PAUSE_KEYS.includes(e.key)) { e.preventDefault(); togglePause(); }
};

onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown);
    if (controlsHideTimer.value) clearTimeout(controlsHideTimer.value);
    if (seekHideTimer) clearTimeout(seekHideTimer);
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
                    <div :key="currentIndex" class="relative h-full w-full">
                        <video
                            v-if="isVideoSlide(currentSlide)"
                            ref="videoEl"
                            :src="currentSlide.file_url"
                            :loop="currentSlide.video_playback_mode === 'loop'"
                            playsinline
                            class="absolute inset-0 h-full w-full object-contain"
                            @ended="onVideoEnded"
                            @loadedmetadata="playWithSound"
                        />
                        <img
                            v-else
                            :src="currentSlide.file_url"
                            :alt="currentSlide.title"
                            class="absolute inset-0 h-full w-full object-contain"
                        />
                        <img
                            v-if="currentSlide.overlay_url"
                            :src="currentSlide.overlay_url"
                            :alt="`${currentSlide.title} overlay`"
                            class="absolute inset-0 h-full w-full object-contain"
                        />
                    </div>
                </transition>

                <!-- Seek indicator -->
                <div
                    v-if="showSeekIndicator"
                    class="absolute bottom-24 left-1/2 transform -translate-x-1/2 flex items-center gap-3 bg-black/60 px-4 py-2 rounded-full backdrop-blur text-white text-sm"
                >
                    <span class="tabular-nums min-w-[7ch] text-right">{{ formatTime(seekPositionSeconds) }} / {{ formatTime(seekDurationSeconds) }}</span>
                    <div class="w-48 h-1.5 rounded-full bg-white/25 overflow-hidden">
                        <div
                            class="h-full bg-white"
                            :style="{ width: (seekDurationSeconds ? (seekPositionSeconds / seekDurationSeconds) * 100 : 0) + '%' }"
                        />
                    </div>
                </div>

                <!-- Controls -->
                <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 flex items-center gap-4 bg-black/50 px-6 py-3 rounded-full backdrop-blur transition-opacity duration-300" :class="showControls ? 'opacity-100' : 'opacity-0 pointer-events-none'">
                    <button
                        @click="prevSlide"
                        class="text-white hover:text-gray-300 transition p-2"
                        title="Previous (↑)"
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
                        title="Next (↓)"
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
