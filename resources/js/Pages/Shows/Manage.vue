<script setup>
import { ref, computed, watch } from 'vue';
import { router, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UploadPanel from '@/Components/UploadPanel.vue';
import ShowSlideRow from '@/Components/ShowSlideRow.vue';
import SlideLightbox from '@/Components/SlideLightbox.vue';
import MediaManager from '@/Components/MediaManager.vue';
import OverlayEditor from '@/Components/OverlayEditor/OverlayEditor.vue';
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

const { locale, t } = useI18n();

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
    global: { key: 'scope_global', classes: 'bg-blue-50 text-blue-700', dot: 'bg-blue-500' },
    nearby: { key: 'scope_nearby', classes: 'bg-purple-50 text-purple-700', dot: 'bg-purple-500' },
    mine:   { key: 'scope_mine',   classes: 'bg-green-50 text-green-700', dot: 'bg-green-500' },
};

function scopeBadge(slide) {
    const badge = scopeBadges[slideScope(slide)];
    return { ...badge, label: t(`show_manage.${badge.key}`) };
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
const editTab = ref('details');
const overlayEditor = ref(null);

// Unsaved overlay edits live only in the editor, which unmounts on tab
// switch or close — confirm before throwing them away.
function confirmDiscardOverlay() {
    return !overlayEditor.value?.isDirty() || confirm(t('overlay_editor.confirm_discard'));
}

function switchEditTab(tab) {
    if (tab === editTab.value) return;
    if (editTab.value === 'overlay' && !confirmDiscardOverlay()) return;
    editTab.value = tab;
}
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
    editTab.value = 'details';
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
    if (editTab.value === 'overlay' && !confirmDiscardOverlay()) return;
    editingSlide.value = null;
}

function submitEdit() {
    editForm.patch(route('local-slides.update', { slide: editingSlide.value.id, entity_id: props.entity.id }), {
        preserveScroll: true,
        onSuccess: () => { editingSlide.value = null; },
    });
}

function archiveEditingSlide() {
    if (!confirm(t('show_manage.archive_confirm', { title: editingSlide.value.title }))) return;
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
    const dateStr = d.toLocaleDateString(locale.value, { month: 'short', day: 'numeric', year: 'numeric' });
    return t(isExpired(slide) ? 'show_manage.expired_on' : 'show_manage.expires_on', { date: dateStr });
}

const activeInShow = computed(() => inShow.value.filter(s => !isExpired(s) && matchesLanguage(s)));
const expiredInShow = computed(() => inShow.value.filter(s => isExpired(s) && matchesLanguage(s)));
const showExpiredPane = ref(false);

