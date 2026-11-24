<script setup>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SlideUploadPanel from '@/Components/SlideUploadPanel.vue';

defineProps({
    promo: { type: Object, required: true },
    languages: { type: Array, default: () => [] },
});
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('admin.promo-shows.index')" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-xl font-semibold text-gray-900">Upload to {{ promo.name }}</h1>
            </div>
        </template>

        <SlideUploadPanel :promo="promo" :languages="languages"
            @cancel="router.visit(route('admin.promo-shows.index'))" />
    </AdminLayout>
</template>
