import { usePage } from '@inertiajs/vue3';

// In-browser 2x AI upscaling (UpscalerJS + TensorFlow.js). Everything here
// runs on the visitor's GPU; the result is a JPEG blob the caller uploads.
//
// The libraries and the chosen model are dynamically imported the first time
// an upscale is requested, so pages that never upscale don't pay for them.
// Model weights are served from our own origin (/upscaler-models/<key>/,
// copied from node_modules by vite.config.js) instead of UpscalerJS's CDN
// default. The keys here must match config('slides.upscale.models').
const MODEL_LOADERS = {
    'default-model': () => import('@upscalerjs/default-model'),
    'esrgan-slim':   () => import('@upscalerjs/esrgan-slim/2x'),
    'esrgan-medium': () => import('@upscalerjs/esrgan-medium/2x'),
    'esrgan-thick':  () => import('@upscalerjs/esrgan-thick/2x'),
};

// Server-side file-size ceiling (ImageValidationService); a result over it is
// re-encoded at lower quality rather than being rejected on upload.
const MAX_BYTES = 5 * 1024 * 1024;
const MIN_QUALITY = 0.8;

export class UpscaleError extends Error {
    constructor(code, message) {
        super(message);
        this.code = code;
    }
}

const engines = new Map();

async function loadEngine(modelKey) {
    if (!engines.has(modelKey)) {
        const loader = MODEL_LOADERS[modelKey];
        if (!loader) throw new UpscaleError('unknown-model', `Unknown upscaler model "${modelKey}".`);

        const pending = (async () => {
            const [{ default: Upscaler }, tf, { default: model }] = await Promise.all([
                import('upscaler'),
                import('@tensorflow/tfjs'),
                loader(),
            ]);
            await tf.ready();
            // The CPU backend would take many minutes per image.
            if (tf.getBackend() === 'cpu') {
                throw new UpscaleError('no-gpu', 'This browser has no GPU acceleration (WebGL), which upscaling needs.');
            }
            const upscaler = new Upscaler({ model: { ...model, path: `/upscaler-models/${modelKey}/model.json` } });
            return { upscaler, tf };
        })();

        // A failed load (offline, no WebGL) must be retryable.
        pending.catch(() => engines.delete(modelKey));
        engines.set(modelKey, pending);
    }

    return engines.get(modelKey);
}

/**
 * Whether an image of this size may be upscaled: at or under `max`, and at
 * least `min` (below which there's too little to build on). `limits` is the
 * shared `upscaler` prop ({ min: {w,h}, max: {w,h} }).
 * Returns null when eligible, else 'too-small' | 'too-large'.
 */
export function upscaleIneligibility(width, height, limits) {
    if (!width || !height) return 'unreadable';
    if (width > limits.max.w || height > limits.max.h) return 'too-large';
    if (width < limits.min.w || height < limits.min.h) return 'too-small';
    return null;
}

export function ineligibleMessage(reason, limits) {
    return {
        'too-small': `Too small to upscale (minimum ${limits.min.w}×${limits.min.h}).`,
        'too-large': `Already larger than ${limits.max.w}×${limits.max.h}; no upscaling needed.`,
        'unreadable': 'The image could not be read.',
    }[reason] ?? '';
}

function canvasToBlob(canvas, quality) {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new UpscaleError('encode', 'Could not encode the upscaled image.'))),
            'image/jpeg',
            quality,
        );
    });
}

/**
 * Encodes a canvas as JPEG at `quality` (0–100), stepping the quality down
 * (to a floor of 80) while the result is over `maxBytes`. Shared with
 * useImageResize.js. Returns { blob, quality } (quality as actually used).
 */
export async function encodeJpeg(canvas, quality, maxBytes = MAX_BYTES) {
    let q = quality / 100;
    let blob = await canvasToBlob(canvas, q);
    while (maxBytes && blob.size > maxBytes && q > MIN_QUALITY) {
        q = Math.max(MIN_QUALITY, q - 0.03);
        blob = await canvasToBlob(canvas, q);
    }

    return { blob, quality: Math.round(q * 100) };
}

/**
 * Upscales an image Blob/File 2x and returns { blob, width, height, ms, quality }
 * with `blob` a JPEG. Options: model (key), quality (0–100), patchSize,
 * onProgress(0..1), signal (AbortSignal), maxBytes (null to skip the size cap).
 * Transparent areas are flattened onto white (JPEG has no alpha).
 */
export async function upscaleImage(source, { model, quality = 98, patchSize = 64, onProgress, signal, maxBytes = MAX_BYTES } = {}) {
    const started = performance.now();
    const { upscaler, tf } = await loadEngine(model);
    if (signal?.aborted) throw new UpscaleError('aborted', 'Cancelled.');

    let bitmap;
    try {
        bitmap = await createImageBitmap(source);
    } catch {
        throw new UpscaleError('unreadable', 'The image could not be read.');
    }

    const input = document.createElement('canvas');
    input.width = bitmap.width;
    input.height = bitmap.height;
    const ctx = input.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, input.width, input.height);
    ctx.drawImage(bitmap, 0, 0);
    bitmap.close?.();

    let tensor;
    try {
        tensor = await upscaler.upscale(input, {
            output: 'tensor',
            patchSize,
            padding: 2,
            progress: (amount) => onProgress?.(amount),
            signal,
        });
    } catch (err) {
        if (signal?.aborted) throw new UpscaleError('aborted', 'Cancelled.');
        // Out of GPU memory is the usual failure on weak hardware.
        throw new UpscaleError('failed', `Upscaling failed${err?.message ? `: ${err.message}` : '.'}`);
    }

    const output = document.createElement('canvas');
    try {
        const pixels = tf.tidy(() => {
            const t = tensor.rank === 4 ? tensor.squeeze([0]) : tensor;
            return t.clipByValue(0, 255).round().cast('int32');
        });
        await tf.browser.toPixels(pixels, output);
        pixels.dispose();
    } finally {
        tensor.dispose();
    }

    const { blob, quality: usedQuality } = await encodeJpeg(output, quality, maxBytes);

    return { blob, width: output.width, height: output.height, ms: performance.now() - started, quality: usedQuality };
}

/** The shared `upscaler` Inertia prop (null when signed out), or null. */
export function useUpscalerSettings() {
    return usePage().props.upscaler ?? null;
}
