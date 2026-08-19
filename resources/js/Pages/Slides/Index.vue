<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import SlideCard from '@/Components/SlideCard.vue';
import Dropdown from '@/Components/Dropdown.vue';
import SlideshowModal from '@/Components/SlideshowModal.vue';
import SlideLightbox from '@/Components/SlideLightbox.vue';
import { useLightbox } from '@/Composables/useLightbox.js';
import { useI18n } from 'vue-i18n';

const { t, locale } = useI18n();

const props = defineProps({
    slides: { type: Array, default: () => [] },
    languages: { type: Array, default: () => [] },
    selectedLanguage: { type: String, default: null },
    entityId: { type: Number, default: null },
    showId: { type: Number, default: null },
    availableShows: { type: Array, default: () => [] },
});

const currentLanguageCode = computed(() => props.selectedLanguage || locale.value);

// Reload the dashboard preserving the current entity / language / show state,
// overriding whichever value changed.
function reloadWith(overrides = {}) {
    const params = {
        language: currentLanguageCode.value,
        ...(props.entityId ? { entity_id: props.entityId } : {}),
        ...(props.showId ? { show_id: props.showId } : {}),
        ...overrides,
    };
    router.get(route('slides.index'), params, { preserveScroll: true });
}

function changeLanguage(code) {
    reloadWith({ language: code });
}

function changeShow(showId) {
    reloadWith({ show_id: showId });
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
    if (props.showId) params.append('show_id', props.showId);
    window.location.href = route('slides.download-zip') + (params.toString() ? `?${params.toString()}` : '');
}

function downloadPowerPointSelected() {
    const ids = [...selectedIds.value].join(',');
    const params = new URLSearchParams();
    if (ids) params.append('ids', ids);
    if (props.selectedLanguage) params.append('language', props.selectedLanguage);
    window.location.href = route('slides.download-pptx') + (params.toString() ? `?${params.toString()}` : '');
}

function downloadPowerPointAll() {
    const params = new URLSearchParams();
    if (props.selectedLanguage) params.append('language', props.selectedLanguage);
    if (props.showId) params.append('show_id', props.showId);
    window.location.href = route('slides.download-pptx') + (params.toString() ? `?${params.toString()}` : '');
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

const { lightboxSlide, openLightbox, closeLightbox } = useLightbox();
</script>

<template>
    <PublicLayout>
        <head><title>Announcement Slides</title></head>

        <div class="mb-8 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $t('slides.announcement_slides_title') }}</h1>
            </div>

            <div class="flex flex-col sm:flex-row flex-wrap gap-3 items-start sm:items-center">
                <select v-if="!entityId" :value="currentLanguageCode" @change="changeLanguage($event.target.value)"
                    class="rounded-lg border border-gray-300 px-3 py-2 pr-8 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 appearance-none bg-white bg-no-repeat bg-right">
                    <option v-for="lang in languages" :key="lang.abbreviation" :value="lang.abbreviation">
                        {{ lang.name }} ({{ lang.native_name }})
                    </option>
                </select>

                <select v-if="availableShows.length > 1" :value="showId" @change="changeShow($event.target.value)"
                    class="rounded-lg border border-gray-300 px-3 py-2 pr-8 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 appearance-none bg-white bg-no-repeat bg-right">
                    <option v-for="show in availableShows" :key="show.id" :value="show.id">{{ show.name }}</option>
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
                                Image Download (.zip)
                            </a>
                            <a @click="downloadPowerPointSelected"
                                class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none cursor-pointer">
                                PowerPoint Download (.pptx)
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
                                Image Download (.zip)
                            </a>
                            <a @click="downloadPowerPointAll"
                                class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none cursor-pointer">
                                PowerPoint Download (.pptx)
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
                :show-expiry="false"
                :show-validation-warning="false"
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

        <SlideLightbox :slide="lightboxSlide" @close="closeLightbox" />
    </PublicLayout>
</template>
