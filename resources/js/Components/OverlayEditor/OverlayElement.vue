<script setup>
import { computed } from 'vue';
import { compileElement, qrCornerRadius } from '@/Composables/overlay/compileOverlay.js';

// Renders one overlay element on the editor canvas. Text and rectangles
// reuse the compiler's output directly (cheap to rebuild); image-like
// elements bind geometry as attributes so dragging never re-parses their
// (potentially multi-megabyte) content.
const props = defineProps({
    el: { type: Object, required: true },
});

const html = computed(() => (props.el.type === 'text' || props.el.type === 'rect') ? compileElement(props.el) : '');
// QR background square, bound as attributes like the rest of this branch.
const qrBox = computed(() => {
    if (props.el.type !== 'qr' || !props.el.backgroundEnabled) return null;
    const viewBoxWidth = Number(String(props.el.viewBox ?? '').split(/[\s,]+/)[2]) || props.el.w;
    return { rx: qrCornerRadius(props.el.viewBox, props.el.radius) * (props.el.w / viewBoxWidth) };
});
const aspect = computed(() => props.el.type === 'svg-import' ? 'xMidYMid meet' : 'none');
</script>

<template>
    <g v-if="!el.hidden" pointer-events="none">
        <g v-if="html" v-html="html" />
        <g v-else :opacity="el.opacity ?? 1">
            <rect v-if="qrBox" :x="el.x" :y="el.y" :width="el.w" :height="el.h" :rx="qrBox.rx"
                :fill="el.background" :fill-opacity="el.backgroundOpacity ?? 1" />
            <image v-if="el.type === 'image'" :x="el.x" :y="el.y" :width="el.w" :height="el.h"
                preserveAspectRatio="none" :href="el.href" />
            <svg v-else :x="el.x" :y="el.y" :width="el.w" :height="el.h" :viewBox="el.viewBox"
                :preserveAspectRatio="aspect" overflow="hidden" v-html="el.markup" />
        </g>
    </g>
</template>
