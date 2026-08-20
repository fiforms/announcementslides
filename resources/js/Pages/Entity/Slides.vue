<script setup>
import { ref } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UploadPanel from '@/Components/UploadPanel.vue';

const props = defineProps({
    entity: { type: Object, required: true },
    slides: { type: Array, default: () => [] },
    languages: { type: Array, default: () => [] },
    shows: { type: Array, default: () => [] },
});

const page = usePage();
const user = page.props.auth.user;

const showUploadPanel = ref(false);

function canEdit(slide) {
    return user.role === 'admin' || slide.uploader?.id === user.id;
}

function archive(slide) {
    if (confirm(`Archive "${slide.title}"? It will no longer be visible.`)) {
        router.post(route('entity.slides.archive', { entity: props.entity.id, slide: slide.id }));
    }
}

function statusBadge(status) {
    const map = {
        published: 'bg-green-100 text-green-800',
        pending:   'bg-yellow-100 text-yellow-800',
        draft:     'bg-gray-100 text-gray-700',
        rejected:  'bg-red-100 text-red-700',
    };
    return map[status] ?? 'bg-gray-100 text-gray-700';
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
            <!-- Upload Panel Toggle Button -->
            <div class="flex justify-end gap-3">
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
            <div class="rounded-lg bg-indigo-50 border border-indigo-100 px-4 py-3 text-sm text-indigo-700">
                Slides uploaded here are visible only to members of <strong>{{ entity.name }}</strong>.
            </div>

            <UploadPanel
                v-if="showUploadPanel"
                :redirect-route="'entity.slides.index'"
                :redirect-params="{ entity: entity.id }"
                :entity-id="entity.id"
                :languages="languages"
                :shows="shows"
                @success="showUploadPanel = false"
            />

            <div v-if="slides.length" class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Slide</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">Status</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">Dates</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">Uploaded by</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="slide in slides" :key="slide.id" class="hover:bg-gray-50">
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
                                    :class="statusBadge(slide.status)">
                                    {{ slide.status }}
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
                            <td class="px-4 py-3 text-right">
                                <div v-if="canEdit(slide)" class="flex items-center justify-end gap-2">
                                    <Link :href="route('entity.slides.edit', { entity: entity.id, slide: slide.id })"
                                        class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                        Edit
                                    </Link>
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

            <div v-else class="rounded-xl border-2 border-dashed border-gray-200 py-16 text-center">
                <p class="text-gray-400">No slides for {{ entity.name }} yet.</p>
                <button @click="showUploadPanel = true"
                    class="mt-4 text-sm font-medium text-indigo-600 hover:text-indigo-800">
                    Upload the first slide
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
