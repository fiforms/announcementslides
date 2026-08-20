<script setup>
import { ref, computed, watch } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UploadPanel from '@/Components/UploadPanel.vue';

const props = defineProps({
    entity: { type: Object, required: true },
    slides: { type: Array, default: () => [] },
    languages: { type: Array, default: () => [] },
    isAdmin: { type: Boolean, default: false },
    shows: { type: Array, default: () => [] },
});

const page = usePage();
const user = page.props.auth.user;

const showUploadPanel = ref(false);
const showArchived = ref(false);
const orderedSlides = ref([...props.slides]);

// Re-sync the local working copy whenever the server returns updated slides
// (e.g. after archiving or toggling "share nearby"), so buttons reflect the
// new state without a manual refresh. Drag reordering uses a fetch() call that
// does not change props, so it won't be clobbered mid-interaction.
watch(() => props.slides, (slides) => {
    orderedSlides.value = [...slides];
});

const activeSlides = computed(() => {
    return orderedSlides.value.filter(s => !isSlideArchived(s));
});

const archivedSlides = computed(() => {
    return orderedSlides.value
        .filter(s => isSlideArchived(s))
        .sort((a, b) => new Date(b.expires_at) - new Date(a.expires_at));
});

function canEdit(slide) {
    return props.isAdmin && (user.role === 'admin' || slide.uploader?.id === user.id);
}

function archive(slide) {
    if (confirm(`Archive "${slide.title}"? It will no longer be visible.`)) {
        router.post(route('local-slides.archive', { slide: slide.id, entity_id: props.entity.id }));
    }
}

function unarchive(slide) {
    if (confirm(`Restore "${slide.title}"? It will be visible again.`)) {
        router.post(route('local-slides.unarchive', { slide: slide.id, entity_id: props.entity.id }));
    }
}

function toggleShareNearby(slide) {
    // Turning sharing ON requires the slide to meet quality requirements, since
    // it will appear on nearby congregations' dashboards. The server enforces
    // this too; this is just immediate feedback.
    if (!slide.share_nearby && slide.validation_status && slide.validation_status !== 'ok') {
        alert(
            'This slide can\'t be shared with nearby churches because it doesn\'t meet the quality requirements:\n\n'
            + (slide.validation_issues || []).join('\n')
        );
        return;
    }
    const routeName = slide.share_nearby ? 'local-slides.unshare-nearby' : 'local-slides.share-nearby';
    router.post(route(routeName, { slide: slide.id, entity_id: props.entity.id }), {}, { preserveScroll: true });
}

function isSlideArchived(slide) {
    return slide.expires_at && new Date(slide.expires_at) <= new Date();
}

