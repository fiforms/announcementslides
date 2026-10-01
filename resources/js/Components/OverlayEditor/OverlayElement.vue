<script setup>
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
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

// Widgets draw a placeholder (their icon in a dashed box) — or, while the
// editor's live preview is on, just the outline, with the real widget
// running in the WidgetLayer above the canvas.
const { t } = useI18n();
const widgetCatalog = inject('widgetCatalog', null);
const widgetPreview = inject('widgetPreview', null);
const widget = computed(() => props.el.type === 'widget' ? widgetCatalog?.value?.[props.el.widget] ?? null : null);
const iconSize = computed(() => Math.max(16, Math.min(props.el.w, props.el.h) * 0.45));
</script>

<template>
    <g v-if="!el.hidden" pointer-events="none">
        <g v-if="el.type === 'widget'" :opacity="el.opacity ?? 1">
            <rect :x="el.x" :y="el.y" :width="el.w" :height="el.h" rx="8"
                :fill="widgetPreview?.value ? 'none' : (widget ? 'rgba(99,102,241,0.18)' : 'rgba(220,38,38,0.2)')"
                :stroke="widget ? '#a5b4fc' : '#f87171'" stroke-width="2" stroke-dasharray="10 6" vector-effect="non-scaling-stroke" />
            <template v-if="!widgetPreview?.value">
                <image v-if="widget" :href="widget.icon_url" :width="iconSize" :height="iconSize"
                    :x="el.x + (el.w - iconSize) / 2" :y="el.y + (el.h - iconSize) / 2 - Math.min(24, el.h * 0.08)"
                    preserveAspectRatio="xMidYMid meet" />
                <text :x="el.x + el.w / 2" :y="el.y + el.h / 2 + iconSize / 2 + Math.min(36, el.h * 0.12)"
                    text-anchor="middle" font-family="sans-serif" :font-size="Math.max(14, Math.min(32, el.h * 0.08))"
                    fill="#ffffff" stroke="#1e293b" stroke-width="4" paint-order="stroke">
                    {{ widget ? widget.name : t('overlay_editor.widget_missing', { slug: el.widget }) }}
                </text>
            </template>
        </g>
        <g v-else-if="html" v-html="html" />
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
