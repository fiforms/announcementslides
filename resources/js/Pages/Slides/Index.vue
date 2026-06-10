<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import SlideCard from '@/Components/SlideCard.vue';

const props = defineProps({
    slides: { type: Array, default: () => [] },
});

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
    window.location.href = route('slides.download-zip') + (ids ? `?ids=${ids}` : '');
}

function downloadAll() {
    window.location.href = route('slides.download-zip');
}
</script>

<template>
    <PublicLayout>
        <head><title>Announcement Slides</title></head>

        <div class="mb-8 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Current Announcement Slides</h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ slides.length }} slide{{ slides.length === 1 ? '' : 's' }} available
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <template v-if="hasSelection">
                    <button @click="clearSelection"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                        Clear ({{ selectedIds.size }})
                    </button>
                    <button @click="downloadSelected"
                        class="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Download Selected ({{ selectedIds.size }})
                    </button>
                </template>
                <template v-else>
                    <button @click="selectAll"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                        Select All
                    </button>
                    <button v-if="slides.length" @click="downloadAll"
                        class="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Download All (.zip)
                    </button>
                </template>
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
            />
        </div>

        <div v-else class="mt-16 text-center">
            <svg class="mx-auto h-16 w-16 text-gray-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            <p class="mt-4 text-lg font-medium text-gray-500">No slides available right now</p>
            <p class="text-sm text-gray-400">Check back soon for upcoming announcements.</p>
        </div>
    </PublicLayout>
</template>
