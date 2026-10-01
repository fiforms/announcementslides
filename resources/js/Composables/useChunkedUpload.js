import { ref, computed } from 'vue';

const CHUNK_SIZE = 1.5 * 1024 * 1024;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

export function useChunkedUpload(options = {}) {
    const chunkRoute          = options.chunkRoute ?? 'uploads.chunk';
    const finalizeRoute       = options.finalizeRoute ?? 'uploads.finalize';
    const finalizeRouteParams = options.finalizeRouteParams ?? {};
    // Overrides the default `{ uploads: [...], ...payload }` finalize body —
    // used by callers finalizing against a single-file endpoint instead of
    // the batch uploads.finalize route (see MediaManager.vue).
    const buildFinalizePayload = options.buildFinalizePayload
        ?? ((completedUploads, payload) => ({ uploads: completedUploads, ...payload }));

    const isUploading     = ref(false);
    const uploadError     = ref(null);
    const fileProgress    = ref([]);
    const overallProgress = computed(() => {
        if (!fileProgress.value.length) return 0;
        return Math.round(fileProgress.value.reduce((sum, f) => sum + f.progress, 0) / fileProgress.value.length);
    });

    async function uploadChunks(file, uploadId, mediaType, onProgress) {
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        let result = null;

        for (let i = 0; i < totalChunks; i++) {
            const start = i * CHUNK_SIZE;
            const chunk = file.slice(start, start + CHUNK_SIZE);

            const fd = new FormData();
            fd.append('upload_id',    uploadId);
            fd.append('chunk_index',  i);
            fd.append('total_chunks', totalChunks);
            fd.append('filename',     file.name);
            fd.append('media_type',   mediaType);
            fd.append('mime_type',    file.type);
            fd.append('chunk',        chunk, `chunk_${i}`);

            const { data } = await window.axios.post(route(chunkRoute), fd, {
                headers: { 'X-CSRF-TOKEN': csrfToken() },
            });

            onProgress(Math.round(((i + 1) / totalChunks) * 100));

            if (data.status === 'complete') {
                result = data;
            }
        }

        return result;
    }

    // `files` entries are Files, or { file, upscale: { model, original } } for an
    // image upscaled in the browser: `file` is the upscaled JPEG, and `original`
    // (a File) is uploaded too, so the server can keep it for undoing the upscale.
    async function upload(files, payload) {
        const items = files.map(f => (f instanceof Blob ? { file: f } : f));

        isUploading.value  = true;
        uploadError.value  = null;
        fileProgress.value = items.map(({ file }) => ({ name: file.name, progress: 0, done: false }));

        const completedUploads = [];
        const mediaType = payload?.media_type ?? 'slide';

        try {
            for (let fi = 0; fi < items.length; fi++) {
                const { file, upscale } = items[fi];
                const original = upscale?.original ?? null;
                const totalBytes = file.size + (original?.size ?? 0);
                let mainPct = 0;
                let originalPct = 0;
                const report = () => {
                    fileProgress.value[fi].progress = Math.round(
                        ((mainPct / 100) * file.size + (originalPct / 100) * (original?.size ?? 0)) / totalBytes * 100,
                    );
                };

                const assembled = await uploadChunks(file, crypto.randomUUID(), mediaType, (pct) => {
                    mainPct = pct;
                    report();
                });

                if (original) {
                    const assembledOriginal = await uploadChunks(original, crypto.randomUUID(), mediaType, (pct) => {
                        originalPct = pct;
                        report();
                    });
                    assembled.upscale = {
                        model: upscale.model,
                        original: {
                            filename:          assembledOriginal.filename,
                            disk_path:         assembledOriginal.disk_path,
                            original_filename: assembledOriginal.original_filename,
                            file_size:         assembledOriginal.file_size,
                            mime_type:         assembledOriginal.mime_type,
                        },
                    };
                }

                fileProgress.value[fi].done = true;
                completedUploads.push(assembled);
            }

            const { data } = await window.axios.post(
                route(finalizeRoute, finalizeRouteParams),
                buildFinalizePayload(completedUploads, payload),
                { headers: { 'X-CSRF-TOKEN': csrfToken() } },
            );

            return data;
        } catch (err) {
            uploadError.value = err.response?.data?.message ?? 'Upload failed. Please try again.';
            return null;
        } finally {
            isUploading.value = false;
        }
    }

    return { isUploading, uploadError, fileProgress, overallProgress, upload };
}
