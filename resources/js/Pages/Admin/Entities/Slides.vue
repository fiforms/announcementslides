<script setup>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    entity: { type: Object, required: true },
    slides: { type: Array, default: () => [] },
});

function destroy(slide) {
    if (confirm(`Delete "${slide.title}"? This cannot be undone.`)) {
        router.delete(route('admin.slides.destroy', slide.id));
    }
}

function approve(slide) {
    router.post(route('admin.slides.approve', slide.id));
}

function reject(slide) {
    if (confirm(`Reject "${slide.title}"?`)) {
        router.post(route('admin.slides.reject', slide.id));
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
    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('admin.entities.index')" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-xl font-semibold text-gray-900">{{ entity.name }} — Entity Slides</h1>
            </div>
        </template>

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
                                    <img v-if="slide.thumbnail_url || slide.file_url"
                                        :src="slide.thumbnail_url || slide.file_url"
                                        class="h-full w-full object-cover" />
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900 line-clamp-1">{{ slide.title }}</p>
                                    <p v-if="slide.notes" class="text-xs text-gray-400 line-clamp-1 mt-0.5">{{ slide.notes }}</p>
                                </div>
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
                            <div class="flex items-center justify-end gap-2">
                                <template v-if="slide.status === 'pending'">
                                    <button @click="approve(slide)"
                                        class="rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700 transition-colors">
                                        Approve
                                    </button>
                                    <button @click="reject(slide)"
                                        class="rounded-md border border-red-300 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50 transition-colors">
                                        Reject
                                    </button>
                                </template>
                                <Link :href="route('admin.slides.edit', slide.id)"
                                    class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                    Edit
                                </Link>
                                <button @click="destroy(slide)"
                                    class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition-colors">
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="rounded-xl border-2 border-dashed border-gray-200 py-16 text-center">
            <p class="text-gray-400">No entity-scoped slides for {{ entity.name }}.</p>
        </div>
    </AdminLayout>
</template>
