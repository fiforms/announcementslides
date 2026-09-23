// The overlay editor's data model. An overlay is a fixed 1920×1080 canvas
// plus an ordered list of elements (index 0 = bottom layer). The model is
// compiled to one self-contained SVG (compileOverlay.js) and its light-
// weight fields are embedded back into that SVG as JSON so it stays
// re-editable; heavy content (image data URIs, QR / imported SVG markup)
// lives only in the SVG body and is read back from it by element id.

export const SCHEMA_VERSION = 1;
export const CANVAS = { w: 1920, h: 1080 };

export const FONTS = [
    { value: 'sans-serif', label: 'Sans-serif' },
    { value: 'serif', label: 'Serif' },
    { value: 'monospace', label: 'Monospace' },
];

// Fields stored only in the compiled SVG body, per element type.
export const HEAVY_FIELDS = {
    image: ['href'],
    qr: ['markup'],
    'svg-import': ['markup'],
};

// Image-like elements keep their aspect ratio when resized.
export const ASPECT_LOCKED = new Set(['image', 'qr', 'svg-import']);

export function nextId(elements) {
    const max = elements.reduce((m, el) => Math.max(m, Number(el.id.replace('as-el-', '')) || 0), 0);
    return `as-el-${max + 1}`;
}

function base(elements, type, box) {
    return { id: nextId(elements), type, ...box, opacity: 1, hidden: false, locked: false };
}

export function createText(elements) {
    return {
        ...base(elements, 'text', { x: 660, y: 440, w: 600, h: 200 }),
        text: 'Your text',
        fontFamily: 'sans-serif',
        fontSize: 72,
        bold: true,
        italic: false,
        fill: '#ffffff',
        align: 'middle',
        background: '#000000',
        backgroundEnabled: false,
        backgroundRadius: 16,
    };
}

export function createRect(elements) {
    return {
        ...base(elements, 'rect', { x: 710, y: 390, w: 500, h: 300 }),
        fill: '#ffffff',
        fillEnabled: true,
        stroke: '#000000',
        strokeWidth: 0,
        radius: 24,
    };
}

export function createImage(elements, href, naturalW, naturalH) {
    const scale = Math.min(1, (CANVAS.w * 0.5) / naturalW, (CANVAS.h * 0.5) / naturalH);
    const w = Math.round(naturalW * scale);
    const h = Math.round(naturalH * scale);
    return { ...base(elements, 'image', { x: Math.round((CANVAS.w - w) / 2), y: Math.round((CANVAS.h - h) / 2), w, h }), href };
}

export function createQr(elements, qr) {
    const size = 320;
    return {
        ...base(elements, 'qr', { x: CANVAS.w - size - 60, y: CANVAS.h - size - 60, w: size, h: size }),
        ...qr, // data, target, foreground, background, radius, ecl, markup, viewBox
    };
}

// A whole external SVG kept as a fixed base layer (or imported to build on).
export function createSvgImport(elements, { markup, viewBox }, locked = true) {
    return {
        ...base(elements, 'svg-import', { x: 0, y: 0, w: CANVAS.w, h: CANVAS.h }),
        markup,
        viewBox,
        locked,
    };
}

export function cloneElements(elements) {
    return elements.map(el => ({ ...el }));
}

// The JSON embedded into the SVG: everything except heavy fields.
export function toSource(elements) {
    return {
        v: SCHEMA_VERSION,
        canvas: { ...CANVAS },
        elements: elements.map(el => {
            const copy = { ...el };
            for (const field of HEAVY_FIELDS[el.type] ?? []) delete copy[field];
            return copy;
        }),
    };
}

// Rebuilds full elements from an embedded source plus its (metadata-free)
// SVG body. Elements whose heavy content can't be found are dropped.
export function fromSource(source, svgText) {
    const doc = new DOMParser().parseFromString(svgText, 'image/svg+xml');
    const serializer = new XMLSerializer();

    return (source.elements ?? []).flatMap(el => {
        const fields = HEAVY_FIELDS[el.type];
        if (!fields) return [{ ...el }];

        const node = doc.getElementById(el.id);
        if (!node) return [];

        if (el.type === 'image') {
            const img = node.querySelector('image');
            const href = img?.getAttribute('href') ?? img?.getAttribute('xlink:href');
            return href ? [{ ...el, href }] : [];
        }

        const nested = node.querySelector('svg');
        if (!nested) return [];
        const markup = [...nested.childNodes].map(n => serializer.serializeToString(n)).join('');
        return [{ ...el, markup }];
    });
}
