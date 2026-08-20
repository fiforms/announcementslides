<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ShowSlideRow from '@/Components/ShowSlideRow.vue';
import SlideLightbox from '@/Components/SlideLightbox.vue';
import { useLightbox } from '@/Composables/useLightbox.js';
import { useI18n } from 'vue-i18n';

const { t, locale } = useI18n();

const props = defineProps({
    slides: { type: Object, required: true }, // paginated
    languages: { type: Array, default: () => [] },
    search: { type: String, default: '' },
    selectedLanguage: { type: String, default: null },
    entityId: { type: Number, default: null },
    isAdmin: { type: Boolean, default: false },
});

const searchQuery = ref(props.search);
const currentLanguageCode = computed(() => props.selectedLanguage || locale.value);
let searchTimeout = null;

// Matches Shows/Manage.vue's own scope badge: relative to the entity this
// page was reached for (props.entityId), not the individual slide's owner.
const scopeBadges = {
    global: { label: 'Global', classes: 'bg-blue-50 text-blue-700', dot: 'bg-blue-500' },
    nearby: { label: 'Nearby', classes: 'bg-purple-50 text-purple-700', dot: 'bg-purple-500' },
    mine:   { label: 'Mine',   classes: 'bg-green-50 text-green-700', dot: 'bg-green-500' },
};

function scopeBadge(slide) {
    if (slide.entity_id === null) return scopeBadges.global;
    if (slide.entity_id === props.entityId) return scopeBadges.mine;
    return scopeBadges.nearby;
}

function expiresLabel(slide) {
    if (!slide.expires_at) return null;
    const dateStr = new Date(slide.expires_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    return `Expired ${dateStr}`;
}

// Un-archiving only makes sense for a slide this entity actually owns — a
// global or nearby-shared slide's archival is someone else's call.
function canUnarchive(slide) {
    return props.isAdmin && slide?.entity_id === props.entityId;
}

function unarchiveSlide(slide) {
    router.post(route('slides.unarchive', slide.id), {}, {
        preserveScroll: true,
        onSuccess: () => closeLightbox(),
    });
}

function onSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        const params = { search: searchQuery.value || undefined };
        if (currentLanguageCode.value) params.language = currentLanguageCode.value;
        if (props.entityId) params.entity_id = props.entityId;
        router.get(route('slides.archive'), params, {
            preserveState: true,
            replace: true,
        });
    }, 350);
}

function changeLanguage(code) {
    const params = { language: code };
    if (searchQuery.value) params.search = searchQuery.value;
    if (props.entityId) params.entity_id = props.entityId;
    router.get(route('slides.archive'), params, {
        preserveState: true,
        replace: true,
    });
}

const { lightboxSlide, openLightbox, closeLightbox } = useLightbox();
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

        <div v-if="slides.data.length" class="mx-auto max-w-3xl space-y-2">
            <ShowSlideRow
                v-for="slide in slides.data"
                :key="slide.id"
                :slide="slide"
                :scope-badge="scopeBadge(slide)"
                :expires-label="expiresLabel(slide)"
                :draggable="false"
                @open="openLightbox"
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

        <SlideLightbox :slide="lightboxSlide" :can-unarchive="canUnarchive(lightboxSlide)"
            @close="closeLightbox" @unarchive="unarchiveSlide" />
    </PublicLayout>
</template>
