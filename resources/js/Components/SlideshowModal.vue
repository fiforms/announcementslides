<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import SlideStage from '@/Components/SlideStage.vue';
import ShowFrameLayer from '@/Components/ShowFrameLayer.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    slide: { type: Object, default: null },
    slides: { type: Array, default: null },
    // Unattended full-viewport playback (the shared-link player): no on-screen
    // controls, no close/fullscreen handling, plays on mount.
    kiosk: { type: Boolean, default: false },
    // The show's frame (background under, overlay + widgets over every slide),
    // or null. Mounted once, outside the per-slide fade.
    frame: { type: Object, default: null },
    // Overrides the profile's slide delay when set.
    intervalSeconds: { type: Number, default: null },
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

// ...and pick up a changed delay without a restart.
watch(() => props.intervalSeconds, () => {
    slideshowInterval.value = getSlideshowInterval();
});
const slideshowInterval = ref(12000);
const controlsHideTimer = ref(null);
const containerRef = ref(null);

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

// Slides are never built on screen. Each one is mounted as a hidden
// SlideStage first and only faded in once everything on it (image/video,
// overlay, widgets) has loaded and painted, so a slide change always lands
// on a finished slide, however slow the network. The next slide is mounted
// ahead of time, as soon as the current one is up, so a normal advance
// swaps instantly; if it isn't ready when the delay runs out, the current
// slide simply stays up until it is.
//
// A stage record is { id, index, slide, visible }. `visible` stages are on
// screen (the active one, plus the outgoing one while it is faded under the
// new one); at most one non-visible stage exists at a time — the incoming
// one. The slide is a snapshot, so a live list refresh never changes what's
// on screen mid-slide.
const FADE_MS = 1000;
let stageSeq = 0;
const stages = ref([]);
const activeId = ref(null);
const stageRefs = new Map();
const readyIds = new Set();
const leaveTimers = new Set();
// True once something asked to move to the incoming slide (the delay ran
// out, or a key was pressed): swap as soon as it is ready.
let swapRequested = false;

const activeStage = computed(() => stages.value.find(s => s.id === activeId.value) ?? null);
const currentSlide = computed(() => activeStage.value?.slide ?? null);
const incomingStage = () => stages.value.find(s => !s.visible) ?? null;
const activeVideo = () => stageRefs.get(activeId.value)?.videoEl ?? null;

// Where the show is heading: the incoming slide if a move is pending, so
// pressing Next twice quickly skips two slides rather than one.
const targetIndex = () => (swapRequested && incomingStage()) ? incomingStage().index : currentIndex.value;

function setStageRef(id, instance) {
    if (instance) stageRefs.set(id, instance);
    else stageRefs.delete(id);
}

function dropStage(id) {
    stages.value = stages.value.filter(s => s.id !== id);
    readyIds.delete(id);
}

function ensureIncoming(index) {
    const existing = incomingStage();
    if (existing?.index === index) return;
    if (existing) dropStage(existing.id);
    stages.value.push({ id: ++stageSeq, index, slide: slidesList.value[index], visible: false });
}

function prefetchNext() {
    const len = slidesList.value.length;
    if (len > 1) ensureIncoming((currentIndex.value + 1) % len);
}

function onStageReady(id) {
    readyIds.add(id);
    trySwap();
}

function trySwap() {
    const next = incomingStage();
    if (!swapRequested || !next || !readyIds.has(next.id)) return;
    swapRequested = false;
    const previous = activeStage.value;
    next.visible = true;
    activeId.value = next.id;
    currentIndex.value = next.index;
    if (previous) {
        // Drop the old stage once the new one has fully faded in over it.
        const timer = setTimeout(() => { leaveTimers.delete(timer); dropStage(previous.id); }, FADE_MS + 100);
        leaveTimers.add(timer);
    }
    scheduleAdvance();
    prefetchNext();
}

function resetStages() {
    leaveTimers.forEach(clearTimeout);
    leaveTimers.clear();
    stages.value = [];
    stageRefs.clear();
    readyIds.clear();
    activeId.value = null;
    currentIndex.value = 0;
    swapRequested = slidesList.value.length > 0;
    if (swapRequested) ensureIncoming(0);
}

watch(() => props.show, (show) => {
    if (show) resetStages();
}, { immediate: true });

// A live list can change under us (kiosk refresh): stay in range and
// re-prepare the incoming slide from the new list. What's on screen stays.
watch(slidesList, (list) => {
    if (!props.show) return;
    if (list.length && currentIndex.value >= list.length) currentIndex.value = 0;
    if (!activeStage.value) {
        resetStages();
        return;
    }
    const incoming = incomingStage();
    if (incoming) {
        dropStage(incoming.id);
        if (swapRequested) ensureIncoming(Math.min(incoming.index, list.length - 1));
    }
    if (!swapRequested) prefetchNext();
});

