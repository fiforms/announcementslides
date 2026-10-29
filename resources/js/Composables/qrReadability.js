// Readability checks for the QR codes on a slide, as the audience sees it.
//
// Pure pixel logic: everything works on `{ data, width, height }` RGBA images
// (an ImageData or a plain look-alike), and the decoder is passed in as
// `read(image) -> results`, so this runs in the browser (zxing-wasm via
// useQrScan) and under plain Node for calibration alike.
//
// A code that decodes at full size can still fail in the room: shrink the
// slide to a projector-ish 360p/450p and it may be too small, or binarize it
// at fixed cutoffs and it may lack contrast. Those two are what we measure,
// plus light-on-dark codes, a missing quiet zone and (elsewhere) several codes.

export const SHRINK_HEIGHTS = [450, 360];
export const THRESHOLDS = [0.2, 0.4, 0.6, 0.8];
export const MIN_THRESHOLD_PASSES = 2;
export const MIN_CONTRAST_RATIO = 3;
// A decoder can squeeze a code down to ~1.3px per module on clean synthetic
// pixels; cameras and projectors can't. Want this many at 450p.
export const MIN_MODULE_PX = 2;
export const MAX_ANALYSED_CODES = 3;
// Long side the audience's screen effectively resolves; bigger slides are
// brought down to it first so "full size" means the same for every upload.
export const MAX_WIDTH = 1920;

const luma = (data, i) => (data[i] * 299 + data[i + 1] * 587 + data[i + 2] * 114) / 1000;

const toLinear = c => {
    const s = c / 255;
    return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
};

const relativeLuminance = (r, g, b) => 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b);

// Area-average downscale. Cheap, deterministic and close enough to how a
// display or camera blurs fine detail; never upscales.
export function downscale(image, targetWidth) {
    const { width, height, data } = image;
    if (targetWidth >= width) return image;

    const w = Math.max(1, Math.round(targetWidth));
    const h = Math.max(1, Math.round((height * w) / width));
    const out = new Uint8ClampedArray(w * h * 4);
    const sx = width / w;
    const sy = height / h;

    for (let y = 0; y < h; y++) {
        const y0 = y * sy;
        const y1 = Math.min(height, (y + 1) * sy);
        for (let x = 0; x < w; x++) {
            const x0 = x * sx;
            const x1 = Math.min(width, (x + 1) * sx);
            let r = 0, g = 0, b = 0, weight = 0;
            for (let yy = Math.floor(y0); yy < Math.ceil(y1); yy++) {
                const wy = Math.min(yy + 1, y1) - Math.max(yy, y0);
                for (let xx = Math.floor(x0); xx < Math.ceil(x1); xx++) {
                    const wgt = wy * (Math.min(xx + 1, x1) - Math.max(xx, x0));
                    const i = (yy * width + xx) * 4;
                    r += data[i] * wgt;
                    g += data[i + 1] * wgt;
                    b += data[i + 2] * wgt;
                    weight += wgt;
                }
            }
            const o = (y * w + x) * 4;
            out[o] = r / weight;
            out[o + 1] = g / weight;
            out[o + 2] = b / weight;
            out[o + 3] = 255;
        }
    }
    return { data: out, width: w, height: h };
}

export function crop(image, x, y, w, h) {
    const x0 = Math.max(0, Math.floor(x));
    const y0 = Math.max(0, Math.floor(y));
    const x1 = Math.min(image.width, Math.ceil(x + w));
    const y1 = Math.min(image.height, Math.ceil(y + h));
    const cw = Math.max(1, x1 - x0);
    const ch = Math.max(1, y1 - y0);
    const out = new Uint8ClampedArray(cw * ch * 4);
    for (let row = 0; row < ch; row++) {
        const from = ((y0 + row) * image.width + x0) * 4;
        out.set(image.data.subarray(from, from + cw * 4), row * cw * 4);
    }
    return { data: out, width: cw, height: ch, x: x0, y: y0 };
}

