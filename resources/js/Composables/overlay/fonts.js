// Fonts the overlay editor offers besides the generic CSS families. The
// files are Latin/Latin-1 subsets in public/fonts/overlay (see
// scripts/build_overlay_fonts.py; SIL OFL, licenses alongside).
//
// Players show an overlay through <img>, which can't see page fonts, so a
// saved overlay carries an @font-face (base64 WOFF2) for just the faces its
// text uses (embedFontCss). The editor itself, being in the page, loads the
// same files through a normal <style> (installEditorFonts). The server-side
// rasterizer (rsvg-convert) ignores @font-face and finds the same files
// through fontconfig, from TTF copies in resources/fonts/overlay (see OverlayCompositor).
//
// Face weights are 400 or 700 only. When no exact face exists (bold
// Italianno) the closest one is used and the renderer synthesizes the rest,
// exactly as the generic fonts do.

export const FONT_BASE = '/fonts/overlay/';

export const CUSTOM_FONTS = [
    { family: 'Inter', label: 'Inter (sans-serif)', fallback: 'sans-serif', faces: [
        { file: 'Inter-Regular', weight: 400, style: 'normal' },
        { file: 'Inter-Italic', weight: 400, style: 'italic' },
        { file: 'Inter-Bold', weight: 700, style: 'normal' },
        { file: 'Inter-BoldItalic', weight: 700, style: 'italic' },
    ] },
    { family: 'Noto Serif', label: 'Noto Serif (serif)', fallback: 'serif', faces: [
        { file: 'NotoSerif-Regular', weight: 400, style: 'normal' },
        { file: 'NotoSerif-Italic', weight: 400, style: 'italic' },
        { file: 'NotoSerif-Bold', weight: 700, style: 'normal' },
        { file: 'NotoSerif-BoldItalic', weight: 700, style: 'italic' },
    ] },
    { family: 'Cormorant Garamond', label: 'Cormorant Garamond (elegant serif)', fallback: 'serif', faces: [
        { file: 'CormorantGaramond-Regular', weight: 400, style: 'normal' },
        { file: 'CormorantGaramond-Italic', weight: 400, style: 'italic' },
        { file: 'CormorantGaramond-Bold', weight: 700, style: 'normal' },
        { file: 'CormorantGaramond-BoldItalic', weight: 700, style: 'italic' },
    ] },
    { family: 'Playfair Display SC', label: 'Playfair Display SC (small caps)', fallback: 'serif', faces: [
        { file: 'PlayfairDisplaySC-Regular', weight: 400, style: 'normal' },
        { file: 'PlayfairDisplaySC-Italic', weight: 400, style: 'italic' },
        { file: 'PlayfairDisplaySC-Bold', weight: 700, style: 'normal' },
        { file: 'PlayfairDisplaySC-BoldItalic', weight: 700, style: 'italic' },
    ] },
    { family: 'Playfair Display SC Black', label: 'Playfair Display SC Black', fallback: 'serif', faces: [
        { file: 'PlayfairDisplaySC-Black', weight: 400, style: 'normal' },
        { file: 'PlayfairDisplaySC-BlackItalic', weight: 400, style: 'italic' },
    ] },
    { family: 'Linden Hill', label: 'Linden Hill (old-style serif)', fallback: 'serif', faces: [
        { file: 'LindenHill-Regular', weight: 400, style: 'normal' },
        { file: 'LindenHill-Italic', weight: 400, style: 'italic' },
    ] },
    { family: 'League Gothic', label: 'League Gothic (bold, condensed)', fallback: 'sans-serif', faces: [
        { file: 'LeagueGothic-Regular', weight: 400, style: 'normal' },
    ] },
    { family: 'Advent Sans', label: 'Advent Sans (logo)', fallback: 'sans-serif', faces: [
        { file: 'AdventSans-Logo', weight: 400, style: 'normal' },
    ] },
    { family: 'Italianno', label: 'Italianno (script)', fallback: 'cursive', faces: [
        { file: 'Italianno-Regular', weight: 400, style: 'normal' },
    ] },
    { family: 'Beau Rivage', label: 'Beau Rivage (script)', fallback: 'cursive', faces: [
        { file: 'BeauRivage-Regular', weight: 400, style: 'normal' },
    ] },
    { family: 'Waterfall', label: 'Waterfall (script)', fallback: 'cursive', faces: [
        { file: 'Waterfall-Regular', weight: 400, style: 'normal' },
    ] },
];

