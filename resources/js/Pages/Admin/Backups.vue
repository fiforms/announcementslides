<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const { t } = useI18n();

const props = defineProps({
    backups:  { type: Array, required: true },
    settings: { type: Object, default: null },
    unsupported: { type: String, default: null }, // 'driver' | 'client' when backups cannot work here
    running:  { type: Boolean, default: false },
});

const form = useForm({ mode: 'database' });

function create(mode) {
    form.mode = mode;
    form.post(route('admin.backups.store'), { preserveScroll: true });
}

function remove(backup) {
    if (confirm(t('backups.confirm_delete'))) {
        router.delete(route('admin.backups.destroy', backup.id), { preserveScroll: true });
    }
}

const busy = computed(() => props.running || props.backups.some((b) => ['queued', 'running'].includes(b.status)));

// While a backup is being built, poll the list.
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (busy.value) router.reload({ only: ['backups', 'running'], preserveScroll: true });
    }, 3000);
});
onBeforeUnmount(() => clearInterval(timer));

function size(bytes) {
    if (bytes == null) return '—';
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
}

function when(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

const statusClass = {
    queued: 'bg-gray-100 text-gray-700',
    running: 'bg-blue-100 text-blue-700',
    completed: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
};
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ $t('nav.backups') }}</h1>
        </template>

        <div v-if="unsupported" class="mx-auto max-w-3xl space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
            <h2 class="text-base font-semibold">{{ $t('backups.unsupported_title') }}</h2>
            <p>{{ $t(`backups.unsupported_${unsupported}`) }}</p>
            <p v-if="unsupported === 'driver'">{{ $t('backups.unsupported_sqlite_howto') }}</p>
        </div>

        <div v-else class="mx-auto max-w-5xl space-y-6">
            <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <p class="text-sm text-gray-600">{{ $t('backups.intro') }}</p>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" :disabled="busy || form.processing" @click="create('database')"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                        {{ $t('backups.create_db') }}
                    </button>
                    <button type="button" :disabled="busy || form.processing" @click="create('full')"
                        class="rounded-lg border border-indigo-600 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-50">
                        {{ $t('backups.create_full') }}
                    </button>
                    <span v-if="busy" class="text-sm text-blue-700">{{ $t('backups.running') }}</span>
                </div>
                <p class="text-xs text-gray-500">{{ $t('backups.create_full_hint') }}</p>
            </section>

            <section class="space-y-1 rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">{{ $t('backups.schedule_title') }}</h2>
                <p v-if="settings.schedule === 'off'">{{ $t('backups.schedule_off') }}</p>
                <p v-else>{{ $t(`backups.schedule_${settings.schedule}`, { time: settings.time, keep: settings.keep }) }}</p>
                <p>{{ settings.before_migrate ? $t('backups.before_migrate', { keep: settings.keep_safety }) : $t('backups.before_migrate_off') }}</p>
                <p>{{ $t('backups.manual_kept') }}</p>
                <p class="pt-2 text-xs text-gray-500">
                    <span class="font-medium">{{ $t('backups.restore_title') }}:</span> {{ $t('backups.restore_help') }}
                </p>
            </section>

            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <p v-if="!backups.length" class="p-6 text-sm text-gray-500">{{ $t('backups.empty') }}</p>
                <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">{{ $t('backups.col_date') }}</th>
                            <th class="px-4 py-3">{{ $t('backups.col_type') }}</th>
                            <th class="px-4 py-3">{{ $t('backups.col_source') }}</th>
                            <th class="px-4 py-3">{{ $t('backups.col_size') }}</th>
                            <th class="px-4 py-3">{{ $t('backups.col_status') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="b in backups" :key="b.id">
                            <td class="whitespace-nowrap px-4 py-3 text-gray-900">{{ when(b.finished_at ?? b.created_at) }}</td>
                            <td class="px-4 py-3">
                                {{ $t(`backups.mode_${b.mode}`) }}
                                <span v-if="b.files_skipped?.length" class="block text-xs text-amber-700">{{ $t('backups.files_not_included') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                {{ $t(`backups.trigger_${b.trigger}`) }}
                                <span v-if="b.created_by" class="block text-xs text-gray-500">{{ b.created_by }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">{{ size(b.size) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass[b.status]">
                                    {{ $t(`backups.status_${b.status}`) }}
                                </span>
                                <span v-if="b.error" class="mt-1 block max-w-xs break-words text-xs text-red-600">{{ b.error }}</span>
                            </td>
                            <td class="space-x-3 whitespace-nowrap px-4 py-3 text-right">
                                <a v-if="b.status === 'completed'" :href="route('admin.backups.download', b.id)"
                                    class="font-medium text-indigo-600 hover:text-indigo-800">{{ $t('backups.download') }}</a>
                                <button v-if="!['queued', 'running'].includes(b.status)" type="button" @click="remove(b)"
                                    class="font-medium text-red-600 hover:text-red-800">{{ $t('backups.delete') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AdminLayout>
</template>