function detachAllExpired() {
    if (!expiredInShow.value.length) return;
    if (!confirm(t('show_manage.remove_expired_confirm', { n: expiredInShow.value.length, show: showName(selectedShow.value) }, expiredInShow.value.length))) return;
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
const zoneLabel = (zone) => t(`show_manage.zone_${zone}`);

// Stored show names are user data, except the built-in Main show.
const showName = (show) => (show?.is_main ? t('shows.main') : show?.name);

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

// The interface language's matching `languages` row, if any — the default
// for the page's display-only language filter.
const uiLanguageId = computed(() => props.languages.find(l => l.abbreviation === locale.value)?.id ?? '');

const showUploadPanel = ref(false);
const otherShows = computed(() => props.shows.filter(s => !s.is_main));

const inShow = ref([...props.showSlides]);
const unused = ref([...props.unusedSlides]);
const newShowName = ref('');
const newShowAutoFillGlobal = ref(false);
const newShowAutoFillNearby = ref(false);
const showingNewShowForm = ref(false);

// Display-only language filter for both lists on this page — never sent to
// the server or saved onto a show (shows hold every language; Slide
// Announcers filter by their own language). Defaults to the interface
// language, and a slide with no language tag always stays visible.
const languageFilter = ref(uiLanguageId.value);
const matchesLanguage = (s) => !languageFilter.value || s.language_id === null || s.language_id === languageFilter.value;

// "This Show" vs "All Shows" scope for the left panel — also client-only.
// The server already excludes membership in *this* show from `unused`, and
// tags each remaining slide with whether it's linked into some other show
// of this entity (`linked_elsewhere`); "All Shows" just narrows that down to
// slides linked into nothing at all, so a slide can end up attached to more
// than one show without a second round trip to change scope.
const unusedScope = ref('all');
const filteredUnused = computed(() => unused.value.filter(s =>
    matchesLanguage(s)
    && (unusedScope.value === 'this' || !s.linked_elsewhere)
));

watch(() => [props.showSlides, props.unusedSlides], () => {
    inShow.value = [...props.showSlides];
    unused.value = [...props.unusedSlides];
    // Keep the open edit modal pointed at the refreshed slide (new media,
    // new overlay) rather than the stale object from before the reload.
    if (editingSlide.value) {
        editingSlide.value = [...props.showSlides, ...props.unusedSlides]
            .find(s => s.id === editingSlide.value.id) ?? editingSlide.value;
    }
});

const selectedShow = computed(() => props.shows.find(s => s.id === props.selectedShowId));

function switchShow(showId) {
    router.get(route('shows.index', { entity_id: props.entity.id, show_id: showId }));
}

function createShow() {
    if (!newShowName.value.trim()) return;
    router.post(route('shows.store', { entity_id: props.entity.id }), {
        name: newShowName.value,
        auto_fill_global: newShowAutoFillGlobal.value,
        auto_fill_nearby: newShowAutoFillNearby.value,
    }, {
        onSuccess: () => {
            newShowName.value = '';
            newShowAutoFillGlobal.value = false;
            newShowAutoFillNearby.value = false;
            showingNewShowForm.value = false;
        },
    });
}

// Updates the currently-selected show's auto-fill. Debounced isn't
// needed since these are discrete select/checkbox changes, not free typing.
function updateShowSettings(changes) {
    router.patch(route('shows.update', { show: props.selectedShowId, entity_id: props.entity.id }),
        changes, { preserveScroll: true });
}

function deleteShow() {
    if (!selectedShow.value || selectedShow.value.is_main) return;
    if (confirm(t('show_manage.delete_show_confirm', { show: showName(selectedShow.value) }))) {
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

const lockedNotice = ref('');
let lockedNoticeTimer = null;

function showLockedNotice() {
    lockedNotice.value = t('show_manage.required_cannot_remove');
    clearTimeout(lockedNoticeTimer);
    lockedNoticeTimer = setTimeout(() => { lockedNotice.value = ''; }, 6000);
}

function dropOnUnused() {
    const slide = draggedSlide.value;
    if (slide?.locked && draggedFrom.value === 'show') {
        showLockedNotice();
        return dragEnd();
    }
    if (!slide || draggedFrom.value !== 'show') return dragEnd();

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
                            {{ show.is_main ? '🔒 ' : '' }}{{ showName(show) }}
                        </option>
                    </select>
                    <select v-model.number="languageFilter"
                        :title="$t('show_manage.language_filter_hint')"
                        class="rounded-lg border-gray-300 text-sm">
                        <option value="">{{ $t('show_manage.all_languages') }}</option>
                        <option v-for="lang in languages" :key="lang.id" :value="lang.id">{{ lang.name }}</option>
                    </select>
                    <button v-if="isAdmin && !showingNewShowForm" @click="showingNewShowForm = true"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        {{ $t('show_manage.new_show') }}
                    </button>
                    <div v-if="showingNewShowForm" class="flex flex-wrap items-center gap-2">
                        <input v-model="newShowName" type="text" :placeholder="$t('show_manage.show_name_placeholder')"
                            class="rounded-lg border-gray-300 text-sm" @keyup.enter="createShow" />
                        <label class="flex items-center gap-1 text-sm text-gray-700">
                            <input v-model="newShowAutoFillGlobal" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            {{ $t('show_manage.add_global') }}
                        </label>
                        <label class="flex items-center gap-1 text-sm text-gray-700">
                            <input v-model="newShowAutoFillNearby" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            {{ $t('show_manage.add_nearby') }}
                        </label>
                        <button @click="createShow" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">{{ $t('show_manage.create') }}</button>
                        <button @click="showingNewShowForm = false" class="text-sm text-gray-500">{{ $t('show_manage.cancel') }}</button>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button v-if="isAdmin && selectedShow && !selectedShow.is_main" @click="deleteShow"
                        class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                        {{ $t('show_manage.delete_show') }}
                    </button>
                    <button v-if="isAdmin" @click="showUploadPanel = !showUploadPanel"
                        class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        {{ $t('show_manage.upload_slide') }}
                    </button>
                </div>
            </div>

            <div v-if="isAdmin && selectedShow" class="space-y-3 rounded-xl border-2 border-gray-300 bg-gray-50 px-4 py-3">
                <div class="rounded-lg border-2 border-gray-300 bg-white px-3 py-2">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $t('show_manage.auto_fill_options') }}</p>
                    <div class="flex flex-wrap items-center gap-4 text-sm text-gray-700">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" :checked="selectedShow.auto_fill_global" :disabled="selectedShow.is_main"
                                @change="updateShowSettings({ auto_fill_global: $event.target.checked })"
                                class="rounded border-gray-300 text-indigo-600" />
                            {{ $t('show_manage.add_global') }}
                            <span v-if="selectedShow.is_main" class="text-xs text-gray-400">{{ $t('show_manage.always_on_main') }}</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" :checked="selectedShow.auto_fill_nearby"
                                @change="updateShowSettings({ auto_fill_nearby: $event.target.checked })"
                                class="rounded border-gray-300 text-indigo-600" />
                            {{ $t('show_manage.add_nearby') }}
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
                        <h2 class="text-sm font-semibold text-gray-700">
                            {{ unusedScope === 'this' ? $t('show_manage.available_slides') : $t('show_manage.unused_slides') }}
                        </h2>
                    </div>
                    <div class="mb-3 flex items-center gap-4 text-xs text-gray-600">
                        <label class="flex items-center gap-1.5 cursor-pointer">
                            <input type="radio" value="this" v-model="unusedScope" class="text-indigo-600 focus:ring-indigo-500" />
                            {{ $t('show_manage.all_available') }}
                        </label>
                        <label class="flex items-center gap-1.5 cursor-pointer">
                            <input type="radio" value="all" v-model="unusedScope" class="text-indigo-600 focus:ring-indigo-500" />
                            {{ $t('show_manage.only_unused') }}
                        </label>
                    </div>
                    <div class="space-y-2 min-h-[8rem]">
                        <ShowSlideRow v-for="slide in filteredUnused" :key="slide.id"
                            :slide="slide" :scope-badge="scopeBadge(slide)" :expires-label="expiresLabel(slide)"
                            :show-edit="canEdit(slide)"
                            @dragstart="dragStart(slide, 'unused')" @dragend="dragEnd" @edit="openEdit(slide)" @open="openLightbox">
                            <template v-if="slide.linked_elsewhere" #extra>
                                <span class="flex-shrink-0 text-[10px] text-gray-400"              :title="$t('show_manage.already_attached')">
                                    {{ $t('show_manage.in_another_show') }}
                                </span>
                            </template>
                        </ShowSlideRow>
                        <p v-if="!filteredUnused.length" class="text-sm text-gray-400">
                            {{ unusedScope === 'this' ? $t('show_manage.nothing_available') : $t('show_manage.nothing_unused') }}
                        </p>
                    </div>
                </div>

                <!-- In this show -->
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold text-gray-700">{{ $t('show_manage.in_show', { show: showName(selectedShow) }) }}</h2>

                    <p v-if="lockedNotice" role="alert"
                        class="mb-3 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs text-indigo-800">{{ lockedNotice }}</p>

                    <div class="space-y-3">
                        <template v-for="zone in ZONE_ORDER" :key="zone">
                            <div v-if="LEADER_ZONES.includes(zone)"
                                class="space-y-2 rounded-lg border border-dashed border-gray-200 p-2 min-h-[3rem]"
                                @dragover.prevent @drop="dropOnZone(zone)">
                                <p class="px-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ zoneLabel(zone) }}</p>
                                <ShowSlideRow v-for="slide in zoneGroups[zone]" :key="slide.id"
                                    :slide="slide" :scope-badge="scopeBadge(slide)" :expires-label="expiresLabel(slide)"
                                    :show-edit="canEdit(slide)"
                                    @dragstart="dragStart(slide, 'show')" @dragend="dragEnd"
                                    @dragover.prevent.stop @drop.stop="dropOnZone(zone, slide)"
                                    @edit="openEdit(slide)" @open="openLightbox" />
                            </div>

                            <div v-else class="space-y-2">
                                <p class="px-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ zoneLabel(zone) }}</p>
                                <div class="h-2 mx-1 rounded bg-slate-800/70" :title="$t('show_manage.auto_insert_here')"></div>
                                <ShowSlideRow v-for="slide in zoneGroups[zone]" :key="slide.id"
                                    :slide="slide" :scope-badge="scopeBadge(slide)" :expires-label="expiresLabel(slide)"
                                    :draggable="true" :auto-tag="true" :show-edit="canEdit(slide)"
                                    @dragstart="dragStart(slide, 'show')" @dragend="dragEnd" @edit="openEdit(slide)" @open="openLightbox" />
                            </div>
                        </template>

                        <p v-if="!activeInShow.length" class="text-sm text-gray-400">{{ $t('show_manage.drag_here') }}</p>
                    </div>

                    <div v-if="expiredInShow.length" class="mt-4 border-t border-gray-100 pt-3">
                        <button type="button" @click="showExpiredPane = !showExpiredPane"
                            class="flex w-full items-center justify-between text-xs font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-700">
                            <span>{{ showExpiredPane ? '▾' : '▸' }} {{ $t('show_manage.expired_in_show', { n: expiredInShow.length }) }}</span>
                        </button>

                        <div v-if="showExpiredPane" class="mt-2 space-y-2">
                            <div class="flex justify-end">
                                <button type="button" @click="detachAllExpired"
                                    class="rounded-lg border border-red-200 px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50">
                                    {{ $t('show_manage.archive_all') }}
                                </button>
                            </div>
                            <ShowSlideRow v-for="slide in expiredInShow" :key="slide.id"
                                :slide="slide" :scope-badge="scopeBadge(slide)" :expires-label="expiresLabel(slide)"
                                :draggable="false" :dimmed="true" :show-edit="canEdit(slide)"
                                @edit="openEdit(slide)" @open="openLightbox">
                                <template #extra>
                                    <button type="button" @click.stop="detachSlide(slide.id)" :title="$t('show_manage.remove_from_show')"
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
                    {{ $t('show_manage.all_archived') }}
                </Link>
            </div>

            <SlideLightbox :slide="lightboxSlide" @close="closeLightbox" />

            <div v-if="editingSlide" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                @click.self="closeEdit">
                <div class="relative w-full max-h-[90vh] overflow-y-auto rounded-xl bg-white p-6 shadow-lg space-y-4"
                    :class="editTab === 'overlay' ? 'max-w-6xl' : 'max-w-2xl'">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">{{ $t('show_manage.edit_slide') }}</h3>
                        <button @click="closeEdit" :aria-label="$t('show_manage.close')" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>

                    <nav class="-mb-px flex gap-6 border-b border-gray-200">
                        <button v-for="tab in ['details', 'overlay']" :key="tab" type="button"
                            @click="switchEditTab(tab)"
                            class="pb-3 text-sm font-medium border-b-2 transition-colors"
                            :class="editTab === tab ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'">
                            {{ t(`overlay_editor.tab_${tab}`) }}
                        </button>
                    </nav>

                    <OverlayEditor v-if="editTab === 'overlay'" ref="overlayEditor" :slide="editingSlide"
                        show-route="local-slides.overlay.show" save-route="local-slides.overlay.save"
                        destroy-route="local-slides.media.destroy"
                        :route-params="{ entity_id: entity.id }" :reload-only="['showSlides', 'unusedSlides']" />

                    <template v-else>
                        <div class="aspect-video w-full max-w-sm overflow-hidden rounded-lg bg-slate-100">
                            <img v-if="editingSlide.thumbnail_url || editingSlide.file_url"
                                :src="editingSlide.thumbnail_url || editingSlide.file_url"
                                :alt="editingSlide.title"
                                class="h-full w-full object-contain" />
                        </div>

                        <form @submit.prevent="submitEdit" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('show_manage.title') }} <span class="text-red-500">*</span></label>
                                <input v-model="editForm.title" type="text" required
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                <p v-if="editForm.errors.title" class="mt-1 text-xs text-red-600">{{ editForm.errors.title }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('show_manage.notes') }} <span class="text-gray-400 font-normal">{{ $t('show_manage.optional') }}</span></label>
                                <textarea v-model="editForm.notes" rows="2"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('show_manage.description') }} <span class="text-gray-400 font-normal">{{ $t('show_manage.optional') }}</span></label>
                                <textarea v-model="editForm.text_description" rows="2"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('show_manage.link') }} <span class="text-gray-400 font-normal">{{ $t('show_manage.optional') }}</span></label>
                                <input v-model="editForm.link" type="url" placeholder="https://…"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                <p v-if="editForm.errors.link" class="mt-1 text-xs text-red-600">{{ editForm.errors.link }}</p>
                            </div>

                            <div v-if="editingSlide.mime_type?.startsWith('video/')">
                                <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('show_manage.video_playback') }}</label>
                                <select v-model="editForm.video_playback_mode"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="play_through">{{ $t('show_manage.video_play_through') }}</option>
                                    <option value="hold_last_frame">{{ $t('show_manage.video_hold_last_frame') }}</option>
                                    <option value="loop">{{ $t('show_manage.video_loop') }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('show_manage.language') }} <span class="text-gray-400 font-normal">{{ $t('show_manage.optional') }}</span></label>
                                <select v-model="editForm.language_id"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">{{ $t('show_manage.no_language') }}</option>
                                    <option v-for="lang in languages" :key="lang.id" :value="lang.id">
                                        {{ lang.name }} ({{ lang.native_name }})
                                    </option>
                                </select>
                                <p v-if="editForm.errors.language_id" class="mt-1 text-xs text-red-600">{{ editForm.errors.language_id }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('show_manage.publish_date') }}</label>
                                    <DateTimeLocalInput v-model="editForm.publish_at" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('show_manage.expiration_date') }}</label>
                                    <DateTimeLocalInput v-model="editForm.expires_at" />
                                </div>
                            </div>

                            <div class="flex gap-3 pt-2">
                                <button type="submit" :disabled="editForm.processing"
                                    class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                    {{ editForm.processing ? $t('show_manage.saving') : $t('show_manage.save_changes') }}
                                </button>
                                <button type="button" @click="closeEdit"
                                    class="rounded-lg border border-gray-300 px-5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                                    {{ $t('show_manage.cancel') }}
                                </button>
                                <button v-if="!isExpired(editingSlide)" type="button" @click="archiveEditingSlide"
                                    class="ml-auto rounded-lg border border-red-200 px-5 py-2 text-sm font-medium text-red-700 hover:bg-red-50 transition-colors">
                                    {{ $t('show_manage.archive') }}
                                </button>
                            </div>
                        </form>

                        <MediaManager :slide="editingSlide" :media-types="mediaTypes"
                            store-route="local-slides.media.store" destroy-route="local-slides.media.destroy"
                            :route-params="{ entity_id: entity.id }" :reload-only="['showSlides', 'unusedSlides']" />
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
