<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, router, Link } from '@inertiajs/vue3';
import QRCode from 'qrcode';
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

// LAN Video Receiver settings, as last reported by the device (or a web
// edit it hasn't applied yet) — synced both ways by revision, see
// App\Support\SlideAnnouncerVideoReceiver. Null until the device runs an
// app version that reports them.
const receiver = props.device.srt_sink_config ?? {};

const form = useForm({
    name: props.device.name,
    language_id: props.device.language_id ?? '',
    update_channel: props.device.update_channel,
    auto_update_enabled: props.device.auto_update_enabled,
    srt_sink_enabled: props.device.srt_sink_enabled,
    rx_mode: receiver.mode ?? 'srt',
    rx_passphrase: receiver.passphrase || props.device.srt_sink_passphrase || '',
    rx_multicast_group: receiver.multicast_group || '',
    rx_multicast_port: receiver.multicast_port ?? 5000,
    rx_multicast_passphrase: receiver.multicast_passphrase || '',
    rx_encryption_bits: receiver.rist_encryption_bits ?? 128,
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
        srt_sink_enabled: data.srt_sink_enabled,
        srt_sink_config: {
            mode: data.rx_mode,
            passphrase: data.rx_passphrase || null,
            multicast_group: data.rx_multicast_group || null,
            multicast_port: data.rx_multicast_port === '' ? null : Number(data.rx_multicast_port),
            multicast_passphrase: data.rx_multicast_passphrase || null,
            rist_encryption_bits: Number(data.rx_encryption_bits),
        },
        settings: {
            ...settings,
            interval_seconds: Number(data.interval_seconds),
            settings_pin: data.settings_pin || null,
        },
    })).patch(route('slide-announcers.update', { slideAnnouncer: props.device.id, entity_id: props.entity.id }));
}

function unpair() {
    if (confirm(`Unpair "${props.device.name}"? It will need a fresh pairing code to reconnect.`)) {
        router.delete(route('slide-announcers.destroy', { slideAnnouncer: props.device.id, entity_id: props.entity.id }), {
            onSuccess: () => router.visit(route('slide-announcers.index', { entity_id: props.entity.id })),
        });
    }
}

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleString() : 'Never';
}

const isRist = computed(() => form.rx_mode !== 'srt');
const isMulticast = computed(() => form.rx_mode === 'rist_multicast');
const ristSupported = computed(() => receiver.rist_supported !== false);

// Same alphabet/length as the device's own generate_passphrase().
function generatePassphrase() {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    const bytes = crypto.getRandomValues(new Uint32Array(10));
    form.rx_passphrase = Array.from(bytes, (b) => alphabet[b % alphabet.length]).join('');
}

// Mirrors slideannouncer/local-app/backend/srt_sink.py's sender_url(): the
// URL to configure a sender (OBS, vMix, an encoder) with. Built from the
// form's current values so it previews an unsaved change; latency/buffer
// are device-side tuning, taken from the device's last report.
const connectUrl = computed(() => {
    const latencyMs = receiver.srt_latency_ms ?? 120;
    const bufferMs = receiver.rist_buffer_ms ?? 500;
    if (form.rx_mode === 'srt') {
        if (!props.device.hostname || !form.rx_passphrase) return null;
        return `srt://${props.device.hostname}.local:${receiver.srt_port ?? 7002}?mode=caller&latency=${latencyMs * 1000}&passphrase=${encodeURIComponent(form.rx_passphrase)}`;
    }
    const [host, port, secret] = isMulticast.value
        ? [form.rx_multicast_group, form.rx_multicast_port, form.rx_multicast_passphrase]
        : [props.device.hostname && `${props.device.hostname}.local`, receiver.rist_port ?? 5000, form.rx_passphrase];
    if (!host || !secret) return null;
    return `rist://${host}:${port}?secret=${encodeURIComponent(secret)}&aes-type=${form.rx_encryption_bits}&buffer=${bufferMs}`;
});

const qrDataUrl = ref(null);
watch(connectUrl, async (url) => {
    qrDataUrl.value = url ? await QRCode.toDataURL(url, { width: 180, margin: 1 }) : null;
}, { immediate: true });

