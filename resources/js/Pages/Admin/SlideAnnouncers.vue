<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const { t, locale } = useI18n();

const props = defineProps({
    devices: { type: Array, default: () => [] },
});

const search = ref('');
const onlyOnline = ref(false);

const filtered = computed(() => {
    return props.devices.filter((device) => {
        if (onlyOnline.value && !device.online) return false;
        if (!search.value) return true;
        const needle = search.value.toLowerCase();
        return device.name.toLowerCase().includes(needle)
            || (device.entity?.name ?? '').toLowerCase().includes(needle);
    });
});

function formatSeen(device) {
    if (!device.last_seen_at) return t('slide_announcers.never');
    return new Date(device.last_seen_at).toLocaleString(locale.value);
}

function channelLabel(channel) {
    return ['stable', 'testing', 'developer'].includes(channel) ? t(`slide_announcers.channel_${channel}`) : channel;
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ $t('slide_announcers.devices_title') }}</h1>
        </template>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input v-model="search" type="search" :placeholder="$t('slide_announcers.search_placeholder')"
                class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input v-model="onlyOnline" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                {{ $t('slide_announcers.online_only') }}
            </label>
            <span class="text-sm text-gray-400">{{ $t('slide_announcers.count_of', { shown: filtered.length, total: devices.length }) }}</span>
        </div>

        <div v-if="filtered.length" class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.device') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.col_site') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.status') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">{{ $t('slide_announcers.app_os_version') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('slide_announcers.col_channel') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('slide_announcers.ip_temp') }}</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">{{ $t('slide_announcers.last_seen') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="device in filtered" :key="device.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <Link v-if="device.entity"
                                :href="route('slide-announcers.show', { slideAnnouncer: device.id, entity_id: device.entity.id })"
                                class="font-medium text-indigo-600 hover:text-indigo-800">
                                {{ device.name }}
                            </Link>
                            <span v-else class="font-medium text-gray-900">{{ device.name }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ device.entity?.name || '—' }}</td>
                        <td class="px-4 py-3">
                            <span :class="device.online ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                class="rounded-full px-2 py-0.5 text-xs font-medium">
                                {{ device.online ? $t('slide_announcers.online') : $t('slide_announcers.offline') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell text-gray-600">
                            {{ device.app_version || '—' }} / {{ device.os_version || '—' }}
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ channelLabel(device.update_channel) }}</td>
                        <td class="px-4 py-3 hidden md:table-cell text-gray-600">
                            {{ device.last_ip || '—' }}
                            <span v-if="device.last_cpu_temp_c != null">&middot; {{ device.last_cpu_temp_c }}&deg;C</span>
                        </td>
                        <td class="px-4 py-3 hidden lg:table-cell text-gray-600">{{ formatSeen(device) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else class="rounded-xl border-2 border-dashed border-gray-200 py-16 text-center">
            <p class="text-gray-400">{{ $t('slide_announcers.no_match') }}</p>
        </div>
    </AdminLayout>
</template>