function isVideoSlide(slide) {
    return !!slide?.mime_type?.startsWith('video/');
}

function formatTime(seconds) {
    const safe = Number.isFinite(seconds) && seconds > 0 ? seconds : 0;
    const mins = Math.floor(safe / 60);
    const secs = Math.floor(safe % 60);
    return `${mins}:${String(secs).padStart(2, '0')}`;
}

// The viewer's profile slide delay (shared Inertia prop; site default when anonymous).
const getSlideshowInterval = () => (props.intervalSeconds || Number(page.props.slideDelaySeconds) || 12) * 1000;

// Routed through goToIndex so each auto-advance (timer or a play_through
// video's 'ended') re-arms the countdown for the next slide, including the
// wrap from the last slide back to the first.
const advanceSlide = () => goToIndex(targetIndex() + 1);

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

    slideshowInterval.value = getSlideshowInterval();
    advanceTimer.value = setTimeout(() => {
        if (!isPaused.value) advanceSlide();
    }, slideshowInterval.value);
};

const onStageEnded = (id) => {
    if (id === activeId.value) onVideoEnded();
};

const onVideoEnded = () => {
    const slide = currentSlide.value;
    if (isVideoSlide(slide) && slide.video_playback_mode === 'play_through') {
        advanceSlide();
    }
    // hold_last_frame: no-op — the <video> (not looping) naturally freezes
    // on its last frame until scheduleAdvance()'s timeout fires.
};

// The advance countdown starts when a slide actually appears (trySwap), not
// here — the first slide may still be loading.
const startSlideshow = () => {
    slideshowInterval.value = getSlideshowInterval();
};

const stopSlideshow = () => {
    clearAdvanceTimer();
};

// Every manual nav or auto-advance goes through here. The countdown is
// stopped while the target slide gets ready and restarts when it appears
// (trySwap); landing on the slide already showing (restartShow() while on
// slide 1, a one-slide show) just restarts it.
const goToIndex = (index) => {
    const len = slidesList.value.length;
    if (len === 0) return;
    const target = ((index % len) + len) % len;
    clearAdvanceTimer();
    if (target === currentIndex.value && activeStage.value) {
        swapRequested = false;
        scheduleAdvance();
        prefetchNext();
    } else {
        swapRequested = true;
        ensureIncoming(target);
        trySwap();
    }
    // A slide change makes any in-progress seek indicator stale (wrong video).
    if (seekHideTimer) { clearTimeout(seekHideTimer); seekHideTimer = null; }
    showSeekIndicator.value = false;
};

const nextSlide = () => goToIndex(targetIndex() + 1);

const prevSlide = () => goToIndex(targetIndex() - 1);

// Left/Right (and MediaRewind/MediaFastForward) seek a playing video by
// SEEK_STEP_SECONDS; a second press in the same direction within
// SEEK_RAPID_WINDOW_MS escalates to SEEK_FAST_STEP_SECONDS so a long video
// can be scrubbed quickly. On a non-video slide there's nothing to seek, so
// these keys fall back to plain next/prev slide navigation instead.
const seekOrGoToIndex = (direction) => {
    const el = activeVideo();
    if (!el) {
        goToIndex(targetIndex() + direction);
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
        const el = activeVideo();
        if (el) {
            el.currentTime = 0;
            if (!isPaused.value) el.play().catch(() => {});
        }
    }
};

const togglePause = () => {
    isPaused.value = !isPaused.value;
    const el = activeVideo();
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
    if (props.show && !props.kiosk) {
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
        if (props.kiosk) return;
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
    leaveTimers.forEach(clearTimeout);
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
        if (props.kiosk) {
            // Nothing to show or hide, and no gesture to enter fullscreen with.
            showControls.value = false;
            return;
        }
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
        appear
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
                <ShowFrameLayer v-if="frame?.file_url || frame?.youtube_url" :frame="frame" layer="base" />
                <SlideStage
                    v-for="stage in stages"
                    :key="stage.id"
                    :ref="(instance) => setStageRef(stage.id, instance)"
                    :slide="stage.slide"
                    :visible="stage.visible"
                    :active="stage.id === activeId"
                    @ready="onStageReady(stage.id)"
                    @ended="onStageEnded(stage.id)"
                />

                <ShowFrameLayer v-if="frame && (frame.overlay_url || frame.overlay_widgets?.length)" :frame="frame" layer="top" />

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
                <div v-if="!kiosk" class="absolute bottom-8 left-1/2 transform -translate-x-1/2 flex items-center gap-4 bg-black/50 px-6 py-3 rounded-full backdrop-blur transition-opacity duration-300" :class="showControls ? 'opacity-100' : 'opacity-0 pointer-events-none'">
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
                    v-if="!kiosk"
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
