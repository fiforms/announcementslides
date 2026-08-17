<script setup>
import { ref, computed } from 'vue';
import { useForm, router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    entity: { type: Object, required: true },
    device: { type: Object, required: true },
    heartbeats: { type: Array, default: () => [] },
    languages: { type: Array, default: () => [] },
});

// interval_seconds also lives in the same `settings` JSON blob the raw
// textarea edits (Slideshow.vue's DEFAULT_INTERVAL_SECONDS is 10) — pulled
// out into its own field since "how fast do slides switch" is common enough
// to deserve a real control instead of hand-editing JSON. settings_pin is
// the on-device Settings PIN gate (4-6 digits, optional) — same treatment.
const { interval_seconds, settings_pin, ...otherSettings } = props.device.settings ?? {};

const form = useForm({
    name: props.device.name,
    language_id: props.device.language_id ?? '',
    update_channel: props.device.update_channel,
    auto_update_enabled: props.device.auto_update_enabled,
    interval_seconds: interval_seconds ?? 10,
    settings_pin: settings_pin ?? '',
    settings_text: JSON.stringify(otherSettings, null, 2),
});

const settingsError = ref('');

const jsonValid = computed(() => {
    try {
        JSON.parse(form.settings_text || '{}');
        return true;
    } catch {
        return false;
    }
});

const pinValid = computed(() => /^\d{4,6}$/.test(form.settings_pin) || form.settings_pin === '');

function submit() {
    settingsError.value = '';
    let settings;
    try {
        settings = JSON.parse(form.settings_text || '{}');
    } catch (e) {
        settingsError.value = `Settings must be valid JSON: ${e.message}`;
        return;
    }
    if (!pinValid.value) {
        settingsError.value = 'Settings PIN must be 4-6 digits, or blank to disable.';
        return;
    }

    form.transform((data) => ({
        name: data.name,
        language_id: data.language_id || null,
        update_channel: data.update_channel,
        auto_update_enabled: data.auto_update_enabled,
        settings: {
            ...settings,
            interval_seconds: Number(data.interval_seconds),
            settings_pin: data.settings_pin || null,
        },
    })).patch(route('entity.slide-announcers.update', { entity: props.entity.id, slideAnnouncer: props.device.id }));
}

