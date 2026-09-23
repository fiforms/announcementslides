<script setup>
import { computed, onMounted, provide, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import OverlayCanvas from './OverlayCanvas.vue';
import OverlayLayerList from './OverlayLayerList.vue';
import OverlayProperties from './OverlayProperties.vue';
import QrDialog from './QrDialog.vue';
import { useOverlayEditor } from '@/Composables/overlay/useOverlayEditor.js';
import { CANVAS, createImage, createQr, createRect, createSvgImport, createText, fromSource, nextId, toSource } from '@/Composables/overlay/model.js';
import { compileOverlay } from '@/Composables/overlay/compileOverlay.js';
import { loadRasterAsDataUri, prepareSvgImport, readFileAs } from '@/Composables/overlay/importSvg.js';

// Builds a slide's overlay in the browser and saves it as the slide's
// single SVG overlay (see ManagesSlideMedia::saveOverlayForSlide). An
// overlay made elsewhere can be kept as a locked base layer to build on.
const props = defineProps({
    slide: { type: Object, required: true },
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

const primary = computed(() => props.slide.media?.find(m => m.media_type === 'slide'));
const backgroundUrl = computed(() => {
    if (!primary.value) return props.slide.file_url;
    return primary.value.mime_type?.startsWith('image/') ? primary.value.file_url : primary.value.thumbnail_url;
});
const existingOverlay = computed(() => props.slide.media?.find(m => m.media_type === 'slide-overlay'));

defineExpose({ isDirty: () => editor.dirty.value });

function routeFor(name) {
    return route(name, { slide: props.slide.id, ...props.routeParams });
}

onMounted(async () => {
    try {
        const { data } = await axios.get(routeFor(props.showRoute));
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
        router.delete(route(props.destroyRoute, { slide: props.slide.id, media: existingOverlay.value.id, ...props.routeParams }), {
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
                    <OverlayCanvas :background-url="backgroundUrl" @edit-selected="editSelected" />
                    <p class="mt-1 text-xs text-gray-400">{{ t('overlay_editor.canvas_hint') }}</p>
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
