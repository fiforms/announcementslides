import wasmUrl from 'zxing-wasm/reader/zxing_reader.wasm?url';

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