function unpair() {
    if (confirm(`Unpair "${props.device.name}"? It will need a fresh pairing code to reconnect.`)) {
        router.delete(route('entity.slide-announcers.destroy', { entity: props.entity.id, slideAnnouncer: props.device.id }), {
            onSuccess: () => router.visit(route('entity.slide-announcers.index', { entity: props.entity.id })),
        });
    }
}

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleString() : 'Never';
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
            <Link :href="route('entity.slide-announcers.index', { entity: entity.id })" class="text-sm text-indigo-600 hover:text-indigo-800">
                &larr; Back to devices
            </Link>

            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">{{ device.name }}</h1>
                    <p class="text-sm text-gray-500">{{ entity.name }}</p>
                </div>
                <span :class="device.online ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                    class="rounded-full px-3 py-1 text-xs font-medium">
                    {{ device.online ? 'Online' : 'Offline' }}
                </span>
            </div>

            <!-- Device info (server-reported, read-only) -->
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Device info</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-gray-500">App version</dt>
                        <dd class="font-medium text-gray-900">{{ device.app_version || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">OS version</dt>
                        <dd class="font-medium text-gray-900">{{ device.os_version || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Architecture</dt>
                        <dd class="font-medium text-gray-900">{{ device.architecture || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">CPU temperature</dt>
                        <dd class="font-medium text-gray-900">
                            {{ device.last_cpu_temp_c != null ? `${device.last_cpu_temp_c}°C` : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Last IP address</dt>
                        <dd class="font-medium text-gray-900">{{ device.last_ip || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">MAC address</dt>
                        <dd class="font-mono text-gray-900">{{ device.mac_address || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Device UUID</dt>
                        <dd class="font-mono text-xs text-gray-900">{{ device.device_uuid || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Last seen</dt>
                        <dd class="font-medium text-gray-900">{{ formatDate(device.last_seen_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Paired</dt>
                        <dd class="font-medium text-gray-900">{{ formatDate(device.paired_at) }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Settings (editable, pushed to the device on its next slide sync) -->
            <form @submit.prevent="submit" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-5">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Settings</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input v-model="form.name" type="text" required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Language</label>
                    <select v-model="form.language_id"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Use the device's own default</option>
                        <option v-for="lang in languages" :key="lang.id" :value="lang.id">{{ lang.name }}</option>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">
                        Filters which language-specific slides sync to this device, and sets its on-screen UI language.
                        Leave unset to fall back to the language configured on the device itself before pairing.
                    </p>
                    <p v-if="form.errors.language_id" class="mt-1 text-xs text-red-600">{{ form.errors.language_id }}</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Update channel</label>
                        <select v-model="form.update_channel"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="stable">Stable</option>
                            <option value="testing">Testing</option>
                            <option value="developer">Developer</option>
                        </select>
                        <p v-if="form.errors.update_channel" class="mt-1 text-xs text-red-600">{{ form.errors.update_channel }}</p>
                    </div>
                    <div class="flex items-end pb-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="form.auto_update_enabled" type="checkbox"
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            Auto-install updates
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slide duration (seconds)</label>
                    <input v-model.number="form.interval_seconds" type="number" min="1" step="1" required
                        class="w-32 rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <p class="mt-1 text-xs text-gray-500">How long each slide stays on screen before switching to the next.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Settings PIN <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input v-model="form.settings_pin" type="text" inputmode="numeric" maxlength="6" placeholder="Off"
                        class="w-32 rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        :class="{ 'border-red-400': !pinValid }" />
                    <p class="mt-1 text-xs text-gray-500">
                        4-6 digits. When set, opening Settings on the device requires this PIN first (leave blank to disable).
                    </p>
                    <p v-if="!pinValid" class="mt-1 text-xs text-red-600">Must be 4-6 digits, or blank.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Custom settings <span class="text-gray-400 font-normal">(JSON — sent to the device with every slide sync)</span>
                    </label>
                    <textarea v-model="form.settings_text" rows="6" spellcheck="false"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        :class="{ 'border-red-400': !jsonValid }" />
                    <p v-if="!jsonValid" class="mt-1 text-xs text-red-600">Not valid JSON.</p>
                    <p v-else-if="settingsError" class="mt-1 text-xs text-red-600">{{ settingsError }}</p>
                    <p v-else-if="form.errors.settings" class="mt-1 text-xs text-red-600">{{ form.errors.settings }}</p>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" :disabled="form.processing || !jsonValid || !pinValid"
                        class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                        {{ form.processing ? 'Saving…' : 'Save Changes' }}
                    </button>
                    <span v-if="form.recentlySuccessful" class="text-sm text-green-600">Saved.</span>
                    <button type="button" @click="unpair" class="ml-auto text-sm font-medium text-red-600 hover:text-red-800">
                        Unpair this device
                    </button>
                </div>
            </form>

            <!-- Heartbeat history -->
            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                <h2 class="px-6 pt-6 text-sm font-semibold text-gray-500 uppercase tracking-wide">Recent heartbeats</h2>
                <table v-if="heartbeats.length" class="min-w-full divide-y divide-gray-200 text-sm mt-4">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-gray-500">Time</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-500">App</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-500">OS</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-500">IP</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-500">Temp</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="(hb, i) in heartbeats" :key="i">
                            <td class="px-4 py-2 text-gray-600 whitespace-nowrap">{{ formatDate(hb.created_at) }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ hb.app_version || '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ hb.os_version || '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ hb.ip_address || '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ hb.cpu_temp_c != null ? `${hb.cpu_temp_c}°C` : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-6 py-8 text-center text-sm text-gray-500">No heartbeats recorded yet.</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
