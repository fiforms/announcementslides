<script setup>
import { computed, onMounted, provide, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import OverlayCanvas from './OverlayCanvas.vue';
import OverlayLayerList from './OverlayLayerList.vue';
import OverlayProperties from './OverlayProperties.vue';
import QrDialog from './QrDialog.vue';
import WidgetLayer from '@/Components/Widgets/WidgetLayer.vue';
import { useOverlayEditor } from '@/Composables/overlay/useOverlayEditor.js';
import { CANVAS, createImage, createQr, createRect, createSvgImport, createText, createWidget, fromSource, nextId, toSource } from '@/Composables/overlay/model.js';
import { compileOverlay } from '@/Composables/overlay/compileOverlay.js';
import { loadRasterAsDataUri, prepareSvgImport, readFileAs } from '@/Composables/overlay/importSvg.js';

// Builds a slide's overlay in the browser and saves it as the slide's
// single SVG overlay (see ManagesSlideMedia::saveOverlayForSlide). An
// overlay made elsewhere can be kept as a locked base layer to build on.
//
// It also edits a show's frame overlay (ShowController::saveOverlay): then
// `slide` is the frame ({ id, media }), `routeModel` names the route's model
// in place of the slide (e.g. { show: 3 }), and `background` is a stand-in
// picture to place things against.
const props = defineProps({
    slide: { type: Object, required: true },
    routeModel: { type: Object, default: null },
    background: { type: String, default: null },
    showRoute: { type: String, required: true },
    saveRoute: { type: String, required: true },
    destroyRoute: { type: String, required: true },
    routeParams: { type: Object, default: () => ({}) },
    reloadOnly: { type: Array, default: () => [] },
});

const { t } = useI18n();
const editor = useOverlayEditor();
provide('overlayEditor', editor);

const MAX_SVG_BYTES = 8 * 1024 * 1024;
const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

const loading = ref(true);
const saving = ref(false);
const error = ref(null);
const external = ref(null); // { svg } | { data_uri } — an overlay not made here
const qrDialog = ref(null); // { initial: element|null }
const properties = ref(null);
const imageInput = ref(null);
const svgInput = ref(null);

// Installed widgets (from the show response), keyed by slug. The live
// preview runs the real widget code over the canvas, fetching through the
// preview endpoint with the current, unsaved parameters.
const widgetList = ref([]);
const widgetCatalog = computed(() => Object.fromEntries(widgetList.value.map(w => [w.slug, w])));
const placeableWidgets = computed(() => widgetList.value.filter(w => w.enabled !== false));
const widgetMenu = ref(false);
const widgetPreview = ref(false);
provide('widgetCatalog', widgetCatalog);
provide('widgetPreview', widgetPreview);

const hasWidgets = computed(() => editor.elements.value.some(el => el.type === 'widget'));
const previewWidgets = ref([]);
let previewTimer = null;
function refreshPreview() {
    previewWidgets.value = editor.elements.value
        .filter(el => el.type === 'widget' && !el.hidden && widgetCatalog.value[el.widget])
        .map(el => ({
            id: el.id, widget: el.widget, x: el.x, y: el.y, w: el.w, h: el.h, opacity: el.opacity,
            params: { ...el.params }, entry_url: widgetCatalog.value[el.widget].entry_url,
        }));
}
// Debounced so typing a URL doesn't remount (and refetch) on every key.
watch(() => widgetPreview.value && JSON.stringify(editor.elements.value.filter(el => el.type === 'widget')), () => {
    clearTimeout(previewTimer);
    if (!widgetPreview.value) { previewWidgets.value = []; return; }
    previewTimer = setTimeout(refreshPreview, 600);
});

const primary = computed(() => props.slide.media?.find(m => m.media_type === 'slide'));
const backgroundUrl = computed(() => {
    if (props.background) return props.background;
    if (!primary.value) return props.slide.file_url;
    return primary.value.mime_type?.startsWith('image/') ? primary.value.file_url : primary.value.thumbnail_url;
});
const existingOverlay = computed(() => props.slide.media?.find(m => ['slide-overlay', 'show-overlay'].includes(m.media_type)));

defineExpose({ isDirty: () => editor.dirty.value });

function routeFor(name, extra = {}) {
    return route(name, { ...(props.routeModel ?? { slide: props.slide.id }), ...extra, ...props.routeParams });
}

onMounted(async () => {
    try {
        const { data } = await axios.get(routeFor(props.showRoute));
        widgetList.value = data.widgets ?? [];
        if (data.source && data.overlay?.svg) {
            editor.reset(fromSource(data.source, data.overlay.svg));
        } else {
            editor.reset([]);
            external.value = data.overlay;
        }
    } catch {
        editor.reset([]);
        error.value = t('overlay_editor.load_failed');
    } finally {
        loading.value = false;
    }
});

async function keepExternal() {
    const overlay = external.value;
    external.value = null;
    try {
        if (overlay.svg) {
            const id = nextId(editor.elements.value);
            editor.elements.value.unshift(createSvgImport(editor.elements.value, prepareSvgImport(overlay.svg, id)));
        } else {
            const img = await loadRasterAsDataUri(overlay.data_uri, { forcePng: true });
            const el = createImage(editor.elements.value, img.href, img.width, img.height);
            // Same object-contain placement the displays use for raster overlays.
            const scale = Math.min(CANVAS.w / img.width, CANVAS.h / img.height);
            Object.assign(el, {
                w: Math.round(img.width * scale), h: Math.round(img.height * scale), locked: true,
            });
            el.x = Math.round((CANVAS.w - el.w) / 2);
            el.y = Math.round((CANVAS.h - el.h) / 2);
            editor.elements.value.unshift(el);
        }
        editor.commit();
    } catch {
        error.value = t('overlay_editor.import_failed');
    }
}

function addText() {
    editor.add(createText(editor.elements.value));
    properties.value?.focusText();
}

function addRect() {
    editor.add(createRect(editor.elements.value));
}

async function onImagePicked(evt) {
    const file = evt.target.files?.[0];
    evt.target.value = '';
    if (!file) return;
    try {
        const dataUrl = await readFileAs(file, 'readAsDataURL');
        const img = await loadRasterAsDataUri(dataUrl, { forcePng: !/jpe?g/i.test(file.type) });
        if (img.href.length > MAX_IMAGE_BYTES) {
            error.value = t('overlay_editor.image_too_large');
            return;
        }
        editor.add(createImage(editor.elements.value, img.href, img.width, img.height));
    } catch {
        error.value = t('overlay_editor.import_failed');
    }
}

async function onSvgPicked(evt) {
    const file = evt.target.files?.[0];
    evt.target.value = '';
    if (!file) return;
    try {
        const text = await readFileAs(file, 'readAsText');
        const id = nextId(editor.elements.value);
        editor.add(createSvgImport(editor.elements.value, prepareSvgImport(text, id), false));
    } catch {
        error.value = t('overlay_editor.import_failed');
    }
}

function addWidget(entry) {
    widgetMenu.value = false;
    editor.add(createWidget(editor.elements.value, entry));
}

function applyQr(qr) {
    const initial = qrDialog.value?.initial;
    if (initial) editor.update(initial.id, qr);
    else editor.add(createQr(editor.elements.value, qr));
    qrDialog.value = null;
}

function editSelected() {
    const el = editor.selected.value;
    if (el?.type === 'qr') qrDialog.value = { initial: el };
    else if (el?.type === 'text') properties.value?.focusText();
}

function onKeydown(evt) {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(evt.target.tagName) || qrDialog.value) return;
    const mod = evt.ctrlKey || evt.metaKey;
    if (mod && evt.key.toLowerCase() === 'z') {
        evt.preventDefault();
        evt.shiftKey ? editor.redo() : editor.undo();
        return;
    }
    if (mod && evt.key.toLowerCase() === 'y') {
        evt.preventDefault();
        editor.redo();
        return;
    }

    const el = editor.selected.value;
    if (!el || el.locked) return;
    if (evt.key === 'Delete' || evt.key === 'Backspace') {
        evt.preventDefault();
        editor.remove(el.id);
        return;
    }
    const step = evt.shiftKey ? 10 : 1;
    const nudge = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[evt.key];
    if (nudge) {
        evt.preventDefault();
        editor.update(el.id, { x: el.x + nudge[0], y: el.y + nudge[1] });
    }
}