const BY_FAMILY = new Map(CUSTOM_FONTS.map(f => [f.family, f]));

export function customFont(family) {
    return BY_FAMILY.get(family) ?? null;
}

// CSS value for a font-family attribute: custom families get a quoted name
// plus a generic fallback; anything else is passed through as-is.
export function fontStack(family) {
    const font = customFont(family);
    return font ? `'${font.family}', ${font.fallback}` : family;
}

// The face of `font` that best matches the wanted weight/style: exact, else
// same style, else same weight, else the first.
export function pickFace(font, bold, italic) {
    const weight = bold ? 700 : 400;
    const style = italic ? 'italic' : 'normal';
    const { faces } = font;
    return faces.find(f => f.weight === weight && f.style === style)
        ?? faces.find(f => f.style === style)
        ?? faces.find(f => f.weight === weight)
        ?? faces[0];
}

// Faces an overlay needs: text elements pick by their bold/italic toggles;
// imported artwork that names a custom family gets the regular face (plus
// bold/italic ones if the markup mentions them).
export function neededFaces(elements) {
    const needed = new Map(); // file -> { font, face }
    const add = (font, bold, italic) => {
        const face = pickFace(font, bold, italic);
        needed.set(face.file, { font, face });
    };
    for (const el of elements) {
        if (el.type === 'text') {
            const font = customFont(el.fontFamily);
            if (font) add(font, !!el.bold, !!el.italic);
        } else if (el.type === 'svg-import' && el.markup) {
            const markup = String(el.markup);
            for (const font of CUSTOM_FONTS) {
                if (!markup.includes(font.family)) continue;
                add(font, false, false);
                if (/bold|font-weight\s*[:=]\s*["']?[6-9]00/i.test(markup)) add(font, true, false);
                if (/italic|oblique/i.test(markup)) add(font, false, true);
            }
        }
    }
    return [...needed.values()];
}

function rule(font, face, src, extra = '') {
    return `@font-face{font-family:'${font.family}';font-weight:${face.weight};font-style:${face.style};`
        + `src:url(${src}) format('woff2')${extra}}`;
}

const dataCache = new Map();

async function dataUri(file) {
    if (!dataCache.has(file)) {
        dataCache.set(file, (async () => {
            const res = await fetch(`${FONT_BASE}${file}.woff2`);
            if (!res.ok) throw new Error(`font ${file}: HTTP ${res.status}`);
            const bytes = new Uint8Array(await res.arrayBuffer());
            let bin = '';
            for (let i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
            return `data:font/woff2;base64,${btoa(bin)}`;
        })().catch(e => { dataCache.delete(file); throw e; }));
    }
    return dataCache.get(file);
}

// '<style>…</style>' embedding the faces `elements` use, or '' if none.
export async function embedFontCss(elements) {
    const faces = neededFaces(elements);
    if (!faces.length) return '';
    const rules = await Promise.all(faces.map(async ({ font, face }) => rule(font, face, await dataUri(face.file))));
    return `<style>${rules.join('')}</style>`;
}

// Makes every custom font available to the editor page (files load lazily,
// only when text actually uses a face).
export function installEditorFonts() {
    if (typeof document === 'undefined' || document.getElementById('as-overlay-fonts')) return;
    const style = document.createElement('style');
    style.id = 'as-overlay-fonts';
    style.textContent = CUSTOM_FONTS.flatMap(font =>
        font.faces.map(face => rule(font, face, `${FONT_BASE}${face.file}.woff2`, ';font-display:swap'))).join('');
    document.head.appendChild(style);
}
