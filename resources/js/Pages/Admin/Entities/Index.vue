<script setup>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    entities: { type: Array, default: () => [] },
});
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">Entity Slides</h1>
        </template>

        <div v-if="entities.length" class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Entity</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">Type</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Slides</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="entity in entities" :key="entity.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ entity.name }}</td>
                        <td class="px-4 py-3 hidden sm:table-cell text-sm text-gray-500">{{ entity.entity_type ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="entity.slides_count > 0 ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500'">
                                {{ entity.slides_count }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('admin.entities.slides', entity.id)"
                                class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                View Slides
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="rounded-xl border-2 border-dashed border-gray-200 py-16 text-center">
            <p class="text-gray-400">No entities with slides yet.</p>
        </div>
    </AdminLayout>
</template>
