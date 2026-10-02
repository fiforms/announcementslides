<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Components/Modal.vue';
import { useChunkedUpload } from '@/Composables/useChunkedUpload.js';

const { t, locale } = useI18n();

const props = defineProps({
    releases: { type: Array, default: () => [] },
});

const CHANNELS = ['developer', 'testing', 'stable'];

const { isUploading, uploadError, overallProgress, upload } = useChunkedUpload({
    chunkRoute: 'admin.slide-announcer-releases.chunk',
    finalizeRoute: 'admin.slide-announcer-releases.finalize',
});

const RELEASE_TYPES_BY_KIND = {
    os: ['full', 'hotfix', 'disk_image'],
    app: ['full'],
};

function kindShort(k) {
    return ['os', 'app'].includes(k) ? t(`slide_announcers.kind_short_${k}`) : k;
}

function typeShort(type) {
    return ['full', 'hotfix', 'disk_image'].includes(type) ? t(`slide_announcers.type_short_${type}`) : type;
}

function channelLabel(channel) {
    return CHANNELS.includes(channel) ? t(`slide_announcers.channel_${channel}`) : channel;
}

function formatDateTime(iso) {
    return new Date(iso).toLocaleString(locale.value);
}

// Mirrors SlideAnnouncerRelease::parseFilename() — a client-side
// convenience to auto-fill the form; the server re-validates everything
// on finalize() regardless.
function parseReleaseFilename(filename) {
    const match = /^slideannouncer-(\d+\.\d+\.\d+)(?:\.hotfix\.from\.(\d+\.\d+\.\d+))?\.(?:raucb|tar\.gz|img\.xz)$/i.exec(filename);
    if (!match) return null;
    const [, version, base] = match;
    return {
        version,
        release_type: base ? 'hotfix' : 'full',
        required_base_version: base ?? null,
    };
}

const selectedFile = ref(null);
const kind = ref('os');
const releaseType = ref('full');
const version = ref('');
const requiredBaseVersion = ref('');
const architecture = ref('aarch64');
const initialChannel = ref('');
const notes = ref('');
const finalizeError = ref(null);
const releases = ref([...props.releases]);

const releaseTypeOptions = computed(() => RELEASE_TYPES_BY_KIND[kind.value].map(value => ({
    value,
    label: t(`slide_announcers.type_${kind.value}_${value}`),
})));

// App only supports 'full' today — if the kind switches away from os,
// drop back to full rather than leaving an os-only type selected.
watch(kind, () => {
    if (!releaseTypeOptions.value.some(o => o.value === releaseType.value)) {
        releaseType.value = 'full';
    }
});

// Inertia re-renders this page with fresh props after any router.post/delete
// (tag/untag/destroy below), but releases was only ever seeded from props
// once — without this, the table stays stale until a manual reload.
watch(() => props.releases, (updated) => {
    releases.value = [...updated];
});

const currentReleases = computed(() => releases.value.filter(r => r.channels.length > 0));
const archivedReleases = computed(() => releases.value.filter(r => r.channels.length === 0));

const acceptedExtension = computed(() => (
    kind.value === 'os' && releaseType.value === 'disk_image' ? '.img.xz' :
    kind.value === 'os' ? '.raucb' : '.tar.gz'
));
const canSubmit = computed(() => selectedFile.value && version.value.trim() && architecture.value.trim()
    && (releaseType.value !== 'hotfix' || requiredBaseVersion.value.trim()));

function onFileSelected(event) {
    selectedFile.value = event.target.files[0] ?? null;
    const parsed = selectedFile.value ? parseReleaseFilename(selectedFile.value.name) : null;
    if (parsed) {
        version.value = parsed.version;
        if (releaseTypeOptions.value.some(o => o.value === parsed.release_type)) {
            releaseType.value = parsed.release_type;
        }
        requiredBaseVersion.value = parsed.required_base_version ?? '';
    }
}

function formatBytes(bytes) {
    if (bytes == null) return '—';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
}

// Seeded with what's actually been tested/documented for disk images —
// an architecture with no entry here just doesn't get a compatibility
// note rather than a guessed one.
const DISK_IMAGE_COMPATIBILITY = {
    aarch64: 'slide_announcers.compat_aarch64',
};

