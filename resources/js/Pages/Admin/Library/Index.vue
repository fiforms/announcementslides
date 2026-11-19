<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useChunkedUpload } from '@/Composables/useChunkedUpload.js';

const props = defineProps({
    backgrounds: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    audio: { type: Array, default: () => [] },
});

const { t } = useI18n();

// ── Backgrounds: chunk-upload each file, then adopt it into the library ──
const fileInput = ref(null);
const notice = ref(null);
const current = ref(null);
const uploader = useChunkedUpload({
    finalizeRoute: 'admin.library.backgrounds.store',
    buildFinalizePayload: (completed) => ({ upload: completed[0] }),
});

async function onFiles(evt) {
    const files = [...evt.target.files];
    evt.target.value = '';
    notice.value = null;
    for (const file of files) {
        current.value = file.name;
        const res = await uploader.upload([file], { media_type: 'slide' });
        if (!res) break;
        if (res.result === 'duplicate') notice.value = t('library.duplicate', { existing: res.existing });
    }
    current.value = null;
    router.reload({ only: ['backgrounds'] });
}

// ── Audio (background music): same chunk upload, adopted by its own route ──
const AUDIO_MIMES = { mp3: 'audio/mpeg', m4a: 'audio/mp4', webm: 'audio/webm' };
const audioInput = ref(null);
const audioNotice = ref(null);
const audioCurrent = ref(null);
const audioUploader = useChunkedUpload({
    finalizeRoute: 'admin.library.audio.store',
    buildFinalizePayload: (completed) => ({ upload: completed[0] }),
});

async function onAudioFiles(evt) {
    const files = [...evt.target.files];
    evt.target.value = '';
    audioNotice.value = null;
    for (const file of files) {
        // Browsers report m4a/webm audio inconsistently (or not at all): go by extension.
        const mime = AUDIO_MIMES[file.name.split('.').pop().toLowerCase()];
        if (!mime) {
            audioNotice.value = t('library.audio_hint');
            continue;
        }
        audioCurrent.value = file.name;
        const typed = new File([file], file.name, { type: mime });
        const res = await audioUploader.upload([typed], { media_type: 'library-audio' });
        if (!res) break;
        if (res.result === 'duplicate') audioNotice.value = t('library.duplicate', { existing: res.existing });
    }
    audioCurrent.value = null;
    router.reload({ only: ['audio'] });
}

function removeAudio(item) {
    if (confirm(t('library.delete_audio_confirm', { title: item.title }))) {
        router.delete(route('admin.library.destroy', item.id), { preserveScroll: true });
    }
}

