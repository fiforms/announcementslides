<script setup>
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SlideCard from '@/Components/SlideCard.vue';
import LibraryBuilder from '@/Components/LibraryBuilder.vue';
import SlideUploadPanel from '@/Components/SlideUploadPanel.vue';

const props = defineProps({
    current:   { type: Array, default: () => [] },
    pending:   { type: Array, default: () => [] },
    upcoming:  { type: Array, default: () => [] },
    archived:  { type: Object, default: () => ({ data: [], total: 0, last_page: 1, links: [] }) }, // paginated
    drafts:    { type: Array, default: () => [] },
    languages: { type: Array, default: () => [] },
    globalShowTemplates: { type: Array, default: () => [] },
});

const { t } = useI18n();

const showUploadPanel = ref(false);
const showBuilder = ref(false);
function onBuilt(result) {
    showBuilder.value = false;
    router.visit(route('admin.slides.edit', result.slide_id));
}

// ── Slide actions ─────────────────────────────────────────────────────────────

// "Copy sharing link": the slide's public /slides/<token> URL.
const copiedSlideId = ref(null);

async function copyLink(slide) {
    try {
        await navigator.clipboard.writeText(slide.canonical_url);
        copiedSlideId.value = slide.id;
        setTimeout(() => { if (copiedSlideId.value === slide.id) copiedSlideId.value = null; }, 1500);
    } catch {
        window.prompt(t('admin.copy_link'), slide.canonical_url);
    }
}

// Double-clicking a row opens the editor (but not when the double-click lands on one of its own buttons/links).
function editSlide(slide, event) {
    if (event.target.closest('a, button, input, select, textarea')) return;
    window.getSelection()?.removeAllRanges();
    router.visit(route('admin.slides.edit', slide.id));
}

function approve(slide) {
    router.post(route('admin.slides.approve', slide.id));
}

function reject(slide) {
    if (confirm(`Reject "${slide.title}"?`)) {
        router.post(route('admin.slides.reject', slide.id));
    }
}

function archive(slide) {
    if (confirm(`Archive "${slide.title}"? It will move to the archived tab.`)) {
        router.post(route('admin.slides.archive', slide.id));
    }
}

function unarchive(slide) {
    if (confirm(`Restore "${slide.title}"? It will be active again.`)) {
        router.post(route('admin.slides.unarchive', slide.id));
    }
}

function destroy(slide) {
    if (confirm(`Delete "${slide.title}"? This cannot be undone.`)) {
        router.delete(route('admin.slides.destroy', slide.id));
    }
}

// ── Active tab ────────────────────────────────────────────────────────────────

const tabs = computed(() => [
    { key: 'current',  label: 'live',     count: props.current.length },
    { key: 'pending',  label: 'pending',  count: props.pending.length },
    { key: 'upcoming', label: 'upcoming', count: props.upcoming.length },
    { key: 'drafts',   label: 'drafts',   count: props.drafts.length },
    { key: 'archived', label: 'archived', count: props.archived.total },
]);

const activeTab = ref('current');

// The "current" tab is ordered by the Global Board's automatic fan-out
// order (see App\Support\SortZones) — no manual reordering here anymore.
// "archived" is server-paginated, so it's the one tab whose list lives at
// .data rather than being the prop itself.
const activeSlides = computed(() => activeTab.value === 'archived' ? props.archived.data : props[activeTab.value]);

function displayStatus(slide) {
    // Archived slides keep their DB status (usually "published"); show the
    // archived state explicitly while on that tab.
    return activeTab.value === 'archived' ? 'archived' : slide.status;
}

