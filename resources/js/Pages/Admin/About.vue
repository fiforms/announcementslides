<script setup>
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AboutContent from '@/Components/AboutContent.vue';

const props = defineProps({
    about:  { type: Object, required: true },
    system: { type: Object, required: true },
});

const { t } = useI18n();

const rows = [
    ['app_version', 'about.app_version'],
    ['laravel', 'about.laravel'],
    ['php', 'about.php'],
    ['environment', 'about.environment'],
    ['database', 'about.database'],
    ['queue', 'about.queue'],
    ['media_offload', 'about.media_offload'],
];
</script>

<template>
    <Head :title="t('about.title')" />
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ t('about.title') }}</h1>
        </template>

        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <AboutContent :about="about" />
        </div>

        <div class="mx-auto mt-6 max-w-2xl rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <h2 class="mb-3 text-sm font-semibold text-gray-700">{{ t('about.system_details') }}</h2>
            <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                <template v-for="[key, label] in rows" :key="key">
                    <dt class="text-gray-500">{{ t(label) }}</dt>
                    <dd class="font-medium text-gray-800">{{ system[key] || t('about.none') }}</dd>
                </template>
            </dl>
        </div>
    </AdminLayout>
</template>
