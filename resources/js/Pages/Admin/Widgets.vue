<script setup>
import { reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const { t, locale } = useI18n();

// Install, configure and remove overlay widgets. A widget is code that runs
// for every viewer of a slide using it, so installing one is a trust
// decision — the page puts what it can reach (its upstream hosts) up front.
const props = defineProps({
    widgets: { type: Array, default: () => [] },
    defaultLocation: { type: Object, default: null },
});

// Site-wide fallback for widgets' api.location (App\Support\WidgetLocation).
const locationForm = useForm({
    name: props.defaultLocation?.name ?? '',
    latitude: props.defaultLocation?.latitude ?? '',
    longitude: props.defaultLocation?.longitude ?? '',
});

function saveLocation() {
    locationForm.patch(route('admin.widgets.location'), { preserveScroll: true });
}

function clearLocation() {
    locationForm.name = '';
    locationForm.latitude = '';
    locationForm.longitude = '';
    saveLocation();
}

const upload = useForm({ package: null });
const fileInput = ref(null);
const openId = ref(null);
// Per-widget unsaved edits: { [id]: { settings: {key: value}, extra: {param: 'one\nper line'} } }
const drafts = reactive({});

function submitUpload() {
    upload.post(route('admin.widgets.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { upload.reset(); if (fileInput.value) fileInput.value.value = ''; },
    });
}

function toggleOpen(widget) {
    openId.value = openId.value === widget.id ? null : widget.id;
    if (openId.value && !drafts[widget.id]) {
        drafts[widget.id] = {
            settings: Object.fromEntries(widget.settings.map(s => [s.key, ''])),
            extra: Object.fromEntries(widget.url_params.map(p => [p.key, p.extra.join('\n')])),
        };
    }
}

function setEnabled(widget, enabled) {
    router.patch(route('admin.widgets.update', widget.id), { enabled }, { preserveScroll: true });
}

function saveSettings(widget) {
    const draft = drafts[widget.id];
    router.patch(route('admin.widgets.update', widget.id), {
        settings: Object.fromEntries(Object.entries(draft.settings).filter(([, v]) => v !== '')),
        extra_allow: Object.fromEntries(Object.entries(draft.extra).map(([k, v]) => [k, v.split('\n').map(s => s.trim()).filter(Boolean)])),
    }, {
        preserveScroll: true,
        onSuccess: () => { delete drafts[widget.id]; openId.value = null; },
    });
}

function clearSetting(widget, key) {
    if (!confirm(t('admin_widgets.clear_setting_confirm'))) return;
    router.patch(route('admin.widgets.update', widget.id), { clear_settings: [key] }, { preserveScroll: true });
}

function remove(widget) {
    const usage = widget.used_by ? ' ' + t('admin_widgets.remove_usage', { n: widget.used_by }) : '';
    if (!confirm(t('admin_widgets.remove_confirm', { name: widget.name }) + usage)) return;
    router.delete(route('admin.widgets.destroy', widget.id), { preserveScroll: true });
}

function formatTime(seconds) {
    return new Date(seconds * 1000).toLocaleString(locale.value);
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ $t('admin_widgets.page_title') }}</h1>
        </template>

        <div class="mx-auto max-w-5xl space-y-8">
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-3">
                <h2 class="text-lg font-semibold text-gray-900">{{ $t('admin_widgets.install_title') }}</h2>
                <p class="text-sm text-gray-600">
                    <i18n-t keypath="admin_widgets.install_description" tag="span">
                        <template #manifest><code>manifest.json</code></template>
                    </i18n-t>
                </p>
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {{ $t('admin_widgets.trust_warning') }}
                </p>
                <form class="flex flex-wrap items-center gap-3" @submit.prevent="submitUpload">
                    <input ref="fileInput" type="file" accept=".zip,application/zip"
                        class="text-sm text-gray-700" @change="upload.package = $event.target.files[0] ?? null" />
                    <button type="submit" :disabled="!upload.package || upload.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                        {{ upload.processing ? $t('admin_widgets.installing') : $t('admin_widgets.install') }}
                    </button>
                </form>
                <ul v-if="upload.errors.package" class="list-disc space-y-0.5 pl-5 text-sm text-red-600">
                    <li v-for="(msg, i) in [].concat(upload.errors.package)" :key="i">{{ msg }}</li>
                </ul>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-3">
                <h2 class="text-lg font-semibold text-gray-900">{{ $t('admin_widgets.default_location') }}</h2>
                <p class="text-sm text-gray-600">
                    {{ $t('admin_widgets.default_location_description') }}
                </p>
                <form class="grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto]" @submit.prevent="saveLocation">
                    <label class="block text-sm">
                        <span class="font-medium text-gray-700">{{ $t('admin_widgets.place_name') }}</span>
                        <input v-model="locationForm.name" type="text" maxlength="80" :placeholder="$t('admin_widgets.place_placeholder')"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium text-gray-700">{{ $t('admin_widgets.latitude') }}</span>
                        <input v-model="locationForm.latitude" type="number" step="any" min="-90" max="90" :placeholder="$t('admin_widgets.example', { value: '40.7128' })"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium text-gray-700">{{ $t('admin_widgets.longitude') }}</span>
                        <input v-model="locationForm.longitude" type="number" step="any" min="-180" max="180" :placeholder="$t('admin_widgets.example', { value: '-74.0060' })"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                    </label>
                    <div class="flex items-end gap-2">
                        <button type="submit" :disabled="locationForm.processing"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                            {{ $t('admin_widgets.save') }}
                        </button>
                        <button v-if="defaultLocation" type="button" :disabled="locationForm.processing"
                            class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                            @click="clearLocation">
                            {{ $t('admin_widgets.clear') }}
                        </button>
                    </div>
                </form>
                <ul v-if="Object.keys(locationForm.errors).length" class="list-disc pl-5 text-sm text-red-600">
                    <li v-for="(msg, field) in locationForm.errors" :key="field">{{ msg }}</li>
                </ul>
            </div>

            <div v-if="!widgets.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                {{ $t('admin_widgets.none_installed') }}
            </div>

            <div v-for="w in widgets" :key="w.id" class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-start gap-4 p-5">
                    <img :src="w.icon_url" alt="" class="h-12 w-12 shrink-0 rounded-lg object-contain" />
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <h3 class="text-base font-semibold text-gray-900">{{ w.name }}</h3>
                            <span class="text-xs text-gray-500">{{ w.slug }} · v{{ w.version }}</span>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="w.enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'">
                                {{ w.enabled ? $t('admin_widgets.enabled') : $t('admin_widgets.disabled') }}
                            </span>
                        </div>
                        <p v-if="w.description" class="text-sm text-gray-600">{{ w.description }}</p>
                        <p class="text-xs text-gray-500">
                            <span class="font-medium">{{ $t('admin_widgets.can_contact') }}</span>
                            {{ w.hosts.length ? w.hosts.join('; ') : $t('admin_widgets.no_network') }}
                        </p>
                        <p class="text-xs text-gray-400">
                            {{ $t('admin_widgets.used_on', { n: w.used_by }) }}<span v-if="w.installed_by"> · {{ $t('admin_widgets.installed_by', { name: w.installed_by }) }}</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <button v-if="w.settings.length || w.url_params.length" type="button"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                            @click="toggleOpen(w)">
                            {{ openId === w.id ? $t('admin_widgets.close') : $t('admin_widgets.settings') }}
                        </button>
                        <button type="button" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                            @click="setEnabled(w, !w.enabled)">
                            {{ w.enabled ? $t('admin_widgets.disable') : $t('admin_widgets.enable') }}
                        </button>
                        <button type="button" class="rounded-lg border border-red-200 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50"
                            @click="remove(w)">
                            {{ $t('admin_widgets.remove') }}
                        </button>
                    </div>
                </div>

                <div v-if="openId === w.id && drafts[w.id]" class="space-y-4 border-t border-gray-100 p-5">
                    <div v-for="s in w.settings" :key="s.key">
                        <label class="block text-sm font-medium text-gray-700">{{ s.label }}</label>
                        <div class="mt-1 flex gap-2">
                            <input v-model="drafts[w.id].settings[s.key]" :type="s.secret ? 'password' : 'text'" autocomplete="off"
                                :placeholder="s.is_set ? $t('admin_widgets.secret_set_placeholder') : $t('admin_widgets.not_set')"
                                class="block w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                            <button v-if="s.is_set" type="button" class="rounded-lg border border-gray-300 px-3 text-sm text-gray-600 hover:bg-gray-50"
                                @click="clearSetting(w, s.key)">{{ $t('admin_widgets.clear') }}</button>
                        </div>
                        <p v-if="s.help" class="mt-1 text-xs text-gray-500">{{ s.help }}</p>
                    </div>

                    <div v-for="p in w.url_params" :key="p.key">
                        <label class="block text-sm font-medium text-gray-700">{{ $t('admin_widgets.extra_addresses', { label: p.label }) }}</label>
                        <p class="text-xs text-gray-500">
                            {{ $t('admin_widgets.extra_addresses_hint', { list: p.declared.join(', ') }) }}
                        </p>
                        <textarea v-model="drafts[w.id].extra[p.key]" rows="3" placeholder="https://example.com/calendars/"
                            class="mt-1 block w-full rounded-lg border-gray-300 font-mono text-xs shadow-sm" />
                    </div>

                    <div class="flex justify-end">
                        <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                            @click="saveSettings(w)">
                            {{ $t('admin_widgets.save_settings') }}
                        </button>
                    </div>
                </div>

                <details v-if="w.errors.length" class="border-t border-gray-100 px-5 py-3 text-sm">
                    <summary class="cursor-pointer text-amber-700">{{ $t('admin_widgets.fetch_problems', { n: w.errors.length }) }}</summary>
                    <ul class="mt-2 space-y-0.5 text-xs text-gray-600">
                        <li v-for="(e, i) in w.errors" :key="i">
                            {{ formatTime(e.at) }} — {{ e.endpoint }} @ {{ e.host }}: {{ e.reason }}
                        </li>
                    </ul>
                </details>
            </div>
        </div>
    </AdminLayout>
</template>
