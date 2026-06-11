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
const slidesList = computed(() => {
    if (props.slides && props.slides.length > 0) {
        return props.slides;
    }
    return props.slide ? [props.slide] : [];
});
const interval = ref(null);
const slideshowInterval = ref(10000); // Default 10 seconds

const currentSlide = computed(() => slidesList.value[currentIndex.value]);

const getSlideshowInterval = () => {
    if (typeof window !== 'undefined' && localStorage) {
        const stored = localStorage.getItem('slideshowInterval');
        return stored ? parseInt(stored) * 1000 : 10000;
    }
    return 10000;
};

const startSlideshow = () => {
    slideshowInterval.value = getSlideshowInterval();
    if (interval.value) clearInterval(interval.value);

    interval.value = setInterval(() => {
        if (!isPaused.value) {
            currentIndex.value = (currentIndex.value + 1) % slidesList.value.length;
        }
    }, slideshowInterval.value);
};

const stopSlideshow = () => {
    if (interval.value) {
        clearInterval(interval.value);
        interval.value = null;
    }
};

const nextSlide = () => {
    currentIndex.value = (currentIndex.value + 1) % slidesList.value.length;
};

const prevSlide = () => {
    currentIndex.value = (currentIndex.value - 1 + slidesList.value.length) % slidesList.value.length;
};

const togglePause = () => {
    isPaused.value = !isPaused.value;
};

const handleKeydown = (e) => {
    if (!props.show) return;

    if (e.key === 'Escape') emit('close');
    if (e.key === 'ArrowRight' || e.key === ' ') nextSlide();
    if (e.key === 'ArrowLeft') prevSlide();
    if (e.key === 'p' || e.key === 'P') togglePause();
};

onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown);
    stopSlideshow();
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
    } else {
        stopSlideshow();
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
        <div v-if="show" class="fixed inset-0 bg-black z-50 flex items-center justify-center">
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
                    <img
                        :key="currentIndex"
                        :src="currentSlide.file_url"
                        :alt="currentSlide.title"
                        class="max-h-full max-w-full object-contain"
                    />
                </transition>

                <!-- Controls -->
                <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 flex items-center gap-4 bg-black/50 px-6 py-3 rounded-full backdrop-blur">
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
                    class="absolute top-8 right-8 text-white hover:text-gray-300 transition p-2 bg-black/50 rounded-full backdrop-blur"
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
