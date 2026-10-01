<script setup>
import { reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

// Install, configure and remove overlay widgets. A widget is code that runs
// for every viewer of a slide using it, so installing one is a trust
// decision — the page puts what it can reach (its upstream hosts) up front.
const props = defineProps({
    widgets: { type: Array, default: () => [] },
});

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
    if (!confirm('Clear this setting?')) return;
    router.patch(route('admin.widgets.update', widget.id), { clear_settings: [key] }, { preserveScroll: true });
}

function remove(widget) {
    const usage = widget.used_by ? ` It's placed on ${widget.used_by} slide overlay(s), which will show nothing there.` : '';
    if (!confirm(`Remove ${widget.name}?${usage}`)) return;
    router.delete(route('admin.widgets.destroy', widget.id), { preserveScroll: true });
}

function formatTime(seconds) {
    return new Date(seconds * 1000).toLocaleString();
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">Overlay Widgets</h1>
        </template>

        <div class="mx-auto max-w-5xl space-y-8">
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-3">
                <h2 class="text-lg font-semibold text-gray-900">Install or upgrade a widget</h2>
                <p class="text-sm text-gray-600">
                    Upload a widget package (.zip with a <code>manifest.json</code>). Uploading a newer version of an
                    installed widget upgrades it everywhere it's used.
                </p>
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    A widget's code runs in the browser of everyone who views a slide that uses it, with their
                    signed-in session. Only install widgets you trust.
                </p>
                <form class="flex flex-wrap items-center gap-3" @submit.prevent="submitUpload">
                    <input ref="fileInput" type="file" accept=".zip,application/zip"
                        class="text-sm text-gray-700" @change="upload.package = $event.target.files[0] ?? null" />
                    <button type="submit" :disabled="!upload.package || upload.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                        {{ upload.processing ? 'Installing…' : 'Install' }}
                    </button>
                </form>
                <ul v-if="upload.errors.package" class="list-disc space-y-0.5 pl-5 text-sm text-red-600">
                    <li v-for="(msg, i) in [].concat(upload.errors.package)" :key="i">{{ msg }}</li>
                </ul>
            </div>

            <div v-if="!widgets.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                No widgets installed yet.
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
                                {{ w.enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                        <p v-if="w.description" class="text-sm text-gray-600">{{ w.description }}</p>
                        <p class="text-xs text-gray-500">
                            <span class="font-medium">Can contact:</span>
                            {{ w.hosts.length ? w.hosts.join('; ') : 'nothing (no network access)' }}
                        </p>
                        <p class="text-xs text-gray-400">
                            Used on {{ w.used_by }} overlay(s)<span v-if="w.installed_by"> · installed by {{ w.installed_by }}</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <button v-if="w.settings.length || w.url_params.length" type="button"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                            @click="toggleOpen(w)">
                            {{ openId === w.id ? 'Close' : 'Settings' }}
                        </button>
                        <button type="button" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                            @click="setEnabled(w, !w.enabled)">
                            {{ w.enabled ? 'Disable' : 'Enable' }}
                        </button>
                        <button type="button" class="rounded-lg border border-red-200 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50"
                            @click="remove(w)">
                            Remove
                        </button>
                    </div>
                </div>

                <div v-if="openId === w.id && drafts[w.id]" class="space-y-4 border-t border-gray-100 p-5">
                    <div v-for="s in w.settings" :key="s.key">
                        <label class="block text-sm font-medium text-gray-700">{{ s.label }}</label>
                        <div class="mt-1 flex gap-2">
                            <input v-model="drafts[w.id].settings[s.key]" :type="s.secret ? 'password' : 'text'" autocomplete="off"
                                :placeholder="s.is_set ? '•••••••• (set — leave blank to keep)' : 'Not set'"
                                class="block w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                            <button v-if="s.is_set" type="button" class="rounded-lg border border-gray-300 px-3 text-sm text-gray-600 hover:bg-gray-50"
                                @click="clearSetting(w, s.key)">Clear</button>
                        </div>
                        <p v-if="s.help" class="mt-1 text-xs text-gray-500">{{ s.help }}</p>
                    </div>

                    <div v-for="p in w.url_params" :key="p.key">
                        <label class="block text-sm font-medium text-gray-700">Extra allowed addresses for “{{ p.label }}”</label>
                        <p class="text-xs text-gray-500">
                            Built in: {{ p.declared.join(', ') }}. Add one https:// prefix per line (for example a
                            church management system's calendar feed address).
                        </p>
                        <textarea v-model="drafts[w.id].extra[p.key]" rows="3" placeholder="https://example.com/calendars/"
                            class="mt-1 block w-full rounded-lg border-gray-300 font-mono text-xs shadow-sm" />
                    </div>

                    <div class="flex justify-end">
                        <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                            @click="saveSettings(w)">
                            Save settings
                        </button>
                    </div>
                </div>

                <details v-if="w.errors.length" class="border-t border-gray-100 px-5 py-3 text-sm">
                    <summary class="cursor-pointer text-amber-700">{{ w.errors.length }} recent data fetch problem(s)</summary>
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
