<script setup>
import { ref, computed, watch } from 'vue';
import { router, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UploadPanel from '@/Components/UploadPanel.vue';
import ShowSlideRow from '@/Components/ShowSlideRow.vue';
import SlideLightbox from '@/Components/SlideLightbox.vue';
import MediaManager from '@/Components/MediaManager.vue';
import DateTimeLocalInput from '@/Components/DateTimeLocalInput.vue';
import { useLightbox } from '@/Composables/useLightbox.js';

const props = defineProps({
    entity: { type: Object, required: true },
    shows: { type: Array, default: () => [] },
    selectedShowId: { type: Number, required: true },
    showSlides: { type: Array, default: () => [] },
    unusedSlides: { type: Array, default: () => [] },
    isAdmin: { type: Boolean, default: false },
    languages: { type: Array, default: () => [] },
    mediaTypes: { type: Array, default: () => [] },
});

const { locale } = useI18n();

const user = usePage().props.auth.user;

// A slide's ownership relative to this entity: 'mine' (belongs to this
// entity), 'global' (entity_id null, visible everywhere), or 'nearby'
// (belongs to some other entity that's sharing it in). The queries backing
// showSlides/unusedSlides only ever return slides in one of these three
// buckets, so entity_id alone is enough to tell them apart client-side.
function slideScope(slide) {
    if (slide.entity_id === null) return 'global';
    if (slide.entity_id === props.entity.id) return 'mine';
    return 'nearby';
}

const scopeBadges = {
    global: { label: 'Global', classes: 'bg-blue-50 text-blue-700', dot: 'bg-blue-500' },
    nearby: { label: 'Nearby', classes: 'bg-purple-50 text-purple-700', dot: 'bg-purple-500' },
    mine:   { label: 'Mine',   classes: 'bg-green-50 text-green-700', dot: 'bg-green-500' },
};

function scopeBadge(slide) {
    return scopeBadges[slideScope(slide)];
}

function canEdit(slide) {
    return slideScope(slide) === 'mine' && props.isAdmin && (user.role === 'admin' || slide.uploader?.id === user.id);
}

function toLocalDatetime(iso) {
    if (!iso) return '';
    return iso.slice(0, 16);
}

const { lightboxSlide, openLightbox, closeLightbox } = useLightbox();

const editingSlide = ref(null);
const editForm = useForm({
    title: '',
    notes: '',
    text_description: '',
    link: '',
    video_playback_mode: 'hold_last_frame',
    language_id: '',
    publish_at: '',
    expires_at: '',
});

function openEdit(slide) {
    editingSlide.value = slide;
    editForm.title = slide.title;
    editForm.notes = slide.notes ?? '';
    editForm.text_description = slide.text_description ?? '';
    editForm.link = slide.link ?? '';
    editForm.video_playback_mode = slide.video_playback_mode ?? 'hold_last_frame';
    editForm.language_id = slide.language_id ?? '';
    editForm.publish_at = toLocalDatetime(slide.publish_at);
    editForm.expires_at = toLocalDatetime(slide.expires_at);
    editForm.clearErrors();
}

function closeEdit() {
    editingSlide.value = null;
}

function submitEdit() {
    editForm.patch(route('local-slides.update', { slide: editingSlide.value.id, entity_id: props.entity.id }), {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    });
}

function archiveEditingSlide() {
    if (!confirm(`Archive "${editingSlide.value.title}"? It will stop showing right away — you can restore it later from the archive.`)) return;
    router.post(route('local-slides.archive', { slide: editingSlide.value.id, entity_id: props.entity.id }), {}, {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    });
}

// A slide "expires out" of a show the same way it expires out of the real
// rotation: it stays attached (nothing detaches it automatically), it just
// stops being current. This mirrors that in the editor by splitting the
// "In this show" list into what's actually current and what's expired.
function isExpired(slide) {
    return !!slide.expires_at && new Date(slide.expires_at).getTime() <= Date.now();
}

function expiresLabel(slide) {
    if (!slide.expires_at) return null;
    const d = new Date(slide.expires_at);
    const dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    return isExpired(slide) ? `Expired ${dateStr}` : `Expires ${dateStr}`;
}

const activeInShow = computed(() => inShow.value.filter(s => !isExpired(s)));
const expiredInShow = computed(() => inShow.value.filter(s => isExpired(s)));
const showExpiredPane = ref(false);

function detachAllExpired() {
    if (!expiredInShow.value.length) return;
    if (!confirm(`Remove ${expiredInShow.value.length} expired slide(s) from "${selectedShow.value.name}"?`)) return;
    router.post(route('shows.slides.detachExpired', { show: props.selectedShowId, entity_id: props.entity.id }),
        {}, { preserveScroll: true });
}

// ── Sort zones ───────────────────────────────────────────────────────────────
// Mirrors App\Support\SortZones: a show's slides fall into 5 fixed regions,
// alternating leader-assigned (manually placed/reordered) with automatic
// (positioned by the global/nearby fan-out counter, never a drop target —
// see the backend docblock for why). The 2 automatic zones each render a
// placeholder marking exactly where the next new slide of that kind lands.
const ZONE_ORDER = ['leader_early', 'global', 'leader_mid', 'nearby', 'leader_late'];
const LEADER_ZONES = ['leader_early', 'leader_mid', 'leader_late'];
const ZONE_LABELS = {
    leader_early: 'Before global slides',
    global: 'Global slides',
    leader_mid: 'Between global & nearby',
    nearby: 'Nearby slides',
    leader_late: 'After nearby slides',
};

function zoneOf(slide) {
    return ZONE_ORDER.includes(slide.zone) ? slide.zone : 'leader_late';
}

const zoneGroups = computed(() => {
    const groups = { leader_early: [], global: [], leader_mid: [], nearby: [], leader_late: [] };
    for (const slide of activeInShow.value) {
        groups[zoneOf(slide)].push(slide);
    }
    return groups;
});

// The interface language's matching `languages` row, if any — used as the
// default for blank/ephemeral language selectors (never forced onto a
// show's actual persisted setting, which may genuinely be "any language").
const uiLanguageId = computed(() => props.languages.find(l => l.abbreviation === locale.value)?.id ?? '');

const showUploadPanel = ref(false);
const otherShows = computed(() => props.shows.filter(s => !s.is_main));

const inShow = ref([...props.showSlides]);
const unused = ref([...props.unusedSlides]);
const newShowName = ref('');
const newShowLanguageId = ref(uiLanguageId.value);
const newShowAutoFillGlobal = ref(false);
const newShowAutoFillNearby = ref(false);
const showingNewShowForm = ref(false);

// Temporary, client-only filter for "Unused slides" — never sent to the
// server or saved onto the show. Defaults to the interface language but a
// slide with no language tag always stays visible (it's meant for everyone).
const unusedLanguageFilter = ref(uiLanguageId.value);
const filteredUnused = computed(() => unused.value.filter(s =>
    !unusedLanguageFilter.value || s.language_id === null || s.language_id === unusedLanguageFilter.value
));

watch(() => [props.showSlides, props.unusedSlides], () => {
    inShow.value = [...props.showSlides];
    unused.value = [...props.unusedSlides];
});

const selectedShow = computed(() => props.shows.find(s => s.id === props.selectedShowId));

function switchShow(showId) {
    router.get(route('shows.index', { entity_id: props.entity.id, show_id: showId }));
}

function createShow() {
    if (!newShowName.value.trim()) return;
    router.post(route('shows.store', { entity_id: props.entity.id }), {
        name: newShowName.value,
        language_id: newShowLanguageId.value || null,
        auto_fill_global: newShowAutoFillGlobal.value,
        auto_fill_nearby: newShowAutoFillNearby.value,
    }, {
        onSuccess: () => {
            newShowName.value = '';
            newShowLanguageId.value = uiLanguageId.value;
            newShowAutoFillGlobal.value = false;
            newShowAutoFillNearby.value = false;
            showingNewShowForm.value = false;
        },
    });
}

// Updates the currently-selected show's language/auto-fill. Debounced isn't
// needed since these are discrete select/checkbox changes, not free typing.
function updateShowSettings(changes) {
    router.patch(route('shows.update', { show: props.selectedShowId, entity_id: props.entity.id }),
        changes, { preserveScroll: true });
}

function deleteShow() {
    if (!selectedShow.value || selectedShow.value.is_main) return;
    if (confirm(`Delete the show "${selectedShow.value.name}"? This can't be undone.`)) {
        router.delete(route('shows.destroy', { show: selectedShow.value.id, entity_id: props.entity.id }));
    }
}

// ── Drag and drop: both within "In this show" (reorder across the 3 leader
// zones) and between the two panes (attach/detach). A slide currently in the
// global/nearby zone can still be dragged OUT — dropping it into a leader
// zone is itself the manual override (see SortZones docblock) — but those
// two zones are never valid drop targets themselves; only the 3 leader zones
// accept drops. Optimistic local update, then persist to the server.
const draggedSlide = ref(null);
const draggedFrom = ref(null); // 'unused' | 'show'

function dragStart(slide, from) {
    draggedSlide.value = slide;
    draggedFrom.value = from;
}

function dragEnd() {
    draggedSlide.value = null;
    draggedFrom.value = null;
}

function dropOnZone(zone, targetSlide = null) {
    const slide = draggedSlide.value;
    if (!slide) return;

    if (draggedFrom.value === 'unused') {
        unused.value = unused.value.filter(s => s.id !== slide.id);
        slide.zone = zone;
        inShow.value = [...inShow.value, slide];
        attachSlide(slide.id, zone);
    } else if (draggedFrom.value === 'show') {
        const list = [...inShow.value];
        const fromIndex = list.findIndex(s => s.id === slide.id);
        if (fromIndex === -1) return dragEnd();
        list.splice(fromIndex, 1);
        slide.zone = zone;

        if (targetSlide && targetSlide.id !== slide.id) {
            const toIndex = list.findIndex(s => s.id === targetSlide.id);
            list.splice(toIndex, 0, slide);
        } else {
            // No specific target row — append to the end of this zone.
            let insertAt = list.length;
            for (let i = list.length - 1; i >= 0; i--) {
                if (zoneOf(list[i]) === zone) { insertAt = i + 1; break; }
            }
            list.splice(insertAt, 0, slide);
        }
        inShow.value = list;
        persistLeaderOrder();
    }

    dragEnd();
}

function dropOnUnused() {
    const slide = draggedSlide.value;
    if (!slide || draggedFrom.value !== 'show') return;

    inShow.value = inShow.value.filter(s => s.id !== slide.id);
    unused.value = [...unused.value, slide];
    detachSlide(slide.id);

    dragEnd();
}

function attachSlide(slideId, zone) {
    router.post(route('shows.slides.attach', { show: props.selectedShowId, entity_id: props.entity.id }),
        { slide_id: slideId, zone }, { preserveScroll: true, preserveState: true });
}

function detachSlide(slideId) {
    router.delete(route('shows.slides.detach', { show: props.selectedShowId, slide: slideId, entity_id: props.entity.id }),
        { preserveScroll: true, preserveState: true });
}

function persistLeaderOrder() {
    const zones = {};
    for (const zone of LEADER_ZONES) {
        zones[zone] = zoneGroups.value[zone].map(s => s.id);
    }

    fetch(route('shows.reorder', { show: props.selectedShowId, entity_id: props.entity.id }), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ zones }),
    }).catch(err => console.error('Failed to reorder show:', err));
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <select :value="selectedShowId" @change="switchShow($event.target.value)"
                        class="rounded-lg border-gray-300 text-sm font-medium">
                        <option v-for="show in shows" :key="show.id" :value="show.id">
                            {{ show.is_main ? '🔒 ' : '' }}{{ show.name }}
                        </option>
                    </select>
                    <button v-if="isAdmin && !showingNewShowForm" @click="showingNewShowForm = true"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        + New Show
                    </button>
                    <div v-if="showingNewShowForm" class="flex flex-wrap items-center gap-2">
                        <input v-model="newShowName" type="text" placeholder="Show name"
                            class="rounded-lg border-gray-300 text-sm" @keyup.enter="createShow" />
                        <select v-model.number="newShowLanguageId" class="rounded-lg border-gray-300 text-sm">
                            <option value="">Any language</option>
                            <option v-for="lang in languages" :key="lang.id" :value="lang.id">{{ lang.name }}</option>
                        </select>
                        <label class="flex items-center gap-1 text-sm text-gray-700">
                            <input v-model="newShowAutoFillGlobal" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            Add global slides
                        </label>
                        <label class="flex items-center gap-1 text-sm text-gray-700">
                            <input v-model="newShowAutoFillNearby" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            Add nearby slides
                        </label>
                        <button @click="createShow" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Create</button>
                        <button @click="showingNewShowForm = false" class="text-sm text-gray-500">Cancel</button>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button v-if="isAdmin && selectedShow && !selectedShow.is_main" @click="deleteShow"
                        class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                        Delete Show
                    </button>
                    <button v-if="isAdmin" @click="showUploadPanel = !showUploadPanel"
                        class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Upload Slide
                    </button>
                </div>
            </div>

            <div v-if="isAdmin && selectedShow" class="space-y-3 rounded-xl border-2 border-gray-300 bg-gray-50 px-4 py-3">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    Filter by Language:
                    <select :value="selectedShow.language_id ?? ''"
                        @change="updateShowSettings({ language_id: $event.target.value || null })"
                        class="rounded-lg border-gray-300 text-sm">
                        <option value="">Any language</option>
                        <option v-for="lang in languages" :key="lang.id" :value="lang.id">{{ lang.name }}</option>
                    </select>
                </label>

                <div class="rounded-lg border-2 border-gray-300 bg-white px-3 py-2">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Auto Fill Options</p>
                    <div class="flex flex-wrap items-center gap-4 text-sm text-gray-700">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" :checked="selectedShow.auto_fill_global" :disabled="selectedShow.is_main"
                                @change="updateShowSettings({ auto_fill_global: $event.target.checked })"
                                class="rounded border-gray-300 text-indigo-600" />
                            Add global slides
                            <span v-if="selectedShow.is_main" class="text-xs text-gray-400">(always on for Main)</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" :checked="selectedShow.auto_fill_nearby"
                                @change="updateShowSettings({ auto_fill_nearby: $event.target.checked })"
                                class="rounded border-gray-300 text-indigo-600" />
                            Add nearby slides
                        </label>
                    </div>
                </div>
            </div>

            <UploadPanel
                v-if="showUploadPanel && isAdmin"
                :redirect-route="'shows.index'"
                :redirect-params="{ entity_id: entity.id, show_id: selectedShowId }"
                :entity-id="entity.id"
                :languages="languages"
                :shows="otherShows"
                @success="showUploadPanel = false"
            />

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <!-- Unused slides -->
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                    @dragover.prevent @drop="dropOnUnused">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-gray-700">Unused slides</h2>
                        <select v-model.number="unusedLanguageFilter"
                            title="Temporarily filter this list — doesn't change the show's settings"
                            class="rounded-lg border-gray-300 text-xs">
                            <option value="">Any language</option>
                            <option v-for="lang in languages" :key="lang.id" :value="lang.id">{{ lang.name }}</option>
                        </select>
                    </div>
                    <div class="space-y-2 min-h-[8rem]">
                        <ShowSlideRow v-for="slide in filteredUnused" :key="slide.id"
                            :slide="slide" :scope-badge="scopeBadge(slide)" :expires-label="expiresLabel(slide)"
                            :show-edit="canEdit(slide)"
                            @dragstart="dragStart(slide, 'unused')" @dragend="dragEnd" @edit="openEdit(slide)" @open="openLightbox" />
                        <p v-if="!filteredUnused.length" class="text-sm text-gray-400">Nothing unused right now.</p>
                    </div>
                </div>

                <!-- In this show -->
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold text-gray-700">In "{{ selectedShow?.name }}"</h2>

                    <div class="space-y-3">
                        <template v-for="zone in ZONE_ORDER" :key="zone">
                            <div v-if="LEADER_ZONES.includes(zone)"
                                class="space-y-2 rounded-lg border border-dashed border-gray-200 p-2 min-h-[3rem]"
                                @dragover.prevent @drop="dropOnZone(zone)">
                                <p class="px-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ ZONE_LABELS[zone] }}</p>
                                <ShowSlideRow v-for="slide in zoneGroups[zone]" :key="slide.id"
                                    :slide="slide" :scope-badge="scopeBadge(slide)" :expires-label="expiresLabel(slide)"
                                    :show-edit="canEdit(slide)"
                                    @dragstart="dragStart(slide, 'show')" @dragend="dragEnd"
                                    @dragover.prevent.stop @drop.stop="dropOnZone(zone, slide)"
                                    @edit="openEdit(slide)" @open="openLightbox" />
                            </div>

                            <div v-else class="space-y-2">
                                <p class="px-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ ZONE_LABELS[zone] }}</p>
                                <div class="h-2 mx-1 rounded bg-slate-800/70" title="New auto-added slides insert here"></div>
                                <ShowSlideRow v-for="slide in zoneGroups[zone]" :key="slide.id"
                                    :slide="slide" :scope-badge="scopeBadge(slide)" :expires-label="expiresLabel(slide)"
                                    :draggable="true" :auto-tag="true" :show-edit="canEdit(slide)"
                                    @dragstart="dragStart(slide, 'show')" @dragend="dragEnd" @edit="openEdit(slide)" @open="openLightbox" />
                            </div>
                        </template>

                        <p v-if="!activeInShow.length" class="text-sm text-gray-400">Drag slides here from "Unused slides."</p>
                    </div>

                    <div v-if="expiredInShow.length" class="mt-4 border-t border-gray-100 pt-3">
                        <button type="button" @click="showExpiredPane = !showExpiredPane"
                            class="flex w-full items-center justify-between text-xs font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-700">
                            <span>{{ showExpiredPane ? '▾' : '▸' }} Expired in this show ({{ expiredInShow.length }})</span>
                        </button>

                        <div v-if="showExpiredPane" class="mt-2 space-y-2">
                            <div class="flex justify-end">
                                <button type="button" @click="detachAllExpired"
                                    class="rounded-lg border border-red-200 px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50">
                                    Archive all
                                </button>
                            </div>
                            <ShowSlideRow v-for="slide in expiredInShow" :key="slide.id"
                                :slide="slide" :scope-badge="scopeBadge(slide)" :expires-label="expiresLabel(slide)"
                                :draggable="false" :dimmed="true" :show-edit="canEdit(slide)"
                                @edit="openEdit(slide)" @open="openLightbox">
                                <template #extra>
                                    <button type="button" @click.stop="detachSlide(slide.id)" title="Remove from show"
                                        class="flex-shrink-0 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                                            <path fill-rule="evenodd" d="M5.28 4.22a.75.75 0 0 0-1.06 1.06L8.94 10l-4.72 4.72a.75.75 0 1 0 1.06 1.06L10 11.06l4.72 4.72a.75.75 0 1 0 1.06-1.06L11.06 10l4.72-4.72a.75.75 0 0 0-1.06-1.06L10 8.94 5.28 4.22Z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </template>
                            </ShowSlideRow>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center">
                <Link :href="route('slides.archive', { entity_id: entity.id })"
                    class="text-sm font-medium text-gray-500 hover:text-gray-700">
                    All archived (expired) slides →
                </Link>
            </div>

            <SlideLightbox :slide="lightboxSlide" @close="closeLightbox" />

            <div v-if="editingSlide" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                @click.self="closeEdit">
                <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl bg-white p-6 shadow-lg space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">Edit Slide</h3>
                        <button @click="closeEdit" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>

                    <div class="aspect-video w-full max-w-sm overflow-hidden rounded-lg bg-slate-100">
                        <img v-if="editingSlide.thumbnail_url || editingSlide.file_url"
                            :src="editingSlide.thumbnail_url || editingSlide.file_url"
                            :alt="editingSlide.title"
                            class="h-full w-full object-contain" />
                    </div>

                    <form @submit.prevent="submitEdit" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
                            <input v-model="editForm.title" type="text" required
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <p v-if="editForm.errors.title" class="mt-1 text-xs text-red-600">{{ editForm.errors.title }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes <span class="text-gray-400 font-normal">(optional)</span></label>
                            <textarea v-model="editForm.notes" rows="2"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description <span class="text-gray-400 font-normal">(optional)</span></label>
                            <textarea v-model="editForm.text_description" rows="2"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Link <span class="text-gray-400 font-normal">(optional)</span></label>
                            <input v-model="editForm.link" type="url" placeholder="https://…"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <p v-if="editForm.errors.link" class="mt-1 text-xs text-red-600">{{ editForm.errors.link }}</p>
                        </div>

                        <div v-if="editingSlide.mime_type?.startsWith('video/')">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Video playback</label>
                            <select v-model="editForm.video_playback_mode"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="play_through">Play through, then advance immediately</option>
                                <option value="hold_last_frame">Hold last frame until slide delay</option>
                                <option value="loop">Loop until slide delay</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Language <span class="text-gray-400 font-normal">(optional)</span></label>
                            <select v-model="editForm.language_id"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">No specific language (visible in all)</option>
                                <option v-for="lang in languages" :key="lang.id" :value="lang.id">
                                    {{ lang.name }} ({{ lang.native_name }})
                                </option>
                            </select>
                            <p v-if="editForm.errors.language_id" class="mt-1 text-xs text-red-600">{{ editForm.errors.language_id }}</p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Publish Date</label>
                                <DateTimeLocalInput v-model="editForm.publish_at" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Expiration Date</label>
                                <DateTimeLocalInput v-model="editForm.expires_at" />
                            </div>
                        </div>

                        <div class="flex gap-3 pt-2">
                            <button type="submit" :disabled="editForm.processing"
                                class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                {{ editForm.processing ? 'Saving…' : 'Save Changes' }}
                            </button>
                            <button type="button" @click="closeEdit"
                                class="rounded-lg border border-gray-300 px-5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                Cancel
                            </button>
                            <button v-if="!isExpired(editingSlide)" type="button" @click="archiveEditingSlide"
                                class="ml-auto rounded-lg border border-red-200 px-5 py-2 text-sm font-medium text-red-700 hover:bg-red-50 transition-colors">
                                Archive
                            </button>
                        </div>
                    </form>

                    <MediaManager :slide="editingSlide" :media-types="mediaTypes"
                        store-route="local-slides.media.store" destroy-route="local-slides.media.destroy"
                        :route-params="{ entity_id: entity.id }" :reload-only="['showSlides', 'unusedSlides']" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
