import { ref } from 'vue';
import { useImageValidation } from '@/Composables/useImageValidation.js';
import {
    upscaleImage, upscaleIneligibility, useUpscalerSettings,
} from '@/Composables/useUpscaler.js';
import { downscaleImage, downscaledSize, exceedsDownscaleLimit } from '@/Composables/useImageResize.js';

const RESIZABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * Browser-side resizing for an upload form's selected files: images beyond 4K
 * are shrunk to fit it, small ones are AI-upscaled 2x (both per the admin
 * settings — see useUpscaler.js / useImageResize.js). The form calls:
 *
 *   setFiles(files, validations)  when files are chosen (validations from
 *                                 useImageValidation, parallel to files)
 *   removeFile(i)                 when one is removed
 *   issuesFor(i)                  quality warnings for the file *as it will be*
 *   prepare(files)                on submit: resizes what's marked, returns the
 *                                 items for useChunkedUpload.upload (or null
 *                                 if cancelled); a failure uploads that file
 *                                 unchanged and says so in its `note`
 *
 * and shows each `infos[i]` with <ResizeOption>.
 */
export function useUploadResize() {
    const settings = useUpscalerSettings();
    const { validateDimensions } = useImageValidation();

    // Parallel to the files: { kind, eligible, reason, enabled, status, progress, note }
    const infos = ref([]);
    const validations = ref([]);
    const isResizing = ref(false);
    let abort = null;

    function infoFor(file, validation) {
        const none = { kind: null, eligible: false, reason: null, enabled: false };
        if (!settings || !RESIZABLE_TYPES.includes(file.type) || !validation?.width) return none;

        const base = { status: null, progress: 0, note: null };

        // Beyond 4K: shrink it (on by default; the checkbox lets them keep it).
        if (exceedsDownscaleLimit(validation.width, validation.height, settings)) {
            return { ...base, kind: 'downscale', eligible: true, reason: null, enabled: true };
        }

        if (!settings.enabled) return none;
        const reason = upscaleIneligibility(validation.width, validation.height, settings);
        return { ...base, kind: 'upscale', eligible: !reason, reason, enabled: !reason && settings.auto_on_upload };
    }

    function setFiles(files, fileValidations) {
        validations.value = [...fileValidations];
        infos.value = files.map((f, i) => infoFor(f, fileValidations[i]));
    }

    function removeFile(i) {
        validations.value.splice(i, 1);
        infos.value.splice(i, 1);
    }

    function reset() {
        validations.value = [];
        infos.value = [];
    }

    // The size file i ends up at if resized, or null.
    function sizeFor(i) {
        const v = validations.value[i];
        const kind = infos.value[i]?.kind;
        if (!v?.width || !v?.height) return null;
        if (kind === 'upscale') return { width: v.width * 2, height: v.height * 2 };
        if (kind === 'downscale') return downscaledSize(v.width, v.height, settings.downscale.max);
        return null;
    }

    // Quality warnings for file i: if it will be resized, judge the new size
    // rather than the original's (file-size issues are kept as they are).
    function issuesFor(i) {
        const v = validations.value[i];
        if (!v) return [];
        const size = infos.value[i]?.enabled ? sizeFor(i) : null;
        if (!size) return v.issues;

        const sizeIssues = v.issues.filter(m => !m.startsWith('Low resolution') && !m.startsWith('High resolution') && !m.startsWith('Aspect ratio'));
        return [...validateDimensions(size.width, size.height), ...sizeIssues];
    }

    function cancel() {
        abort?.abort();
    }

    async function prepare(files) {
        const items = [];
        isResizing.value = true;
        abort = new AbortController();

        try {
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const info = infos.value[i];

                if (!info?.enabled) {
                    items.push(file);
                    continue;
                }

                info.status = 'working';
                info.progress = 0;
                info.note = null;

                try {
                    const isUpscale = info.kind === 'upscale';
                    const result = isUpscale
                        ? await upscaleImage(file, {
                            model: settings.model,
                            quality: settings.jpeg_quality,
                            patchSize: settings.patch_size,
                            onProgress: (p) => { info.progress = Math.round(p * 100); },
                            signal: abort.signal,
                        })
                        : await downscaleImage(file, settings.downscale.max, { quality: settings.jpeg_quality });

                    items.push({
                        file: new File([result.blob], file.name.replace(/\.[^/.]+$/, '') + '.jpg', { type: 'image/jpeg' }),
                        resize: { kind: info.kind, model: isUpscale ? settings.model : null, original: file },
                    });
                    info.status = 'done';
                    info.note = `${isUpscale ? 'Upscaled' : 'Downscaled'} to ${result.width}×${result.height}.`;
                } catch (err) {
                    if (err.code === 'aborted') {
                        info.status = null;
                        return null;
                    }
                    items.push(file);
                    info.status = 'failed';
                    info.note = `${err.message} The original will be uploaded instead.`;
                }
            }
        } finally {
            isResizing.value = false;
            abort = null;
        }

        return items;
    }

    return { settings, infos, isResizing, setFiles, removeFile, reset, sizeFor, issuesFor, prepare, cancel };
}