function statusBadge(status) {
    const map = {
        published: 'bg-green-100 text-green-800',
        pending:   'bg-yellow-100 text-yellow-800',
        draft:     'bg-gray-100 text-gray-700',
        rejected:  'bg-red-100 text-red-700',
        archived:  'bg-slate-100 text-slate-700',
    };
    return map[status] ?? 'bg-gray-100 text-gray-700';
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between w-full">
                <h1 class="text-xl font-semibold text-gray-900">{{ $t('nav.slides') }}</h1>
                <div class="flex items-center gap-3">
                <button @click="showBuilder = true"
                    class="rounded-lg border border-indigo-200 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50">
                    {{ $t('library.builder_button') }}
                </button>
                <button @click="showUploadPanel = !showUploadPanel"
                    class="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    {{ $t('admin.upload_slides') }}
                </button>
                </div>
            </div>
        </template>

        <LibraryBuilder v-if="showBuilder" @built="onBuilt" @close="showBuilder = false" />

        <!-- Upload Panel -->
        <SlideUploadPanel v-if="showUploadPanel" :languages="languages" :global-show-templates="globalShowTemplates"
            @cancel="showUploadPanel = false" @done="showUploadPanel = false" />

        <!-- Tabs -->
        <div class="border-b border-gray-200 mb-6">
            <nav class="-mb-px flex gap-6">
                <button v-for="tab in tabs" :key="tab.key"
                    @click="activeTab = tab.key"
                    class="pb-3 text-sm font-medium border-b-2 transition-colors"
                    :class="activeTab === tab.key
                        ? 'border-indigo-600 text-indigo-600'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'">
                    {{ $t(`admin.tab_${tab.label}`) }}
                    <span class="ml-1.5 rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="tab.count > 0 ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500'">
                        {{ tab.count }}
                    </span>
                </button>
            </nav>
        </div>

        <!-- Slide list table -->
        <div v-if="activeSlides.length" class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('admin.col_slide') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">{{ $t('admin.status') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('admin.col_dates') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">{{ $t('admin.col_uploaded_by') }}</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">{{ $t('admin.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="slide in activeSlides" :key="slide.id" class="hover:bg-gray-50 transition-colors cursor-pointer"
                        @dblclick="editSlide(slide, $event)">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-12 w-20 flex-shrink-0 rounded overflow-hidden bg-slate-100">
                                    <img v-if="slide.thumbnail_url || (slide.file_url && !slide.mime_type?.startsWith('video/'))"
                                        :src="slide.thumbnail_url || slide.file_url"
                                        class="h-full w-full object-cover" />
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <p class="font-medium text-gray-900 line-clamp-1">{{ slide.title }}</p>
                                        <svg v-if="slide.validation_issues?.length" class="h-4 w-4 text-yellow-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" title="Image quality warnings detected">
                                            <path fill-rule="evenodd"
                                                d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                                clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <p v-if="slide.entity" class="text-xs text-indigo-600 font-medium mt-0.5">{{ slide.entity.name }}</p>
                                    <p v-if="slide.validation_issues?.length" class="text-xs text-yellow-700 mt-0.5">
                                        <span v-for="(issue, idx) in slide.validation_issues" :key="idx" class="block">• {{ issue }}</span>
                                    </p>
                                    <p v-if="slide.notes" class="text-xs text-gray-400 line-clamp-1 mt-0.5">{{ slide.notes }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold capitalize"
                                :class="statusBadge(displayStatus(slide))">
                                {{ displayStatus(slide) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500 space-y-0.5">
                            <div v-if="slide.publish_at">{{ $t('admin.from') }} {{ new Date(slide.publish_at).toLocaleDateString() }}</div>
                            <div v-if="slide.expires_at">{{ $t('admin.expiration') }} {{ new Date(slide.expires_at).toLocaleDateString() }}</div>
                            <div v-if="!slide.publish_at && !slide.expires_at" class="text-gray-300">{{ $t('admin.always_on') }}</div>
                        </td>
                        <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-500">
                            {{ slide.uploader?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <template v-if="slide.status === 'pending'">
                                    <button @click="approve(slide)"
                                        class="rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700 transition-colors">
                                        {{ $t('admin.approve') }}
                                    </button>
                                    <button @click="reject(slide)"
                                        class="rounded-md border border-red-300 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50 transition-colors">
                                        {{ $t('admin.reject') }}
                                    </button>
                                </template>
                                <button type="button" @click="copyLink(slide)"
                                    :title="copiedSlideId === slide.id ? $t('admin.link_copied') : $t('admin.copy_link')"
                                    :aria-label="$t('admin.copy_link')"
                                    class="rounded-md border border-gray-300 p-1.5 text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition-colors">
                                    <svg v-if="copiedSlideId === slide.id" class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                    </svg>
                                </button>
                                <Link :href="route('admin.slides.edit', slide.id)"
                                    class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                    {{ $t('admin.edit') }}
                                </Link>
                                <button v-if="activeTab === 'archived'" @click="unarchive(slide)"
                                    class="rounded-md border border-blue-200 px-2.5 py-1 text-xs font-medium text-blue-700 hover:bg-blue-50 transition-colors">
                                    {{ $t('admin.restore') }}
                                </button>
                                <button v-if="activeTab === 'archived'" @click="destroy(slide)"
                                    class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition-colors">
                                    {{ $t('admin.delete') }}
                                </button>
                                <button v-else @click="archive(slide)"
                                    class="rounded-md border border-amber-200 px-2.5 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 transition-colors">
                                    {{ $t('admin.archive') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="mt-8 rounded-xl border-2 border-dashed border-gray-200 py-16 text-center">
            <p class="text-gray-400">{{ $t('admin.no_slides_in_category') }}</p>
        </div>

        <!-- Pagination (archived tab only — every other tab is a single unpaginated fetch) -->
        <div v-if="activeTab === 'archived' && archived.last_page > 1" class="mt-6 flex justify-center gap-1">
            <template v-for="link in archived.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" preserve-state preserve-scroll :only="['archived']"
                    class="px-3 py-1.5 rounded-md text-sm border transition-colors"
                    :class="link.active ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                    v-html="link.label" />
                <span v-else class="px-3 py-1.5 rounded-md text-sm border border-gray-200 text-gray-400" v-html="link.label" />
            </template>
        </div>
    </AdminLayout>
</template>
