<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import SlideCard from '@/Components/SlideCard.vue';
import Dropdown from '@/Components/Dropdown.vue';
import SlideshowModal from '@/Components/SlideshowModal.vue';
import { useI18n } from 'vue-i18n';

const { t, locale } = useI18n();

const props = defineProps({
    slides: { type: Array, default: () => [] },
    languages: { type: Array, default: () => [] },
    selectedLanguage: { type: String, default: null },
});

const currentLanguageCode = computed(() => props.selectedLanguage || locale.value);

function changeLanguage(code) {
    console.log('[Index] changeLanguage called with:', code);
    router.get(route('slides.index'), { language: code });
}

const selectedIds = ref(new Set());

function toggleSelect(id) {
    if (selectedIds.value.has(id)) {
        selectedIds.value.delete(id);
    } else {
        selectedIds.value.add(id);
    }
    // Force reactivity
    selectedIds.value = new Set(selectedIds.value);
}

function selectAll() {
    selectedIds.value = new Set(props.slides.map(s => s.id));
}

function clearSelection() {
    selectedIds.value = new Set();
}

const hasSelection = computed(() => selectedIds.value.size > 0);

function downloadSelected() {
    const ids = [...selectedIds.value].join(',');
    const params = new URLSearchParams();
    if (ids) params.append('ids', ids);
    if (props.selectedLanguage) params.append('language', props.selectedLanguage);
    window.location.href = route('slides.download-zip') + (params.toString() ? `?${params.toString()}` : '');
}

function downloadAll() {
    const params = new URLSearchParams();
    if (props.selectedLanguage) params.append('language', props.selectedLanguage);
    window.location.href = route('slides.download-zip') + (params.toString() ? `?${params.toString()}` : '');
}

// Slideshow
const showSlideshow = ref(false);
const slideshowSlides = ref([]);

function openSlideshowSelected() {
    slideshowSlides.value = props.slides.filter(s => selectedIds.value.has(s.id));
    showSlideshow.value = true;
}

function openSlideshowAll() {
    slideshowSlides.value = props.slides;
    showSlideshow.value = true;
}

// Lightbox
const lightboxSlide = ref(null);

function openLightbox(slide) {
    lightboxSlide.value = slide;
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    lightboxSlide.value = null;
    document.body.style.overflow = '';
}

function onLightboxKeydown(e) {
    if (e.key === 'Escape') closeLightbox();
}

onMounted(() => document.addEventListener('keydown', onLightboxKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', onLightboxKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <PublicLayout>
        <head><title>Announcement Slides</title></head>

        <div class="mb-8 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $t('slides.announcement_slides_title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ slides.length }} {{ slides.length === 1 ? $t('slides.slides_available').split('|')[0] : $t('slides.slides_available').split('|')[1] }}
                </p>
            </div>

            <div class="flex flex-col sm:flex-row flex-wrap gap-3 items-start sm:items-center">
                <select :value="currentLanguageCode" @change="changeLanguage($event.target.value)"
                    class="rounded-lg border border-gray-300 px-3 py-2 pr-8 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 appearance-none bg-white bg-no-repeat bg-right">
                    <option v-for="lang in languages" :key="lang.abbreviation" :value="lang.abbreviation">
                        {{ lang.name }} ({{ lang.native_name }})
                    </option>
                </select>

                <div class="flex flex-wrap gap-2">
                <template v-if="hasSelection">
                    <button @click="clearSelection"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                        {{ $t('slides.clear') }} ({{ selectedIds.size }})
                    </button>
                    <Dropdown align="left" width="48" contentClasses="py-1 bg-white">
                        <template #trigger>
                            <button class="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 19l-7-7 7-7" />
                                </svg>
                                Show/Download Selected ({{ selectedIds.size }})
                            </button>
                        </template>
                        <template #content>
                            <button @click="openSlideshowSelected"
                                class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none">
                                Slideshow
                            </button>
                            <a @click="downloadSelected"
                                class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none cursor-pointer">
                                Download (.zip)
                            </a>
                        </template>
                    </Dropdown>
                </template>
                <template v-else>
                    <button @click="selectAll"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                        {{ $t('slides.select_all') }}
                    </button>
                    <Dropdown v-if="slides.length" align="left" width="48" contentClasses="py-1 bg-white">
                        <template #trigger>
                            <button class="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 19l-7-7 7-7" />
                                </svg>
                                Show/Download All
                            </button>
                        </template>
                        <template #content>
                            <button @click="openSlideshowAll"
                                class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none">
                                Slideshow
                            </button>
                            <a @click="downloadAll"
                                class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none cursor-pointer">
                                Download (.zip)
                            </a>
                        </template>
                    </Dropdown>
                </template>
                </div>
            </div>
        </div>

        <!-- Grid -->
        <div v-if="slides.length" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            <SlideCard
                v-for="slide in slides"
                :key="slide.id"
                :slide="slide"
                :selectable="true"
                :selected="selectedIds.has(slide.id)"
                @toggle-select="toggleSelect"
                @open="openLightbox"
            />
        </div>

        <div v-else class="mt-16 text-center">
            <svg class="mx-auto h-16 w-16 text-gray-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            <p class="mt-4 text-lg font-medium text-gray-500">{{ $t('slides.no_slides_available') }}</p>
            <p class="text-sm text-gray-400">{{ $t('slides.check_back_soon') }}</p>
        </div>
        <!-- Slideshow Modal -->
        <SlideshowModal :show="showSlideshow" :slides="slideshowSlides" @close="showSlideshow = false" />

        <!-- Lightbox -->
        <Teleport to="body">
            <Transition
                enter-active-class="duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="lightboxSlide"
                    class="fixed inset-0 z-50 flex flex-col items-center justify-center bg-black/85 p-4"
                    @click.self="closeLightbox">

                    <!-- Close button -->
                    <button @click="closeLightbox"
                        class="absolute top-4 right-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20 transition-colors"
                        aria-label="Close">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>

                    <!-- Image -->
                    <img :src="lightboxSlide.file_url || lightboxSlide.thumbnail_url"
                        :alt="lightboxSlide.title"
                        class="max-h-[80vh] max-w-full rounded-lg object-contain shadow-2xl" />

                    <!-- Caption + download -->
                    <div class="mt-4 flex items-center gap-4">
                        <p class="text-white font-medium text-lg">{{ lightboxSlide.title }}</p>
                        <a :href="route('slides.download', lightboxSlide.id)"
                            class="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            {{ $t('slides.download') }}
                        </a>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </PublicLayout>
</template>
