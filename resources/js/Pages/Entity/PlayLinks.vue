<script setup>
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    entity: { type: Object, required: true },
    links: { type: Array, default: () => [] },
    shows: { type: Array, default: () => [] },
    languages: { type: Array, default: () => [] },
    defaultDelaySeconds: { type: Number, default: 12 },
});

const { t, locale } = useI18n();

const editingId = ref(null);
const copiedId = ref(null);

const form = useForm({
    title: '',
    show_id: null,
    language_id: null,
    delay_seconds: props.defaultDelaySeconds,
});

function reset() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function edit(link) {
    editingId.value = link.id;
    form.title = link.title;
    form.show_id = link.show_id;
    form.language_id = link.language_id;
    form.delay_seconds = link.delay_seconds;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function submit() {
    const options = { preserveScroll: true, onSuccess: reset };
    if (editingId.value) {
        form.patch(route('play-links.update', { playLink: editingId.value, entity_id: props.entity.id }), options);
    } else {
        form.post(route('play-links.store', { entity_id: props.entity.id }), options);
    }
}

function revoke(link) {
    if (!confirm(t('play_links.revoke_confirm', { title: link.title }))) return;
    router.delete(route('play-links.destroy', { playLink: link.id, entity_id: props.entity.id }), {
        preserveScroll: true,
        onSuccess: () => { if (editingId.value === link.id) reset(); },
    });
}

async function copy(link) {
    try {
        await navigator.clipboard.writeText(link.url);
    } catch {
        // Clipboard API needs a secure context; fall back to a manual copy.
        window.prompt(t('play_links.url'), link.url);
        return;
    }
    copiedId.value = link.id;
    setTimeout(() => { if (copiedId.value === link.id) copiedId.value = null; }, 2000);
}

function showName(link) {
    return link.show_id
        ? props.shows.find(s => s.id === link.show_id)?.name
        : props.shows.find(s => s.is_main)?.name;
}

function languageName(link) {
    return props.languages.find(l => l.id === link.language_id)?.native_name ?? t('play_links.language_all');
}

function formatUsed(link) {
    return link.last_used_at ? new Date(link.last_used_at).toLocaleString(locale.value) : t('play_links.never');
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
            <Link :href="route('entity.slides.index', { entity: entity.id })" class="text-sm text-indigo-600 hover:text-indigo-800">
                &larr; {{ $t('play_links.back_to_slides') }}
            </Link>
            <div>
                <h1 class="text-xl font-semibold text-gray-900">{{ $t('play_links.title', { name: entity.name }) }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ $t('play_links.intro') }}</p>
            </div>

            <form @submit.prevent="submit" class="rounded-lg bg-white p-4 shadow sm:p-6 space-y-4">
                <h2 class="text-base font-medium text-gray-900">
                    {{ editingId ? $t('play_links.edit') : $t('play_links.new') }}
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="pl-title" class="block text-sm font-medium text-gray-700 mb-1">{{ $t('play_links.field_title') }}</label>
                        <input id="pl-title" v-model="form.title" type="text" maxlength="255" required
                            :placeholder="$t('play_links.title_placeholder')"
                            class="block w-full rounded-lg border-gray-300 text-sm" />
                        <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                    </div>
                    <div>
                        <label for="pl-show" class="block text-sm font-medium text-gray-700 mb-1">{{ $t('play_links.field_show') }}</label>
                        <select id="pl-show" v-model="form.show_id" class="block w-full rounded-lg border-gray-300 text-sm">
                            <option :value="null">{{ $t('play_links.show_main') }}</option>
                            <option v-for="show in shows.filter(s => !s.is_main)" :key="show.id" :value="show.id">{{ show.name }}</option>
                        </select>
                        <p v-if="form.errors.show_id" class="mt-1 text-sm text-red-600">{{ form.errors.show_id }}</p>
                    </div>
                    <div>
                        <label for="pl-language" class="block text-sm font-medium text-gray-700 mb-1">{{ $t('play_links.field_language') }}</label>
                        <select id="pl-language" v-model="form.language_id" class="block w-full rounded-lg border-gray-300 text-sm">
                            <option :value="null">{{ $t('play_links.language_all') }}</option>
                            <option v-for="lang in languages" :key="lang.id" :value="lang.id">{{ lang.native_name }}</option>
                        </select>
                        <p v-if="form.errors.language_id" class="mt-1 text-sm text-red-600">{{ form.errors.language_id }}</p>
                    </div>
                    <div>
                        <label for="pl-delay" class="block text-sm font-medium text-gray-700 mb-1">{{ $t('play_links.field_delay') }}</label>
                        <input id="pl-delay" v-model.number="form.delay_seconds" type="number" min="1" max="600" step="1" required
                            class="block w-32 rounded-lg border-gray-300 text-sm" />
                        <p v-if="form.errors.delay_seconds" class="mt-1 text-sm text-red-600">{{ form.errors.delay_seconds }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                        {{ editingId ? $t('play_links.save') : $t('play_links.create') }}
                    </button>
                    <button v-if="editingId" type="button" @click="reset" class="text-sm text-gray-600 hover:text-gray-900">
                        {{ $t('play_links.cancel') }}
                    </button>
                </div>
            </form>

            <p v-if="!links.length" class="text-sm text-gray-500">{{ $t('play_links.none') }}</p>

            <ul v-else class="divide-y divide-gray-100 rounded-lg bg-white shadow">
                <li v-for="link in links" :key="link.id" class="p-4 sm:px-6 space-y-2">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-medium text-gray-900">{{ link.title }}</p>
                            <p class="text-sm text-gray-600">
                                {{ showName(link) }} · {{ languageName(link) }} · {{ $t('play_links.seconds', { n: link.delay_seconds }) }}
                            </p>
                            <p class="text-xs text-gray-500">{{ $t('play_links.last_used') }}: {{ formatUsed(link) }}</p>
                        </div>
                        <div class="flex items-center gap-3 text-sm">
                            <button @click="copy(link)" class="text-indigo-600 hover:text-indigo-800">
                                {{ copiedId === link.id ? $t('play_links.copied') : $t('play_links.copy') }}
                            </button>
                            <a :href="link.url" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:text-indigo-800">{{ $t('play_links.open') }}</a>
                            <button @click="edit(link)" class="text-indigo-600 hover:text-indigo-800">{{ $t('slide_announcers.edit') }}</button>
                            <button @click="revoke(link)" class="text-red-600 hover:text-red-800">{{ $t('play_links.revoke') }}</button>
                        </div>
                    </div>
                    <input :value="link.url" readonly @focus="$event.target.select()"
                        class="block w-full rounded border-gray-200 bg-gray-50 px-2 py-1 font-mono text-xs text-gray-600" />
                </li>
            </ul>
        </div>
    </AuthenticatedLayout>
</template>
