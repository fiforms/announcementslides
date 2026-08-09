<script setup>
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    entity: { type: Object, required: true },
    devices: { type: Array, default: () => [] },
    pairingCode: { type: Object, default: null },
});

const generating = ref(false);

function generateCode() {
    generating.value = true;
    router.post(route('entity.slide-announcers.pairing-codes.store', { entity: props.entity.id }), {}, {
        onFinish: () => { generating.value = false; },
    });
}

function unpair(device) {
    if (confirm(`Unpair "${device.name}"? It will need a fresh pairing code to reconnect.`)) {
        router.delete(route('entity.slide-announcers.destroy', { entity: props.entity.id, slideAnnouncer: device.id }));
    }
}

function formatSeen(device) {
    if (!device.last_seen_at) return 'Never';
    return new Date(device.last_seen_at).toLocaleString();
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
            <Link :href="route('entity.slides.index', { entity: entity.id })" class="text-sm text-indigo-600 hover:text-indigo-800">
                &larr; Back to slides
            </Link>
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-gray-900">Slide Announcer devices &mdash; {{ entity.name }}</h1>
                <button @click="generateCode" :disabled="generating"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                    Generate pairing code
                </button>
            </div>

            <div v-if="pairingCode" class="rounded-lg bg-indigo-50 border border-indigo-100 px-4 py-3 text-sm text-indigo-800">
                Pairing code: <span class="font-mono text-lg font-bold tracking-widest">{{ pairingCode.code }}</span>
                &mdash; enter this on the device's pairing screen. Expires {{ new Date(pairingCode.expires_at).toLocaleTimeString() }}.
            </div>

            <div v-if="devices.length" class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Device</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden sm:table-cell">App / OS version</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">IP / Temp</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">Last seen</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="device in devices" :key="device.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ device.name }}</div>
                                <div class="text-xs text-gray-500">{{ device.mac_address || 'no MAC on file' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="device.online ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                    class="rounded-full px-2 py-0.5 text-xs font-medium">
                                    {{ device.online ? 'Online' : 'Offline' }}
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
                                    Unpair
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500">
                No Slide Announcer devices paired yet. Generate a pairing code and enter it on the device's setup screen.
            </div>
        </div>
    </AuthenticatedLayout>
</template>
