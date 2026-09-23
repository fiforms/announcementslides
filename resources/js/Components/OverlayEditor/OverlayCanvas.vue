<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue';
import OverlayElement from './OverlayElement.vue';
import { ASPECT_LOCKED, CANVAS } from '@/Composables/overlay/model.js';

// The 1920×1080 editing surface: the slide itself as a reference backdrop
// (not part of the overlay), the elements, invisible hit areas for
// selecting/dragging, and resize handles on the selection. Coordinates are
// converted from screen space with getScreenCTM so the canvas can be any
// on-screen size.
const props = defineProps({
    backgroundUrl: { type: String, default: null },
});
const emit = defineEmits(['edit-selected']);

const editor = inject('overlayEditor');
const svgEl = ref(null);
const unitsPerPx = ref(1);

const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
const MIN_SIZE = 10;

const interactive = computed(() => editor.elements.value.filter(el => !el.hidden && !el.locked));
const handleSize = computed(() => 12 * unitsPerPx.value);

function measure() {
    if (svgEl.value?.clientWidth) unitsPerPx.value = CANVAS.w / svgEl.value.clientWidth;
}
let observer;
onMounted(() => {
    measure();
    observer = new ResizeObserver(measure);
    observer.observe(svgEl.value);
});
onBeforeUnmount(() => observer?.disconnect());

function toSvgPoint(evt) {
    const pt = svgEl.value.createSVGPoint();
    pt.x = evt.clientX;
    pt.y = evt.clientY;
    return pt.matrixTransform(svgEl.value.getScreenCTM().inverse());
}

function handlePos(el, handle) {
    const x = handle.includes('w') ? el.x : handle.includes('e') ? el.x + el.w : el.x + el.w / 2;
    const y = handle.includes('n') ? el.y : handle.includes('s') ? el.y + el.h : el.y + el.h / 2;
    return { x, y };
}

const cursors = { nw: 'nwse-resize', se: 'nwse-resize', ne: 'nesw-resize', sw: 'nesw-resize', n: 'ns-resize', s: 'ns-resize', e: 'ew-resize', w: 'ew-resize' };

let gesture = null;

function startGesture(evt, el, handle = null) {
    if (evt.button !== 0) return;
    editor.select(el.id);
    const start = toSvgPoint(evt);
    gesture = { id: el.id, handle, start, orig: { x: el.x, y: el.y, w: el.w, h: el.h }, moved: false };
    svgEl.value.setPointerCapture(evt.pointerId);
}

function onPointerMove(evt) {
    if (!gesture) return;
    const pt = toSvgPoint(evt);
    const dx = pt.x - gesture.start.x;
    const dy = pt.y - gesture.start.y;
    if (!gesture.moved && Math.hypot(dx, dy) < 2 * unitsPerPx.value) return;
    gesture.moved = true;

    const { orig, handle } = gesture;
    if (!handle) {
        editor.update(gesture.id, { x: Math.round(orig.x + dx), y: Math.round(orig.y + dy) }, false);
        return;
    }

    let { x, y, w, h } = orig;
    if (handle.includes('e')) w = orig.w + dx;
    if (handle.includes('w')) w = orig.w - dx;
    if (handle.includes('s')) h = orig.h + dy;
    if (handle.includes('n')) h = orig.h - dy;
    w = Math.max(MIN_SIZE, w);
    h = Math.max(MIN_SIZE, h);

    const el = editor.find(gesture.id);
    const keepAspect = el.type === 'qr' || (ASPECT_LOCKED.has(el.type) !== evt.shiftKey);
    if (keepAspect) {
        const ratio = orig.w / orig.h;
        if (handle === 'n' || handle === 's') w = h * ratio;
        else h = w / ratio;
    }

    if (handle.includes('w')) x = orig.x + orig.w - w;
    if (handle.includes('n')) y = orig.y + orig.h - h;
    if (keepAspect && (handle === 'n' || handle === 's')) x = orig.x + (orig.w - w) / 2;
    if (keepAspect && (handle === 'e' || handle === 'w')) y = orig.y + (orig.h - h) / 2;

    editor.update(gesture.id, { x: Math.round(x), y: Math.round(y), w: Math.round(w), h: Math.round(h) }, false);
}

function endGesture(evt) {
    if (!gesture) return;
    if (svgEl.value.hasPointerCapture?.(evt.pointerId)) svgEl.value.releasePointerCapture(evt.pointerId);
    if (gesture.moved) editor.commit();
    gesture = null;
}

function onBackgroundDown(evt) {
    if (evt.target === svgEl.value || evt.target.dataset?.backdrop !== undefined) editor.select(null);
}
</script>

<template>
    <svg ref="svgEl" :viewBox="`0 0 ${CANVAS.w} ${CANVAS.h}`"
        class="block h-auto w-full touch-none select-none rounded-lg bg-slate-800"
        @pointerdown="onBackgroundDown" @pointermove="onPointerMove" @pointerup="endGesture" @pointercancel="endGesture">
        <image v-if="backgroundUrl" data-backdrop :href="backgroundUrl" x="0" y="0" :width="CANVAS.w" :height="CANVAS.h"
            preserveAspectRatio="xMidYMid meet" />

        <OverlayElement v-for="el in editor.elements.value" :key="el.id" :el="el" />

        <rect v-for="el in interactive" :key="`hit-${el.id}`" :x="el.x" :y="el.y" :width="el.w" :height="el.h"
            fill="transparent" class="cursor-move"
            @pointerdown.stop="startGesture($event, el)" @dblclick="emit('edit-selected')" />

        <g v-if="editor.selected.value && !editor.selected.value.hidden" pointer-events="none">
            <rect :x="editor.selected.value.x" :y="editor.selected.value.y"
                :width="editor.selected.value.w" :height="editor.selected.value.h"
                fill="none" stroke="#6366f1" stroke-width="2" vector-effect="non-scaling-stroke"
                :stroke-dasharray="editor.selected.value.locked ? '6 4' : null" />
        </g>
        <template v-if="editor.selected.value && !editor.selected.value.hidden && !editor.selected.value.locked">
            <rect v-for="handle in HANDLES" :key="handle"
                :x="handlePos(editor.selected.value, handle).x - handleSize / 2"
                :y="handlePos(editor.selected.value, handle).y - handleSize / 2"
                :width="handleSize" :height="handleSize"
                fill="#ffffff" stroke="#6366f1" stroke-width="1.5" vector-effect="non-scaling-stroke"
                :style="{ cursor: cursors[handle] }"
                @pointerdown.stop="startGesture($event, editor.selected.value, handle)" />
        </template>
    </svg>
</template>