function diskImageCompatibility(architecture) {
    const key = DISK_IMAGE_COMPATIBILITY[architecture];
    return key ? t(key) : null;
}

function channelBadgeClass(channel) {
    return {
        stable: 'bg-green-100 text-green-800',
        testing: 'bg-amber-100 text-amber-800',
        developer: 'bg-blue-100 text-blue-800',
    }[channel] ?? 'bg-gray-100 text-gray-600';
}

async function submit() {
    finalizeError.value = null;

    const result = await upload([selectedFile.value], {
        kind: kind.value,
        release_type: releaseType.value,
        version: version.value.trim(),
        required_base_version: releaseType.value === 'hotfix' ? requiredBaseVersion.value.trim() : null,
        architecture: architecture.value.trim(),
        channel: initialChannel.value || null,
        notes: notes.value || null,
    });

    if (!result) {
        // uploadError is already set by the composable
        return;
    }
    if (!result.success) {
        finalizeError.value = result.message ?? t('slide_announcers.publish_failed');
        return;
    }

    releases.value.unshift(result.release);
    selectedFile.value = null;
    releaseType.value = 'full';
    version.value = '';
    requiredBaseVersion.value = '';
    architecture.value = '';
    initialChannel.value = '';
    notes.value = '';
}

function tagChannel(release, channel) {
    // Mirrors the server's per-slot eviction rule in tagChannel(): same
    // kind+architecture+release_type, and for hotfixes also the same
    // required_base_version — a full release or a differently-targeted
    // hotfix isn't evicted, so this warning shouldn't claim it will be.
    const holder = releases.value.find(r => r.channels.some(c => c.channel === channel)
        && r.kind === release.kind
        && r.architecture === release.architecture
        && r.release_type === release.release_type
        && (release.release_type !== 'hotfix' || r.required_base_version === release.required_base_version));
    const warning = holder && holder.id !== release.id
        ? t('slide_announcers.tag_warning', { kind: kindShort(holder.kind), version: holder.version, arch: holder.architecture })
        : '';
    if (confirm(t('slide_announcers.tag_confirm', { kind: kindShort(release.kind), version: release.version, arch: release.architecture, channel: channelLabel(channel), warning }))) {
        router.post(route('admin.slide-announcer-releases.channels.tag', { slideAnnouncerRelease: release.id }), { channel });
    }
}

function untagChannel(release, channel) {
    if (confirm(t('slide_announcers.untag_confirm', { channel: channelLabel(channel), kind: kindShort(release.kind), version: release.version, arch: release.architecture }))) {
        router.delete(route('admin.slide-announcer-releases.channels.untag', { slideAnnouncerRelease: release.id, channel }));
    }
}

function destroy(release) {
    if (confirm(t('slide_announcers.delete_confirm', { kind: kindShort(release.kind), version: release.version, arch: release.architecture }))) {
        router.delete(route('admin.slide-announcer-releases.destroy', { slideAnnouncerRelease: release.id }), {
            onSuccess: () => { detailsId.value = null; },
        });
    }
}

// ── Details lightbox ────────────────────────────────────────────────────

const detailsId = ref(null);
const copied = ref(null); // 'url' | 'sha256' | null
const tagToAdd = ref('');
const detailsRelease = computed(() => releases.value.find(r => r.id === detailsId.value) ?? null);
const availableChannelsToAdd = computed(() => {
    if (!detailsRelease.value) return [];
    const current = detailsRelease.value.channels.map(c => c.channel);
    return CHANNELS.filter(c => !current.includes(c));
});

function openDetails(release) {
    detailsId.value = release.id;
    copied.value = null;
    tagToAdd.value = '';
}

function closeDetails() {
    detailsId.value = null;
}

function addTagFromDetails() {
    if (tagToAdd.value) {
        tagChannel(detailsRelease.value, tagToAdd.value);
        tagToAdd.value = '';
    }
}

