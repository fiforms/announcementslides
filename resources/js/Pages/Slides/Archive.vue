<script setup>
import { ref, computed, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import SlideCard from '@/Components/SlideCard.vue';
import { useI18n } from 'vue-i18n';

const { t, locale } = useI18n();

const props = defineProps({
    slides: { type: Object, required: true }, // paginated
    languages: { type: Array, default: () => [] },
    search: { type: String, default: '' },
    selectedLanguage: { type: String, default: null },
});

const searchQuery = ref(props.search);
const currentLanguageCode = computed(() => props.selectedLanguage || locale.value);
let searchTimeout = null;

function onSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        const params = { search: searchQuery.value || undefined };
        if (currentLanguageCode.value) params.language = currentLanguageCode.value;
        router.get(route('slides.archive'), params, {
            preserveState: true,
            replace: true,
        });
    }, 350);
}

function changeLanguage(code) {
    const params = { language: code };
    if (searchQuery.value) params.search = searchQuery.value;
    router.get(route('slides.archive'), params, {
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <PublicLayout>
        <head><title>Slide Archive</title></head>

        <div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $t('slides.archive_title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $t('slides.archive_description') }}</p>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center w-full sm:w-auto">
                <select :value="currentLanguageCode" @change="changeLanguage($event.target.value)"
                    class="rounded-lg border border-gray-300 px-3 py-2 pr-8 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 appearance-none bg-white bg-no-repeat bg-right flex-shrink-0">
                    <option v-for="lang in languages" :key="lang.abbreviation" :value="lang.abbreviation">
                        {{ lang.name }} ({{ lang.native_name }})
                    </option>
                </select>

                <input v-model="searchQuery" @input="onSearch" type="search" :placeholder="$t('slides.search_placeholder')"
                    class="w-full sm:w-64 rounded-lg border border-gray-300 px-4 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
            </div>
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
                {{ search ? $t('slides.no_results') : $t('slides.no_archived_slides') }}
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
