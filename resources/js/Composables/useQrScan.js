import wasmUrl from 'zxing-wasm/reader/zxing_reader.wasm?url';
import { analyzeQrReadability } from '@/Composables/qrReadability.js';

let readerPromise = null;

// zxing-wasm is loaded on first use, with its .wasm served as a Vite asset.
function loadReader() {
    readerPromise ??= import('zxing-wasm/reader').then(async (mod) => {
        mod.setZXingModuleOverrides({ locateFile: (path, prefix) => (path.endsWith('.wasm') ? wasmUrl : prefix + path) });
        return mod;
    });
    return readerPromise;
}

async function imageData(file) {
    const bitmap = await createImageBitmap(file);
    try {
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(bitmap, 0, 0);
        return ctx.getImageData(0, 0, canvas.width, canvas.height);
    } finally {
        bitmap.close();
    }
}

/**
 * Finds the QR codes in an image file, in reading order (top to bottom, then
 * left to right). Returns the decoded texts; never throws — an unreadable
 * image or a failure to load the decoder just yields no codes.
 */
export async function scanQrCodes(file) {
    if (!file.type.startsWith('image/') || file.type === 'image/svg+xml') return [];

    try {
        const [{ readBarcodes }, data] = await Promise.all([loadReader(), imageData(file)]);
        const results = await readBarcodes(data, {
            formats: ['QRCode'],
            maxNumberOfSymbols: 10,
            tryHarder: true,
        });

        const rowHeight = data.height / 10;
        return results
            .filter(r => r.isValid && r.text)
            .sort((a, b) => {
                const rowDiff = Math.floor(a.position.topLeft.y / rowHeight) - Math.floor(b.position.topLeft.y / rowHeight);
                return rowDiff || a.position.topLeft.x - b.position.topLeft.x;
            })
            .map(r => r.text);
    } catch {
        return [];
    }
}

export function isWebUrl(text) {
    try {
        return ['http:', 'https:'].includes(new URL(text).protocol);
    } catch {
        return false;
    }
}

// The QR targets an overlay was built with: the overlay editor embeds its
// source as <metadata id="as-overlay-source"> JSON, whose `qr` elements keep
// their target in `data`. (An overlay edited outside the editor has none.)
export function overlayQrTargets(svgText) {
    try {
        const doc = new DOMParser().parseFromString(svgText, 'image/svg+xml');
        const json = doc.getElementById('as-overlay-source')?.textContent;
        const elements = json ? JSON.parse(json).elements ?? [] : [];
        return elements.filter(el => el.type === 'qr' && typeof el.data === 'string' && el.data).map(el => el.data);
    } catch {
        return [];
    }
}

/**
 * Every QR target on a slide's media rows (an Edit page's `slide.media`):
 * codes in the slide image itself, then those in its overlay. Rejects if a
 * file can't be fetched.
 */
export async function scanSlideMedia(media) {
    const slide = media.find(m => m.media_type === 'slide' && m.mime_type?.startsWith('image/'));
    const overlay = media.find(m => m.media_type === 'slide-overlay');

    const [inImage, inOverlay] = await Promise.all([
        slide ? fetch(slide.file_url).then(r => r.blob()).then(b => scanQrCodes(b)) : [],
        overlay ? fetch(overlay.file_url).then(r => r.text()).then(overlayQrTargets) : [],
    ]);

    return { inImage, inOverlay, all: [...new Set([...inImage, ...inOverlay])] };
}

// The decoder as the readability checks drive it (see qrReadability.js).
async function readQr(image) {
    const { readBarcodes } = await loadReader();
    return readBarcodes(image, { formats: ['QRCode'], maxNumberOfSymbols: 10, tryHarder: true });
}

/**
 * Readability warnings for the QR codes in an image file (see
 * analyzeQrReadability). Never throws — failures just mean no warnings.
 */
export async function checkQrReadability(file) {
    if (!file.type.startsWith('image/') || file.type === 'image/svg+xml') return { codes: [], issues: [] };

    try {
        return await analyzeQrReadability(await imageData(file), readQr);
    } catch {
        return { codes: [], issues: [] };
    }
}

const SLIDE_CANVAS = { w: 1920, h: 1080 };

function loadImage(blob) {
    const url = URL.createObjectURL(blob);
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('image failed to load')); };
        img.src = url;
    });
}

// Draws an image into the canvas the way the displays do: scaled to fit
// ("object-contain") and centered. SVGs without an intrinsic size are
// taken to be canvas-sized.
function drawContained(ctx, img) {
    const w = img.naturalWidth || SLIDE_CANVAS.w;
    const h = img.naturalHeight || SLIDE_CANVAS.h;
    const scale = Math.min(SLIDE_CANVAS.w / w, SLIDE_CANVAS.h / h);
    ctx.drawImage(img, (SLIDE_CANVAS.w - w * scale) / 2, (SLIDE_CANVAS.h - h * scale) / 2, w * scale, h * scale);
}

const fetchBlob = url => fetch(url, { cache: 'no-cache' }).then(r => {
    if (!r.ok) throw new Error(`could not fetch ${url}`);
    return r.blob();
});

/**
 * QR readability of a slide as shown: its image with the overlay burned in,
 * on the 1080p black canvas the displays use — so a code that only goes
 * wrong against the picture behind it (or whose overlay hides part of one)
 * is judged as the audience sees it. Live widgets aren't included. Returns
 * null for a slide that isn't an image (video) or has nothing to render;
 * rejects if a file can't be fetched or drawn.
 */
export async function checkSlideReadability(media) {
    const slides = media.filter(m => m.media_type === 'slide');
    if (slides.some(m => !m.mime_type?.startsWith('image/'))) return null;
    const slide = slides[0];
    const overlay = media.find(m => m.media_type === 'slide-overlay');
    if (!slide && !overlay) return null;

    const [slideImg, overlayImg] = await Promise.all([
        slide ? fetchBlob(slide.file_url).then(loadImage) : null,
        overlay ? fetchBlob(overlay.file_url).then(async b => loadImage(
            overlay.mime_type?.includes('svg') ? new Blob([await b.text()], { type: 'image/svg+xml' }) : b,
        )) : null,
    ]);

    const canvas = document.createElement('canvas');
    canvas.width = SLIDE_CANVAS.w;
    canvas.height = SLIDE_CANVAS.h;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    if (slideImg) drawContained(ctx, slideImg);
    if (overlayImg) drawContained(ctx, overlayImg);

    return analyzeQrReadability(ctx.getImageData(0, 0, canvas.width, canvas.height), readQr);
}
