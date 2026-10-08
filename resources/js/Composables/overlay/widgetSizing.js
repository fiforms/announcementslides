import { CANVAS } from './model.js';

// A widget's manifest `sizing` (see resources/widgets/README.md): an aspect
// range (width ÷ height) and a minimum width as a fraction of the slide's,
// optionally overridden per value of one enum parameter. The server hands it
// over unchanged in the editor catalog.

// The rules that apply to a placement with these params; null if unconstrained.
export function resolveSizing(entry, params) {
    const s = entry?.sizing;
    if (!s) return null;
    const mode = s.by ? s.modes?.[String(params?.[s.by])] : null;
    const aspect = mode?.aspect ?? s.aspect ?? null;
    const minWidth = mode?.minWidth ?? s.minWidth ?? null;
    return aspect || minWidth ? { aspect, minWidth: minWidth ? Math.round(minWidth * CANVAS.w) : null } : null;
}

// Nudges a box into the rules around its center. Width wins over height: the
// aspect is fixed by changing the height.
export function fitBox(box, sizing) {
    if (!sizing) return box;
    let { x, y, w, h } = box;
    const cx = x + w / 2;
    const cy = y + h / 2;
    if (sizing.minWidth && w < sizing.minWidth) w = sizing.minWidth;
    if (sizing.aspect) {
        const ratio = w / h;
        if (ratio > sizing.aspect.max) h = w / sizing.aspect.max;
        else if (ratio < sizing.aspect.min) h = w / sizing.aspect.min;
    }
    // Growing may have pushed it off the slide; shrink back, keeping the ratio.
    const scale = Math.min(1, CANVAS.w / w, CANVAS.h / h);
    w *= scale;
    h *= scale;
    return {
        x: Math.round(Math.min(Math.max(0, cx - w / 2), CANVAS.w - w)),
        y: Math.round(Math.min(Math.max(0, cy - h / 2), CANVAS.h - h)),
        w: Math.round(w),
        h: Math.round(h),
    };
}