function getSlideStatus(slide) {
    return isSlideArchived(slide) ? 'archived' : slide.status;
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
    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
            <!-- Upload Panel Toggle Button -->
            <div v-if="isAdmin" class="flex justify-end gap-3">
                <Link :href="route('shows.index', { entity_id: entity.id })"
                    class="flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                    Manage Shows
                </Link>
                <Link :href="route('slide-announcers.index', { entity_id: entity.id })"
                    class="flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                    Slide Announcer Devices
                </Link>
                <button @click="showUploadPanel = !showUploadPanel"
                    class="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Upload Slide
                </button>
            </div>

            <div v-if="isAdmin" class="rounded-lg bg-indigo-50 border border-indigo-100 px-4 py-3 text-sm text-indigo-700">
                Slides uploaded here are visible only to members of <strong>{{ entity.name }}</strong> (and nearby churches, if applicable).
            </div>

            <div v-else class="rounded-lg bg-blue-50 border border-blue-100 px-4 py-3 text-sm text-blue-700">
                You are viewing slides for <strong>{{ entity.name }}</strong>, but you don't have permission to upload or edit. Contact an entity administrator if you need access.
            </div>

            <UploadPanel
                v-if="showUploadPanel && isAdmin"
                :redirect-route="'local-slides.index'"
                :redirect-params="{ entity_id: entity.id }"
                :entity-id="entity.id"
                :languages="languages"
                :shows="shows"
                @success="showUploadPanel = false"
            />

            <!-- Active Slides -->
            <div v-if="activeSlides.length" class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Slide</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">Status</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">Dates</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">Uploaded by</th>
                            <th v-if="isAdmin" class="px-4 py-3 text-right font-medium text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="slide in activeSlides" :key="slide.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="h-12 w-20 flex-shrink-0 rounded overflow-hidden bg-slate-100">
                                        <img v-if="slide.thumbnail_url || (slide.file_url && !slide.mime_type?.startsWith('video/'))"
                                            :src="slide.thumbnail_url || slide.file_url"
                                            class="h-full w-full object-cover" />
                                    </div>
                                    <p class="font-medium text-gray-900 line-clamp-1">{{ slide.title }}</p>
                                </div>
                            </td>
                            <td class="px-4 py-3 hidden sm:table-cell">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold capitalize"
                                    :class="statusBadge(getSlideStatus(slide))">
                                    {{ getSlideStatus(slide) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500 space-y-0.5">
                                <div v-if="slide.publish_at">From: {{ new Date(slide.publish_at).toLocaleDateString() }}</div>
                                <div v-if="slide.expires_at">Exp: {{ new Date(slide.expires_at).toLocaleDateString() }}</div>
                                <div v-if="!slide.publish_at && !slide.expires_at" class="text-gray-300">Always on</div>
                            </td>
                            <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-500">
                                {{ slide.uploader?.name ?? '—' }}
                            </td>
                            <td v-if="isAdmin" class="px-4 py-3 text-right">
                                <div v-if="canEdit(slide)" class="flex items-center justify-end gap-2">
                                    <Link :href="route('local-slides.edit', { slide: slide.id, entity_id: entity.id })"
                                        class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                        Edit
                                    </Link>
                                    <button @click="toggleShareNearby(slide)"
                                        :title="slide.share_nearby ? 'Stop sharing with nearby churches' : 'Share with nearby churches'"
                                        class="rounded-md border px-2.5 py-1 text-xs font-medium transition-colors"
                                        :class="slide.share_nearby
                                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                                            : 'border-gray-300 text-gray-700 hover:bg-gray-50'">
                                        {{ slide.share_nearby ? 'Sharing nearby' : 'Share nearby' }}
                                    </button>
                                    <button @click="archive(slide)"
                                        class="rounded-md border border-amber-200 px-2.5 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 transition-colors">
                                        Archive
                                    </button>
                                </div>
                                <span v-else class="text-xs text-gray-300">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Archived Slides Toggle & Section -->
            <div v-if="archivedSlides.length" class="space-y-4">
                <button @click="showArchived = !showArchived"
                    class="flex items-center gap-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition-colors">
                    <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-90': showArchived }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                    Show archived slides ({{ archivedSlides.length }})
                </button>

                <div v-if="showArchived" class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Slide</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">Archived</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">Dates</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">Uploaded by</th>
                                <th v-if="isAdmin" class="px-4 py-3 text-right font-medium text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="slide in archivedSlides" :key="slide.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="h-12 w-20 flex-shrink-0 rounded overflow-hidden bg-slate-100">
                                            <img v-if="slide.thumbnail_url || (slide.file_url && !slide.mime_type?.startsWith('video/'))"
                                                :src="slide.thumbnail_url || slide.file_url"
                                                class="h-full w-full object-cover" />
                                        </div>
                                        <p class="font-medium text-gray-900 line-clamp-1">{{ slide.title }}</p>
                                    </div>
                                </td>
                                <td class="px-4 py-3 hidden sm:table-cell">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold bg-slate-100 text-slate-700">
                                        archived
                                    </span>
                                </td>
                                <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500 space-y-0.5">
                                    <div v-if="slide.publish_at">From: {{ new Date(slide.publish_at).toLocaleDateString() }}</div>
                                    <div v-if="slide.expires_at">Archived: {{ new Date(slide.expires_at).toLocaleDateString() }}</div>
                                </td>
                                <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-500">
                                    {{ slide.uploader?.name ?? '—' }}
                                </td>
                                <td v-if="isAdmin" class="px-4 py-3 text-right">
                                    <div v-if="canEdit(slide)">
                                        <button @click="unarchive(slide)"
                                            class="rounded-md border border-blue-200 px-2.5 py-1 text-xs font-medium text-blue-700 hover:bg-blue-50 transition-colors">
                                            Restore
                                        </button>
                                    </div>
                                    <span v-else class="text-xs text-gray-300">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Empty State -->
            <div v-if="!activeSlides.length && !archivedSlides.length" class="rounded-xl border-2 border-dashed border-gray-200 py-16 text-center">
                <p class="text-gray-400">No slides for {{ entity.name }} yet.</p>
                <button v-if="isAdmin" @click="showUploadPanel = true"
                    class="mt-4 text-sm font-medium text-indigo-600 hover:text-indigo-800">
                    Upload the first slide
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