async function copyToClipboard(text, which) {
    await navigator.clipboard.writeText(text);
    copied.value = which;
    setTimeout(() => { if (copied.value === which) copied.value = null; }, 1500);
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-gray-900">{{ $t('slide_announcers.releases_title') }}</h1>
        </template>

        <div class="mx-auto max-w-5xl px-8 py-8 space-y-8">
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                <h2 class="text-lg font-semibold text-gray-900">{{ $t('slide_announcers.publish_title') }}</h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <label class="block">
                            <span class="text-sm font-medium text-gray-700">{{ $t('slide_announcers.kind') }}</span>
                            <select v-model="kind" :disabled="isUploading"
                                class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                                <option value="os">{{ $t('slide_announcers.kind_os') }}</option>
                                <option value="app">{{ $t('slide_announcers.kind_app') }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-gray-700">{{ $t('slide_announcers.architecture') }}</span>
                            <input type="text" v-model="architecture" :disabled="isUploading" :placeholder="$t('slide_announcers.placeholder_eg', { example: 'aarch64' })"
                                class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                        </label>
                    </div>

                    <label class="block" v-if="releaseTypeOptions.length > 1">
                        <span class="text-sm font-medium text-gray-700">{{ $t('slide_announcers.type') }}</span>
                        <select v-model="releaseType" :disabled="isUploading"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                            <option v-for="opt in releaseTypeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                    </label>

                    <div class="grid grid-cols-2 gap-4">
                        <label class="block">
                            <span class="text-sm font-medium text-gray-700">{{ $t('slide_announcers.version') }}</span>
                            <input type="text" v-model="version" :disabled="isUploading" :placeholder="$t('slide_announcers.placeholder_eg', { example: '2026.08.1 / 0.2.0' })"
                                class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                        </label>
                        <label class="block" v-if="releaseType === 'hotfix'">
                            <span class="text-sm font-medium text-gray-700">{{ $t('slide_announcers.required_base_version') }}</span>
                            <input type="text" v-model="requiredBaseVersion" :disabled="isUploading" :placeholder="$t('slide_announcers.placeholder_eg', { example: '1.2.0' })"
                                class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                            <span class="text-xs text-gray-500">{{ $t('slide_announcers.required_base_help') }}</span>
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">{{ $t('slide_announcers.file_label', { ext: acceptedExtension }) }}</span>
                        <input type="file" :accept="acceptedExtension" :disabled="isUploading" @change="onFileSelected"
                            class="mt-1 block w-full text-sm text-gray-700">
                        <span v-if="selectedFile" class="text-xs text-gray-500">
                            {{ selectedFile.name }} ({{ formatBytes(selectedFile.size) }})
                        </span>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">{{ $t('slide_announcers.notes') }}</span>
                        <textarea v-model="notes" :disabled="isUploading" rows="2"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm"></textarea>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">{{ $t('slide_announcers.tag_as_optional') }}</span>
                        <select v-model="initialChannel" :disabled="isUploading"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm">
                            <option value="">{{ $t('slide_announcers.tag_none') }}</option>
                            <option v-for="c in CHANNELS" :key="c" :value="c">{{ channelLabel(c) }}</option>
                        </select>
                        <span class="text-xs text-gray-500">
                            {{ $t('slide_announcers.tag_help') }}
                        </span>
                    </label>

                    <div v-if="isUploading" class="rounded-lg bg-indigo-50 px-4 py-3">
                        <div class="flex justify-between text-xs text-indigo-700 mb-1">
                            <span>{{ $t('slide_announcers.uploading') }}</span>
                            <span>{{ overallProgress }}%</span>
                        </div>
                        <div class="h-2 rounded-full bg-indigo-100 overflow-hidden">
                            <div class="h-full bg-indigo-500 transition-all" :style="{ width: overallProgress + '%' }" />
                        </div>
                    </div>

                    <div v-if="uploadError || finalizeError" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                        {{ uploadError || finalizeError }}
                    </div>

                    <button type="submit" :disabled="isUploading || !canSubmit"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                        {{ isUploading ? $t('slide_announcers.uploading_pct', { n: overallProgress }) : $t('slide_announcers.publish_release') }}
                    </button>
                </form>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-2">{{ $t('slide_announcers.current_heading', { n: currentReleases.length }) }}</h2>
                <div class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.kind') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.type') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.version') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.architecture') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.col_channels') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('slide_announcers.size') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">{{ $t('slide_announcers.published') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="release in currentReleases" :key="release.id" class="hover:bg-gray-50 cursor-pointer"
                                @click="openDetails(release)">
                                <td class="px-4 py-3">{{ kindShort(release.kind) }}</td>
                                <td class="px-4 py-3">
                                    {{ typeShort(release.release_type) }}
                                    <span v-if="release.release_type === 'hotfix'" class="text-gray-500">{{ $t('slide_announcers.hotfix_from', { version: release.required_base_version }) }}</span>
                                </td>
                                <td class="px-4 py-3 font-mono">{{ release.version }}</td>
                                <td class="px-4 py-3">{{ release.architecture }}</td>
                                <td class="px-4 py-3">
                                    <span v-for="c in release.channels" :key="c.channel" :class="channelBadgeClass(c.channel)"
                                        class="rounded-full px-2 py-0.5 text-xs font-medium mr-1">
                                        {{ channelLabel(c.channel) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ formatBytes(release.file_size) }}</td>
                                <td class="px-4 py-3 hidden lg:table-cell text-gray-600">
                                    {{ formatDateTime(release.created_at) }}
                                </td>
                            </tr>
                            <tr v-if="!currentReleases.length">
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">{{ $t('slide_announcers.no_current') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-2">{{ $t('slide_announcers.archived_heading', { n: archivedReleases.length }) }}</h2>
                <p class="text-xs text-gray-500 mb-2">{{ $t('slide_announcers.archived_note') }}</p>
                <div class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.kind') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.type') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.version') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">{{ $t('slide_announcers.architecture') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 hidden md:table-cell">{{ $t('slide_announcers.size') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500 hidden lg:table-cell">{{ $t('slide_announcers.published') }}</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-500">{{ $t('slide_announcers.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="release in archivedReleases" :key="release.id" class="hover:bg-gray-50 cursor-pointer"
                                @click="openDetails(release)">
                                <td class="px-4 py-3">{{ kindShort(release.kind) }}</td>
                                <td class="px-4 py-3">
                                    {{ typeShort(release.release_type) }}
                                    <span v-if="release.release_type === 'hotfix'" class="text-gray-500">{{ $t('slide_announcers.hotfix_from', { version: release.required_base_version }) }}</span>
                                </td>
                                <td class="px-4 py-3 font-mono">{{ release.version }}</td>
                                <td class="px-4 py-3">{{ release.architecture }}</td>
                                <td class="px-4 py-3 hidden md:table-cell text-gray-600">{{ formatBytes(release.file_size) }}</td>
                                <td class="px-4 py-3 hidden lg:table-cell text-gray-600">
                                    {{ formatDateTime(release.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-right" @click.stop>
                                    <button @click="destroy(release)" class="text-red-600 hover:text-red-800 text-sm font-medium">
                                        {{ $t('slide_announcers.delete') }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!archivedReleases.length">
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">{{ $t('slide_announcers.nothing_archived') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <Modal :show="!!detailsRelease" max-width="lg" @close="closeDetails">
            <div v-if="detailsRelease" class="p-6 space-y-5">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">
                            {{ kindShort(detailsRelease.kind) }} {{ detailsRelease.version }}
                        </h3>
                        <p class="text-sm text-gray-500">
                            {{ detailsRelease.architecture }} &middot; {{ typeShort(detailsRelease.release_type) }}
                            <span v-if="detailsRelease.release_type === 'hotfix'">{{ $t('slide_announcers.hotfix_from', { version: detailsRelease.required_base_version }) }}</span>
                        </p>
                    </div>
                </div>

                <div>
                    <dt class="text-sm text-gray-500 mb-1">{{ $t('slide_announcers.col_channels') }}</dt>
                    <div class="flex flex-wrap items-center gap-2">
                        <span v-for="c in detailsRelease.channels" :key="c.channel"
                            :class="channelBadgeClass(c.channel)"
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium">
                            {{ channelLabel(c.channel) }}
                            <button @click="untagChannel(detailsRelease, c.channel)" class="hover:opacity-60" :title="$t('slide_announcers.untag')">
                                &times;
                            </button>
                        </span>
                        <span v-if="!detailsRelease.channels.length" class="text-xs text-gray-500">{{ $t('slide_announcers.archived_untagged') }}</span>

                        <div v-if="availableChannelsToAdd.length" class="flex items-center gap-1 ml-2">
                            <select v-model="tagToAdd" class="text-xs rounded-lg border-gray-300 shadow-sm">
                                <option value="">{{ $t('slide_announcers.tag_as') }}</option>
                                <option v-for="c in availableChannelsToAdd" :key="c" :value="c">{{ channelLabel(c) }}</option>
                            </select>
                            <button @click="addTagFromDetails" :disabled="!tagToAdd"
                                class="rounded-lg border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                                {{ $t('slide_announcers.add') }}
                            </button>
                        </div>
                    </div>
                </div>

                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-gray-500">{{ $t('slide_announcers.size') }}</dt>
                        <dd class="text-gray-900">{{ formatBytes(detailsRelease.file_size) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ $t('slide_announcers.published') }}</dt>
                        <dd class="text-gray-900">{{ formatDateTime(detailsRelease.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ $t('slide_announcers.published_by') }}</dt>
                        <dd class="text-gray-900">{{ detailsRelease.creator?.name ?? '—' }}</dd>
                    </div>
                    <div class="col-span-2" v-if="detailsRelease.notes">
                        <dt class="text-gray-500">{{ $t('slide_announcers.notes') }}</dt>
                        <dd class="text-gray-900 whitespace-pre-wrap">{{ detailsRelease.notes }}</dd>
                    </div>
                </dl>

                <div>
                    <dt class="text-sm text-gray-500 mb-1">SHA-256</dt>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 rounded-lg bg-gray-50 border border-gray-200 px-3 py-2 text-xs text-gray-700 break-all">
                            {{ detailsRelease.sha256 }}
                        </code>
                        <button @click="copyToClipboard(detailsRelease.sha256, 'sha256')"
                            class="rounded-lg border border-gray-300 p-2 text-gray-500 hover:bg-gray-50 flex-shrink-0"
                            :title="$t('slide_announcers.copy_sha')">
                            <svg v-if="copied === 'sha256'" class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div>
                    <dt class="text-sm text-gray-500 mb-1">{{ $t('slide_announcers.direct_link') }}</dt>
                    <div class="flex items-center gap-2">
                        <input type="text" readonly :value="detailsRelease.url"
                            class="flex-1 rounded-lg bg-gray-50 border border-gray-200 px-3 py-2 text-xs text-gray-700"
                            @click="$event.target.select()">
                        <button @click="copyToClipboard(detailsRelease.url, 'url')"
                            class="rounded-lg border border-gray-300 p-2 text-gray-500 hover:bg-gray-50 flex-shrink-0"
                            :title="$t('slide_announcers.copy_link')">
                            <svg v-if="copied === 'url'" class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                        <a :href="detailsRelease.url" download
                            class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-700 flex-shrink-0">
                            {{ $t('slide_announcers.download') }}
                        </a>
                    </div>
                    <div v-if="detailsRelease.release_type === 'disk_image'" class="mt-2 text-xs text-gray-500 space-y-1">
                        <i18n-t keypath="slide_announcers.etcher_help_admin" tag="p">
                            <template #etcher>
                                <a href="https://etcher.balena.io/" target="_blank" rel="noopener noreferrer"
                                    class="text-indigo-600 hover:text-indigo-800 underline">balenaEtcher</a>
                            </template>
                        </i18n-t>
                        <p v-if="diskImageCompatibility(detailsRelease.architecture)">
                            {{ diskImageCompatibility(detailsRelease.architecture) }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-gray-100 pt-4">
                    <button v-if="!detailsRelease.channels.length" @click="destroy(detailsRelease)"
                        class="text-red-600 hover:text-red-800 text-sm font-medium">
                        {{ $t('slide_announcers.delete') }}
                    </button>
                    <span v-else></span>
                    <button @click="closeDetails" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        {{ $t('slide_announcers.close') }}
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
