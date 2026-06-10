<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import SlideCard from '@/Components/SlideCard.vue';

const props = defineProps({
    slides: { type: Object, required: true }, // paginated
    search: { type: String, default: '' },
});

const searchQuery = ref(props.search);
let searchTimeout = null;

function onSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('slides.archive'), { search: searchQuery.value || undefined }, {
            preserveState: true,
            replace: true,
        });
    }, 350);
}
</script>

<template>
    <PublicLayout>
        <head><title>Slide Archive</title></head>

        <div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Slide Archive</h1>
                <p class="mt-1 text-sm text-gray-500">Past slides that have expired.</p>
            </div>

            <input v-model="searchQuery" @input="onSearch" type="search" placeholder="Search by title or notes…"
                class="w-full sm:w-64 rounded-lg border border-gray-300 px-4 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
        </div>

        <div v-if="slides.data.length" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            <SlideCard
                v-for="slide in slides.data"
                :key="slide.id"
                :slide="slide"
                :show-download="true"
            />
        </div>

        <div v-else class="mt-16 text-center">
            <p class="text-lg font-medium text-gray-500">
                {{ search ? 'No results for your search.' : 'No archived slides yet.' }}
            </p>
        </div>

        <!-- Pagination -->
        <div v-if="slides.last_page > 1" class="mt-10 flex justify-center gap-1">
            <template v-for="link in slides.links" :key="link.label">
                <a v-if="link.url" :href="link.url"
                    class="px-3 py-1.5 rounded-md text-sm border transition-colors"
                    :class="link.active ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                    v-html="link.label" />
                <span v-else class="px-3 py-1.5 rounded-md text-sm border border-gray-200 text-gray-400" v-html="link.label" />
            </template>
        </div>
    </PublicLayout>
</template>