function save() {
    error.value = null;
    const elements = editor.elements.value;

    if (!elements.length) {
        if (!existingOverlay.value) return;
        if (!confirm(t('overlay_editor.confirm_remove'))) return;
        saving.value = true;
        router.delete(routeFor(props.destroyRoute, { media: existingOverlay.value.id }), {
            preserveScroll: true,
            preserveState: true,
            only: props.reloadOnly,
            onSuccess: () => { editor.markSaved(); external.value = null; },
            onFinish: () => { saving.value = false; },
        });
        return;
    }

    const svg = compileOverlay(elements);
    if (svg.length > MAX_SVG_BYTES) {
        error.value = t('overlay_editor.too_large');
        return;
    }

    saving.value = true;
    router.put(routeFor(props.saveRoute), { svg, source: JSON.stringify(toSource(elements)) }, {
        preserveScroll: true,
        preserveState: true,
        only: props.reloadOnly,
        onSuccess: () => { editor.markSaved(); external.value = null; },
        onError: errors => { error.value = Object.values(errors)[0] ?? t('overlay_editor.save_failed'); },
        onFinish: () => { saving.value = false; },
    });
}

const toolButton = 'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40';
</script>

<template>
    <div class="relative outline-none" tabindex="0" @keydown="onKeydown">
        <div v-if="loading" class="py-16 text-center text-sm text-gray-500">{{ t('overlay_editor.loading') }}</div>

        <div v-else class="space-y-3">
            <div v-if="external" class="flex flex-wrap items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                <span class="flex-1">{{ t('overlay_editor.external_notice') }}</span>
                <button type="button" class="rounded-md bg-amber-600 px-3 py-1 text-xs font-medium text-white hover:bg-amber-700" @click="keepExternal">
                    {{ t('overlay_editor.keep_as_base') }}
                </button>
                <button type="button" class="rounded-md border border-amber-300 px-3 py-1 text-xs font-medium hover:bg-amber-100" @click="external = null">
                    {{ t('overlay_editor.start_blank') }}
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                    @click="qrDialog = { initial: null }">
                    {{ t('overlay_editor.add_qr') }}
                </button>
                <button type="button" :class="toolButton" @click="addText">{{ t('overlay_editor.add_text') }}</button>
                <button type="button" :class="toolButton" @click="addRect">{{ t('overlay_editor.add_rect') }}</button>
                <button type="button" :class="toolButton" @click="imageInput.click()">{{ t('overlay_editor.add_image') }}</button>
                <button type="button" :class="toolButton" @click="svgInput.click()">{{ t('overlay_editor.import_svg') }}</button>
                <div v-if="placeableWidgets.length" class="relative">
                    <button type="button" :class="toolButton" @click="widgetMenu = !widgetMenu">{{ t('overlay_editor.add_widget') }} ▾</button>
                    <div v-if="widgetMenu" class="absolute left-0 z-20 mt-1 w-64 rounded-lg border border-gray-200 bg-white p-1 shadow-lg">
                        <button v-for="w in placeableWidgets" :key="w.slug" type="button"
                            class="flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left hover:bg-gray-50"
                            @click="addWidget(w)">
                            <img :src="w.icon_url" alt="" class="h-8 w-8 shrink-0 object-contain" />
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-gray-800">{{ w.name }}</span>
                                <span v-if="w.description" class="block truncate text-xs text-gray-500">{{ w.description }}</span>
                            </span>
                        </button>
                    </div>
                </div>
                <label v-if="hasWidgets" class="flex items-center gap-1 text-sm text-gray-700">
                    <input v-model="widgetPreview" type="checkbox" class="rounded" /> {{ t('overlay_editor.widget_live_preview') }}
                </label>
                <input ref="imageInput" type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" @change="onImagePicked" />
                <input ref="svgInput" type="file" accept="image/svg+xml,.svg" class="hidden" @change="onSvgPicked" />

                <span class="mx-1 h-6 w-px bg-gray-200" />
                <button type="button" :class="toolButton" :disabled="!editor.canUndo.value" :title="t('overlay_editor.undo')" @click="editor.undo()">↶</button>
                <button type="button" :class="toolButton" :disabled="!editor.canRedo.value" :title="t('overlay_editor.redo')" @click="editor.redo()">↷</button>

                <button type="button" class="ml-auto rounded-lg bg-indigo-600 px-5 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                    :disabled="saving || !editor.dirty.value || (!editor.elements.value.length && !existingOverlay)"
                    @click="save">
                    {{ saving ? t('overlay_editor.saving')
                        : (!editor.elements.value.length && existingOverlay) ? t('overlay_editor.remove_overlay')
                        : t('overlay_editor.save') }}
                </button>
            </div>

            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

            <div class="flex flex-col gap-4 lg:flex-row">
                <div class="min-w-0 flex-1">
                    <div class="relative">
                        <OverlayCanvas :background-url="backgroundUrl" @edit-selected="editSelected" />
                        <WidgetLayer v-if="widgetPreview && previewWidgets.length" :widgets="previewWidgets" mode="editor" class="rounded-lg" />
                    </div>
                    <p class="mt-1 text-xs text-gray-400">{{ t('overlay_editor.canvas_hint') }}</p>
                    <p v-if="hasWidgets" class="mt-0.5 text-xs text-gray-400">{{ t('overlay_editor.widget_hint') }}</p>
                </div>
                <div class="w-full shrink-0 space-y-5 lg:w-72">
                    <OverlayLayerList />
                    <OverlayProperties ref="properties" @edit-qr="el => qrDialog = { initial: el }" />
                </div>
            </div>
        </div>

        <QrDialog v-if="qrDialog" :slide="slide" :initial="qrDialog.initial" @apply="applyQr" @close="qrDialog = null" />
    </div>
</template>