// Hard black/white at `cutoff` (0–1 of full luminance).
export function binarize(image, cutoff) {
    const out = new Uint8ClampedArray(image.data.length);
    const t = cutoff * 255;
    for (let i = 0; i < out.length; i += 4) {
        const v = luma(image.data, i) >= t ? 255 : 0;
        out[i] = out[i + 1] = out[i + 2] = v;
        out[i + 3] = 255;
    }
    return { data: out, width: image.width, height: image.height };
}

// Otsu's split of an image's luminance, and the WCAG contrast ratio between
// the mean colours of the two sides. A clean code is two tight clusters
// (huge ratio); a washed-out or busy-background one is two close ones.
export function contrastOf(image) {
    const hist = new Float64Array(256);
    const total = image.width * image.height;
    for (let i = 0; i < image.data.length; i += 4) hist[Math.round(luma(image.data, i))]++;

    let sumAll = 0;
    for (let v = 0; v < 256; v++) sumAll += v * hist[v];
    let wB = 0, sumB = 0, best = -1, first = 127, last = 127;
    for (let v = 0; v < 256; v++) {
        wB += hist[v];
        if (!wB) continue;
        const wF = total - wB;
        if (!wF) break;
        sumB += v * hist[v];
        const between = wB * wF * (sumB / wB - (sumAll - sumB) / wF) ** 2;
        // A clean two-colour code is flat between its colours: take the middle.
        if (between > best * (1 + 1e-9)) { best = between; first = last = v; }
        else if (between >= best * (1 - 1e-9)) last = v;
    }

    const split = Math.round((first + last) / 2);
    const sums = [[0, 0, 0, 0], [0, 0, 0, 0]]; // dark, light: r, g, b, count
    for (let i = 0; i < image.data.length; i += 4) {
        const s = sums[luma(image.data, i) > split ? 1 : 0];
        s[0] += image.data[i]; s[1] += image.data[i + 1]; s[2] += image.data[i + 2]; s[3]++;
    }
    const lum = s => (s[3] ? relativeLuminance(s[0] / s[3], s[1] / s[3], s[2] / s[3]) : 0);
    const dark = lum(sums[0]);
    const light = lum(sums[1]);
    return { split, ratio: (Math.max(dark, light) + 0.05) / (Math.min(dark, light) + 0.05) };
}

function geometry(position) {
    const { topLeft: a, topRight: b, bottomRight: c, bottomLeft: d } = position;
    const xs = [a.x, b.x, c.x, d.x];
    const ys = [a.y, b.y, c.y, d.y];
    const x = Math.min(...xs);
    const y = Math.min(...ys);
    const side = (Math.hypot(b.x - a.x, b.y - a.y) + Math.hypot(c.x - d.x, c.y - d.y)
        + Math.hypot(d.x - a.x, d.y - a.y) + Math.hypot(c.x - b.x, c.y - b.y)) / 4;
    return { x, y, w: Math.max(...xs) - x, h: Math.max(...ys) - y, side };
}

// Modules per side from the symbol version ("5", "5-L"…), when reported.
function modulesOf(version) {
    const v = parseInt(version, 10);
    return v >= 1 && v <= 40 ? 17 + 4 * v : null;
}

const decoded = results => results.filter(r => r.isValid && r.text);
const reads = (results, text) => decoded(results).some(r => r.text === text);

// Fraction of the 1-module ring just outside the code that is "background"
// (light for a normal code, dark for an inverted one).
function quietZoneFraction(image, box, ring, split, inverted) {
    const x0 = Math.round(box.x - ring), y0 = Math.round(box.y - ring);
    const x1 = Math.round(box.x + box.w + ring), y1 = Math.round(box.y + box.h + ring);
    let good = 0, n = 0;
    for (let y = y0; y < y1; y++) {
        for (let x = x0; x < x1; x++) {
            const inside = x >= box.x && x < box.x + box.w && y >= box.y && y < box.y + box.h;
            if (inside) continue;
            n++;
            // Off the slide counts as missing border.
            if (x < 0 || y < 0 || x >= image.width || y >= image.height) continue;
            const light = luma(image.data, (y * image.width + x) * 4) > split;
            if (light !== inverted) good++;
        }
    }
    return n ? good / n : 1;
}

