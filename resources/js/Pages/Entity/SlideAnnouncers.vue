<script setup>
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    entity: { type: Object, required: true },
    devices: { type: Array, default: () => [] },
    pairingCode: { type: Object, default: null },
    diskImages: { type: Array, default: () => [] },
});

const { t, locale } = useI18n();

const generating = ref(false);

function generateCode() {
    generating.value = true;
    router.post(route('slide-announcers.pairing-codes.store', { entity_id: props.entity.id }), {}, {
        onFinish: () => { generating.value = false; },
    });
}

function unpair(device) {
    if (confirm(t('slide_announcers.unpair_confirm', { name: device.name }))) {
        router.delete(route('slide-announcers.destroy', { slideAnnouncer: device.id, entity_id: props.entity.id }));
    }
}

function formatSeen(device) {
    if (!device.last_seen_at) return t('slide_announcers.never');
    return new Date(device.last_seen_at).toLocaleString(locale.value);
}

function formatBytes(bytes) {
    if (bytes == null) return '—';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
            <Link :href="route('entity.slides.index', { entity: entity.id })" class="text-sm text-indigo-600 hover:text-indigo-800">
                &larr; {{ $t('slide_announcers.back_to_slides') }}
            </Link>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-gray-900">{{ $t('slide_announcers.entity_title', { name: entity.name }) }}</h1>
                <button @click="generateCode" :disabled="generating"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                    {{ $t('slide_announcers.generate_code') }}
                </button>
            </div>

            <div v-if="pairingCode" class="rounded-lg bg-indigo-50 border border-indigo-100 px-4 py-3 text-sm text-indigo-800">
                {{ $t('slide_announcers.pairing_code') }} <span class="font-mono text-lg font-bold tracking-widest">{{ pairingCode.code }}</span>
                {{ $t('slide_announcers.pairing_instructions', { time: new Date(pairingCode.expires_at).toLocaleTimeString(locale) }) }}
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-3">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">{{ $t('slide_announcers.image_title') }}</h2>
                <i18n-t keypath="slide_announcers.etcher_help_entity" tag="p" class="text-sm text-gray-600">
                    <template #etcher>
                        <a href="https://etcher.balena.io/" target="_blank" rel="noopener noreferrer"
                            class="text-indigo-600 hover:text-indigo-800 underline">balenaEtcher</a>
                    </template>
                </i18n-t>

                <div v-if="diskImages.length" class="overflow-hidden rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">{{ $t('slide_announcers.architecture') }}</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">{{ $t('slide_announcers.col_stable') }}</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">{{ $t('slide_announcers.col_testing') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="row in diskImages" :key="row.architecture">
                                <td class="px-4 py-2 font-mono text-gray-900">{{ row.architecture }}</td>
                                <td class="px-4 py-2">
                                    <template v-if="row.stable">
                                        <a :href="row.stable.url" download class="text-indigo-600 hover:text-indigo-800 font-medium">
                                            v{{ row.stable.version }}
                                        </a>
                                        <span class="text-gray-500"> &middot; {{ formatBytes(row.stable.file_size) }}</span>
                                    </template>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="px-4 py-2">
                                    <template v-if="row.testing">
                                        <a :href="row.testing.url" download class="text-indigo-600 hover:text-indigo-800 font-medium">
                                            v{{ row.testing.version }}
                                        </a>
                                        <span class="text-gray-500"> &middot; {{ formatBytes(row.testing.file_size) }}</span>
                                    </template>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-gray-500">{{ $t('slide_announcers.no_images') }}</p>
            </div>

            <div v-if="devices.length" class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.device') }}</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.status') }}</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">{{ $t('slide_announcers.app_os_version') }}</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('slide_announcers.ip_temp') }}</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">{{ $t('slide_announcers.last_seen') }}</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">{{ $t('slide_announcers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="device in devices" :key="device.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <Link :href="route('slide-announcers.show', { slideAnnouncer: device.id, entity_id: entity.id })"
                                    class="font-medium text-indigo-600 hover:text-indigo-800">
                                    {{ device.name }}
                                </Link>
                                <div class="text-xs text-gray-500">{{ device.mac_address || $t('slide_announcers.no_mac') }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="device.online ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                    class="rounded-full px-2 py-0.5 text-xs font-medium">
                                    {{ device.online ? $t('slide_announcers.online') : $t('slide_announcers.offline') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 hidden sm:table-cell text-gray-600">
                                {{ device.app_version || '—' }} / {{ device.os_version || '—' }}
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell text-gray-600">
                                {{ device.last_ip || '—' }}
                                <span v-if="device.last_cpu_temp_c != null">&middot; {{ device.last_cpu_temp_c }}&deg;C</span>
                            </td>
                            <td class="px-4 py-3 hidden lg:table-cell text-gray-600">{{ formatSeen(device) }}</td>
                            <td class="px-4 py-3 text-right">
                                <button @click="unpair(device)" class="text-red-600 hover:text-red-800 text-sm font-medium">
                                    {{ $t('slide_announcers.unpair') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500">
                {{ $t('slide_announcers.no_devices') }}
            </div>
        </div>
    </AuthenticatedLayout>
</template>