function copyConnectUrl() {
    if (connectUrl.value) navigator.clipboard?.writeText(connectUrl.value);
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
            <Link :href="route('slide-announcers.index', { entity_id: entity.id })" class="text-sm text-indigo-600 hover:text-indigo-800">
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

                <!-- LAN Video Receiver — mode/passphrases sync down to the device
                     (and device-side edits sync back up); see
                     App\Support\SlideAnnouncerVideoReceiver. -->
                <div class="rounded-lg border border-gray-200 p-4 space-y-4">
                    <div>
                        <h3 class="text-sm font-medium text-gray-900">LAN Video Receiver</h3>
                        <p class="text-xs text-gray-500">
                            Lets a video switcher or encoder on this church's network (OBS, vMix, etc.) send a live feed
                            to this device over SRT or RIST. It also has to be switched on in the device's own
                            Settings &gt; Advanced.
                        </p>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="form.srt_sink_enabled" type="checkbox"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        Allow the LAN video receiver
                    </label>
                    <p class="-mt-3 text-xs text-gray-500">Unchecking force-disables it, overriding the device's own switch.</p>

                    <div v-if="device.srt_sink_config_pending" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Saved — waiting for the device to apply these settings (it checks in about once a minute).
                    </div>
                    <div v-if="receiver.apply_error" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
                        The device couldn't apply the last change: {{ receiver.apply_error }}
                    </div>
                    <p v-if="receiver.local_enabled === false" class="text-xs text-gray-500">
                        Currently switched off on the device itself (Settings &gt; Advanced).
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Mode</label>
                            <select v-model="form.rx_mode" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="srt">SRT</option>
                                <option value="rist_unicast" :disabled="!ristSupported">RIST Unicast</option>
                                <option value="rist_multicast" :disabled="!ristSupported">RIST Multicast</option>
                            </select>
                            <p v-if="!ristSupported" class="mt-1 text-xs text-gray-500">This device's software doesn't support RIST.</p>
                            <p v-if="form.errors['srt_sink_config.mode']" class="mt-1 text-xs text-red-600">{{ form.errors['srt_sink_config.mode'] }}</p>
                        </div>
                        <div v-if="isRist">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Encryption</label>
                            <select v-model="form.rx_encryption_bits" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option :value="128">AES-128</option>
                                <option :value="256">AES-256</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Must match the sender's.</p>
                        </div>
                    </div>

                    <!-- SRT / RIST Unicast: the device listens, senders connect to it. -->
                    <div v-if="!isMulticast">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Passphrase</label>
                        <div class="flex gap-2">
                            <input v-model="form.rx_passphrase" type="text" autocomplete="off" spellcheck="false"
                                class="flex-1 font-mono rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <button type="button" @click="generatePassphrase"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Generate</button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">10–79 characters, no spaces. Shared by SRT and RIST Unicast.</p>
                        <p v-if="form.errors['srt_sink_config.passphrase']" class="mt-1 text-xs text-red-600">{{ form.errors['srt_sink_config.passphrase'] }}</p>
                    </div>

                    <!-- RIST Multicast: the device joins the group the sender transmits to. -->
                    <div v-else class="grid grid-cols-1 sm:grid-cols-[1fr_8rem] gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Multicast IP</label>
                            <input v-model="form.rx_multicast_group" type="text" placeholder="239.1.2.3" autocomplete="off"
                                class="w-full font-mono rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <p v-if="form.errors['srt_sink_config.multicast_group']" class="mt-1 text-xs text-red-600">{{ form.errors['srt_sink_config.multicast_group'] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">UDP Port</label>
                            <input v-model="form.rx_multicast_port" type="number" min="1024" max="65534" step="2"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <p v-if="form.errors['srt_sink_config.multicast_port']" class="mt-1 text-xs text-red-600">{{ form.errors['srt_sink_config.multicast_port'] }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Passphrase</label>
                            <input v-model="form.rx_multicast_passphrase" type="text" autocomplete="off" spellcheck="false"
                                placeholder="Must match the sender's" class="w-full font-mono rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <p v-if="form.errors['srt_sink_config.multicast_passphrase']" class="mt-1 text-xs text-red-600">{{ form.errors['srt_sink_config.multicast_passphrase'] }}</p>
                        </div>
                        <p class="sm:col-span-2 text-xs text-amber-700">
                            Multicast over WiFi is not recommended — connect this device by Ethernet.
                        </p>
                    </div>

                    <div v-if="connectUrl" class="flex items-start gap-2">
                        <span class="text-xs text-gray-500 whitespace-nowrap pt-1">{{ isMulticast ? 'Sender URL:' : 'Connect With:' }}</span>
                        <code class="flex-1 min-w-0 break-all rounded bg-gray-100 px-2 py-1 text-xs font-mono text-gray-800">{{ connectUrl }}</code>
                        <button type="button" @click="copyConnectUrl" class="shrink-0 text-xs font-medium text-indigo-600 hover:text-indigo-800">
                            Copy
                        </button>
                    </div>
                    <img v-if="qrDataUrl" :src="qrDataUrl" alt="Connect With QR code" class="rounded border border-gray-200" width="180" height="180" />
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
