import { encodeJpeg } from '@/Composables/useUpscaler.js';

// Downscaling images larger than 4K, and re-encoding files over the size
// limit, in the browser. No AI involved: the image is drawn onto a hidden
// canvas (at the smaller size, or unchanged) and encoded as a JPEG, which the
// caller uploads in place of the original (the server keeps the original —
// see SlideMedia variants). The limits are the shared `upscaler` prop's
// `downscale.max` ({ w, h }) and `downscale.max_bytes`, from the server's
// config('slides.downscale') and ImageValidationService.

/** Whether an image of this size is over the limit and should be shrunk. */
export function exceedsDownscaleLimit(width, height, settings) {
    const d = settings?.downscale;
    return !!(d?.enabled && width && height && (width > d.max.w || height > d.max.h));
}

/** Whether a file is over the size limit (and, if it's an image, worth re-encoding). */
export function exceedsFileSizeLimit(bytes, settings) {
    const d = settings?.downscale;
    return !!(d?.enabled && bytes > d.max_bytes);
}

/**
 * The size the image shrinks to: fitted within the limit, aspect ratio kept.
 * Mirrors App\Support\ImageResize::target on the server.
 */
export function downscaledSize(width, height, max) {
    const scale = Math.min(max.w / width, max.h / height);
    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

/**
 * Shrinks an image Blob/File to fit within `max` ({ w, h }) — or, with a null
 * `max`, keeps its size and only re-encodes it — and returns
 * { blob, width, height, quality } with `blob` a JPEG. Transparent areas are
 * flattened onto white (JPEG has no alpha). Options: quality (0–100),
 * maxBytes (null to skip the size cap, see encodeJpeg).
 */
export async function downscaleImage(source, max, { quality = 98, maxBytes } = {}) {
    let bitmap;
    try {
        bitmap = await createImageBitmap(source);
    } catch {
        throw new Error('The image could not be read.');
    }

    const { width, height } = max ? downscaledSize(bitmap.width, bitmap.height, max) : bitmap;
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, width, height);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(bitmap, 0, 0, width, height);
    bitmap.close?.();

    const encoded = await encodeJpeg(canvas, quality, maxBytes);

    return { blob: encoded.blob, width, height, quality: encoded.quality };
}
