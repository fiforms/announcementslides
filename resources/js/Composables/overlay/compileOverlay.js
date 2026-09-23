import { CANVAS } from './model.js';

// Compiles the editor model to a single self-contained SVG string — the
// file the displays render over the slide. Pure and synchronous: QR codes
// and imported SVGs are pre-rendered into each element's `markup`.

export const LINE_HEIGHT = 1.2;
export const TEXT_PADDING = 24;

export function escapeXml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&apos;');
}

function num(value) {
    const n = Number(value);
    return Number.isFinite(n) ? Math.round(n * 100) / 100 : 0;
}

function color(value, fallback = '#000000') {
    return /^#[0-9a-f]{3,8}$/i.test(value ?? '') ? value : fallback;
}

function viewBox(value) {
    return /^[-\d.eE\s,]+$/.test(value ?? '') ? value : `0 0 ${CANVAS.w} ${CANVAS.h}`;
}

function wrapperAttrs(el) {
    let attrs = `id="${escapeXml(el.id)}"`;
    if (num(el.opacity ?? 1) < 1) attrs += ` opacity="${num(el.opacity)}"`;
    // Hidden layers stay in the file (display:none) so their content survives.
    if (el.hidden) attrs += ' display="none"';
    return attrs;
}

function textInner(el) {
    const size = Math.max(1, num(el.fontSize));
    const anchor = ['start', 'middle', 'end'].includes(el.align) ? el.align : 'start';
    const x = anchor === 'start' ? el.x + TEXT_PADDING
        : anchor === 'end' ? el.x + el.w - TEXT_PADDING
        : el.x + el.w / 2;
    const lines = String(el.text ?? '').split('\n');

    let out = '';
    if (el.backgroundEnabled) {
        out += `<rect x="${num(el.x)}" y="${num(el.y)}" width="${num(el.w)}" height="${num(el.h)}" `
            + `rx="${num(el.backgroundRadius)}" fill="${color(el.background)}"/>`;
    }
    out += `<text x="${num(x)}" y="${num(el.y + TEXT_PADDING)}" font-family="${escapeXml(el.fontFamily || 'sans-serif')}" `
        + `font-size="${size}" font-weight="${el.bold ? 'bold' : 'normal'}" font-style="${el.italic ? 'italic' : 'normal'}" `
        + `fill="${color(el.fill, '#ffffff')}" text-anchor="${anchor}" xml:space="preserve">`;
    lines.forEach((line, i) => {
        // First baseline sits ~one ascent below the top padding.
        const dy = i === 0 ? size * 0.9 : size * LINE_HEIGHT;
        out += `<tspan x="${num(x)}" dy="${num(dy)}">${escapeXml(line)}</tspan>`;
    });
    out += '</text>';
    return out;
}

function rectInner(el) {
    const strokeWidth = num(el.strokeWidth);
    return `<rect x="${num(el.x)}" y="${num(el.y)}" width="${num(el.w)}" height="${num(el.h)}" rx="${num(el.radius)}" `
        + `fill="${el.fillEnabled === false ? 'none' : color(el.fill, '#ffffff')}"`
        + (strokeWidth > 0 ? ` stroke="${color(el.stroke)}" stroke-width="${strokeWidth}"` : '')
        + '/>';
}

// Attribute strings shared by the compiler and the editor canvas (which
// binds these directly so dragging never re-parses heavy markup).
export function nestedSvgOpen(el) {
    const aspect = el.type === 'svg-import' ? 'xMidYMid meet' : 'none';
    return `<svg x="${num(el.x)}" y="${num(el.y)}" width="${num(el.w)}" height="${num(el.h)}" `
        + `viewBox="${viewBox(el.viewBox)}" preserveAspectRatio="${aspect}" overflow="hidden">`;
}

export function compileElement(el) {
    let inner;
    switch (el.type) {
        case 'text': inner = textInner(el); break;
        case 'rect': inner = rectInner(el); break;
        case 'image':
            inner = `<image x="${num(el.x)}" y="${num(el.y)}" width="${num(el.w)}" height="${num(el.h)}" `
                + `preserveAspectRatio="none" href="${escapeXml(el.href ?? '')}"/>`;
            break;
        case 'qr':
        case 'svg-import':
            inner = `${nestedSvgOpen(el)}${el.markup ?? ''}</svg>`;
            break;
        default:
            return '';
    }
    return `<g ${wrapperAttrs(el)}>${inner}</g>`;
}

export function compileOverlay(elements) {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${CANVAS.w}" height="${CANVAS.h}" viewBox="0 0 ${CANVAS.w} ${CANVAS.h}">`
        + elements.map(compileElement).join('')
        + '</svg>';
}
