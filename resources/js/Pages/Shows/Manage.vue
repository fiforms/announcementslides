<script setup>
import { ref, computed, watch } from 'vue';
import { router, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UploadPanel from '@/Components/UploadPanel.vue';

const props = defineProps({
    entity: { type: Object, required: true },
    shows: { type: Array, default: () => [] },
    selectedShowId: { type: Number, required: true },
    showSlides: { type: Array, default: () => [] },
    unusedSlides: { type: Array, default: () => [] },
    isAdmin: { type: Boolean, default: false },
    languages: { type: Array, default: () => [] },
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

// ── Drag and drop: both within "In this show" (reorder) and between the two
// panes (attach/detach). Optimistic local update, then persist to the server.
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

function dropOnShow(targetSlide = null) {
    const slide = draggedSlide.value;
    if (!slide) return;

    if (draggedFrom.value === 'unused') {
        unused.value = unused.value.filter(s => s.id !== slide.id);
        inShow.value = [...inShow.value, slide];
        attachSlide(slide.id);
    } else if (targetSlide && targetSlide.id !== slide.id) {
        const list = [...inShow.value];
        const fromIndex = list.findIndex(s => s.id === slide.id);
        list.splice(fromIndex, 1);
        const toIndex = list.findIndex(s => s.id === targetSlide.id);
        list.splice(toIndex, 0, slide);
        inShow.value = list;
        persistOrder();
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

function attachSlide(slideId) {
    router.post(route('shows.slides.attach', { show: props.selectedShowId, entity_id: props.entity.id }),
        { slide_id: slideId }, { preserveScroll: true, preserveState: true });
}

function detachSlide(slideId) {
    router.delete(route('shows.slides.detach', { show: props.selectedShowId, slide: slideId, entity_id: props.entity.id }),
        { preserveScroll: true, preserveState: true });
}

function persistOrder() {
    fetch(route('shows.reorder', { show: props.selectedShowId, entity_id: props.entity.id }), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ slides: inShow.value.map(s => s.id) }),
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
                        <div v-for="slide in filteredUnused" :key="slide.id" draggable="true"
                            @dragstart="dragStart(slide, 'unused')" @dragend="dragEnd"
                            class="flex items-center gap-3 rounded-lg border border-gray-200 p-2 cursor-grab active:cursor-grabbing hover:bg-gray-50">
                            <div class="h-10 w-16 flex-shrink-0 rounded overflow-hidden bg-slate-100">
                                <img v-if="slide.thumbnail_url" :src="slide.thumbnail_url" class="h-full w-full object-cover" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 line-clamp-1">{{ slide.title }}</p>
                                <span :class="scopeBadge(slide).classes" class="mt-0.5 inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium">
                                    <span :class="scopeBadge(slide).dot" class="h-1.5 w-1.5 rounded-full"></span>
                                    {{ scopeBadge(slide).label }}
                                </span>
                            </div>
                            <button v-if="canEdit(slide)" @click.stop="openEdit(slide)" title="Edit slide"
                                class="flex-shrink-0 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                                    <path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-9 9a2 2 0 0 1-.878.507l-3 .824a.5.5 0 0 1-.615-.615l.824-3a2 2 0 0 1 .507-.878l9-9Z" />
                                </svg>
                            </button>
                        </div>
                        <p v-if="!filteredUnused.length" class="text-sm text-gray-400">Nothing unused right now.</p>
                    </div>
                </div>

                <!-- In this show -->
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                    @dragover.prevent @drop="dropOnShow()">
                    <h2 class="mb-3 text-sm font-semibold text-gray-700">In "{{ selectedShow?.name }}"</h2>
                    <div class="space-y-2 min-h-[8rem]">
                        <div v-for="slide in inShow" :key="slide.id" draggable="true"
                            @dragstart="dragStart(slide, 'show')" @dragend="dragEnd"
                            @dragover.prevent.stop @drop.stop="dropOnShow(slide)"
                            class="flex items-center gap-3 rounded-lg border border-gray-200 p-2 cursor-grab active:cursor-grabbing hover:bg-gray-50">
                            <div class="h-10 w-16 flex-shrink-0 rounded overflow-hidden bg-slate-100">
                                <img v-if="slide.thumbnail_url" :src="slide.thumbnail_url" class="h-full w-full object-cover" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 line-clamp-1">{{ slide.title }}</p>
                                <span :class="scopeBadge(slide).classes" class="mt-0.5 inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium">
                                    <span :class="scopeBadge(slide).dot" class="h-1.5 w-1.5 rounded-full"></span>
                                    {{ scopeBadge(slide).label }}
                                </span>
                            </div>
                            <button v-if="canEdit(slide)" @click.stop="openEdit(slide)" title="Edit slide"
                                class="flex-shrink-0 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                                    <path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-9 9a2 2 0 0 1-.878.507l-3 .824a.5.5 0 0 1-.615-.615l.824-3a2 2 0 0 1 .507-.878l9-9Z" />
                                </svg>
                            </button>
                        </div>
                        <p v-if="!inShow.length" class="text-sm text-gray-400">Drag slides here from "Unused slides."</p>
                    </div>
                </div>
            </div>

            <div class="text-center">
                <Link :href="route('slides.archive', { entity_id: entity.id })"
                    class="text-sm font-medium text-gray-500 hover:text-gray-700">
                    View archived (expired) slides →
                </Link>
            </div>

            <div v-if="editingSlide" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                @click.self="closeEdit">
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-lg space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">Edit Slide</h3>
                        <button @click="closeEdit" class="text-gray-400 hover:text-gray-600">&times;</button>
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
                                <input v-model="editForm.publish_at" type="datetime-local"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Expiration Date</label>
                                <input v-model="editForm.expires_at" type="datetime-local"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
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
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