function duration(seconds) {
    if (!seconds && seconds !== 0) return '';
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

function removeBackground(item) {
    if (confirm(t('library.delete_background_confirm', { title: item.title }))) {
        router.delete(route('admin.library.destroy', item.id), { preserveScroll: true });
    }
}

// ── Templates ──
const adding = ref(false);
const form = useForm({ title: '' });
function createTemplate() {
    form.post(route('admin.library.templates.store'));
}
function removeTemplate(item) {
    if (confirm(t('library.delete_template_confirm', { title: item.title }))) {
        router.delete(route('admin.library.destroy', item.id), { preserveScroll: true });
    }
}

// ── Rename (both kinds) ──
function rename(item) {
    const title = prompt(t('library.rename'), item.title);
    if (title && title.trim() && title !== item.title) {
        router.patch(route('admin.library.update', item.id), { title: title.trim() }, { preserveScroll: true });
    }
}

const card = 'overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm';
const linkBtn = 'text-xs font-medium text-gray-600 hover:text-indigo-700';
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ $t('library.title') }}</h1>
        </template>

        <p class="mb-8 max-w-3xl text-sm text-gray-600">{{ $t('library.intro') }}</p>

        <!-- Backgrounds -->
        <section class="mb-12">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-900">{{ $t('library.backgrounds') }}</h2>
                <div>
                    <input ref="fileInput" type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime"
                        class="hidden" @change="onFiles" />
                    <button type="button" :disabled="uploader.isUploading.value" @click="fileInput.click()"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                        {{ $t('library.upload_backgrounds') }}
                    </button>
                </div>
            </div>
            <p class="mb-3 text-xs text-gray-500">{{ $t('library.upload_hint') }}</p>

            <div v-if="uploader.isUploading.value" class="mb-3 rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                <div>{{ $t('library.uploading', { name: current }) }}</div>
                <div class="mt-2 h-1.5 overflow-hidden rounded bg-indigo-100">
                    <div class="h-full bg-indigo-600 transition-all" :style="{ width: uploader.overallProgress.value + '%' }" />
                </div>
            </div>
            <p v-if="uploader.uploadError.value" class="mb-3 text-sm text-red-600">{{ uploader.uploadError.value }}</p>
            <p v-if="notice" class="mb-3 text-sm text-amber-700">{{ notice }}</p>

            <p v-if="!backgrounds.length" class="text-sm text-gray-500">{{ $t('library.no_backgrounds') }}</p>
            <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <div v-for="item in backgrounds" :key="item.id" :class="card">
                    <div class="relative aspect-video bg-slate-100">
                        <img v-if="item.thumbnail_url" :src="item.thumbnail_url" :alt="item.title" class="h-full w-full object-cover" loading="lazy" />
                        <div v-else class="flex h-full items-center justify-center text-xs text-gray-500">{{ $t('library.processing') }}</div>
                        <span v-if="item.is_video" class="absolute left-2 top-2 rounded bg-black/60 px-1.5 py-0.5 text-[10px] font-medium uppercase text-white">{{ $t('library.video') }}</span>
                    </div>
                    <div class="space-y-1 p-3">
                        <div class="truncate text-sm font-medium text-gray-900" :title="item.title">{{ item.title }}</div>
                        <div class="text-xs text-gray-500">{{ $t('library.used_by', { n: item.uses }) }}</div>
                        <div class="flex gap-3 pt-1">
                            <button type="button" :class="linkBtn" @click="rename(item)">{{ $t('library.rename') }}</button>
                            <button type="button" class="text-xs font-medium text-red-600 hover:text-red-800" @click="removeBackground(item)">{{ $t('library.delete') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Audio -->
        <section class="mb-12">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-900">{{ $t('library.audio') }}</h2>
                <div>
                    <input ref="audioInput" type="file" multiple accept=".mp3,.m4a,.webm,audio/mpeg,audio/mp4,audio/x-m4a,audio/webm"
                        class="hidden" @change="onAudioFiles" />
                    <button type="button" :disabled="audioUploader.isUploading.value" @click="audioInput.click()"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                        {{ $t('library.upload_audio') }}
                    </button>
                </div>
            </div>
            <p class="mb-3 text-xs text-gray-500">{{ $t('library.audio_hint') }}</p>

            <div v-if="audioUploader.isUploading.value" class="mb-3 rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                <div>{{ $t('library.uploading', { name: audioCurrent }) }}</div>
                <div class="mt-2 h-1.5 overflow-hidden rounded bg-indigo-100">
                    <div class="h-full bg-indigo-600 transition-all" :style="{ width: audioUploader.overallProgress.value + '%' }" />
                </div>
            </div>
            <p v-if="audioUploader.uploadError.value" class="mb-3 text-sm text-red-600">{{ audioUploader.uploadError.value }}</p>
            <p v-if="audioNotice" class="mb-3 text-sm text-amber-700">{{ audioNotice }}</p>

            <p v-if="!audio.length" class="text-sm text-gray-500">{{ $t('library.no_audio') }}</p>
            <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="item in audio" :key="item.id" :class="card" class="space-y-2 p-3">
                    <div class="flex items-baseline justify-between gap-2">
                        <div class="truncate text-sm font-medium text-gray-900" :title="item.title">{{ item.title }}</div>
                        <div class="shrink-0 text-xs text-gray-500">{{ duration(item.duration_seconds) }}</div>
                    </div>
                    <audio :src="item.file_url" controls preload="none" class="h-9 w-full" />
                    <div class="text-xs text-gray-500">{{ $t('library.used_by_shows', { n: item.uses }) }}</div>
                    <div class="flex gap-3">
                        <button type="button" :class="linkBtn" @click="rename(item)">{{ $t('library.rename') }}</button>
                        <button type="button" class="text-xs font-medium text-red-600 hover:text-red-800" @click="removeAudio(item)">{{ $t('library.delete') }}</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Templates -->
        <section>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-900">{{ $t('library.templates') }}</h2>
                <button v-if="!adding" type="button" @click="adding = true"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    {{ $t('library.new_template') }}
                </button>
                <form v-else class="flex items-center gap-2" @submit.prevent="createTemplate">
                    <input v-model="form.title" type="text" required maxlength="255" autofocus :placeholder="$t('library.template_name')"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <button type="submit" :disabled="form.processing" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">{{ $t('library.create') }}</button>
                    <button type="button" class="text-sm text-gray-500" @click="adding = false">{{ $t('library.cancel') }}</button>
                </form>
            </div>

            <p v-if="!templates.length" class="text-sm text-gray-500">{{ $t('library.no_templates') }}</p>
            <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <div v-for="item in templates" :key="item.id" :class="card">
                    <!-- The SVG shown as an image over a checkerboard, so transparency reads. -->
                    <div class="aspect-video bg-slate-700 bg-[linear-gradient(45deg,#64748b_25%,transparent_25%,transparent_75%,#64748b_75%),linear-gradient(45deg,#64748b_25%,transparent_25%,transparent_75%,#64748b_75%)] bg-[length:16px_16px] bg-[position:0_0,8px_8px]">
                        <img v-if="item.ready" :src="item.preview_url" :alt="item.title" class="h-full w-full object-contain" loading="lazy" />
                        <div v-else class="flex h-full items-center justify-center px-3 text-center text-xs text-slate-200">{{ $t('library.template_empty') }}</div>
                    </div>
                    <div class="space-y-1 p-3">
                        <div class="truncate text-sm font-medium text-gray-900" :title="item.title">{{ item.title }}</div>
                        <div class="flex gap-3 pt-1">
                            <a :href="route('admin.library.templates.edit', item.id)" :class="linkBtn">{{ $t('library.edit_template') }}</a>
                            <button type="button" :class="linkBtn" @click="rename(item)">{{ $t('library.rename') }}</button>
                            <button type="button" class="text-xs font-medium text-red-600 hover:text-red-800" @click="removeTemplate(item)">{{ $t('library.delete') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </AdminLayout>
</template>