/**
 * Analyses every (up to MAX_ANALYSED_CODES) readable QR code in `image`.
 * `read(image)` must return zxing-style results (`text`, `isValid`,
 * `position`, `isInverted`, `version`).
 *
 * Returns { codes: [{ text, widthPct, modules, tooSmall, ... }], issues: [], texts: [] }
 * where issues are human-readable warnings; `codes` is empty when none read.
 */
export async function analyzeQrReadability(source, read) {
    const image = downscale(source, MAX_WIDTH);
    const found = decoded(await read(image));
    if (!found.length) return { codes: [], issues: [], texts: [] };

    const many = found.length > 1;
    const codes = [];
    const issues = [];

    for (const hit of found.slice(0, MAX_ANALYSED_CODES)) {
        const label = many ? `QR code ${codes.length + 1}: ` : 'QR code ';
        const box = geometry(hit.position);
        const modules = modulesOf(hit.version);
        const widthPct = (box.side / image.width) * 100;
        const report = { text: hit.text, widthPct, modules, inverted: !!hit.isInverted };
        const note = (text, key) => { issues.push(text); report[key] = true; };

        // Contrast first: its split also normalises the size test below, so a
        // washed-out code isn't blamed on size (and vice versa).
        const pad = box.side * 0.3;
        const region = crop(image, box.x - pad, box.y - pad, box.w + pad * 2, box.h + pad * 2);
        const inner = crop(image, box.x, box.y, box.w, box.h);
        const { split, ratio } = contrastOf(inner);

        // Size: does a clean copy of the code survive a small screen?
        const clean = binarize(region, (split + 0.5) / 255);
        const shrunk = [];
        for (const h of SHRINK_HEIGHTS) {
            if (h >= image.height) { shrunk.push(true); continue; }
            const small = downscale(clean, Math.max(1, Math.round((clean.width * h) / image.height)));
            shrunk.push(reads(await read(small), hit.text));
        }
        if (!shrunk[shrunk.length - 1]) {
            note(`${label}is too small — it stops scanning when the slide is shrunk to ${SHRINK_HEIGHTS[SHRINK_HEIGHTS.length - 1]}p `
                + `(it's ${widthPct.toFixed(0)}% of the slide's width${modules && modules > 33 ? '; a shorter link makes a simpler, easier code' : ''})`, 'tooSmall');
        } else if (!shrunk[0]) {
            note(`${label}is on the small side — it stops scanning when the slide is shrunk to ${SHRINK_HEIGHTS[0]}p `
                + `(it's ${widthPct.toFixed(0)}% of the slide's width)`, 'tooSmall');
        } else if (modules && (box.side / image.height) * SHRINK_HEIGHTS[0] / modules < MIN_MODULE_PX) {
            note(`${label}is on the small side — its squares would be under ${MIN_MODULE_PX} pixels wide on a ${SHRINK_HEIGHTS[0]}p screen `
                + `(it's ${widthPct.toFixed(0)}% of the slide's width${modules > 33 ? '; a shorter link makes a simpler, easier code' : ''})`, 'tooSmall');
        }

        // Contrast: fixed black/white cutoffs over the code and its border.
        let passes = 0;
        for (const cutoff of THRESHOLDS) {
            if (reads(await read(binarize(region, cutoff)), hit.text)) passes++;
        }
        Object.assign(report, { passes, contrast: ratio });
        if (passes < MIN_THRESHOLD_PASSES || ratio < MIN_CONTRAST_RATIO) {
            note(`${label}doesn't have enough contrast to scan reliably `
                + `(${ratio.toFixed(1)}:1; use dark modules on a light background)`, 'lowContrast');
        }

        if (hit.isInverted) {
            issues.push(`${label}is light on dark — many phone scanners can't read inverted codes`);
        }

        // Quiet zone: scanners need an empty border about the width of a module.
        const ring = Math.max(2, box.side / (modules ?? 33));
        if (quietZoneFraction(image, box, ring, split, !!hit.isInverted) < 0.8) {
            note(`${label}has no clear border around it — leave a plain margin of at least a few code squares`, 'noQuietZone');
        }

        codes.push(report);
    }

    return { codes, issues, texts: found.map(f => f.text) };
}
